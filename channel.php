<?php

declare(strict_types=1);

$title = 'Channel';
$currentPage = 'channels';
require_once __DIR__ . '/_boot.php';

// require login authorization
require_auth();

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

// Check EPG configuration
$epgUrl = get_setting('epg_url', '');
$epgLastSync = get_setting('epg_last_sync_date', '');
$epgConfigured = !empty($epgUrl);
$epgSynced = !empty($epgLastSync) && $epgLastSync !== 'failure';
$epgFailed = $epgLastSync === 'failure';

// Helper function to get score badge class
function score_badge_class($score): string
{
	$score = (int)$score; // Convert to int safely
	if ($score >= 75) {
		return 'bg-success';
	} elseif ($score >= 50) {
		return 'bg-warning';
	} else {
		return 'bg-danger';
	}
}

// === SCHEMA DETECTION ===
$hasJunctionTable = false;
try {
	$pdo->query("SELECT 1 FROM channel_feeds LIMIT 1");
	$hasJunctionTable = true;
} catch (Throwable $e) {
	$hasJunctionTable = false;
}

// Pull ALL channels that share this tvg-id + their feeds (PRIMARY MATCHES)
if ($hasJunctionTable) {

	// New schema: use junction table
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
	  JOIN channel_feeds cf ON cf.channel_id = c.id
	  JOIN feeds f ON f.id = cf.feed_id
	  WHERE c.tvg_id = :tvg
	  ORDER BY
	    COALESCE(f.reliability_score,0) DESC,
	    pixels DESC,
	    COALESCE(f.last_fps,0) DESC,
	    f.last_ok DESC,
	    f.last_checked_at DESC
	");
} else {

	// Old schema: direct join
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
}

$st->execute([':tvg' => $tvgId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// === ASSOCIATION MATCHES ===
$associationMatches = [];

// Get the clicked channel's prefix
$clickedPrefix = '';
$groupTitle = (string)($clicked['group_title'] ?? '');
if (strpos($groupTitle, '|') !== false) {
	$clickedPrefix = substr($groupTitle, 0, strpos($groupTitle, '|') + 1);
}

// Extract base from tvg-id for similarity matching
$tvgIdBase = $tvgId;
if (strpos($tvgId, '.') !== false) {
	$tvgIdBase = substr($tvgId, 0, strpos($tvgId, '.'));
}

if ($clickedPrefix !== '' && $tvgIdBase !== '') {
	// Find associations containing this prefix
	$stAssoc = $pdo->prepare("
		SELECT DISTINCT ga.id, ga.name
		FROM group_associations ga
		JOIN group_association_prefixes gap ON gap.association_id = ga.id
		WHERE gap.prefix = ?
	");
	$stAssoc->execute([$clickedPrefix]);
	$associations = $stAssoc->fetchAll(PDO::FETCH_ASSOC);

	foreach ($associations as $assoc) {

		// Get OTHER prefixes from this association (exclude current prefix)
		$stPrefixes = $pdo->prepare("
			SELECT prefix
			FROM group_association_prefixes
			WHERE association_id = ? AND prefix != ?
		");
		$stPrefixes->execute([$assoc['id'], $clickedPrefix]);
		$otherPrefixes = $stPrefixes->fetchAll(PDO::FETCH_COLUMN);

		if (empty($otherPrefixes)) {
			continue;
		}

		// Build WHERE clause for prefixes
		$prefixPlaceholders = implode(',', array_fill(0, count($otherPrefixes), '?'));

		// Build params: prefixes + bidirectional matching + exclude clicked tvg-id
		$params = $otherPrefixes;
		$params[] = '%' . $tvgIdBase . '%'; // Search for tvg-ids containing our base
		$params[] = $tvgId; // Our full tvg-id for reverse matching
		$params[] = $tvgId; // Exclude exact match

		// Query for association matches with bidirectional matching:
		if ($hasJunctionTable) {
			$sql = "
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
				JOIN channel_feeds cf ON cf.channel_id = c.id
				JOIN feeds f ON f.id = cf.feed_id
				WHERE CONCAT(SUBSTRING_INDEX(c.group_title, '|', 1), '|') IN ($prefixPlaceholders)
				AND (
					c.tvg_id LIKE ?
					OR ? LIKE CONCAT('%', SUBSTRING_INDEX(c.tvg_id, '.', 1), '%')
				)
				AND c.tvg_id != ?
				ORDER BY
					COALESCE(f.reliability_score,0) DESC,
					pixels DESC,
					COALESCE(f.last_fps,0) DESC,
					f.last_ok DESC,
					f.last_checked_at DESC
			";
		} else {
			$sql = "
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
				WHERE CONCAT(SUBSTRING_INDEX(c.group_title, '|', 1), '|') IN ($prefixPlaceholders)
				AND (
					c.tvg_id LIKE ?
					OR ? LIKE CONCAT('%', SUBSTRING_INDEX(c.tvg_id, '.', 1), '%')
				)
				AND c.tvg_id != ?
				ORDER BY
					COALESCE(f.reliability_score,0) DESC,
					pixels DESC,
					COALESCE(f.last_fps,0) DESC,
					f.last_ok DESC,
					f.last_checked_at DESC
			";
		}

		$stMatch = $pdo->prepare($sql);
		$stMatch->execute($params);
		$matches = $stMatch->fetchAll(PDO::FETCH_ASSOC);

		if (!empty($matches)) {
			$associationMatches[] = [
				'association_name' => $assoc['name'],
				'matches' => $matches
			];
		}
	}
}

// Count total association matches
$totalAssocMatches = 0;
foreach ($associationMatches as $am) {
	$totalAssocMatches += count($am['matches']);
}

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


<div class="d-flex justify-content-between align-items-start mb-3">
	<div>
		<div class="h2 mb-1"><?= h($displayName) ?></div>
		<div class="text-muted">
			<span class="me-3">
				<i class="fa-solid fa-fingerprint me-1"></i>
				<span class="text-muted">tvg-id:</span> <?= h($tvgId) ?>
			</span>
			<span class="me-3">
				<i class="fa-solid fa-layer-group me-1"></i>
				<span class="text-muted">Instances:</span> <?= number_format(count($rows)) ?> feeds
			</span>
		</div>
	</div>

	<?php if ($displayLogo !== ''): ?>
		<div id="logo_holder">
			<img src="<?= h($displayLogo) ?>"
				alt=""
				style="height:70px;width:auto;object-fit:contain;border-radius:12px;padding:10px;"
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
	$bestRel = ($best['reliability_score'] !== null) ? number_format((float)$best['reliability_score'], 2) : '—';
	$bestCodec = $best['last_codec'] ? (string)$best['last_codec'] : '—';
	$bestFile = ts_filename((string)$best['url_any']);

	$bestScore = rank_score($best['last_ok'], $best['reliability_score'], $bestW, $bestH, $best['last_fps']);
	?>
	<div class="best-feed-card shadow-sm mb-4">
		<div class="best-feed-top-bar">
			<div class="best-feed-top-left">
				<div class="best-feed-header">
					<i class="fa-solid text-warning fa-crown"></i>
					<span>Current Best Feed</span>
				</div>
				<div class="score-inline <?= score_badge_class($bestScore) ?>">
					<span class="score-value-inline"><?= h((string)$bestScore) ?></span>
					<span class="score-max-inline">/100</span>
				</div>
			</div>
			<button type="button" class="btn btn-outline-success btn-preview" data-feed-id="<?= (int)$best['feed_id'] ?>">
				<i class="fa-solid fa-play me-2"></i> Preview Feed
			</button>
		</div>

		<div class="best-feed-meta-inline">
			<div class="meta-inline-item">
				<i class="fa-solid fa-layer-group"></i>
				<span class="meta-inline-label">Group:</span>
				<span class="meta-inline-value"><?= h((string)$best['group_title']) ?></span>
			</div>
			<div class="meta-inline-item">
				<i class="fa-solid fa-tv"></i>
				<span class="meta-inline-label">Channel:</span>
				<span class="meta-inline-value"><?= h((string)$best['tvg_name']) ?></span>
			</div>
			<div class="meta-inline-item">
				<i class="fa-solid fa-fingerprint"></i>
				<span class="meta-inline-label">tvg-id:</span>
				<span class="meta-inline-value"><?= h((string)$best['tvg_id']) ?></span>
			</div>
			<div class="meta-inline-item">
				<i class="fa-solid fa-file"></i>
				<span class="meta-inline-label">File:</span>
				<span class="meta-inline-value"><?= h($bestFile) ?></span>
			</div>
		</div>

		<div class="best-feed-stream-inline">
			<div class="stream-inline-item">
				<i class="fa-solid fa-chart-simple"></i>
				<span class="stream-inline-label">Rel:</span>
				<span class="stream-inline-value"><?= h($bestRel) ?>%</span>
			</div>
			<div class="stream-inline-item">
				<span class="stream-inline-label">Res:</span>
				<span class="stream-inline-value">
					<?= res_badge($bestCls) ?> <span class="text-muted ms-1"><?= h($bestRes) ?></span>
				</span>
			</div>
			<div class="stream-inline-item">
				<span class="stream-inline-label">FPS:</span>
				<span class="stream-inline-value"><?= h($bestFps) ?></span>
			</div>
			<div class="stream-inline-item">
				<span class="stream-inline-label">Codec:</span>
				<span class="stream-inline-value"><?= h($bestCodec) ?></span>
			</div>
		</div>
	</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
	<li class="nav-item" role="presentation">
		<button class="nav-link active" id="primary-tab" data-bs-toggle="tab" data-bs-target="#primary-feeds"
			type="button" role="tab" aria-controls="primary-feeds" aria-selected="true">
			<i class="fa-solid fa-check-double me-1"></i> Primary Feeds
			<span class="badge bg-primary badge-count"><?= count($rows) ?></span>
		</button>
	</li>
	<li class="nav-item" role="presentation">
		<button class="nav-link <?= $totalAssocMatches === 0 ? 'disabled' : '' ?>"
			id="association-tab" data-bs-toggle="tab" data-bs-target="#association-feeds"
			type="button" role="tab" aria-controls="association-feeds" aria-selected="false"
			<?= $totalAssocMatches === 0 ? 'disabled' : '' ?>>
			<i class="fa-solid fa-diagram-project me-1"></i> Association Matches
			<span class="badge <?= $totalAssocMatches === 0 ? 'bg-secondary' : 'bg-primary' ?> badge-count"><?= $totalAssocMatches ?></span>
		</button>
	</li>
</ul>

<!-- Tab Content -->
<div class="tab-content">
	<!-- Primary Feeds Tab -->
	<div class="tab-pane fade show active" id="primary-feeds" role="tabpanel" aria-labelledby="primary-tab">
		<div class="d-flex gap-3">
			<!-- Sidebar -->
			<div class="feed-sidebar" style="min-width: 200px; max-width: 200px;">
				<div class="card shadow-sm">
					<div class="card-body">
						<h6 class="fw-semibold mb-3"><i class="fa-solid fa-filter me-1"></i> Sort & Filter</h6>

						<div class="mb-3">
							<label class="form-label small fw-semibold">Search</label>
							<input type="text" class="form-control form-control-sm" id="primary-search" placeholder="Filter feeds...">
							<small class="text-muted">Group, Channel, or File</small>
						</div>

						<div class="mb-3">
							<label class="form-label small fw-semibold">Sort By</label>
							<select class="form-select form-select-sm" id="primary-sort">
								<option value="score" selected>Best Feed First</option>
								<option value="group">Group</option>
								<option value="channel">Channel Name</option>
								<option value="status">Status</option>
								<option value="reliability">Reliability</option>
								<option value="resolution">Resolution</option>
								<option value="class">Class</option>
								<option value="fps">FPS</option>
								<option value="codec">Codec</option>
								<option value="checked">Last Checked</option>
							</select>
						</div>

						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" id="primary-show-epg">
							<label class="form-check-label small" for="primary-show-epg">
								Show EPG Info
							</label>
						</div>
					</div>
				</div>
			</div>

			<!-- Feed Results -->
			<div class="feed-results flex-grow-1">
				<div id="primary-feeds-container">
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
						$feedTvgId = (string)$r['tvg_id'];
						?>

						<div class="card shadow-sm mb-3 feed-card <?= $isBest ? 'border-success' : '' ?>"
							data-score="<?= $score ?>"
							data-group="<?= h($groupTitle) ?>"
							data-channel="<?= h((string)$r['tvg_name']) ?>"
							data-file="<?= h($file) ?>"
							data-status="<?= $r['last_ok'] ?>"
							data-reliability="<?= $rel ?? 0 ?>"
							data-resolution="<?= $px ?>"
							data-class="<?= $clsPts ?>"
							data-fps="<?= $fps ?? 0 ?>"
							data-codec="<?= h($codec) ?>"
							data-checked="<?= $r['last_checked_at'] ? strtotime($r['last_checked_at']) : 0 ?>"
							data-feed-id="<?= (int)$r['feed_id'] ?>"
							data-tvg-id="<?= h($feedTvgId) ?>">

							<div class="card-body">
								<?php if ($isBest): ?>
									<!-- Best Feed: Badge left, Score right -->
									<div class="d-flex justify-content-between align-items-start mb-2">
										<div class="badge bg-success">
											<i class="fa-solid fa-star me-1"></i> Best Feed
										</div>
										<div class="score-inline <?= score_badge_class($score) ?>">
											<span class="score-value-inline"><?= h((string)$score) ?></span>
											<span class="score-max-inline">/100</span>
										</div>
									</div>
								<?php else: ?>
									<!-- Non-Best Feed: Score absolutely positioned -->
									<div class="score-inline <?= score_badge_class($score) ?>" style="position: absolute; top: 1rem; right: 1rem;">
										<span class="score-value-inline"><?= h((string)$score) ?></span>
										<span class="score-max-inline">/100</span>
									</div>
								<?php endif; ?>

								<!-- Group and Channel on same line -->
								<div class="best-feed-meta-inline mb-2">
									<div class="meta-inline-item">
										<i class="fa-solid fa-layer-group"></i>
										<span class="meta-inline-label">Group:</span>
										<span class="meta-inline-value">
											<a class="text-decoration-none" href="<?= h($groupLink) ?>"><?= h($groupTitle) ?></a>
										</span>
									</div>
									<div class="meta-inline-item">
										<i class="fa-solid fa-tv"></i>
										<span class="meta-inline-label">Channel:</span>
										<span class="meta-inline-value"><?= h((string)$r['tvg_name']) ?></span>
									</div>
								</div>

								<!-- tvg-id and File on second line -->
								<div class="best-feed-meta-inline mb-3">
									<div class="meta-inline-item">
										<i class="fa-solid fa-fingerprint"></i>
										<span class="meta-inline-label">tvg-id:</span>
										<span class="meta-inline-value"><?= h($feedTvgId) ?></span>
									</div>
									<div class="meta-inline-item">
										<i class="fa-solid fa-file"></i>
										<span class="meta-inline-label">File:</span>
										<span class="meta-inline-value"><?= h($file) ?></span>
									</div>
								</div>

								<!-- Stream metrics with extra space above -->
								<div class="best-feed-stream-inline mb-3">
									<div class="stream-inline-item">
										<i class="fa-solid fa-chart-simple"></i>
										<span class="stream-inline-label">Rel:</span>
										<span class="stream-inline-value"><?= h($relTxt) ?></span>
									</div>
									<div class="stream-inline-item">
										<span class="stream-inline-label">Res:</span>
										<span class="stream-inline-value">
											<?= res_badge($cls) ?> <span class="text-muted ms-1"><?= h($res) ?></span>
										</span>
									</div>
									<div class="stream-inline-item">
										<span class="stream-inline-label">FPS:</span>
										<span class="stream-inline-value"><?= h($fpsTxt) ?></span>
									</div>
									<div class="stream-inline-item">
										<span class="stream-inline-label">Codec:</span>
										<span class="stream-inline-value"><?= h($codec) ?></span>
									</div>
								</div>

								<!-- Bottom section: Last checked & Status left, Actions right -->
								<div class="d-flex justify-content-between align-items-center">
									<div class="text-muted small">
										<span class="me-3">
											<i class="fa-solid fa-clock me-1"></i>
											<span>Last Checked:</span> <?= fmt_dt($r['last_checked_at'] ? (string)$r['last_checked_at'] : null) ?>
										</span>
										<span>
											<span>Status:</span> <?= status_badge($r['last_ok']) ?>
										</span>
									</div>
									<div class="btn-group">
										<a href="feed_history.php?feed_id=<?= (int)$r['feed_id'] ?>"
											class="btn btn-sm btn-outline-primary me-2"
											title="View check history">
											<i class="fa-solid fa-clock-rotate-left"></i> History
										</a>
										<button type="button" class="btn btn-outline-success btn-sm btn-preview me-2"
											data-feed-id="<?= (int)$r['feed_id'] ?>">
											<i class="fa-solid fa-play"></i> Preview
										</button>
										<button type="button"
											class="btn btn-sm btn-outline-info btn-create-task"
											data-feed-id="<?= (int)$r['feed_id'] ?>"
											data-group="<?= h($r['group_title']) ?>"
											data-ref-tvg-id="<?= h($tvgId) ?>"
											data-ref-group="<?= h($clicked['group_title']) ?>"
											data-suggested-feed-id=""
											title="Create task">
											<i class="fa-solid fa-list-check"></i> Add Task
										</button>
									</div>
								</div>

								<!-- EPG Section (hidden by default) -->
								<div class="epg-section mt-3 pt-3 border-top" style="display: none;">
									<div class="epg-loading text-center py-2">
										<div class="spinner-border spinner-border-sm me-2" role="status"></div>
										Loading EPG data...
									</div>
									<div class="epg-content" style="display: none;"></div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- Association Matches Tab -->
	<div class="tab-pane fade" id="association-feeds" role="tabpanel" aria-labelledby="association-tab">
		<?php if (empty($associationMatches)): ?>
			<div class="card shadow-sm">
				<div class="card-body text-center py-5 text-muted">
					<i class="fa-solid fa-diagram-project fa-3x mb-3" style="opacity: 0.3;"></i>
					<p class="mb-0">No association matches found</p>
					<small>Create group associations in Admin to discover backup streams from other regions</small>
				</div>
			</div>
		<?php else: ?>

			<div class="alert alert-warning d-flex align-items-start mb-3">
				<i class="fa-solid fa-triangle-exclamation me-2 mt-1"></i>
				<div>
					<strong>Verify before use.</strong> These suggestions come from your custom group associations and match on tvg-id similarity—they may not always contain identical content.
				</div>
			</div>

			<div class="d-flex gap-3">
				<!-- Sidebar -->
				<div class="feed-sidebar" style="min-width: 200px; max-width: 200px;">
					<div class="card shadow-sm">
						<div class="card-body">
							<h6 class="fw-semibold mb-3"><i class="fa-solid fa-filter me-1"></i> Sort & Filter</h6>

							<div class="mb-3">
								<label class="form-label small fw-semibold">Search</label>
								<input type="text" class="form-control form-control-sm" id="assoc-search" placeholder="Filter feeds...">
								<small class="text-muted">Group, Channel, or File</small>
							</div>

							<div class="mb-3">
								<label class="form-label small fw-semibold">Sort By</label>
								<select class="form-select form-select-sm" id="assoc-sort">
									<option value="score" selected>Best Feed First</option>
									<option value="group">Group</option>
									<option value="channel">Channel Name</option>
									<option value="status">Status</option>
									<option value="reliability">Reliability</option>
									<option value="resolution">Resolution</option>
									<option value="class">Class</option>
									<option value="fps">FPS</option>
									<option value="codec">Codec</option>
									<option value="checked">Last Checked</option>
								</select>
							</div>

							<div class="form-check form-switch">
								<input class="form-check-input" type="checkbox" id="assoc-show-epg">
								<label class="form-check-label small" for="assoc-show-epg">
									Show EPG Info
								</label>
							</div>
						</div>
					</div>
				</div>

				<!-- Feed Results -->
				<div class="feed-results flex-grow-1">
					<?php foreach ($associationMatches as $assocGroup): ?>
						<div class="mb-4">
							<h5 class="mb-3">
								<i class="fa-solid fa-link me-1"></i> <?= h($assocGroup['association_name']) ?>
								<span class="badge bg-secondary ms-2"><?= count($assocGroup['matches']) ?> matches</span>
							</h5>

							<div class="assoc-feeds-container">
								<?php foreach ($assocGroup['matches'] as $r): ?>
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
									$groupTitle = (string)$r['group_title'];
									$groupLink  = 'feeds.php?group=' . urlencode($groupTitle);
									$feedTvgId = (string)$r['tvg_id'];
									?>

									<div class="card shadow-sm mb-3 feed-card"
										data-score="<?= $score ?>"
										data-group="<?= h($groupTitle) ?>"
										data-channel="<?= h((string)$r['tvg_name']) ?>"
										data-file="<?= h($file) ?>"
										data-status="<?= $r['last_ok'] ?>"
										data-reliability="<?= $rel ?? 0 ?>"
										data-resolution="<?= $px ?>"
										data-class="<?= $clsPts ?>"
										data-fps="<?= $fps ?? 0 ?>"
										data-codec="<?= h($codec) ?>"
										data-checked="<?= $r['last_checked_at'] ? strtotime($r['last_checked_at']) : 0 ?>"
										data-feed-id="<?= (int)$r['feed_id'] ?>"
										data-tvg-id="<?= h($feedTvgId) ?>">

										<div class="card-body">
											<!-- Score absolutely positioned in upper right -->
											<div class="score-inline <?= score_badge_class($score) ?>" style="position: absolute; top: 1rem; right: 1rem;">
												<span class="score-value-inline"><?= h((string)$score) ?></span>
												<span class="score-max-inline">/100</span>
											</div>

											<!-- Group and Channel on same line -->
											<div class="best-feed-meta-inline mb-2">
												<div class="meta-inline-item">
													<i class="fa-solid fa-layer-group"></i>
													<span class="meta-inline-label">Group:</span>
													<span class="meta-inline-value">
														<a class="text-decoration-none" href="<?= h($groupLink) ?>"><?= h($groupTitle) ?></a>
													</span>
												</div>
												<div class="meta-inline-item">
													<i class="fa-solid fa-tv"></i>
													<span class="meta-inline-label">Channel:</span>
													<span class="meta-inline-value"><?= h((string)$r['tvg_name']) ?></span>
												</div>
											</div>

											<!-- tvg-id and File on second line -->
											<div class="best-feed-meta-inline mb-3">
												<div class="meta-inline-item">
													<i class="fa-solid fa-fingerprint"></i>
													<span class="meta-inline-label">tvg-id:</span>
													<span class="meta-inline-value"><?= h($feedTvgId) ?></span>
												</div>
												<div class="meta-inline-item">
													<i class="fa-solid fa-file"></i>
													<span class="meta-inline-label">File:</span>
													<span class="meta-inline-value"><?= h($file) ?></span>
												</div>
											</div>

											<!-- Stream metrics with extra space above -->
											<div class="best-feed-stream-inline mb-3">
												<div class="stream-inline-item">
													<i class="fa-solid fa-chart-simple"></i>
													<span class="stream-inline-label">Rel:</span>
													<span class="stream-inline-value"><?= h($relTxt) ?></span>
												</div>
												<div class="stream-inline-item">
													<span class="stream-inline-label">Res:</span>
													<span class="stream-inline-value">
														<?= res_badge($cls) ?> <span class="text-muted ms-1"><?= h($res) ?></span>
													</span>
												</div>
												<div class="stream-inline-item">
													<span class="stream-inline-label">FPS:</span>
													<span class="stream-inline-value"><?= h($fpsTxt) ?></span>
												</div>
												<div class="stream-inline-item">
													<span class="stream-inline-label">Codec:</span>
													<span class="stream-inline-value"><?= h($codec) ?></span>
												</div>
											</div>

											<!-- Bottom section: Last checked & Status left, Actions right -->
											<div class="d-flex justify-content-between align-items-center">
												<div class="text-muted small">
													<span class="me-3">
														<i class="fa-solid fa-clock me-1"></i>
														<span>Last Checked:</span> <?= fmt_dt($r['last_checked_at'] ? (string)$r['last_checked_at'] : null) ?>
													</span>
													<span>
														<span>Status:</span> <?= status_badge($r['last_ok']) ?>
													</span>
												</div>
												<div class="btn-group">
													<a href="feed_history.php?feed_id=<?= (int)$r['feed_id'] ?>"
														class="btn btn-sm btn-outline-primary me-2"
														title="View check history">
														<i class="fa-solid fa-clock-rotate-left"></i> History
													</a>
													<button type="button" class="btn btn-outline-success btn-sm btn-preview me-2"
														data-feed-id="<?= (int)$r['feed_id'] ?>">
														<i class="fa-solid fa-play"></i> Preview
													</button>
													<button type="button"
														class="btn btn-sm btn-outline-info btn-create-task"
														data-feed-id="<?= (int)$r['feed_id'] ?>"
														data-group="<?= h($r['group_title']) ?>"
														data-ref-group="<?= h($clicked['group_title']) ?>"
														data-ref-tvg-id="<?= h($tvgId) ?>"
														data-suggested-feed-id=""
														title="Create task">
														<i class="fa-solid fa-list-check"></i> Add Task
													</button>
												</div>
											</div>

											<!-- EPG Section (hidden by default) -->
											<div class="epg-section mt-3 pt-3 border-top" style="display: none;">
												<div class="epg-loading text-center py-2">
													<div class="spinner-border spinner-border-sm me-2" role="status"></div>
													Loading EPG data...
												</div>
												<div class="epg-content" style="display: none;"></div>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>

<script>
	$(function() {
		// EPG configuration from PHP
		const epgConfigured = <?= json_encode($epgConfigured) ?>;
		const epgSynced = <?= json_encode($epgSynced) ?>;
		const epgFailed = <?= json_encode($epgFailed) ?>;

		// Sorting functionality
		function sortFeeds(container, sortBy) {
			const cards = Array.from(container.find('.feed-card'));

			cards.sort((a, b) => {
				const $a = $(a);
				const $b = $(b);

				let valA, valB;

				switch (sortBy) {
					case 'score':
						valA = parseFloat($a.data('score')) || 0;
						valB = parseFloat($b.data('score')) || 0;
						return valB - valA; // desc
					case 'group':
						valA = $a.data('group').toLowerCase();
						valB = $b.data('group').toLowerCase();
						return valA.localeCompare(valB);
					case 'channel':
						valA = $a.data('channel').toLowerCase();
						valB = $b.data('channel').toLowerCase();
						return valA.localeCompare(valB);
					case 'status':
						valA = parseInt($a.data('status')) || 0;
						valB = parseInt($b.data('status')) || 0;
						return valB - valA; // OK first
					case 'reliability':
						valA = parseFloat($a.data('reliability')) || 0;
						valB = parseFloat($b.data('reliability')) || 0;
						return valB - valA; // desc
					case 'resolution':
						valA = parseInt($a.data('resolution')) || 0;
						valB = parseInt($b.data('resolution')) || 0;
						return valB - valA; // desc
					case 'class':
						valA = parseInt($a.data('class')) || 0;
						valB = parseInt($b.data('class')) || 0;
						return valB - valA; // desc
					case 'fps':
						valA = parseFloat($a.data('fps')) || 0;
						valB = parseFloat($b.data('fps')) || 0;
						return valB - valA; // desc
					case 'codec':
						valA = $a.data('codec').toLowerCase();
						valB = $b.data('codec').toLowerCase();
						return valA.localeCompare(valB);
					case 'checked':
						valA = parseInt($a.data('checked')) || 0;
						valB = parseInt($b.data('checked')) || 0;
						return valB - valA; // most recent first
					default:
						return 0;
				}
			});

			cards.forEach(card => container.append(card));
		}

		// Search/filter functionality
		function filterFeeds(container, searchText) {
			const search = searchText.toLowerCase().trim();

			container.find('.feed-card').each(function() {
				const $card = $(this);

				if (search === '') {
					$card.show();
					return;
				}

				const group = $card.data('group').toLowerCase();
				const channel = $card.data('channel').toLowerCase();
				const file = $card.data('file').toLowerCase();

				if (group.includes(search) || channel.includes(search) || file.includes(search)) {
					$card.show();
				} else {
					$card.hide();
				}
			});
		}

		// EPG functionality
		function loadEPG(card) {
			const $card = $(card);
			const $epgSection = $card.find('.epg-section');
			const $epgLoading = $epgSection.find('.epg-loading');
			const $epgContent = $epgSection.find('.epg-content');
			const tvgId = $card.data('tvg-id');

			if ($epgContent.data('loaded')) {
				return; // Already loaded
			}

			$epgLoading.show();
			$epgContent.hide();

			// Check EPG configuration
			if (!epgConfigured) {
				$epgLoading.hide();
				$epgContent.html(`
					<div class="alert alert-info mb-0 small">
						<i class="fa-solid fa-circle-info me-1"></i>
						EPG URL not configured. Visit the <a href="admin.php?tab=playlist" class="alert-link">Admin Panel</a> to add your EPG source.
					</div>
				`).show().data('loaded', true);
				return;
			}

			if (epgFailed) {
				$epgLoading.hide();
				$epgContent.html(`
					<div class="alert alert-danger mb-0 small">
						<i class="fa-solid fa-circle-exclamation me-1"></i>
						EPG sync failed. Check <a href="admin.php?tab=playlist" class="alert-link">Admin Panel</a> for details.
					</div>
				`).show().data('loaded', true);
				return;
			}

			if (!epgSynced) {
				$epgLoading.hide();
				$epgContent.html(`
					<div class="alert alert-warning mb-0 small">
						<i class="fa-solid fa-clock me-1"></i>
						EPG data pending initial sync. The cron job will populate program information on its next run.
					</div>
				`).show().data('loaded', true);
				return;
			}

			// Fetch EPG data from server
			$.ajax({
				url: 'get_epg_data.php',
				method: 'GET',
				data: {
					tvg_id: tvgId
				},
				dataType: 'json',
				success: function(response) {
					$epgLoading.hide();

					if (response.success && response.programs && response.programs.length > 0) {
						let html = '<div class="epg-programs">';
						html += '<h6 class="small fw-semibold mb-2"><i class="fa-solid fa-tv me-1"></i> Program Guide</h6>';

						response.programs.forEach(program => {
							const isCurrent = program.is_current;
							html += `
								<div class="epg-program ${isCurrent ? 'border-start border-success border-3 ps-2' : 'ps-2'}">
									<div class="d-flex justify-content-between align-items-start">
										<div class="flex-grow-1">
											<div class="fw-semibold small">${escapeHtml(program.title)}</div>
											${program.description ? `<div class="text-muted small mt-1">${escapeHtml(program.description)}</div>` : ''}
										</div>
										<div class="text-muted small ms-2 text-nowrap">
											${program.start_time} - ${program.end_time}
										</div>
									</div>
								</div>
							`;
						});

						html += '</div>';
						$epgContent.html(html).show().data('loaded', true);
					} else {
						$epgContent.html(`
							<div class="alert alert-secondary mb-0 small">
								<i class="fa-solid fa-circle-info me-1"></i>
								No EPG information available for tvg-id: <code>${escapeHtml(tvgId)}</code> at this time.
							</div>
						`).show().data('loaded', true);
					}
				},
				error: function() {
					$epgLoading.hide();
					$epgContent.html(`
						<div class="alert alert-danger mb-0 small">
							<i class="fa-solid fa-circle-exclamation me-1"></i>
							Failed to load EPG data. Please try again.
						</div>
					`).show().data('loaded', true);
				}
			});
		}

		function escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}

		// Primary feeds sorting
		$('#primary-sort').on('change', function() {
			sortFeeds($('#primary-feeds-container'), $(this).val());
		});

		// Primary feeds search
		$('#primary-search').on('input', function() {
			filterFeeds($('#primary-feeds-container'), $(this).val());
		});

		// Primary feeds EPG toggle
		$('#primary-show-epg').on('change', function() {
			const show = $(this).is(':checked');
			$('#primary-feeds-container .feed-card').each(function() {
				const $epgSection = $(this).find('.epg-section');
				if (show) {
					$epgSection.show();
					loadEPG(this);
				} else {
					$epgSection.hide();
				}
			});
		});

		// Association feeds sorting
		$('#assoc-sort').on('change', function() {
			const sortBy = $(this).val();
			$('.assoc-feeds-container').each(function() {
				sortFeeds($(this), sortBy);
			});
		});

		// Association feeds search
		$('#assoc-search').on('input', function() {
			const searchText = $(this).val();
			$('.assoc-feeds-container').each(function() {
				filterFeeds($(this), searchText);
			});
		});

		// Association feeds EPG toggle
		$('#assoc-show-epg').on('change', function() {
			const show = $(this).is(':checked');
			$('.assoc-feeds-container .feed-card').each(function() {
				const $epgSection = $(this).find('.epg-section');
				if (show) {
					$epgSection.show();
					loadEPG(this);
				} else {
					$epgSection.hide();
				}
			});
		});
	});

	// Make feed data available for task creation modal
	window.channelPageData = {
		tvgId: <?= json_encode($tvgId) ?>,
		group: <?= json_encode($clicked['group_title']) ?>,
		primaryMatches: <?= json_encode(array_map(function ($r) {
							$w = $r['last_w'] !== null ? (int)$r['last_w'] : null;
							$h = $r['last_h'] !== null ? (int)$r['last_h'] : null;

							if ($w <= 0 || $h <= 0) {
								$cls = 'Unknown';
							} elseif ($h >= 2160 || $w >= 3840) {
								$cls = '4K';
							} elseif ($h >= 1080) {
								$cls = 'FHD';
							} elseif ($h >= 720) {
								$cls = 'HD';
							} else {
								$cls = 'SD';
							}

							$resBadgeMap = [
								'4K' => 'bg-warning text-dark',
								'FHD' => 'bg-primary',
								'HD' => 'bg-info text-dark',
								'SD' => 'bg-secondary',
								'Unknown' => 'bg-light text-dark',
							];
							$badgeClass = $resBadgeMap[$cls] ?? $resBadgeMap['Unknown'];

							$res = ($w && $h) ? ($w . '×' . $h) : '—';
							$resolutionHtml = '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($cls) . '</span> <span class="text-muted ms-1">' . htmlspecialchars($res) . '</span>';

							// Extract file name
							$url = (string)($r['url_any'] ?? '');
							$path = parse_url($url, PHP_URL_PATH);
							$path = is_string($path) ? $path : '';
							$file = $path !== '' ? basename($path) : '';
							if ($file === '' && $url !== '') $file = basename($url);
							if ($file === '') $file = '—';

							return [
								'feed_id' => (int)$r['feed_id'],
								'group_title' => (string)$r['group_title'],
								'tvg_name' => (string)$r['tvg_name'],
								'tvg_id' => (string)$r['tvg_id'],
								'reliability' => $r['reliability_score'] !== null ? number_format((float)$r['reliability_score'], 2) : '—',
								'resolution_html' => $resolutionHtml,
								'fps' => $r['last_fps'] !== null ? number_format((float)$r['last_fps'], 2) : '—',
								'codec' => $r['last_codec'] ? (string)$r['last_codec'] : '—',
								'file' => $file
							];
						}, $rows)) ?>,
		associationMatches: <?= json_encode(array_map(function ($assocGroup) {
								return [
									'association_name' => $assocGroup['association_name'],
									'matches' => array_map(function ($r) {
										$w = $r['last_w'] !== null ? (int)$r['last_w'] : null;
										$h = $r['last_h'] !== null ? (int)$r['last_h'] : null;

										if ($w <= 0 || $h <= 0) {
											$cls = 'Unknown';
										} elseif ($h >= 2160 || $w >= 3840) {
											$cls = '4K';
										} elseif ($h >= 1080) {
											$cls = 'FHD';
										} elseif ($h >= 720) {
											$cls = 'HD';
										} else {
											$cls = 'SD';
										}

										$resBadgeMap = [
											'4K' => 'bg-warning text-dark',
											'FHD' => 'bg-primary',
											'HD' => 'bg-info text-dark',
											'SD' => 'bg-secondary',
											'Unknown' => 'bg-light text-dark',
										];
										$badgeClass = $resBadgeMap[$cls] ?? $resBadgeMap['Unknown'];

										$res = ($w && $h) ? ($w . '×' . $h) : '—';
										$resolutionHtml = '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($cls) . '</span> <span class="text-muted ms-1">' . htmlspecialchars($res) . '</span>';

										// Extract file name
										$url = (string)($r['url_any'] ?? '');
										$path = parse_url($url, PHP_URL_PATH);
										$path = is_string($path) ? $path : '';
										$file = $path !== '' ? basename($path) : '';
										if ($file === '' && $url !== '') $file = basename($url);
										if ($file === '') $file = '—';

										return [
											'feed_id' => (int)$r['feed_id'],
											'group_title' => (string)$r['group_title'],
											'tvg_name' => (string)$r['tvg_name'],
											'tvg_id' => (string)$r['tvg_id'],
											'reliability' => $r['reliability_score'] !== null ? number_format((float)$r['reliability_score'], 2) : '—',
											'resolution_html' => $resolutionHtml,
											'fps' => $r['last_fps'] !== null ? number_format((float)$r['last_fps'], 2) : '—',
											'codec' => $r['last_codec'] ? (string)$r['last_codec'] : '—',
											'file' => $file
										];
									}, $assocGroup['matches'])
								];
							}, $associationMatches)) ?>
	};

	// Handle search parameter from URL
	$(document).ready(function() {
		const urlParams = new URLSearchParams(window.location.search);
		const searchParam = urlParams.get('search');

		if (searchParam) {
			// Clean URL
			window.history.replaceState({}, document.title, window.location.pathname);
		}
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>