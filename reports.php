<?php

declare(strict_types=1);

$title = 'Reports';
$currentPage = 'reports';
require_once __DIR__ . '/_boot.php';

// require login authorization
require_auth();

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

<style>
	div.dataTables_filter {
		display: none;
	}

	div.dataTables_wrapper div.dataTables_filter {
		display: none !important;
	}

	.quick-filter.active {
		pointer-events: none;
	}

	div#reportsTable_wrapper {
		padding: 10pt;
	}

	/* Tab styling */
	.nav-tabs .nav-link {
		color: #6c757d;
	}

	.nav-tabs .nav-link.active {
		color: #0d6efd;
		font-weight: 600;
	}

	/* Group Audit specific styles */
	.audit-card {
		margin-bottom: 1rem;
		border-left: 4px solid #dee2e6;
		transition: all 0.2s;
	}

	.audit-card:hover {
		box-shadow: 0 0.125rem 0.5rem rgba(0, 0, 0, 0.1);
	}

	.audit-card.status-optimal {
		border-left-color: #28a745;
	}

	.audit-card.status-suboptimal {
		border-left-color: #ffc107;
	}

	.audit-card.status-no-data {
		border-left-color: #6c757d;
	}

	.audit-card.status-failed {
		border-left-color: #dc3545;
	}

	.status-indicator {
		width: 12px;
		height: 12px;
		border-radius: 50%;
		display: inline-block;
		margin-right: 0.5rem;
	}

	.status-optimal .status-indicator {
		background-color: #28a745;
	}

	.status-suboptimal .status-indicator {
		background-color: #ffc107;
	}

	.status-no-data .status-indicator {
		background-color: #6c757d;
	}

	.status-failed .status-indicator {
		background-color: #dc3545;
	}

	.alternative-feed {
		background: var(--bs-secondary-bg);
		border-radius: 4px;
		padding: 0.5rem;
		margin-bottom: 0.5rem;
		border: 1px solid var(--bs-border-color);
	}

	.score-badge {
		font-size: 0.875rem;
		font-weight: 600;
		padding: 0.25rem 0.5rem;
	}

	#audit-loading {
		text-align: center;
		padding: 3rem;
		display: none;
	}

	#audit_summary {
		font-size: 13pt;
	}

	#reportsTabs li button {
		font-size: 14pt;
		color: var(--bs-card-cap-color);
	}
</style>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-3" id="reportsTabs" role="tablist">
	<li class="nav-item" role="presentation">
		<button class="nav-link active" id="feed-report-tab" data-bs-toggle="tab" data-bs-target="#feed-report" type="button" role="tab">
			<i class="fa-solid fa-ranking-star me-1"></i> Feed Report
		</button>
	</li>
	<li class="nav-item" role="presentation">
		<button class="nav-link" id="group-audit-tab" data-bs-toggle="tab" data-bs-target="#group-audit" type="button" role="tab">
			<i class="fa-solid fa-list-check me-1"></i> Group Audit
		</button>
	</li>
</ul>

<!-- Tab Content -->
<div class="tab-content" id="reportsTabContent">
	<!-- FEED REPORT TAB (Original) -->
	<div class="tab-pane fade show active" id="feed-report" role="tabpanel">
		<div class="row g-3">
			<div class="col-lg-3">
				<div class="card shadow-sm">
					<div class="card-header fw-semibold"><i class="fa-solid fa-filter me-1"></i> Filters</div>
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
						<div class="fw-semibold"><i class="fa-solid fa-ranking-star me-1"></i> Feed Report</div>

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
	</div>

	<!-- GROUP AUDIT TAB (New) -->
	<div class="tab-pane fade" id="group-audit" role="tabpanel">
		<div class="row g-3">
			<div class="col-lg-3">
				<div class="card shadow-sm mb-3">
					<div class="card-header fw-semibold"><i class="fa-solid fa-sliders me-1"></i> Audit Settings</div>
					<div class="card-body">
						<label class="form-label small text-muted mb-1">Select Group to Audit</label>
						<select id="audit_group" class="form-select mb-3">
							<option value="">-- Select a group --</option>
							<?php foreach ($groups as $gRow): $gName = (string)$gRow['group_title']; ?>
								<option value="<?= h($gName) ?>"><?= h($gName) ?></option>
							<?php endforeach; ?>
						</select>

						<label class="form-label small text-muted mb-1">Date Range Filter</label>
						<select id="audit_date_range" class="form-select mb-2">
							<option value="all">All Time</option>
							<option value="7">Last 7 Days</option>
							<option value="30" selected>Last 30 Days</option>
							<option value="90">Last 90 Days</option>
							<option value="custom">Custom Range</option>
						</select>

						<div id="custom_date_range" style="display:none;">
							<label class="form-label small text-muted mb-1">From</label>
							<input type="date" id="audit_date_from" class="form-control form-control-sm mb-2">
							<label class="form-label small text-muted mb-1">To</label>
							<input type="date" id="audit_date_to" class="form-control form-control-sm mb-3">
						</div>

						<div class="d-grid gap-2 mb-3">
							<button id="btn_run_audit" class="btn btn-dark" type="button">
								<i class="fa-solid fa-play me-1"></i> Run Audit
							</button>
						</div>

						<div class="alert alert-info small mb-0">
							<i class="fa-solid fa-circle-info me-1"></i>
							This will analyze all channels in the selected group and identify better feed alternatives based on historical data.
						</div>
					</div>
				</div>

				<div class="card shadow-sm">
					<div class="card-header fw-semibold d-flex justify-content-between align-items-center">
						<span><i class="fa-solid fa-eye-slash me-1"></i> Ignored Feeds</span>
						<button id="btn_manage_ignores" class="btn btn-sm btn-outline-secondary" type="button">
							Manage
						</button>
					</div>
					<div class="card-body">
						<div id="ignored_count_display" class="text-muted small">
							No ignored feeds for current group
						</div>
					</div>
				</div>
			</div>

			<div class="col-lg-9">
				<div class="card shadow-sm">
					<div class="card-header fw-semibold">
						<i class="fa-solid fa-list-check me-1"></i> Audit Results
						<span id="audit_summary" class="text-muted small ms-2"></span>
					</div>
					<div class="card-body">
						<div id="audit-loading">
							<div class="spinner-border text-primary" role="status">
								<span class="visually-hidden">Loading...</span>
							</div>
							<div class="mt-2 text-muted">Running audit...</div>
						</div>

						<div id="audit-results"></div>

						<div id="audit-empty" style="display:none;" class="text-center text-muted py-5">
							<i class="fa-solid fa-magnifying-glass fa-3x mb-3 opacity-25"></i>
							<p>Select a group and click "Run Audit" to begin analysis.</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- Ignores Management Modal -->
<div class="modal fade" id="ignoresModal" tabindex="-1">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="fa-solid fa-eye-slash me-2"></i>Manage Ignored Recommendations</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<div id="ignores_list"></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-danger" id="btn_clear_all_ignores">
					<i class="fa-solid fa-trash me-1"></i> Clear All Ignores
				</button>
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>


<script>
	// FEED REPORT 
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
			// ADD THESE:
			dom: "<'row'<'col-sm-12 col-md-4'l><'col-sm-12 col-md-8'<'d-flex justify-content-end align-items-center gap-2'Bf>>>" +
				"<'row'<'col-sm-12'tr>>" +
				"<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
			buttons: [{
					extend: 'copy',
					text: '<i class="fa-solid fa-copy me-1"></i> Copy',
					className: 'btn btn-outline-secondary btn-sm',
					exportOptions: {
						columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]
					}
				},
				{
					extend: 'csv',
					text: '<i class="fa-solid fa-file-csv me-1"></i> Export CSV',
					className: 'btn btn-outline-secondary btn-sm',
					exportOptions: {
						columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]
					}
				}
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

	// GROUP AUDIT 
	let currentAuditGroup = '';

	// Show/hide custom date range
	$('#audit_date_range').on('change', function() {
		if ($(this).val() === 'custom') {
			$('#custom_date_range').slideDown();
		} else {
			$('#custom_date_range').slideUp();
		}
	});

	// Update ignored count when group changes
	$('#audit_group').on('change', function() {
		updateIgnoredCount();
	});

	// Run audit
	$('#btn_run_audit').on('click', function() {
		const group = $('#audit_group').val();
		if (!group) {
			alert('Please select a group to audit');
			return;
		}

		currentAuditGroup = group;

		const dateRange = $('#audit_date_range').val();
		const customFrom = $('#audit_date_from').val();
		const customTo = $('#audit_date_to').val();

		// Show loading
		$('#audit-loading').show();
		$('#audit-results').empty();
		$('#audit-empty').hide();

		// Make API request
		$.ajax({
			url: '<?= h($BASE) ?>/reports_group_audit_data.php',
			type: 'GET',
			data: {
				group: group,
				date_range: dateRange,
				custom_from: customFrom,
				custom_to: customTo
			},
			success: function(response) {
				$('#audit-loading').hide();

				if (response.error) {
					$('#audit-results').html('<div class="alert alert-danger">' + response.error + '</div>');
					return;
				}

				displayAuditResults(response);
				updateIgnoredCount();
			},
			error: function(xhr, status, error) {
				$('#audit-loading').hide();
				$('#audit-results').html('<div class="alert alert-danger">Error running audit: ' + error + '</div>');
			}
		});
	});

	function displayAuditResults(data) {
		const channels = data.channels || [];
		let html = '';

		// Summary
		const optimal = channels.filter(c => c.status === 'optimal').length;
		const suboptimal = channels.filter(c => c.status === 'suboptimal').length;
		const failed = channels.filter(c => c.status === 'failed').length;
		const noData = channels.filter(c => c.status === 'no_data').length;

		$('#audit_summary').html(`
			<span class="badge bg-success">${optimal} Optimal</span>
			<span class="badge bg-warning text-dark">${suboptimal} Needs Review</span>
			<span class="badge bg-danger">${failed} Failed</span>
			<span class="badge bg-secondary">${noData} No Data</span>
		`);

		if (channels.length === 0) {
			$('#audit-results').html('<div class="alert alert-info">No channels found in this group with check history in the selected date range.</div>');
			return;
		}

		// Build results cards
		channels.forEach(channel => {
			html += buildChannelCard(channel, data.group);
		});

		$('#audit-results').html(html);
	}

	function buildChannelCard(channel, sourceGroup) {
		const statusClass = 'status-' + channel.status;
		const statusText = channel.status === 'optimal' ? 'Using Best Feed' :
			channel.status === 'suboptimal' ? 'Better Feeds Available' :
			channel.status === 'failed' ? 'Feed Failed' : 'No Data';

		let currentFeedHtml = '';
		if (channel.current_feed_id) {
			currentFeedHtml = `
				<div class="d-flex align-items-center gap-2 mb-2">
					<span class="badge bg-primary">Score: ${channel.current_score}/100</span>
					${channel.current_class_badge}
					<span class="text-muted small">Rel: ${channel.current_reliability}% • Res: ${channel.current_res_display} • FPS: ${channel.current_fps} • Checks: ${channel.current_check_count}</span>
					<a href="feed_history.php?feed_id=${channel.current_feed_id}" class="btn btn-sm btn-outline-primary" target="_blank" title="View current feed history" style="font-size: 0.75rem; padding: 0.15rem 0.35rem;">
						<i class="fa-solid fa-clock-rotate-left"></i> History
					</a>
					<button class="btn btn-sm btn-outline-success btn-preview" data-feed-id="${channel.current_feed_id}" title="Preview Stream" style="font-size: 0.75rem; padding: 0.15rem 0.35rem;">
						<i class="fa-solid fa-play"></i> Preview
					</button>
				</div>
			`;
		}

		let alternativesHtml = '';
		if (channel.alternatives && channel.alternatives.length > 0) {
			alternativesHtml = '<div class="mt-2"><strong class="small text-muted">RECOMMENDED ALTERNATIVES:</strong>';
			channel.alternatives.forEach(alt => {
				alternativesHtml += `
					<div class="alternative-feed d-flex justify-content-between align-items-center">
						<div class="flex-grow-1">
							<strong>${alt.group}</strong>
							<span class="text-muted small ms-2">(${alt.tvg_name})</span>
							<a href="feed_history.php?feed_id=${alt.feed_id}" class="btn btn-sm btn-outline-primary ms-2" target="_blank" title="View feed history" style="font-size: 0.75rem; padding: 0.15rem 0.35rem;">
								<i class="fa-solid fa-clock-rotate-left"></i>
							</a>
							<button class="btn btn-sm btn-outline-success btn-preview ms-1" data-feed-id="${alt.feed_id}" title="Preview Stream" style="font-size: 0.75rem; padding: 0.15rem 0.35rem;">
								<i class="fa-solid fa-play"></i>
							</button>
							<div class="text-muted small">
								Score: <strong>${alt.score}/100</strong> •
								Rel: ${alt.reliability}% • 
								${alt.class_badge} (${alt.res_display}) •
								FPS: ${alt.fps} •
								${alt.check_count} checks
							</div>
						</div>
						<button class="btn btn-sm btn-outline-secondary btn-ignore" 
							data-tvg-id="${channel.tvg_id}"
							data-source-group="${sourceGroup}"
							data-suggested-group="${alt.group}"
							data-suggested-feed-id="${alt.feed_id}">
							<i class="fa-solid fa-eye-slash"></i> Ignore
						</button>
					</div>
				`;
			});
			alternativesHtml += '</div>';
		}

		return `
			<div class="card audit-card ${statusClass}">
				<div class="card-body">
					<div class="d-flex justify-content-between align-items-start">
						<div class="flex-grow-1">
							<h6 class="mb-1">
								<span class="status-indicator"></span>
								${channel.tvg_name}
								<span class="badge bg-light text-dark ms-2">${channel.tvg_id}</span>
							</h6>
							<div class="text-muted small mb-2">${statusText}</div>
							${currentFeedHtml}
							${alternativesHtml}
						</div>
						${channel.tvg_logo ? `<img src="${channel.tvg_logo}" style="width:40px;height:40px;object-fit:contain;" loading="lazy">` : ''}
					</div>
				</div>
			</div>
		`;
	}

	// Handle ignore button clicks
	$(document).on('click', '.btn-ignore', function() {
		const btn = $(this);
		const data = {
			action: 'add',
			tvg_id: btn.data('tvg-id'),
			source_group: btn.data('source-group'),
			suggested_group: btn.data('suggested-group'),
			suggested_feed_id: btn.data('suggested-feed-id')
		};

		$.ajax({
			url: '<?= h($BASE) ?>/reports_group_audit_ignores.php',
			type: 'POST',
			data: data,
			success: function(response) {
				if (response.success) {
					// Remove the alternative from display
					btn.closest('.alternative-feed').fadeOut(300, function() {
						$(this).remove();
						// If no more alternatives, update status
						const card = btn.closest('.audit-card');
						const remainingAlts = card.find('.alternative-feed').length;
						if (remainingAlts === 0) {
							card.removeClass('status-suboptimal').addClass('status-optimal');
							card.find('.text-muted.small:first').text('Using Best Feed');
						}
					});
					updateIgnoredCount();
				} else {
					alert('Error: ' + response.error);
				}
			},
			error: function() {
				alert('Failed to add ignore');
			}
		});
	});

	// Manage ignores modal
	$('#btn_manage_ignores').on('click', function() {
		loadIgnores();
		$('#ignoresModal').modal('show');
	});

	function loadIgnores() {
		const group = currentAuditGroup || $('#audit_group').val() || '';
		$.ajax({
			url: '<?= h($BASE) ?>/reports_group_audit_ignores.php',
			type: 'GET',
			data: {
				action: 'list',
				source_group: group
			},
			success: function(response) {
				if (response.success) {
					displayIgnores(response.ignores);
				} else {
					$('#ignores_list').html('<div class="alert alert-danger">' + response.error + '</div>');
				}
			},
			error: function() {
				$('#ignores_list').html('<div class="alert alert-danger">Failed to load ignores</div>');
			}
		});
	}

	function displayIgnores(ignores) {
		if (ignores.length === 0) {
			$('#ignores_list').html('<div class="text-muted text-center py-4">No ignored feeds</div>');
			return;
		}

		let html = '';
		ignores.forEach(ignore => {
			html += `
				<div class="ignored-item">
					<div class="alternative-feed d-flex justify-content-between align-items-center">
						<div>
							<strong>${ignore.tvg_name || ignore.tvg_id}</strong>
							<div class="text-muted small">
								Ignoring: <strong>${ignore.suggested_group}</strong> 
								<span class="text-muted">• Added ${new Date(ignore.created_at).toLocaleDateString()}</span>
							</div>
						</div>
						<button class="btn btn-sm btn-outline-danger btn-unignore" data-id="${ignore.id}">
							<i class="fa-solid fa-trash"></i> Remove
						</button>
					</div>
				</div>
			`;
		});

		$('#ignores_list').html(html);
	}

	// Unignore individual item
	$(document).on('click', '.btn-unignore', function() {
		const id = $(this).data('id');
		const btn = $(this);

		if (!confirm('Remove this ignore?')) return;

		$.ajax({
			url: '<?= h($BASE) ?>/reports_group_audit_ignores.php',
			type: 'POST',
			data: {
				action: 'delete',
				id: id
			},
			success: function(response) {
				if (response.success) {
					btn.closest('.ignored-item').fadeOut(300, function() {
						$(this).remove();
						if ($('.ignored-item').length === 0) {
							$('#ignores_list').html('<div class="text-muted text-center py-4">No ignored feeds</div>');
						}
					});
					updateIgnoredCount();
					// Re-run audit to show the alternative again
					if (currentAuditGroup) {
						$('#btn_run_audit').click();
					}
				} else {
					alert('Error: ' + response.error);
				}
			},
			error: function() {
				alert('Failed to remove ignore');
			}
		});
	});

	// Clear all ignores
	$('#btn_clear_all_ignores').on('click', function() {
		if (!confirm('Clear ALL ignored feeds? This cannot be undone.')) return;

		$.ajax({
			url: '<?= h($BASE) ?>/reports_group_audit_ignores.php',
			type: 'POST',
			data: {
				action: 'clear_all'
			},
			success: function(response) {
				if (response.success) {
					$('#ignores_list').html('<div class="text-muted text-center py-4">No ignored feeds</div>');
					updateIgnoredCount();
					// Re-run audit
					if (currentAuditGroup) {
						$('#btn_run_audit').click();
					}
				} else {
					alert('Error: ' + response.error);
				}
			},
			error: function() {
				alert('Failed to clear ignores');
			}
		});
	});

	function updateIgnoredCount() {
		const group = currentAuditGroup || $('#audit_group').val();
		if (!group) return;

		$.ajax({
			url: '<?= h($BASE) ?>/reports_group_audit_ignores.php',
			type: 'GET',
			data: {
				action: 'list',
				source_group: group
			},
			success: function(response) {
				if (response.success) {
					const count = response.ignores.length;
					if (count === 0) {
						$('#ignored_count_display').text('No ignored feeds for current group');
					} else {
						$('#ignored_count_display').html(`<strong>${count}</strong> ignored feed${count > 1 ? 's' : ''} for this group`);
					}
				}
			}
		});
	}

	// Show empty state initially
	$(document).ready(function() {
		$('#audit-empty').show();

		// Update ignored count when switching to Group Audit tab
		$('#group-audit-tab').on('shown.bs.tab', function() {
			updateIgnoredCount();
		});
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>