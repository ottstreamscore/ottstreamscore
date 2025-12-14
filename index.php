<?php

declare(strict_types=1);

$title = 'Dashboard';
require_once __DIR__ . '/_top.php';

$pdo = db();

/**
 * Classify resolution into 4K/FHD/HD/SD/Unknown
 */
function res_class(?int $w, ?int $h): string
{
	if (!$w || !$h) return 'Unknown';
	$px = $w * $h;

	if ($h >= 2160 || $px >= 3840 * 2160) return '4K';
	if ($h >= 1080 || $px >= 1920 * 1080) return 'FHD';
	if ($h >= 720  || $px >= 1280 * 720)  return 'HD';
	return 'SD';
}
function res_rank(string $c): int
{
	return match ($c) {
		'4K' => 4,
		'FHD' => 3,
		'HD' => 2,
		'SD' => 1,
		default => 0,
	};
}
function status_rank($ok): int
{
	if ($ok === null) return 0;
	return ((int)$ok === 1) ? 2 : 1;
}

/**
 * Reliability % from cron logic:
 * reliability_score is already a % (0..100) computed from last 168h window
 * (this column is updated in cron_check_feeds.php).
 */

function fmt_rel($rel): string
{
	if ($rel === null) return '—';
	return number_format((float)$rel, 1) . '%';
}

$counts = [
	'Channels' => (int)$pdo->query("SELECT COUNT(*) FROM channels")->fetchColumn(),
	'Feeds' => (int)$pdo->query("SELECT COUNT(*) FROM feeds")->fetchColumn(),
	'In Queue' => (int)$pdo->query("SELECT COUNT(*) FROM feed_check_queue WHERE next_run_at <= NOW() AND locked_at IS NULL")->fetchColumn(),
	'Locked' => (int)$pdo->query("SELECT COUNT(*) FROM feed_check_queue WHERE locked_at IS NOT NULL")->fetchColumn(),
	'OK Feeds' => (int)$pdo->query("SELECT COUNT(*) FROM feeds WHERE last_ok=1")->fetchColumn(),
	'Failed Feeds' => (int)$pdo->query("SELECT COUNT(*) FROM feeds WHERE last_ok=0")->fetchColumn(),
];

/**
 * Recent checks (add channel_id + reliability_score)
 */

$recent = $pdo->query("
  SELECT
    c.id AS channel_id,
    c.group_title,
    c.tvg_name,
    f.last_ok,
    f.last_checked_at,
    f.last_w,
    f.last_h,
    f.last_fps,
    f.reliability_score
  FROM feeds f
  JOIN channels c ON c.id = f.channel_id
  WHERE f.last_checked_at IS NOT NULL
  ORDER BY f.last_checked_at DESC
  LIMIT 50
")->fetchAll();
?>

<style>
	.dataTables_wrapper {
		padding: 10pt;
	}
</style>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.10/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/js/dataTables.bootstrap5.min.js"></script>

<div class="row g-3 mb-4">
	<?php foreach ($counts as $label => $val): ?>
		<div class="col-6 col-lg-2">
			<div class="card shadow-sm">
				<div class="card-body">
					<div class="text-muted small"><?= h($label) ?></div>
					<div class="fs-4 fw-semibold"><?= number_format($val) ?></div>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<div class="card shadow-sm">
	<div class="card-header fw-semibold">Recent Feed Checks</div>
	<div class="table-responsive">
		<table id="recentChecks" class="table table-sm table-striped table-hover mb-0 align-middle">
			<thead>
				<tr>
					<th>Group</th>
					<th>Channel</th>
					<th>Status</th>
					<th class="text-end">Reliability</th>
					<th>Class</th>
					<th>Res</th>
					<th class="text-end">FPS</th>
					<th>Checked</th>

					<!-- hidden sort helpers -->
					<th style="display:none;">status_rank</th>
					<th style="display:none;">class_rank</th>
					<th style="display:none;">checked_ts</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($recent as $r): ?>
					<?php
					$group = (string)$r['group_title'];
					$channelId = (int)$r['channel_id'];

					$ok = $r['last_ok'];
					$sr = status_rank($ok);

					$w = $r['last_w'] ? (int)$r['last_w'] : null;
					$h = $r['last_h'] ? (int)$r['last_h'] : null;
					$class = res_class($w, $h);
					$cr = res_rank($class);

					$fps = $r['last_fps'] !== null ? (float)$r['last_fps'] : null;
					$checkedAt = (string)$r['last_checked_at'];
					$checkedTs = $checkedAt ? strtotime($checkedAt) : 0;

					$statusBadge = ($ok === null)
						? '<span class="badge bg-secondary">UNK</span>'
						: (((int)$ok === 1)
							? '<span class="badge bg-success">OK</span>'
							: '<span class="badge bg-danger">FAIL</span>');

					$classBadge = match ($class) {
						'4K' => '<span class="badge bg-dark">4K</span>',
						'FHD' => '<span class="badge bg-primary">FHD</span>',
						'HD' => '<span class="badge bg-info text-dark">HD</span>',
						'SD' => '<span class="badge bg-light text-dark border">SD</span>',
						default => '<span class="badge bg-secondary">UNK</span>',
					};

					$resDisp = ($w && $h) ? ($w . '×' . $h) : '—';
					$fpsDisp = ($fps !== null) ? number_format($fps, 1) : '—';

					$groupLink = 'feeds.php?group=' . urlencode($group);
					$chanLink = 'channel.php?id=' . $channelId;
					?>
					<tr>
						<td>
							<a href="<?= h($groupLink) ?>" class="text-decoration-none">
								<?= h($group) ?>
							</a>
						</td>
						<td>
							<a href="<?= h($chanLink) ?>" class="fw-semibold text-decoration-none">
								<?= h((string)$r['tvg_name']) ?>
							</a>
						</td>
						<td><?= $statusBadge ?></td>
						<td class="text-end"><?= fmt_rel($r['reliability_score'] ?? null) ?></td>
						<td><?= $classBadge ?></td>
						<td><?= h($resDisp) ?></td>
						<td class="text-end"><?= h($fpsDisp) ?></td>
						<td><?= fmt_dt($checkedAt) ?></td>

						<!-- hidden sort helpers -->
						<td style="display:none;"><?= (int)$sr ?></td>
						<td style="display:none;"><?= (int)$cr ?></td>
						<td style="display:none;"><?= (int)$checkedTs ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<script>
	$(function() {
		$('#recentChecks').DataTable({
			paging: true,
			pageLength: 25,
			lengthMenu: [
				[10, 25, 50, 100],
				[10, 25, 50, 100]
			],
			info: true,
			searching: true,
			order: [
				[8, 'desc'], // status_rank
				[9, 'desc'], // class_rank
				[10, 'desc'] // checked_ts
			],
			columnDefs: [{
				targets: [8, 9, 10],
				visible: false,
				searchable: false
			}],
			language: {
				emptyTable: "No recent checks yet.",
				zeroRecords: "No matches."
			}
		});
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>