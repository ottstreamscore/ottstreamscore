<?php

declare(strict_types=1);

$title = 'Channel';
require_once __DIR__ . '/_top.php';

$pdo = db();

$channelId = (int)q('id', '0');
if ($channelId <= 0) {
	http_response_code(400);
	echo "Missing or invalid channel id";
	require_once __DIR__ . '/_bottom.php';
	exit;
}

// clicked channel (for header)
$st = $pdo->prepare("SELECT * FROM channels WHERE id = :id LIMIT 1");
$st->execute([':id' => $channelId]);
$clicked = $st->fetch(PDO::FETCH_ASSOC);

if (!$clicked) {
	http_response_code(404);
	echo "Channel not found";
	require_once __DIR__ . '/_bottom.php';
	exit;
}

$tvgId = (string)($clicked['tvg_id'] ?? '');
if ($tvgId === '') {
	http_response_code(400);
	echo "This channel row has no tvg-id. (Cannot group duplicates.)";
	require_once __DIR__ . '/_bottom.php';
	exit;
}

// Pull ALL channels that share this tvg-id + their feeds
$st = $pdo->prepare("
  SELECT
    c.id AS channel_id,
    c.group_title,
    c.tvg_name,
    c.tvg_logo,
    c.tvg_id,

    f.id AS feed_id,
    f.last_ok,
    f.reliability_score,
    f.last_w,
    f.last_h,
    f.last_fps,
    f.last_codec,
    f.last_checked_at,
    COALESCE(f.url_display, f.url) AS url_any,

    (COALESCE(f.last_w,0) * COALESCE(f.last_h,0)) AS pixels
  FROM channels c
  JOIN feeds f ON f.channel_id = c.id
  WHERE c.tvg_id = :tvg
  ORDER BY
    COALESCE(f.reliability_score,0) DESC,
    pixels DESC,
    COALESCE(f.last_fps,0) DESC,
    f.last_ok DESC,
    f.last_checked_at DESC
");
$st->execute([':tvg' => $tvgId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ---------- helpers (guard against redeclare) ----------
if (!function_exists('status_badge')) {
	function status_badge($lastOk): string
	{
		if ($lastOk === null) return '<span class="badge bg-secondary">unknown</span>';
		return ((int)$lastOk === 1)
			? '<span class="badge bg-success">ok</span>'
			: '<span class="badge bg-danger">fail</span>';
	}
}

if (!function_exists('res_class')) {
	/**
	 * Return [label, points, pixels]
	 */
	function res_class(?int $w, ?int $h): array
	{
		$w = $w ?: 0;
		$h = $h ?: 0;
		$pixels = $w * $h;

		if ($w <= 0 || $h <= 0) return ['Unknown', 40, 0];

		// primarily by height
		if ($h >= 2160 || $w >= 3840) return ['4K', 100, $pixels];
		if ($h >= 1080) return ['FHD', 85, $pixels];
		if ($h >= 720)  return ['HD', 70, $pixels];
		return ['SD', 50, $pixels];
	}
}


if (!function_exists('res_badge')) {
	function res_badge(string $cls): string
	{
		$clsU = strtoupper($cls);

		$map = [
			'4K'      => 'bg-warning text-dark',
			'FHD'     => 'bg-primary',
			'HD'      => 'bg-info text-dark',
			'SD'      => 'bg-secondary',
			'UNKNOWN' => 'bg-light text-dark',
		];
		$key = $clsU;
		$badgeClass = $map[$key] ?? $map['UNKNOWN'];
		return '<span class="badge ' . $badgeClass . '">' . h($clsU) . '</span>';
	}
}

if (!function_exists('rank_score')) {
	/**
	 * Human score 0–100 based on preference:
	 * Reliability (60%), Resolution class (25%), FPS (15%)
	 * Penalize failed last check slightly.
	 */
	function rank_score($lastOk, $rel, ?int $w, ?int $h, $fps): float
	{
		$relN = ($rel !== null) ? (float)$rel : 0.0;

		[$cls, $resPts] = res_class($w, $h);

		$fpsN = ($fps !== null) ? (float)$fps : 0.0;
		$fpsPts = $fpsN <= 0 ? 0.0 : min(100.0, ($fpsN / 30.0) * 100.0);

		$score = ($relN * 0.60) + ($resPts * 0.25) + ($fpsPts * 0.15);

		if ($lastOk !== null && (int)$lastOk === 0) {
			$score -= 15.0;
		}

		if ($score < 0) $score = 0;
		if ($score > 100) $score = 100;

		return round($score, 1);
	}
}

if (!function_exists('ts_filename')) {
	function ts_filename(?string $url): string
	{
		$url = (string)$url;
		$path = parse_url($url, PHP_URL_PATH);
		$path = is_string($path) ? $path : '';
		$base = $path !== '' ? basename($path) : '';
		if ($base === '' && $url !== '') $base = basename($url);
		return $base !== '' ? $base : '—';
	}
}

$displayName = (string)($clicked['tvg_name'] ?? 'Unknown');
$displayLogo = (string)($clicked['tvg_logo'] ?? '');

$best = $rows[0] ?? null;
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.10/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/js/dataTables.bootstrap5.min.js"></script>

<style>
	div#tvgTable_wrapper {
		padding: 10pt;
	}

	div#logo_holder {
		background: #a0a0a0;
		border-radius: 12px;
	}
</style>

<div class="d-flex justify-content-between align-items-start mb-3">
	<div>
		<div class="h2 mb-1"><?= h($displayName) ?></div>
		<div class="text-muted">
			<span class="me-3"><span class="text-muted">tvg-id:</span> <?= h($tvgId) ?></span>
			<span class="me-3"><span class="text-muted">Instances:</span> <?= number_format(count($rows)) ?> feeds</span>
		</div>
	</div>

	<?php if ($displayLogo !== ''): ?>
		<div id="logo_holder">
			<img src="<?= h($displayLogo) ?>"
				alt=""
				style="height:54px;max-width:180px;object-fit:contain;border-radius:12px;padding:10px;"
				loading="lazy">
		</div>
	<?php endif; ?>
</div>

<?php if ($best): ?>
	<?php
	$bestW = $best['last_w'] !== null ? (int)$best['last_w'] : null;
	$bestH = $best['last_h'] !== null ? (int)$best['last_h'] : null;

	[$bestCls] = res_class($bestW, $bestH);
	$bestRes = ($bestW && $bestH) ? ($bestW . '×' . $bestH) : '—';

	$bestFps = ($best['last_fps'] !== null) ? number_format((float)$best['last_fps'], 2) : '—';
	$bestRel = ($best['reliability_score'] !== null) ? number_format((float)$best['reliability_score'], 2) . '%' : '—';

	$bestScore = rank_score($best['last_ok'], $best['reliability_score'], $bestW, $bestH, $best['last_fps']);
	?>
	<div class="alert alert-secondary border-0 shadow-sm">
		<div class="fw-semibold mb-1 text">Current best feed (Reliability → Resolution → FPS)</div>
		<div class="text">
			<?= h((string)$best['group_title']) ?> • <?= h((string)$best['tvg_name']) ?>
			• Score <?= h((string)$bestScore) ?>/100
			• Rel <?= h($bestRel) ?>
			• <?= res_badge($bestCls) ?> <span class="ms-1">(<?= h($bestRes) ?>)</span>
			• FPS <?= h($bestFps) ?>
		</div>
	</div>
<?php endif; ?>

<div class="card shadow-sm">
	<div class="card-header fw-semibold">All instances for this tvg-id</div>
	<div class="table-responsive">
		<table id="tvgTable" class="table table-striped table-hover mb-0 align-middle">
			<thead>
				<tr>
					<th>Group</th>
					<th>Channel</th>
					<th>Status</th>
					<th class="text-end">Rel %</th>
					<th class="text-end">Res</th>
					<th class="text-end">Class</th>
					<th class="text-end">FPS</th>
					<th>Codec</th>
					<th class="text-end">Score</th>
					<th>Checked</th>
					<th>File</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $r): ?>
					<?php
					$w = $r['last_w'] !== null ? (int)$r['last_w'] : null;
					$h = $r['last_h'] !== null ? (int)$r['last_h'] : null;

					[$cls, $clsPts, $px] = res_class($w, $h);

					$res = ($w && $h) ? ($w . '×' . $h) : '—';
					$fps = ($r['last_fps'] !== null) ? (float)$r['last_fps'] : null;
					$fpsTxt = ($fps !== null) ? number_format($fps, 2) : '—';

					$rel = ($r['reliability_score'] !== null) ? (float)$r['reliability_score'] : null;
					$relTxt = ($rel !== null) ? number_format($rel, 2) . '%' : '—';

					$codec = $r['last_codec'] ? (string)$r['last_codec'] : '—';
					$file = ts_filename((string)$r['url_any']);

					$score = rank_score($r['last_ok'], $rel, $w, $h, $fps);

					$isBest = ($best && (int)$r['feed_id'] === (int)$best['feed_id']);

					$groupTitle = (string)$r['group_title'];
					$groupLink  = 'feeds.php?group=' . urlencode($groupTitle);
					?>
					<tr class="<?= $isBest ? 'table-success' : '' ?>">
						<td>
							<a class="text-decoration-none" href="<?= h($groupLink) ?>"><?= h($groupTitle) ?></a>
						</td>
						<td><?= h((string)$r['tvg_name']) ?></td>
						<td><?= status_badge($r['last_ok']) ?></td>

						<td class="text-end" data-order="<?= $rel !== null ? $rel : 0 ?>"><?= h($relTxt) ?></td>

						<td class="text-end" data-order="<?= $px ?>"><?= h($res) ?></td>

						<td class="text-end" data-order="<?= $clsPts ?>">
							<?= res_badge($cls) ?>
						</td>

						<td class="text-end" data-order="<?= $fps !== null ? $fps : 0 ?>"><?= h($fpsTxt) ?></td>
						<td><?= h($codec) ?></td>

						<td class="text-end" data-order="<?= $score ?>">
							<span class="fw-semibold"><?= h((string)$score) ?></span><span class="text-muted">/100</span>
						</td>

						<td><?= fmt_dt($r['last_checked_at'] ? (string)$r['last_checked_at'] : null) ?></td>
						<td class="text-muted small"><?= h($file) ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<script>
	$(function() {
		$('#tvgTable').DataTable({
			pageLength: 50,
			order: [
				[8, 'desc'],
				[3, 'desc'],
				[5, 'desc'],
				[6, 'desc']
			]
		});
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>