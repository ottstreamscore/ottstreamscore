<?php

declare(strict_types=1);

$title = 'Dashboard';
$currentPage = 'dashboard';
require_once __DIR__ . '/_boot.php';

// require login authorization
require_auth();

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

// Define stats with icons and colors
$stats = [
	[
		'label' => 'Channels',
		'value' => (int)$pdo->query("SELECT COUNT(*) FROM channels")->fetchColumn(),
		'icon' => 'fa-tv',
		'color' => 'primary'
	],
	[
		'label' => 'Feeds',
		'value' => (int)$pdo->query("SELECT COUNT(*) FROM feeds")->fetchColumn(),
		'icon' => 'fa-signal',
		'color' => 'info'
	],
	[
		'label' => 'In Queue',
		'value' => (int)$pdo->query("SELECT COUNT(*) FROM feed_check_queue WHERE next_run_at <= NOW() AND locked_at IS NULL")->fetchColumn(),
		'icon' => 'fa-clock',
		'color' => 'warning'
	],
	[
		'label' => 'Locked',
		'value' => (int)$pdo->query("SELECT COUNT(*) FROM feed_check_queue WHERE locked_at IS NOT NULL")->fetchColumn(),
		'icon' => 'fa-lock',
		'color' => 'secondary'
	],
	[
		'label' => 'OK Feeds',
		'value' => (int)$pdo->query("SELECT COUNT(*) FROM feeds WHERE last_ok=1")->fetchColumn(),
		'icon' => 'fa-circle-check',
		'color' => 'success'
	],
	[
		'label' => 'Failed Feeds',
		'value' => (int)$pdo->query("SELECT COUNT(*) FROM feeds WHERE last_ok=0")->fetchColumn(),
		'icon' => 'fa-circle-xmark',
		'color' => 'danger'
	],
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

	/* Dashboard stat cards */
	.stat-card {
		transition: transform 0.2s ease, box-shadow 0.2s ease;
		height: 100%;
	}

	.stat-card:hover {
		transform: translateY(-2px);
		box-shadow: 0 0.5rem 1rem var(--shadow) !important;
	}

	.stat-icon {
		font-size: 2rem;
		opacity: 0.6;
		/* Increased from 0.3 */
		transition: opacity 0.2s ease;
	}

	.stat-card:hover .stat-icon {
		opacity: 1;
		/* Full opacity on hover */
	}

	.stat-value {
		font-size: 1.75rem;
		font-weight: 700;
		line-height: 1;
		margin: 0.5rem 0;
	}

	.stat-label {
		font-size: 0.875rem;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		font-weight: 500;
	}

	/* Responsive adjustments */
	@media (max-width: 768px) {
		.stat-value {
			font-size: 1.5rem;
		}

		.stat-icon {
			font-size: 1.5rem;
		}

		.stat-label {
			font-size: 0.75rem;
		}
	}

	/* Color variants for icons - much more visible */
	.text-primary-muted {
		color: rgba(13, 110, 253, 0.6);
	}

	.text-info-muted {
		color: rgba(13, 202, 240, 0.6);
	}

	.text-warning-muted {
		color: rgba(255, 193, 7, 0.7);
	}

	.text-secondary-muted {
		color: rgba(108, 117, 125, 0.6);
	}

	.text-success-muted {
		color: rgba(25, 135, 84, 0.6);
	}

	.text-danger-muted {
		color: rgba(220, 53, 69, 0.6);
	}

	[data-bs-theme="dark"] .text-primary-muted {
		color: rgba(110, 168, 254, 0.7);
	}

	[data-bs-theme="dark"] .text-info-muted {
		color: rgba(13, 202, 240, 0.7);
	}

	[data-bs-theme="dark"] .text-warning-muted {
		color: rgba(255, 193, 7, 0.8);
	}

	[data-bs-theme="dark"] .text-secondary-muted {
		color: rgba(173, 181, 189, 0.7);
	}

	[data-bs-theme="dark"] .text-success-muted {
		color: rgba(25, 135, 84, 0.7);
	}

	[data-bs-theme="dark"] .text-danger-muted {
		color: rgba(220, 53, 69, 0.7);
	}
</style>


<div class="row g-3 mb-4">
	<?php foreach ($stats as $stat): ?>
		<div class="col-6 col-md-4 col-lg-2">
			<div class="card stat-card shadow-sm">
				<div class="card-body text-center">
					<div class="d-flex justify-content-between align-items-start mb-2">
						<span class="stat-label text-<?= $stat['color'] ?>"><?= h($stat['label']) ?></span>
						<i class="fa-solid <?= $stat['icon'] ?> stat-icon text-<?= $stat['color'] ?>-muted"></i>
					</div>
					<div class="stat-value text-<?= $stat['color'] ?>">
						<?= number_format($stat['value']) ?>
					</div>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<div class="card shadow-sm">
	<div class="card-header fw-semibold"><i class="fa-regular fa-clock me-1"></i> Recent Feed Checks</div>
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
						'4K' => '<span class="badge bg-warning text-dark">4K</span>',
						'FHD' => '<span class="badge bg-primary">FHD</span>',
						'HD' => '<span class="badge bg-info text-dark">HD</span>',
						'SD' => '<span class="badge bg-secondary">SD</span>',
						default => '<span class="badge bg-light text-dark">UNK</span>',
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
						<td class="text-muted small"><?= fmt_dt($checkedAt) ?></td>

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
			searching: false,
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
			},
			dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'<'d-flex justify-content-end'B>>>" +
				"<'row'<'col-sm-12'tr>>" +
				"<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
			buttons: [{
					extend: 'copy',
					text: '<i class="fa-solid fa-copy me-1"></i> Copy',
					className: 'btn btn-outline-secondary btn-sm',
					exportOptions: {
						columns: ':visible'
					}
				},
				{
					extend: 'csv',
					text: '<i class="fa-solid fa-file-csv me-1"></i> Export',
					className: 'btn btn-outline-secondary btn-sm',
					exportOptions: {
						columns: ':visible'
					}
				}
			]
		});
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>