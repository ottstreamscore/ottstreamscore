<?php

declare(strict_types=1);


$title = 'Channels';
$currentPage = 'channels';
require_once __DIR__ . '/_boot.php';

// require login authorization
require_auth();

require_once __DIR__ . '/_top.php';
$pdo = db();

// base URL (for installs anywhere)
function base_url(): string
{
	$script = $_SERVER['SCRIPT_NAME'] ?? '';
	$dir = rtrim(dirname($script), '/');
	return $dir === '.' ? '' : $dir;
}
$BASE = base_url();

// groups for dropdown
$groups = $pdo->query("
	SELECT DISTINCT group_title
	FROM channels
	WHERE group_title IS NOT NULL AND group_title <> ''
	ORDER BY group_title
")->fetchAll(PDO::FETCH_COLUMN);
?>

<style>
	div.dataTables_filter {
		display: none;
	}

	div#channelsTable_wrapper {
		padding: 10pt;
	}
</style>

<div class="row g-3">
	<div class="col-lg-3">
		<div class="card shadow-sm">
			<div class="card-header fw-semibold"><i class="fa-solid fa-filter me-1"></i> Filters</div>
			<div class="card-body">

				<label class="form-label small text-muted mb-1">Search (tvg-name or tvg-id)</label>
				<input id="q" class="form-control mb-3" placeholder="OWN, ESPN, dummy-123...">

				<label class="form-label small text-muted mb-1">Group</label>
				<select id="group" class="form-select mb-3">
					<option value="">All groups</option>
					<?php foreach ($groups as $g): ?>
						<option value="<?= h((string)$g) ?>"><?= h((string)$g) ?></option>
					<?php endforeach; ?>
				</select>

				<div class="d-grid gap-2">
					<button id="apply" type="button" class="btn btn-dark">Apply</button>
					<button id="reset" type="button" class="btn btn-outline-secondary">Reset</button>
				</div>

				<div class="text-muted small mt-3">
					Press Enter to search.
				</div>

			</div>
		</div>
	</div>

	<div class="col-lg-9">
		<div class="card shadow-sm">
			<div class="card-header fw-semibold"><i class="fa-solid fa-tv me-1"></i> Channels</div>
			<div class="table-responsive">
				<table id="channelsTable" class="table table-striped table-hover mb-0 align-middle">
					<thead>
						<tr>
							<th style="width:56px;"></th>
							<th>Group</th>
							<th>Channel</th>
							<th>tvg-id</th>
							<th class="text-end">Feeds</th>
							<th>Checked</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<script>
	$(function() {

		const table = $('#channelsTable').DataTable({
			serverSide: true,
			processing: true,
			searching: false,
			pageLength: 50,
			lengthMenu: [
				[25, 50, 100, 250],
				[25, 50, 100, 250]
			],
			order: [
				[2, 'asc']
			],
			dom: "<'row'<'col-sm-12 col-md-4'l><'col-sm-12 col-md-4'f><'col-sm-12 col-md-4'<'d-flex justify-content-end align-items-center gap-2'B>>>" +
				"<'row'<'col-sm-12'tr>>" +
				"<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
			buttons: [{
					extend: 'copy',
					text: '<i class="fa-solid fa-copy me-1"></i> Copy',
					className: 'btn btn-outline-secondary btn-sm',
					exportOptions: {
						columns: [1, 2, 3, 4, 5]
					}
				},
				{
					extend: 'csv',
					text: '<i class="fa-solid fa-file-csv me-1"></i> Export',
					className: 'btn btn-outline-secondary btn-sm',
					exportOptions: {
						columns: [1, 2, 3, 4, 5]
					}
				}
			],
			ajax: {
				url: '<?= h($BASE) ?>/channels_data.php',
				type: 'GET',
				data: function(d) {
					d.q = ($('#q').val() || '').trim();
					d.group = $('#group').val() || '';
				}
			},
			columns: [{
					data: 'logo',
					orderable: false
				},
				{
					data: 'group'
				},
				{
					data: 'name'
				},
				{
					data: 'tvg_id'
				},
				{
					data: 'feeds',
					className: 'text-end'
				},
				{
					data: 'last_checked'
				}
			]
		});

		$('#apply').on('click', function() {
			table.ajax.reload(null, true);
		});

		$('#reset').on('click', function() {
			$('#q').val('');
			$('#group').val('');
			$('#channelsTable_filter input').val('');
			table.ajax.reload(null, true);
		});

		$('#q').on('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				table.ajax.reload(null, true);
			}
		});

		$('#group').on('change', function() {
			table.ajax.reload(null, true);
		});

	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>