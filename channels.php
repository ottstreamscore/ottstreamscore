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

// Extract prefixes and count groups per prefix
$prefixes = [];
$prefixCounts = [];
foreach ($groups as $g) {
	if (strpos($g, '|') !== false) {
		$prefix = substr($g, 0, strpos($g, '|') + 1);
		if (!isset($prefixCounts[$prefix])) {
			$prefixes[] = $prefix;
			$prefixCounts[$prefix] = 0;
		}
		$prefixCounts[$prefix]++;
	}
}
sort($prefixes);
?>

<div class="row g-3">
	<div class="col-lg-3">
		<div class="card shadow-sm">
			<div class="card-header fw-semibold"><i class="fa-solid fa-filter me-1"></i> Filters</div>
			<div class="card-body">

				<label class="form-label small text-muted mb-1">Search (tvg-name or tvg-id)</label>
				<input id="q" class="form-control mb-3" placeholder="OWN, ESPN, dummy-123...">

				<label class="form-label small text-muted mb-1">Groups</label>
				<div class="custom-multiselect">
					<div class="multiselect-trigger form-control" id="groupTrigger">
						<span class="multiselect-placeholder">Select groups...</span>
					</div>
					<div class="multiselect-dropdown border rounded shadow-sm bg-body" id="groupDropdown">
						<div class="multiselect-search">
							<input type="text" class="form-control form-control-sm" id="groupSearch" placeholder="Search groups...">
						</div>

						<?php if (!empty($prefixes)): ?>
							<div class="multiselect-prefixes">
								<div class="px-2 py-2">
									<details>
										<summary class="small text-muted mb-1">Quick Select Prefixes (<?= count($prefixes) ?>)</summary>
										<div class="d-flex flex-wrap gap-1 mt-2">
											<?php foreach ($prefixes as $prefix): ?>
												<button type="button" class="btn btn-sm btn-outline-primary prefix-btn"
													data-prefix="<?= h($prefix) ?>"
													data-count="<?= $prefixCounts[$prefix] ?>">
													<?= h($prefix) ?> (<?= $prefixCounts[$prefix] ?>)
												</button>
											<?php endforeach; ?>
										</div>
									</details>
								</div>
							</div>
						<?php endif; ?>

						<div class="multiselect-options" id="groupOptions">
							<?php foreach ($groups as $idx => $g): ?>
								<div class="multiselect-option" data-value="<?= h((string)$g) ?>">
									<input type="checkbox" class="form-check-input" id="group_<?= $idx ?>">
									<label class="form-check-label flex-grow-1" for="group_<?= $idx ?>"><?= h((string)$g) ?></label>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<div class="selected-tags" id="selectedTags"></div>
				<input type="hidden" id="group" name="group" value="">
				<div class="mb-3"></div>

				<label class="form-label small text-muted mb-1">Hide</label>
				<div class="form-check mb-2">
					<input class="form-check-input" type="checkbox" id="hidePPV">
					<label class="form-check-label" for="hidePPV">
						PPV
					</label>
				</div>
				<div class="form-check mb-3">
					<input class="form-check-input" type="checkbox" id="hide247">
					<label class="form-check-label" for="hide247">
						24/7
					</label>
				</div>

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
	// Prefix counts for display
	const prefixCounts = <?= json_encode($prefixCounts) ?>;

	$(function() {

		// Track selected prefixes and individual groups separately
		let selectedPrefixes = [];
		let selectedIndividualGroups = [];

		// Load saved filter state
		function loadFilterState() {
			const saved = localStorage.getItem('channelsFilters');
			if (saved) {
				try {
					const state = JSON.parse(saved);
					$('#q').val(state.q || '');
					selectedPrefixes = state.prefixes || [];
					selectedIndividualGroups = state.groups || [];
					$('#hidePPV').prop('checked', state.hide_ppv || false);
					$('#hide247').prop('checked', state.hide_247 || false);
					updateSelectedTags();
				} catch (e) {
					console.error('Error loading filter state:', e);
				}
			}
		}

		// Save filter state
		function saveFilterState() {
			const state = {
				q: $('#q').val() || '',
				prefixes: selectedPrefixes,
				groups: selectedIndividualGroups,
				hide_ppv: $('#hidePPV').is(':checked'),
				hide_247: $('#hide247').is(':checked')
			};
			localStorage.setItem('channelsFilters', JSON.stringify(state));
		}

		function updateSelectedTags() {
			const container = $('#selectedTags');
			const trigger = $('#groupTrigger');

			container.empty();

			const totalCount = selectedPrefixes.length + selectedIndividualGroups.length;

			if (totalCount === 0) {
				trigger.html('<span class="multiselect-placeholder">Select groups...</span>');
				return;
			}

			// Update trigger text
			trigger.html(`<span>${totalCount} selection(s)</span>`);

			// Show prefix tags
			selectedPrefixes.forEach(function(prefix) {
				const count = prefixCounts[prefix] || 0;
				const tag = $('<div class="selected-tag badge bg-primary"></div>')
					.text(prefix + ' (' + count + ' groups)')
					.append('<span class="remove-tag">×</span>');

				tag.find('.remove-tag').on('click', function(e) {
					e.stopPropagation();
					removePrefix(prefix);
				});

				container.append(tag);
			});

			// Show individual group tags and mark checkboxes
			selectedIndividualGroups.forEach(function(group) {
				// Mark the checkbox and option as selected
				$('.multiselect-option[data-value="' + group + '"]').addClass('selected');
				$('.multiselect-option[data-value="' + group + '"] input[type="checkbox"]').prop('checked', true);

				// Create the tag
				const tag = $('<div class="selected-tag badge bg-secondary"></div>')
					.text(group)
					.append('<span class="remove-tag">×</span>');

				tag.find('.remove-tag').on('click', function(e) {
					e.stopPropagation();
					removeIndividualGroup(group);
				});

				container.append(tag);
			});
		}

		function addPrefix(prefix) {
			if (!selectedPrefixes.includes(prefix)) {
				selectedPrefixes.push(prefix);
				updateSelectedTags();
			}
		}

		function removePrefix(prefix) {
			selectedPrefixes = selectedPrefixes.filter(p => p !== prefix);
			updateSelectedTags();
		}

		function addIndividualGroup(group) {
			if (!selectedIndividualGroups.includes(group)) {
				selectedIndividualGroups.push(group);
				$('.multiselect-option[data-value="' + group + '"]').addClass('selected');
				$('.multiselect-option[data-value="' + group + '"] input[type="checkbox"]').prop('checked', true);
				updateSelectedTags();
			}
		}

		function removeIndividualGroup(group) {
			selectedIndividualGroups = selectedIndividualGroups.filter(g => g !== group);
			$('.multiselect-option[data-value="' + group + '"]').removeClass('selected');
			$('.multiselect-option[data-value="' + group + '"] input[type="checkbox"]').prop('checked', false);
			updateSelectedTags();
		}

		function toggleIndividualGroup(group) {
			if (selectedIndividualGroups.includes(group)) {
				removeIndividualGroup(group);
			} else {
				addIndividualGroup(group);
			}
		}

		// Toggle dropdown
		$('#groupTrigger').on('click', function(e) {
			e.stopPropagation();
			$('#groupDropdown').toggleClass('show');
			if ($('#groupDropdown').hasClass('show')) {
				$('#groupSearch').focus();
			}
		});

		// Prevent dropdown from closing when clicking inside
		$('#groupDropdown').on('click', function(e) {
			e.stopPropagation();
		});

		// Close dropdown when clicking outside
		$(document).on('click', function() {
			$('#groupDropdown').removeClass('show');
		});

		// Handle option clicks
		$('.multiselect-option').on('click', function() {
			const value = $(this).data('value');
			toggleIndividualGroup(value);
		});

		// Handle checkbox clicks (prevent double toggle)
		$('.multiselect-option input[type="checkbox"]').on('click', function(e) {
			e.stopPropagation();
			const value = $(this).closest('.multiselect-option').data('value');
			toggleIndividualGroup(value);
		});

		// Search functionality
		$('#groupSearch').on('input', function() {
			const searchTerm = $(this).val().toLowerCase();
			$('.multiselect-option').each(function() {
				const text = $(this).text().toLowerCase();
				if (text.includes(searchTerm)) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		});

		// Prefix button clicks - add prefix to selection
		$('.prefix-btn').on('click', function(e) {
			e.stopPropagation();
			const prefix = $(this).data('prefix');
			addPrefix(prefix);
		});

		// Load saved state BEFORE initializing table
		loadFilterState();

		// DataTable initialization
		const table = $('#channelsTable').DataTable({
			serverSide: true,
			processing: true,
			stateSave: true,
			stateDuration: 60 * 60,
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
					d.prefixes = selectedPrefixes;
					d.groups = selectedIndividualGroups;
					d.hide_ppv = $('#hidePPV').is(':checked') ? '1' : '0';
					d.hide_247 = $('#hide247').is(':checked') ? '1' : '0';
				}
			},
			columns: [{
					data: 'logo',
					orderable: false
				},
				{
					data: 'group',
					className: 'text-nowrap'
				},
				{
					data: 'name'
				},
				{
					data: 'tvg_id',
					className: 'text-nowrap'
				},
				{
					data: 'feeds',
					className: 'text-end'
				},
				{
					data: 'last_checked',
					createdCell: function(td, cellData, rowData, row, col) {
						$(td).addClass('text-muted small');
					},
				},
			]
		});

		$('#apply').on('click', function() {
			saveFilterState();
			table.ajax.reload(null, true);
		});

		$('#reset').on('click', function() {
			$('#q').val('');
			selectedPrefixes = [];
			selectedIndividualGroups = [];
			$('.multiselect-option').removeClass('selected');
			$('.multiselect-option input[type="checkbox"]').prop('checked', false);
			updateSelectedTags();
			$('#hidePPV').prop('checked', false);
			$('#hide247').prop('checked', false);
			$('#channelsTable_filter input').val('');
			$('#groupSearch').val('');
			$('.multiselect-option').show();
			localStorage.removeItem('channelsFilters');
			table.ajax.reload(null, true);
		});

		$('#q').on('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				saveFilterState();
				table.ajax.reload(null, true);
			}
		});

		// Handle clicks on group links in the table
		$('#channelsTable').on('click', 'a[data-group]', function(e) {
			e.preventDefault();
			const groupName = $(this).data('group');

			// Clear existing selections and add this group
			selectedPrefixes = [];
			selectedIndividualGroups = [groupName];

			// Update UI
			$('.multiselect-option').removeClass('selected');
			$('.multiselect-option input[type="checkbox"]').prop('checked', false);
			updateSelectedTags();

			// Save and reload
			saveFilterState();
			table.ajax.reload(null, true);
		});

	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>