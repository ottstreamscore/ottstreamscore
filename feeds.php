<?php

declare(strict_types=1);


$title = 'Feeds';
$currentPage = 'feeds';
require_once __DIR__ . '/_top.php';

$pdo = db();

// Group options (for select)
$groups = $pdo->query("
  SELECT group_title
  FROM channels
  WHERE group_title IS NOT NULL AND group_title <> ''
  GROUP BY group_title
  ORDER BY group_title
")->fetchAll();

// preserve last selections (optional, purely UI)
$q     = h((string)($_GET['q'] ?? ''));
$group = h((string)($_GET['group'] ?? ''));
?>

<style>
	div.dataTables_filter {
		display: none;
	}

	div#feedsTable_wrapper {
		padding: 10pt;
	}
</style>

<div class="row g-3">
	<!-- Sidebar -->
	<div class="col-lg-3">
		<div class="card shadow-sm">
			<div class="card-header fw-semibold"><i class="fa-solid fa-filter me-1"></i> Filters</div>
			<div class="card-body">

				<label class="form-label small text-muted mb-1">Search (tvg-name or tvg-id)</label>
				<input id="flt_q" class="form-control mb-3" value="<?= $q ?>" placeholder="ESPN, HBO, 12345...">

				<label class="form-label small text-muted mb-1">Group</label>
				<select id="flt_group" class="form-select mb-3">
					<option value="">All groups</option>
					<?php foreach ($groups as $gRow): $gName = (string)$gRow['group_title']; ?>
						<option value="<?= h($gName) ?>" <?= $group === $gName ? 'selected' : '' ?>>
							<?= h($gName) ?>
						</option>
					<?php endforeach; ?>
				</select>

				<div class="mb-3">
					<div class="form-label small text-muted mb-1">Status</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="st_ok" checked>
						<label class="form-check-label" for="st_ok">OK</label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="st_fail" checked>
						<label class="form-check-label" for="st_fail">FAIL</label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="st_unknown" checked>
						<label class="form-check-label" for="st_unknown">Unknown</label>
					</div>
				</div>

				<div class="mb-3">
					<div class="form-label small text-muted mb-1">Quality</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="q_4k">
						<label class="form-check-label" for="q_4k">4K</label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="q_fhd">
						<label class="form-check-label" for="q_fhd">FHD (1080p)</label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="q_hd">
						<label class="form-check-label" for="q_hd">HD (720p)</label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="q_sd">
						<label class="form-check-label" for="q_sd">SD</label>
					</div>

					<div class="text-muted small mt-2">
						If none selected, quality is not filtered.
					</div>
				</div>

				<div class="mb-3">
					<div class="form-label small text-muted mb-1">Hide</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="hide_ppv">
						<label class="form-check-label" for="hide_ppv">PPV</label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="hide_247">
						<label class="form-check-label" for="hide_247">24/7</label>
					</div>
				</div>

				<div class="d-grid gap-2">
					<button id="btn_apply" class="btn btn-dark" type="button">Apply</button>
					<button id="btn_reset" class="btn btn-outline-secondary" type="button">Reset</button>
				</div>

				<div class="text-muted small mt-3">
					Searches/sorts across all records.
				</div>
			</div>
		</div>
	</div>

	<!-- Table -->
	<div class="col-lg-9">
		<div class="card shadow-sm">
			<div class="card-header d-flex justify-content-between align-items-center">
				<div class="fw-semibold"><i class="fa-solid fa-tower-broadcast me-1"></i> Feeds</div>
			</div>

			<div class="table-responsive">
				<table id="feedsTable" class="table table-striped table-hover mb-0 align-middle">
					<thead>
						<tr>
							<th>Status</th>
							<th>Quality</th>
							<th>Group</th>
							<th>Channel</th>
							<th class="text-end">FPS</th>
							<th>Codec</th>
							<th>Checked</th>
							<th>File</th>
							<th>History</th>
							<th style="display:none;">status_rank</th>
							<th style="display:none;">res_rank</th>
							<th style="display:none;">checked_ts</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>

			<div class="card-body border-top">
				<div class="text-muted small">
					Tip: click column headers to sort (Status, Quality, FPS, Codec, Last Checked).
				</div>
			</div>
		</div>
	</div>
</div>

<script>
	function getFilters() {
		return {
			q: $('#flt_q').val() || '',
			group: $('#flt_group').val() || '',
			status: {
				ok: $('#st_ok').is(':checked') ? 1 : 0,
				fail: $('#st_fail').is(':checked') ? 1 : 0,
				unknown: $('#st_unknown').is(':checked') ? 1 : 0
			},
			qual: {
				k4: $('#q_4k').is(':checked') ? 1 : 0,
				fhd: $('#q_fhd').is(':checked') ? 1 : 0,
				hd: $('#q_hd').is(':checked') ? 1 : 0,
				sd: $('#q_sd').is(':checked') ? 1 : 0
			},
			hide: {
				ppv: $('#hide_ppv').is(':checked') ? 1 : 0,
				t247: $('#hide_247').is(':checked') ? 1 : 0
			}
		};
	}

	$(function() {
		const table = $('#feedsTable').DataTable({
			serverSide: true,
			processing: true,
			pageLength: 50,
			lengthMenu: [
				[25, 50, 100, 250],
				[25, 50, 100, 250]
			],
			ajax: {
				url: 'feeds_data.php',
				type: 'GET',
				data: function(d) {
					d.filters = getFilters();
				}
			},
			columns: [{
					data: 'status_badge'
				},
				{
					data: 'qual_badge'
				},
				{
					data: 'group_html'
				},
				{
					data: 'channel_html'
				},
				{
					data: 'fps'
				},
				{
					data: 'codec'
				},
				{
					data: 'last_checked'
				},
				{
					data: 'file'
				},
				{
					data: 'history',
					orderable: false
				},
				{
					data: 'status_rank',
					visible: false
				},
				{
					data: 'res_rank',
					visible: false
				},
				{
					data: 'checked_ts',
					visible: false
				}
			],
			order: [
				[9, 'desc'], // status_rank
				[10, 'desc'], // res_rank
				[4, 'desc'], // fps
				[11, 'desc'] // checked_ts
			],
			language: {
				emptyTable: "No feeds matched your filters.",
				zeroRecords: "No feeds matched your filters."
			}
		});

		$('#btn_apply').on('click', function() {
			table.ajax.reload(null, true);
		});

		$('#btn_reset').on('click', function() {
			$('#flt_q').val('');
			$('#flt_group').val('');
			$('#st_ok,#st_fail,#st_unknown').prop('checked', true);
			$('#q_4k,#q_fhd,#q_hd,#q_sd').prop('checked', false);
			$('#hide_ppv,#hide_247').prop('checked', false);
			table.ajax.reload(null, true);
		});

		// convenience: pressing Enter in search applies
		$('#flt_q').on('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				table.ajax.reload(null, true);
			}
		});
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>