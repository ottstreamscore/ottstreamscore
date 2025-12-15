<?php

declare(strict_types=1);

$title = 'Reports';
$currentPage = 'reports';
require_once __DIR__ . '/_top.php';

$pdo = db();

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

function base_url(): string
{
	$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
	$dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
	return ($dir === '.' ? '' : $dir);
}
$BASE = base_url();

$groups = $pdo->query("
  SELECT group_title
  FROM channels
  WHERE group_title IS NOT NULL AND group_title <> ''
  GROUP BY group_title
  ORDER BY group_title
")->fetchAll();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.10/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/js/dataTables.bootstrap5.min.js"></script>

<style>
	div.dataTables_filter {
		display: none;
	}

	.quick-filter.active {
		pointer-events: none;
	}

	div#reportsTable_wrapper {
		padding: 10pt;
	}
</style>

<div class="row g-3">
	<div class="col-lg-3">
		<div class="card shadow-sm">
			<div class="card-header fw-semibold">Report Filters</div>
			<div class="card-body">

				<label class="form-label small text-muted mb-1">Search (Channel name or EPG ID)</label>
				<input id="flt_q" class="form-control mb-3" placeholder="OWN, ESPN, epg id...">

				<label class="form-label small text-muted mb-1">Group</label>
				<select id="flt_group" class="form-select mb-3">
					<option value="">All groups</option>
					<?php foreach ($groups as $gRow): $gName = (string)$gRow['group_title']; ?>
						<option value="<?= h($gName) ?>"><?= h($gName) ?></option>
					<?php endforeach; ?>
				</select>

				<div class="d-grid gap-2">
					<button id="btn_apply" class="btn btn-dark" type="button">Apply</button>
					<button id="btn_reset" class="btn btn-outline-secondary" type="button">Reset</button>
				</div>

				<div class="text-muted small mt-3">
					Searches/sorts across <strong>all records</strong>.
				</div>
			</div>
		</div>
	</div>

	<div class="col-lg-9">
		<div class="card shadow-sm">
			<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
				<div class="fw-semibold">Feed Report</div>

				<div class="d-flex flex-wrap gap-2">
					<button class="btn btn-outline-success btn-sm quick-filter" data-quick="top">Top Channels</button>
					<button class="btn btn-outline-danger btn-sm quick-filter" data-quick="dead">Dead Channels</button>
					<button class="btn btn-outline-warning btn-sm quick-filter" data-quick="unstable">Unstable</button>
					<button class="btn btn-outline-secondary btn-sm quick-filter" data-quick="unknown">Never Checked</button>
					<button class="btn btn-outline-primary btn-sm quick-filter" data-quick="recent">Last 24h</button>
					<button class="btn btn-outline-secondary btn-sm quick-filter" data-quick="">All</button>
				</div>
			</div>

			<div class="table-responsive">
				<table id="reportsTable" class="table table-striped table-hover mb-0 align-middle">
					<thead>
						<tr>
							<th>Group</th>
							<th>Channel</th>
							<th>EPG ID</th>
							<th>Status</th>
							<th>Quality</th>
							<th>Res</th>
							<th class="text-end">FPS</th>
							<th>Codec</th>
							<th>Checked</th>
							<th>File</th>
							<th style="display:none;">status_rank</th>
							<th style="display:none;">class_rank</th>
							<th style="display:none;">checked_ts</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>

			<div class="card-body border-top">
				<div class="text-muted small">
					Sort suggestion: Status → Class → FPS → Last Checked.
				</div>
			</div>
		</div>
	</div>
</div>

<script>
	let quickMode = '';

	function getFilters() {
		return {
			q: ($('#flt_q').val() || '').trim(),
			group: $('#flt_group').val() || ''
		};
	}

	function setQuickActive(mode) {
		$('.quick-filter').removeClass('active btn-dark');
		const $btn = $('.quick-filter[data-quick="' + mode + '"]');
		if ($btn.length) $btn.addClass('btn-dark active');
	}

	$(function() {
		const table = $('#reportsTable').DataTable({
			serverSide: true,
			processing: true,
			pageLength: 50,
			lengthMenu: [
				[25, 50, 100, 250],
				[25, 50, 100, 250]
			],
			ajax: {
				url: '<?= h($BASE) ?>/reports_data.php',
				type: 'GET',
				data: function(d) {
					d.filters = getFilters();
					d.quick = quickMode;
				}
			},
			columns: [{
					data: 'group_html',
					orderable: true
				},
				{
					data: 'channel_html',
					orderable: true
				},
				{
					data: 'tvg_id',
					orderable: true
				},
				{
					data: 'status_badge',
					orderable: true
				},
				{
					data: 'class_badge',
					orderable: true
				},
				{
					data: 'res',
					orderable: true
				},
				{
					data: 'fps',
					className: 'text-end',
					orderable: true
				},
				{
					data: 'codec',
					orderable: true
				},
				{
					data: 'last_checked',
					orderable: true
				},
				{
					data: 'file',
					orderable: false
				},
				{
					data: 'status_rank',
					visible: false
				},
				{
					data: 'class_rank',
					visible: false
				},
				{
					data: 'checked_ts',
					visible: false
				}
			],
			order: [
				[10, 'desc'],
				[11, 'desc'],
				[6, 'desc'],
				[12, 'desc']
			],
			language: {
				emptyTable: "No rows matched your filters.",
				zeroRecords: "No rows matched your filters."
			}
		});

		$(document).on('click', '.quick-filter', function() {
			quickMode = $(this).data('quick') || '';
			setQuickActive(quickMode);
			table.ajax.reload(null, true);
		});

		$('#btn_apply').on('click', function() {
			table.ajax.reload(null, true);
		});

		$('#btn_reset').on('click', function() {
			$('#flt_q').val('');
			$('#flt_group').val('');
			quickMode = '';
			setQuickActive('');
			table.ajax.reload(null, true);
		});

		$('#flt_q').on('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				table.ajax.reload(null, true);
			}
		});

		setQuickActive('');
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>