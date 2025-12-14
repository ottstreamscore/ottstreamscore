<?php

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

function cap_str($s, int $maxLen): string
{
	$s = trim((string)$s);
	if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
	return $s;
}

function clamp_int($v, int $min, int $max, int $default): int
{
	if (!is_numeric($v)) return $default;
	$n = (int)$v;
	if ($n < $min) return $min;
	if ($n > $max) return $max;
	return $n;
}

function ts_filename(string $url): string
{
	$path = parse_url($url, PHP_URL_PATH);
	if (!$path) return '';
	$b = basename($path);
	return $b ?: '';
}

function res_label(?int $w, ?int $h): string
{
	if (!$w || !$h) return '—';
	$pixels = $w * $h;
	if ($h >= 2160 || $pixels >= (3840 * 2160)) return '4K';
	if ($h >= 1080 || $pixels >= (1920 * 1080)) return 'FHD';
	if ($h >= 720  || $pixels >= (1280 * 720))  return 'HD';
	return 'SD';
}

function res_rank(string $label): int
{
	return match ($label) {
		'4K' => 4,
		'FHD' => 3,
		'HD' => 2,
		'SD' => 1,
		default => 0
	};
}

function status_label($lastOk): string
{
	if ($lastOk === null) return 'UNKNOWN';
	return ((int)$lastOk === 1) ? 'OK' : 'FAIL';
}

function status_rank(string $label): int
{
	return match ($label) {
		'OK' => 2,
		'FAIL' => 1,
		'UNKNOWN' => 0,
		default => 0
	};
}

// DataTables core params
$draw   = clamp_int($_GET['draw'] ?? 1, 1, 1000000000, 1);
$start  = clamp_int($_GET['start'] ?? 0, 0, 1000000000, 0);
$length = clamp_int($_GET['length'] ?? 50, 1, 250, 50);

// We use our own sidebar search, not DT global search
$filters = $_GET['filters'] ?? [];
if (!is_array($filters)) $filters = [];

// Sanitize filters
$q = cap_str($filters['q'] ?? '', 120);
$group = cap_str($filters['group'] ?? '', 160);

$status = $filters['status'] ?? [];
$qual   = $filters['qual'] ?? [];
$hide   = $filters['hide'] ?? [];
if (!is_array($status)) $status = [];
if (!is_array($qual)) $qual = [];
if (!is_array($hide)) $hide = [];

$st_ok      = ((string)($status['ok'] ?? '0')) === '1';
$st_fail    = ((string)($status['fail'] ?? '0')) === '1';
$st_unknown = ((string)($status['unknown'] ?? '0')) === '1';

$q_4k  = ((string)($qual['k4'] ?? '0')) === '1';
$q_fhd = ((string)($qual['fhd'] ?? '0')) === '1';
$q_hd  = ((string)($qual['hd'] ?? '0')) === '1';
$q_sd  = ((string)($qual['sd'] ?? '0')) === '1';

$hide_ppv = ((string)($hide['ppv'] ?? '0')) === '1';
$hide_247 = ((string)($hide['t247'] ?? '0')) === '1';

// WHERE - using positional parameters
$where = [];
$params = [];

if ($q !== '') {
	$where[] = "(c.tvg_name LIKE ? OR c.tvg_id LIKE ?)";
	$params[] = "%{$q}%";
	$params[] = "%{$q}%";
}

if ($group !== '') {
	$where[] = "c.group_title = ?";
	$params[] = $group;
}

if ($hide_ppv) {
	$where[] = "(c.tvg_name NOT LIKE '%PPV%' AND c.group_title NOT LIKE '%PPV%')";
}

if ($hide_247) {
	$where[] = "(c.tvg_name NOT LIKE '%24/7%' AND c.group_title NOT LIKE '%24/7%')";
}

// Status clauses (if none checked, show none)
$statusClauses = [];
if ($st_ok)      $statusClauses[] = "f.last_ok = 1";
if ($st_fail)    $statusClauses[] = "f.last_ok = 0";
if ($st_unknown) $statusClauses[] = "f.last_ok IS NULL";
if ($statusClauses) {
	$where[] = "(" . implode(" OR ", $statusClauses) . ")";
} else {
	$where[] = "(1=0)";
}

// Quality clauses (only apply if any checked)
$qualClauses = [];
if ($q_4k)  $qualClauses[] = "(COALESCE(f.last_h,0) >= 2160 OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (3840 * 2160) . ")";
if ($q_fhd) $qualClauses[] = "((COALESCE(f.last_h,0) >= 1080 OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (1920 * 1080) . ")
                              AND NOT (COALESCE(f.last_h,0) >= 2160 OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (3840 * 2160) . "))";
if ($q_hd)  $qualClauses[] = "((COALESCE(f.last_h,0) >= 720 OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (1280 * 720) . ")
                              AND COALESCE(f.last_h,0) < 1080
                              AND (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) < " . (1920 * 1080) . ")";
if ($q_sd)  $qualClauses[] = "((COALESCE(f.last_h,0) > 0 AND COALESCE(f.last_h,0) < 720) OR (COALESCE(f.last_h,0)=0 AND COALESCE(f.last_w,0)=0))";
if ($qualClauses) {
	$where[] = "(" . implode(" OR ", $qualClauses) . ")";
}

$wsql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// ORDER BY: map DataTables column index to SQL expression
$orderCol = (int)(($_GET['order'][0]['column'] ?? 10)); // default checked_ts
$orderDir = (string)(($_GET['order'][0]['dir'] ?? 'desc'));
$orderDir = strtolower($orderDir) === 'asc' ? 'ASC' : 'DESC';

$colMap = [
	0 => "status_rank",                // computed later in SQL via CASE
	1 => "res_rank",                   // computed later in SQL via CASE
	2 => "c.group_title",
	3 => "c.tvg_name",
	4 => "COALESCE(f.last_fps,0)",
	5 => "COALESCE(f.last_codec,'')",
	6 => "COALESCE(f.last_checked_at,'1970-01-01')",
	10 => "COALESCE(f.last_checked_at,'1970-01-01')",
];

$orderExpr = $colMap[$orderCol] ?? "COALESCE(f.last_checked_at,'1970-01-01')";

// counts
$total = (int)$pdo->query("SELECT COUNT(*) FROM feeds")->fetchColumn();

$st = $pdo->prepare("
  SELECT COUNT(*)
  FROM feeds f
  JOIN channels c ON c.id = f.channel_id
  {$wsql}
");
$st->execute($params);
$filtered = (int)$st->fetchColumn();

// main query
$sql = "
  SELECT
    f.channel_id,
    f.last_ok,
    f.last_w,
    f.last_h,
    f.last_fps,
    f.last_codec,
    f.last_checked_at,
    COALESCE(f.url_display, f.url) AS url_any,
    c.tvg_name,
    c.tvg_id,
    c.group_title,

    -- status_rank
    CASE
      WHEN f.last_ok = 1 THEN 2
      WHEN f.last_ok = 0 THEN 1
      ELSE 0
    END AS status_rank,

    -- res_rank
    CASE
      WHEN (COALESCE(f.last_h,0) >= 2160 OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (3840 * 2160) . ") THEN 4
      WHEN (COALESCE(f.last_h,0) >= 1080 OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (1920 * 1080) . ") THEN 3
      WHEN (COALESCE(f.last_h,0) >= 720  OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (1280 * 720) . ") THEN 2
      WHEN (COALESCE(f.last_h,0) > 0 OR COALESCE(f.last_w,0) > 0) THEN 1
      ELSE 0
    END AS res_rank
  FROM feeds f
  JOIN channels c ON c.id = f.channel_id
  {$wsql}
  ORDER BY {$orderExpr} {$orderDir}
  LIMIT ? OFFSET ?
";
$st = $pdo->prepare($sql);

// Combine filter params with pagination params
$allParams = array_merge($params, [$length, $start]);
$st->execute($allParams);

$data = [];
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
	$stxt = status_label($r['last_ok']);
	$srank = (int)$r['status_rank'];

	$qlbl = res_label($r['last_w'] ? (int)$r['last_w'] : null, $r['last_h'] ? (int)$r['last_h'] : null);
	$qrank = (int)$r['res_rank'];

	$statusBadge = $stxt === 'OK'
		? '<span class="badge bg-success">OK</span>'
		: ($stxt === 'FAIL' ? '<span class="badge bg-danger">FAIL</span>' : '<span class="badge bg-secondary">UNK</span>');

	$qualBadge = match ($qlbl) {
		'4K'  => '<span class="badge bg-dark">4K</span>',
		'FHD' => '<span class="badge bg-primary">FHD</span>',
		'HD'  => '<span class="badge bg-info text-dark">HD</span>',
		'SD'  => '<span class="badge bg-light text-dark border">SD</span>',
		default => '<span class="badge bg-secondary">—</span>',
	};

	$fpsDisp = ($r['last_fps'] !== null) ? number_format((float)$r['last_fps'], 2) : '—';
	$codec = $r['last_codec'] ? (string)$r['last_codec'] : '—';

	$checkedAt = $r['last_checked_at'] ? (string)$r['last_checked_at'] : null;
	$checkedTs = $checkedAt ? strtotime($checkedAt) : 0;

	$masked = redact_live_url((string)$r['url_any']);
	$file = ts_filename($masked);
	if ($file === '') $file = '—';

	$chan = h((string)$r['tvg_name']);
	$tid  = h((string)$r['tvg_id']);
	$groupTitle = (string)$r['group_title'];
	$groupLink  = 'feeds.php?group=' . urlencode($groupTitle);
	$groupHtml  = '<a class="text-decoration-none" href="' . h($groupLink) . '">' . h($groupTitle) . '</a>';


	$channelHtml = '<a class="text-decoration-none fw-semibold" href="channel.php?id=' . (int)$r['channel_id'] . '">' . $chan . '</a>'
		. '<div class="text-muted small">' . $tid . '</div>';

	$data[] = [
		'status_badge' => $statusBadge,
		'qual_badge'   => $qualBadge,
		'group_html'   => $groupHtml,      // clickable
		'channel_html' => $channelHtml,
		'fps'          => $fpsDisp,
		'codec'        => $codec,
		'last_checked' => $checkedAt ? fmt_dt($checkedAt) : '—',
		'file'         => $file,
		'status_rank'  => $srank,
		'res_rank'     => $qrank,
		'checked_ts'   => $checkedTs,
	];
}

echo json_encode([
	'draw' => $draw,
	'recordsTotal' => $total,
	'recordsFiltered' => $filtered,
	'data' => $data
], JSON_UNESCAPED_SLASHES);
