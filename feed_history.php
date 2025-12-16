<?php

declare(strict_types=1);

$title = 'Feed Check History';
$currentPage = 'feeds';
require_once __DIR__ . '/_boot.php';

// require login authorization
require_auth();

require_once __DIR__ . '/_top.php';
$pdo = db();

$feedId = (int)q('feed_id', '0');
if ($feedId <= 0) {
	http_response_code(400);
	echo "Missing or invalid feed id";
	require_once __DIR__ . '/_bottom.php';
	exit;
}

// Get feed info
$st = $pdo->prepare("
	SELECT f.*, c.id AS channel_id, c.tvg_name, c.tvg_id, c.group_title, c.tvg_logo
	FROM feeds f
	LEFT JOIN channels c ON c.id = (
		SELECT cf.channel_id 
		FROM channel_feeds cf 
		WHERE cf.feed_id = f.id 
		LIMIT 1
	)
	WHERE f.id = :id
	LIMIT 1
");
$st->execute([':id' => $feedId]);
$feed = $st->fetch(PDO::FETCH_ASSOC);

if (!$feed) {
	http_response_code(404);
	echo "Feed not found";
	require_once __DIR__ . '/_bottom.php';
	exit;
}

// Get check history (last 30 days)
$days = (int)($_GET['days'] ?? 30);
$days = max(1, min($days, 365)); // limit between 1 and 365 days

$historySt = $pdo->prepare("
	SELECT 
		checked_at,
		ok,
		codec,
		w,
		h,
		fps,
		error,
		raw_json
	FROM feed_checks
	WHERE feed_id = :id
	  AND checked_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
	ORDER BY checked_at DESC
");
$historySt->execute([':id' => $feedId, ':days' => $days]);
$checks = $historySt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary statistics
$totalChecks = count($checks);
$okChecks = 0;
$failChecks = 0;
$resolutions = [];
$fpsList = [];
$codecs = [];

foreach ($checks as $check) {
	if ((int)$check['ok'] === 1) {
		$okChecks++;

		// Collect resolution data
		if ($check['w'] && $check['h']) {
			$resKey = $check['w'] . 'x' . $check['h'];
			$resolutions[$resKey] = ($resolutions[$resKey] ?? 0) + 1;
		}

		// Collect FPS data
		if ($check['fps'] !== null) {
			$fpsList[] = (float)$check['fps'];
		}

		// Collect codec data
		if ($check['codec']) {
			$codec = (string)$check['codec'];
			$codecs[$codec] = ($codecs[$codec] ?? 0) + 1;
		}
	} else {
		$failChecks++;
	}
}

$reliability = $totalChecks > 0 ? round(($okChecks / $totalChecks) * 100, 2) : 0;
$avgFps = count($fpsList) > 0 ? round(array_sum($fpsList) / count($fpsList), 2) : null;

// Most common resolution
arsort($resolutions);
$mostCommonRes = !empty($resolutions) ? array_key_first($resolutions) : null;

// Most common codec
arsort($codecs);
$mostCommonCodec = !empty($codecs) ? array_key_first($codecs) : null;

$displayName = (string)($feed['tvg_name'] ?? 'Unknown Feed');
$displayGroup = (string)($feed['group_title'] ?? '—');
$displayLogo = (string)($feed['tvg_logo'] ?? '');
$displayUrl = (string)($feed['url_display'] ?? $feed['url'] ?? '');
$tvgId = (string)($feed['tvg_id'] ?? '');

// Helper functions
if (!function_exists('status_badge')) {
	function status_badge($ok): string
	{
		if ($ok === null) return '<span class="badge bg-secondary">unknown</span>';
		return ((int)$ok === 1)
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
?>

<style>
	.stat-card {
		background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
		color: white;
		border-radius: 12px;
		padding: 1.5rem;
		margin-bottom: 1rem;
	}

	.stat-number {
		font-size: 2.5rem;
		font-weight: bold;
		line-height: 1;
		margin-bottom: 0.25rem;
	}

	.stat-label {
		opacity: 0.9;
		font-size: 0.875rem;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}

	.info-card {
		background: var(--bs-body-bg);
		border: 1px solid var(--bs-border-color);
		border-radius: 8px;
		padding: 1rem;
	}

	div#historyTable_wrapper {
		padding: 10pt;
	}

	.error-cell {
		max-width: 300px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	.breadcrumb {
		font-size: 10pt;
	}
</style>

<!-- Header with feed info -->
<div class="d-flex justify-content-between align-items-start mb-4">
	<div>
		<nav aria-label="breadcrumb" class="mb-2">
			<ol class="breadcrumb mb-0">
				<li class="breadcrumb-item"><a href="feeds.php">Feeds</a></li>
				<?php if ($tvgId && !empty($feed['channel_id'])): ?>
					<li class="breadcrumb-item"><a href="channel.php?id=<?= (int)$feed['channel_id'] ?>">Channel</a></li>
				<?php endif; ?>
				<li class="breadcrumb-item active">Check History</li>
			</ol>
		</nav>
		<div class="h2 mb-1"><?= h($displayName) ?></div>
		<div class="text-muted">
			<span class="me-3"><span class="text-muted">Group:</span> <?= h($displayGroup) ?></span>
			<?php if ($tvgId): ?>
				<span class="me-3"><span class="text-muted">tvg-id:</span> <?= h($tvgId) ?></span>
			<?php endif; ?>
			<span class="me-3"><span class="text-muted">Feed:</span>
				<?= h($displayUrl) ?>
			</span>
		</div>
	</div>

	<?php if ($displayLogo !== ''): ?>
		<div style="background-color: var(--logo-background); border-radius: 12px;">
			<img src="<?= h($displayLogo) ?>"
				alt=""
				style="height:70px;width:auto;object-fit:contain;border-radius:12px;padding:10px;"
				loading="lazy">
		</div>
	<?php endif; ?>
</div>

<!-- Summary Statistics -->
<div class="row g-3 mb-4">
	<div class="col-md-3">
		<div class="stat-card">
			<div class="stat-number"><?= number_format($totalChecks) ?></div>
			<div class="stat-label">Total Checks</div>
		</div>
	</div>
	<div class="col-md-3">
		<div class="stat-card" style="background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);">
			<div class="stat-number"><?= number_format($reliability, 1) ?>%</div>
			<div class="stat-label">Reliability</div>
		</div>
	</div>
	<div class="col-md-3">
		<div class="stat-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
			<div class="stat-number"><?= number_format($okChecks) ?></div>
			<div class="stat-label">Successful</div>
		</div>
	</div>
	<div class="col-md-3">
		<div class="stat-card" style="background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);">
			<div class="stat-number"><?= number_format($failChecks) ?></div>
			<div class="stat-label">Failed</div>
		</div>
	</div>
</div>

<!-- Additional Stats -->
<div class="row g-3 mb-4">
	<div class="col-md-4">
		<div class="info-card">
			<div class="fw-semibold mb-1">Most Common Resolution</div>
			<div class="h4 mb-0">
				<?php if ($mostCommonRes): ?>
					<?php
					// Parse the resolution to get class
					$resParts = explode('x', $mostCommonRes);
					$resW = isset($resParts[0]) ? (int)$resParts[0] : 0;
					$resH = isset($resParts[1]) ? (int)$resParts[1] : 0;
					[$resCls] = res_class($resW, $resH);
					?>
					<?= h($mostCommonRes) ?> <?= res_badge($resCls) ?>
				<?php else: ?>
					—
				<?php endif; ?>
			</div>
		</div>
	</div>
	<div class="col-md-4">
		<div class="info-card">
			<div class="fw-semibold mb-1">Average FPS</div>
			<div class="h4 mb-0"><?= $avgFps !== null ? number_format($avgFps, 2) : '—' ?></div>
		</div>
	</div>
	<div class="col-md-4">
		<div class="info-card">
			<div class="fw-semibold mb-1">Most Common Codec</div>
			<div class="h4 mb-0"><?= $mostCommonCodec ? h($mostCommonCodec) : '—' ?></div>
		</div>
	</div>
</div>

<!-- Time period selector -->
<div class="card shadow-sm mb-3">
	<div class="card-body">
		<div class="row align-items-center">
			<div class="col-md-6">
				<strong>Viewing last <?= $days ?> days</strong>
			</div>
			<div class="col-md-6 text-end">
				<div class="btn-group" role="group">
					<a href="?feed_id=<?= $feedId ?>&days=7" class="btn btn-sm <?= $days === 7 ? 'btn-primary' : 'btn-outline-secondary' ?>">7 days</a>
					<a href="?feed_id=<?= $feedId ?>&days=30" class="btn btn-sm <?= $days === 30 ? 'btn-primary' : 'btn-outline-secondary' ?>">30 days</a>
					<a href="?feed_id=<?= $feedId ?>&days=90" class="btn btn-sm <?= $days === 90 ? 'btn-primary' : 'btn-outline-secondary' ?>">90 days</a>
					<a href="?feed_id=<?= $feedId ?>&days=365" class="btn btn-sm <?= $days === 365 ? 'btn-primary' : 'btn-outline-secondary' ?>">1 year</a>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- Check History Table -->
<div class="card shadow-sm">
	<div class="card-header fw-semibold">Check History</div>
	<div class="table-responsive">
		<table id="historyTable" class="table table-striped table-hover mb-0 align-middle">
			<thead>
				<tr>
					<th>Timestamp</th>
					<th>Status</th>
					<th>Resolution</th>
					<th>Class</th>
					<th>FPS</th>
					<th>Codec</th>
					<th>Error</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($checks as $check): ?>
					<?php
					$w = $check['w'];
					$h = $check['h'];
					$res = ($w && $h) ? ($w . '×' . $h) : '—';

					[$cls, $clsPts, $px] = res_class($w, $h);

					$fps = $check['fps'] !== null ? number_format((float)$check['fps'], 2) : '—';
					$codec = $check['codec'] ? h((string)$check['codec']) : '—';
					$error = $check['error'] ? h((string)$check['error']) : 'None';
					$timestamp = $check['checked_at'] ? (string)$check['checked_at'] : null;
					?>
					<tr>
						<td><?= fmt_dt($timestamp) ?></td>
						<td><?= status_badge($check['ok']) ?></td>
						<td><?= h($res) ?></td>
						<td><?= res_badge($cls) ?></td>
						<td><?= h($fps) ?></td>
						<td><?= $codec ?></td>
						<td class="error-cell text-muted" title="<?= $error ?>"><?= $error ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<script>
	$(function() {
		$('#historyTable').DataTable({
			pageLength: 50,
			order: [
				[0, 'desc']
			],
			lengthMenu: [
				[25, 50, 100, 250],
				[25, 50, 100, 250]
			]
		});
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>