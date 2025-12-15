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
function res_class(?int $w, ?int $h): string
{
	if (!$w || !$h) return 'Unknown';
	$px = $w * $h;
	if ($h >= 2160 || $px >= 3840 * 2160) return '4K';
	if ($h >= 1080 || $px >= 1920 * 1080) return 'FHD';
	if ($h >= 720  || $px >= 1280 * 720)  return 'HD';
	return 'SD';
}
function class_rank(string $c): int
{
	return match ($c) {
		'4K' => 4,
		'FHD' => 3,
		'HD' => 2,
		'SD' => 1,
		default => 0
	};
}
function status_rank($ok): int
{
	if ($ok === null) return 0;
	return ((int)$ok === 1) ? 2 : 1;
}

// === SCHEMA DETECTION ===
$hasJunctionTable = false;
try {
	$pdo->query("SELECT 1 FROM channel_feeds LIMIT 1");
	$hasJunctionTable = true;
} catch (Throwable $e) {
	$hasJunctionTable = false;
}

// DataTables base params
$draw   = clamp_int($_GET['draw'] ?? 1, 1, 1000000000, 1);
$start  = clamp_int($_GET['start'] ?? 0, 0, 1000000000, 0);
$length = clamp_int($_GET['length'] ?? 50, 1, 250, 50);

// Filters
$filters = $_GET['filters'] ?? [];
if (!is_array($filters)) $filters = [];

$q = cap_str($filters['q'] ?? '', 120);
$group = cap_str($filters['group'] ?? '', 160);

// Quick filter mode
$quick = cap_str($_GET['quick'] ?? '', 40);

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

// Quick filter conditions
switch ($quick) {
	case 'dead':
		$where[] = "f.last_ok = 0";
		break;
	case 'unknown':
		$where[] = "f.last_checked_at IS NULL";
		break;
	case 'recent':
		$where[] = "f.last_checked_at >= (NOW() - INTERVAL 24 HOUR)";
		break;
	case 'top':
		$where[] = "f.last_ok = 1";
		$where[] = "COALESCE(f.reliability_score,0) >= 95";
		break;
	case 'unstable':
		$where[] = "f.last_ok = 1";
		$where[] = "COALESCE(f.reliability_score,0) BETWEEN 50 AND 94.99";
		break;
	default:
		break;
}

$wsql = $where ? ("WHERE " . implode(" AND ", $where)) : "";


// ORDER BY mapping
$orderCol = (int)(($_GET['order'][0]['column'] ?? 12)); // default checked_ts hidden
$orderDir = (string)(($_GET['order'][0]['dir'] ?? 'desc'));
$orderDir = strtolower($orderDir) === 'asc' ? 'ASC' : 'DESC';

// column indexes in reports.php
$colMap = [
	0  => "c.group_title",
	1  => "c.tvg_name",
	2  => "c.tvg_id",
	3  => "status_rank", // computed below
	4  => "class_rank",  // computed below
	5  => "COALESCE(f.last_h,0)",
	6  => "COALESCE(f.last_fps,0)",
	7  => "COALESCE(f.last_codec,'')",
	8  => "COALESCE(f.last_checked_at,'1970-01-01')",
	10 => "status_rank",
	11 => "class_rank",
	12 => "COALESCE(f.last_checked_at,'1970-01-01')",
];

$orderExpr = $colMap[$orderCol] ?? "COALESCE(f.last_checked_at,'1970-01-01')";

// === BUILD JOINS BASED ON SCHEMA ===
if ($hasJunctionTable) {
	// New schema: feeds <-> channel_feeds <-> channels
	$fromJoin = "FROM feeds f
		JOIN channel_feeds cf ON cf.feed_id = f.id
		JOIN channels c ON c.id = cf.channel_id";
} else {
	// Old schema: feeds.channel_id -> channels.id
	$fromJoin = "FROM feeds f
		JOIN channels c ON c.id = f.channel_id";
}

// counts
$total = (int)$pdo->query("SELECT COUNT(*) FROM feeds")->fetchColumn();

$st = $pdo->prepare("
  SELECT COUNT(*)
  {$fromJoin}
  {$wsql}
");
$st->execute($params);
$filtered = (int)$st->fetchColumn();

// main query
$sql = "
  SELECT
    c.id AS channel_id,
    f.last_ok,
    f.last_checked_at,
    f.last_w,
    f.last_h,
    f.last_fps,
    f.last_codec,
    COALESCE(f.url_display, f.url) AS url_any,
    c.group_title,
    c.tvg_name,
    c.tvg_id,

    CASE
      WHEN f.last_ok = 1 THEN 2
      WHEN f.last_ok = 0 THEN 1
      ELSE 0
    END AS status_rank,

    CASE
      WHEN (COALESCE(f.last_h,0) >= 2160 OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (3840 * 2160) . ") THEN 4
      WHEN (COALESCE(f.last_h,0) >= 1080 OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (1920 * 1080) . ") THEN 3
      WHEN (COALESCE(f.last_h,0) >= 720  OR (COALESCE(f.last_w,0)*COALESCE(f.last_h,0)) >= " . (1280 * 720) . ") THEN 2
      WHEN (COALESCE(f.last_h,0) > 0 OR COALESCE(f.last_w,0) > 0) THEN 1
      ELSE 0
    END AS class_rank

  {$fromJoin}
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
	$groupTitle = (string)$r['group_title'];
	$groupLink = 'feeds.php?group=' . urlencode($groupTitle);
	$groupHtml = '<a class="text-decoration-none" href="' . h($groupLink) . '">' . h($groupTitle) . '</a>';

	$channelId = (int)$r['channel_id'];
	$chan = h((string)$r['tvg_name']);
	$chanLink = 'channel.php?id=' . $channelId;
	$channelHtml = '<a class="fw-semibold text-decoration-none" href="' . h($chanLink) . '">' . $chan . '</a>';

	$tvgId = (string)$r['tvg_id'];

	$ok = $r['last_ok'];
	$sr = (int)$r['status_rank'];
	$statusBadge = ($ok === null)
		? '<span class="badge bg-secondary">UNK</span>'
		: (((int)$ok === 1)
			? '<span class="badge bg-success">OK</span>'
			: '<span class="badge bg-danger">FAIL</span>');

	$w = $r['last_w'] ? (int)$r['last_w'] : null;
	$h = $r['last_h'] ? (int)$r['last_h'] : null;

	$class = res_class($w, $h);
	$cr = (int)$r['class_rank'];

	$classBadge = match ($class) {
		'4K'  => '<span class="badge bg-warning text-dark">4K</span>',
		'FHD' => '<span class="badge bg-primary">FHD</span>',
		'HD'  => '<span class="badge bg-info text-dark">HD</span>',
		'SD'  => '<span class="badge bg-secondary">SD</span>',
		default => '<span class="badge bg-light text-dark">—</span>',
	};

	$resDisp = ($w && $h) ? "{$w}×{$h}" : '—';

	$fpsDisp = ($r['last_fps'] !== null) ? number_format((float)$r['last_fps'], 1) : '—';
	$codec = $r['last_codec'] ? (string)$r['last_codec'] : '—';

	$checkedAt = $r['last_checked_at'] ? (string)$r['last_checked_at'] : null;
	$checkedTs = $checkedAt ? strtotime($checkedAt) : 0;

	$masked = redact_live_url((string)$r['url_any']);
	$file = ts_filename($masked);
	if ($file === '') $file = '—';

	$data[] = [
		'group_html' => $groupHtml,
		'channel_html' => $channelHtml,
		'tvg_id' => $tvgId,
		'status_badge' => $statusBadge,
		'class_badge' => $classBadge,
		'res' => $resDisp,
		'fps' => $fpsDisp,
		'codec' => $codec,
		'last_checked' => $checkedAt ? fmt_dt($checkedAt) : '—',
		'file' => $file,

		'status_rank' => $sr,
		'class_rank' => $cr,
		'checked_ts' => $checkedTs,
	];
}

echo json_encode([
	'draw' => $draw,
	'recordsTotal' => $total,
	'recordsFiltered' => $filtered,
	'data' => $data
], JSON_UNESCAPED_SLASHES);
