<?php

declare(strict_types=1);

$title = 'Group Audit';
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

<div class="row g-3">
	<div class="col-lg-3">
		<div class="card shadow-sm mb-3">
			<div class="card-header fw-semibold"><i class="fa-solid fa-sliders me-1"></i> Report Settings</div>
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
				<i class="fa-solid fa-list-check me-1"></i> Report Results
				<span id="audit_summary" class="text-muted small ms-2"></span>
			</div>
			<div class="card-body">
				<div id="audit-loading" style="display:none; text-align:center !important;">
					<div class="spinner-border text-primary" role="status">
						<span class="visually-hidden">Loading...</span>
					</div>
					<div style="font-size:13pt;" class="mt-2 text-muted">Running audit...</div>
				</div>

				<div id="audit-results"></div>

				<div id="audit-empty" class="text-center text-muted py-5">
					<i class="fa-solid fa-magnifying-glass fa-3x mb-3 opacity-25"></i>
					<p>Select a group and click "Run Audit" to begin analysis.</p>
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
	let currentAuditGroup = '';

	// Escape ID for use in HTML IDs and selectors
	function escapeId(id) {
		return id.replace(/[^a-zA-Z0-9_-]/g, '_');
	}

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
			<span class="badge bg-success me-1">${optimal} Optimal</span>
			<span class="badge bg-warning text-dark me-1">${suboptimal} Needs Review</span>
			<span class="badge bg-danger me-1">${failed} Failed</span>
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

		// Logo image (right side of header)
		let logoHtml = '';
		if (channel.tvg_logo && channel.tvg_logo.trim() !== '') {
			logoHtml = `<img src="${channel.tvg_logo}" alt="${channel.tvg_name}" style="max-height: 30px; max-width: 100%;" onerror="this.style.display='none'">`;
		}

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
			alternativesHtml = '<div class="mt-4 mb-3"><strong class="small text-muted d-block mb-2">RECOMMENDED ALTERNATIVES:</strong>';
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
						<button type="button" class="btn btn-sm btn-outline-info btn-create-task me-2" 
							data-feed-id="${channel.current_feed_id}"
							data-group="${sourceGroup}"
							data-suggested-feed-id="${alt.feed_id}"
							data-suggested-group="${alt.group}"
							data-from-reports="1"
							style="padding: 0.25rem 0.5rem; font-size: 0.875rem;"
							title="Create task">
							<i class="fa-solid fa-list-check"></i> Add Task
						</button>
						<button class="btn btn-sm btn-outline-secondary btn-ignore" 
							data-tvg-id="${channel.tvg_id}"
							data-source-group="${sourceGroup}"
							data-suggested-group="${alt.group}"
							data-suggested-feed-id="${alt.feed_id}"
							data-suggested-tvg-name="${alt.tvg_name}">
							<i class="fa-solid fa-eye-slash"></i> Ignore
						</button>
					</div>
				`;
			});
			alternativesHtml += '</div>';
		}

		// Build association matches section
		let associationHtml = '';
		const associationCount = channel.association_matches ? channel.association_matches.length : 0;

		// Only show association section if there are regular alternatives AND association matches exist
		if (channel.alternatives && channel.alternatives.length > 0 && associationCount > 0) {
			const escapedId = escapeId(channel.tvg_id);

			associationHtml = `
			<div class="association-toggle" onclick="toggleAssociations('${escapedId}')" style="cursor: pointer;">
				<span class="text-warning">
					<i class="fa-solid fa-diagram-project me-2"></i>
					<strong class="small">Association Matches</strong>
					<span class="badge bg-secondary ms-2">${associationCount}</span>
				</span>
				<i class="fa-solid fa-chevron-down ms-2"></i>
			</div>
		`;

			associationHtml += `<div class="association-matches-container" id="assoc-${escapedId}">`;

			// Warning message
			associationHtml += `
				<div class="alert alert-warning alert-sm mt-3 mb-3" style="font-size: 0.85rem; padding: 0.5rem 0.75rem;">
					<i class="fa-solid fa-triangle-exclamation me-2"></i>
					These matches are generated from your group associations based on tvg-id similarity. Verify stream content before use.
				</div>
			`;

			// Group by association name
			const groupedMatches = {};
			channel.association_matches.forEach(match => {
				if (!groupedMatches[match.association_name]) {
					groupedMatches[match.association_name] = [];
				}
				groupedMatches[match.association_name].push(match);
			});

			// Render grouped matches
			for (const [assocName, matches] of Object.entries(groupedMatches)) {
				associationHtml += `
					<div class="mt-3 mb-4">
						<div class="d-flex align-items-center mb-2">
							<span class="association-badge me-2">${assocName}</span>
							<span class="text-muted small">${matches.length} match${matches.length > 1 ? 'es' : ''}</span>
						</div>
				`;

				matches.forEach(match => {
					associationHtml += `
						<div class="association-feed d-flex justify-content-between align-items-center">
							<div class="flex-grow-1">
								<strong>${match.group}</strong>
								<span class="text-muted small ms-2">(${match.tvg_name})</span>
								<a href="feed_history.php?feed_id=${match.feed_id}" class="btn btn-sm btn-outline-primary ms-2" target="_blank" title="View feed history" style="font-size: 0.75rem; padding: 0.15rem 0.35rem;">
									<i class="fa-solid fa-clock-rotate-left"></i>
								</a>
								<button class="btn btn-sm btn-outline-success btn-preview ms-1" data-feed-id="${match.feed_id}" title="Preview Stream" style="font-size: 0.75rem; padding: 0.15rem 0.35rem;">
									<i class="fa-solid fa-play"></i>
								</button>
								<div class="text-muted small">
									Score: <strong>${match.score}/100</strong> •
									Rel: ${match.reliability}% •
									${match.class_badge} (${match.res_display}) •
									FPS: ${match.fps} •
									${match.check_count} checks
								</div>
							</div>
							<button type="button" class="btn btn-sm btn-outline-info btn-create-task me-2" 
								data-feed-id="${channel.current_feed_id}"
								data-group="${sourceGroup}"
								data-suggested-feed-id="${match.feed_id}"
								data-suggested-group="${match.group}"
								data-from-reports="1"
								style="padding: 0.25rem 0.5rem; font-size: 0.875rem;"
								title="Create task">
								<i class="fa-solid fa-list-check"></i> Add Task
							</button>
							<button class="btn btn-sm btn-outline-secondary btn-ignore"
								data-tvg-id="${channel.tvg_id}"
								data-source-group="${sourceGroup}"
								data-suggested-group="${match.group}"
								data-suggested-feed-id="${match.feed_id}"
								data-suggested-tvg-name="${match.tvg_name}">
								<i class="fa-solid fa-eye-slash"></i> Ignore
							</button>
						</div>
					`;
				});

				associationHtml += `</div>`;
			}

			associationHtml += `</div>`;
		}

		return `
			<div class="card audit-card ${statusClass} mb-3">
				<div class="card-body">
					<div class="d-flex justify-content-between align-items-start mb-2">
						<div>
							<h6 class="card-title mb-1">
								<span class="status-indicator"></span>
								${channel.tvg_name}
								<span class="badge bg-secondary ms-2">${channel.tvg_id}</span>
							</h6>
							<div class="text-muted small">${statusText}</div>
						</div>
						${logoHtml}
					</div>
					${currentFeedHtml}
					${alternativesHtml}
					${associationHtml}
				</div>
			</div>
		`;
	}

	function toggleAssociations(channelId) {
		const container = $('#assoc-' + channelId);
		const toggle = container.prev('.association-toggle');
		const icon = toggle.find('.fa-chevron-down, .fa-chevron-up');

		container.slideToggle(200);
		icon.toggleClass('fa-chevron-down fa-chevron-up');
	}

	// Handle ignore button clicks
	$(document).on('click', '.btn-ignore', function() {
		const btn = $(this);
		const data = {
			action: 'add',
			tvg_id: btn.data('tvg-id'),
			source_group: btn.data('source-group'),
			suggested_feed_id: btn.data('suggested-feed-id'),
			suggested_group: btn.data('suggested-group'),
			suggested_tvg_name: btn.data('suggested-tvg-name')
		};

		$.ajax({
			url: '<?= h($BASE) ?>/reports_group_audit_ignores.php',
			type: 'POST',
			data: data,
			success: function(response) {
				if (response.success) {
					// Determine if this is an association feed or regular alternative
					const feedDiv = btn.closest('.alternative-feed, .association-feed');
					const isAssociation = feedDiv.hasClass('association-feed');

					feedDiv.fadeOut(300, function() {
						const card = btn.closest('.audit-card');

						// Handle association match removal
						if (isAssociation) {
							const assocContainer = feedDiv.closest('.association-matches-container');
							const toggle = assocContainer.prev('.association-toggle');

							$(this).remove();
							const remainingAssocCount = assocContainer.find('.association-feed').length;
							toggle.find('.badge').text(remainingAssocCount);

							// If no more association matches, hide entire section
							if (remainingAssocCount === 0) {
								toggle.fadeOut(200);
								assocContainer.fadeOut(200);
							}
						} else {
							$(this).remove();
						}

						// Check if card should be marked optimal
						const remainingAlts = card.find('.alternative-feed').length;
						const remainingAssoc = card.find('.association-feed').length;

						if (remainingAlts === 0 && remainingAssoc === 0) {
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
							<strong>${ignore.suggested_tvg_name || ignore.tvg_name || ignore.tvg_id}</strong>
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

	// Task creation modal functionality for reports page
	$(document).on('click', '.btn-create-task', function() {
		const $btn = $(this);
		const suggestedFeedId = $btn.data('feed-id');
		const refTvgId = $btn.data('ref-tvg-id');
		const refGroup = $btn.data('ref-group');

		// Show loading states
		$('#ref-feed-loading').show();
		$('#ref-feed-content').hide();
		$('#sug-feed-loading').show();
		$('#sug-feed-content').hide();

		// Reset form
		$('#task-category').val('');
		$('#task-note').val('');

		// Set reference info in hidden fields
		$('#task-ref-tvg-id').val(refTvgId);
		$('#task-ref-group').val(refGroup);

		// Show modal
		const modal = new bootstrap.Modal(document.getElementById('createTaskModal'));
		modal.show();

		// Fetch reference feed details (best feed for this tvg-id + group)
		$.get('get_feed_details.php', {
			tvg_id: refTvgId,
			group: refGroup,
			get_best: 1
		}, function(response) {
			if (response.success) {
				const feed = response.feed;

				$('#ref-group').text(feed.group_title);
				$('#ref-channel').text(feed.tvg_name);
				$('#ref-tvg-id').text(feed.tvg_id);
				$('#ref-file').text(feed.file);
				$('#ref-reliability').text(feed.reliability + '%');
				$('#ref-resolution').html(feed.resolution_html);
				$('#ref-fps').text(feed.fps);
				$('#ref-codec').text(feed.codec);

				$('#ref-feed-loading').hide();
				$('#ref-feed-content').show();
			} else {
				console.error('Failed to load reference feed:', response.message);
				$('#ref-feed-loading').hide();
			}
		}, 'json').fail(function() {
			console.error('AJAX failed for reference feed');
			$('#ref-feed-loading').hide();
		});

		// Fetch suggested feed details
		$.get('get_feed_details.php', {
			feed_id: suggestedFeedId
		}, function(response) {
			if (response.success) {
				const feed = response.feed;

				$('#sug-group').text(feed.group_title);
				$('#sug-channel').text(feed.tvg_name);
				$('#sug-tvg-id').text(feed.tvg_id);
				$('#sug-file').text(feed.file);
				$('#sug-reliability').text(feed.reliability + '%');
				$('#sug-resolution').html(feed.resolution_html);
				$('#sug-fps').text(feed.fps);
				$('#sug-codec').text(feed.codec);

				// Set hidden form fields
				$('#task-sug-feed-id').val(feed.feed_id);
				$('#task-sug-group').val(feed.group_title);

				$('#sug-feed-loading').hide();
				$('#sug-feed-content').show();
			} else {
				alert('Error loading suggested feed details: ' + response.message);
				modal.hide();
			}
		}, 'json').fail(function() {
			alert('Failed to load suggested feed details.');
			modal.hide();
		});
	});

	// Show empty state initially
	$(document).ready(function() {
		$('#audit-empty').show();
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>