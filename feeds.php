<?php

declare(strict_types=1);


$title = 'Feeds';
$currentPage = 'feeds';
require_once __DIR__ . '/_boot.php';

// require login authorization
require_auth();

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

// Extract just the group names for easier handling
$groupNames = array_map(function ($row) {
	return (string)$row['group_title'];
}, $groups);

// Extract prefixes and count groups per prefix
$prefixes = [];
$prefixCounts = [];
foreach ($groupNames as $g) {
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

// preserve last selections (optional, purely UI)
$q = h((string)($_GET['q'] ?? ''));

// Clear saved state if coming from URL parameter
if (!empty($_GET['q'])) {
	echo '<script>localStorage.removeItem("feedsFilters");</script>';
}

?>

<div class="row g-3">
	<!-- Sidebar -->
	<div class="col-lg-3">
		<div class="card shadow-sm">
			<div class="card-header fw-semibold"><i class="fa-solid fa-filter me-1"></i> Filters</div>
			<div class="card-body">

				<label class="form-label small text-muted mb-1">Search (tvg-name or tvg-id)</label>
				<input id="flt_q" class="form-control mb-3" value="<?= $q ?>" placeholder="ESPN, HBO, 12345...">

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
							<?php foreach ($groupNames as $idx => $gName): ?>
								<div class="multiselect-option" data-value="<?= h($gName) ?>">
									<input type="checkbox" class="form-check-input" id="group_<?= $idx ?>">
									<label class="form-check-label flex-grow-1" for="group_<?= $idx ?>"><?= h($gName) ?></label>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<div class="selected-tags" id="selectedTags"></div>
				<input type="hidden" id="flt_group" name="flt_group" value="">
				<div class="mb-3"></div>

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
			<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
				<div class="fw-semibold"><i class="fa-solid fa-tower-broadcast me-1"></i> Feeds</div>

				<div class="d-flex flex-wrap gap-2">
					<button class="btn btn-outline-success btn-sm quick-filter" data-quick="top">Top Feeds</button>
					<button class="btn btn-outline-danger btn-sm quick-filter" data-quick="dead">Dead Feeds</button>
					<button class="btn btn-outline-warning btn-sm quick-filter" data-quick="unstable">Unstable</button>
					<button class="btn btn-outline-secondary btn-sm quick-filter" data-quick="unknown">Never Checked</button>
					<button class="btn btn-outline-primary btn-sm quick-filter" data-quick="recent">Last 24h</button>
					<button class="btn btn-outline-secondary btn-sm quick-filter" data-quick="">All</button>
				</div>
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
	// Prefix counts for display
	const prefixCounts = <?= json_encode($prefixCounts) ?>;

	// Track selected prefixes and individual groups separately
	let selectedPrefixes = [];
	let selectedIndividualGroups = [];

	// Quick filter mode
	let quickMode = '';

	// Load saved filter state
	function loadFilterState() {
		const saved = localStorage.getItem('feedsFilters');
		if (saved) {
			try {
				const state = JSON.parse(saved);
				$('#flt_q').val(state.q || '');
				selectedPrefixes = state.prefixes || [];
				selectedIndividualGroups = state.groups || [];
				$('#hide_ppv').prop('checked', state.hide_ppv || false);
				$('#hide_247').prop('checked', state.hide_247 || false);
				$('#st_ok').prop('checked', state.st_ok !== false);
				$('#st_fail').prop('checked', state.st_fail !== false);
				$('#st_unknown').prop('checked', state.st_unknown !== false);
				$('#q_4k').prop('checked', state.q_4k || false);
				$('#q_fhd').prop('checked', state.q_fhd || false);
				$('#q_hd').prop('checked', state.q_hd || false);
				$('#q_sd').prop('checked', state.q_sd || false);
				quickMode = state.quick || '';
				updateSelectedTags();
				setQuickActive(quickMode);
			} catch (e) {
				console.error('Error loading filter state:', e);
			}
		}
	}

	// Save filter state
	function saveFilterState() {
		const state = {
			q: $('#flt_q').val() || '',
			prefixes: selectedPrefixes,
			groups: selectedIndividualGroups,
			hide_ppv: $('#hide_ppv').is(':checked'),
			hide_247: $('#hide_247').is(':checked'),
			st_ok: $('#st_ok').is(':checked'),
			st_fail: $('#st_fail').is(':checked'),
			st_unknown: $('#st_unknown').is(':checked'),
			q_4k: $('#q_4k').is(':checked'),
			q_fhd: $('#q_fhd').is(':checked'),
			q_hd: $('#q_hd').is(':checked'),
			q_sd: $('#q_sd').is(':checked'),
			quick: quickMode
		};
		localStorage.setItem('feedsFilters', JSON.stringify(state));
	}

	function setQuickActive(mode) {
		$('.quick-filter').removeClass('active btn-dark');
		const $btn = $('.quick-filter[data-quick="' + mode + '"]');
		if ($btn.length) $btn.addClass('btn-dark active');
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

	function getFilters() {
		return {
			q: $('#flt_q').val() || '',
			prefixes: selectedPrefixes,
			groups: selectedIndividualGroups,
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

		// Quick filter button clicks
		$(document).on('click', '.quick-filter', function() {
			quickMode = $(this).data('quick') || '';
			setQuickActive(quickMode);
			saveFilterState();
			table.ajax.reload(null, true);
		});

		// Load saved state BEFORE initializing table
		loadFilterState();

		// Handle group parameter from URL
		const urlParams = new URLSearchParams(window.location.search);
		const groupParam = urlParams.get('group');

		if (groupParam) {
			// Clear saved state
			localStorage.removeItem('feedsFilters');

			// Add group to selected
			selectedIndividualGroups = [groupParam];

			// Update the UI
			updateSelectedTags();
		}

		const table = $('#feedsTable').DataTable({
			serverSide: true,
			processing: true,
			stateSave: true,
			stateDuration: 60 * 60,
			pageLength: 50,
			lengthMenu: [
				[25, 50, 100, 250],
				[25, 50, 100, 250]
			],
			dom: "<'row'<'col-sm-12 col-md-4'l><'col-sm-12 col-md-8'<'d-flex justify-content-end align-items-center gap-2'Bf>>>" +
				"<'row'<'col-sm-12'tr>>" +
				"<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
			buttons: [{
					extend: 'copy',
					text: '<i class="fa-solid fa-copy me-1"></i> Copy',
					className: 'btn btn-outline-secondary btn-sm',
					exportOptions: {
						columns: [0, 1, 2, 3, 4, 5, 6, 7]
					}
				},
				{
					extend: 'csv',
					text: '<i class="fa-solid fa-file-csv me-1"></i> Export CSV',
					className: 'btn btn-outline-secondary btn-sm',
					exportOptions: {
						columns: [0, 1, 2, 3, 4, 5, 6, 7]
					}
				}
			],
			ajax: {
				url: 'feeds_data.php',
				type: 'GET',
				data: function(d) {
					d.filters = getFilters();
					d.quick = quickMode;
				}
			},
			columns: [{
					data: 'status_badge'
				},
				{
					data: 'qual_badge'
				},
				{
					data: 'group_html',
					className: 'text-nowrap'
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
					data: 'last_checked',
					createdCell: function(td, cellData, rowData, row, col) {
						$(td).addClass('text-muted small');
					},
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

		// Apply group filter from URL
		if (groupParam) {
			table.ajax.reload();
			// Clean URL
			window.history.replaceState({}, document.title, window.location.pathname);
		}

		$('#btn_apply').on('click', function() {
			saveFilterState();
			table.ajax.reload(null, true);
		});

		$('#btn_reset').on('click', function() {
			$('#flt_q').val('');
			selectedPrefixes = [];
			selectedIndividualGroups = [];
			$('.multiselect-option').removeClass('selected');
			$('.multiselect-option input[type="checkbox"]').prop('checked', false);
			updateSelectedTags();
			$('#st_ok,#st_fail,#st_unknown').prop('checked', true);
			$('#q_4k,#q_fhd,#q_hd,#q_sd').prop('checked', false);
			$('#hide_ppv,#hide_247').prop('checked', false);
			$('#groupSearch').val('');
			$('.multiselect-option').show();
			quickMode = '';
			setQuickActive('');
			localStorage.removeItem('feedsFilters');
			table.ajax.reload(null, true);
		});

		// convenience: pressing Enter in search applies
		$('#flt_q').on('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				saveFilterState();
				table.ajax.reload(null, true);
			}
		});

		// Handle clicks on group links in the table
		$('#feedsTable').on('click', 'a[data-group]', function(e) {
			e.preventDefault();
			const groupName = $(this).data('group');

			// Clear existing selections and add this group
			selectedPrefixes = [];
			selectedIndividualGroups = [groupName];

			// Update UI
			$('.multiselect-option').removeClass('selected');
			$('.multiselect-option input[type="checkbox"]').prop('checked', false);
			updateSelectedTags();

			// Clear quick mode
			quickMode = '';
			setQuickActive('');

			// Save and reload
			saveFilterState();
			table.ajax.reload(null, true);
		});

		// Initialize
		updateSelectedTags();
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>