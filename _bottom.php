<?php
// require auth
require_auth();
?>
</main>

<!-- Task Creation Modal -->
<div class="modal fade" id="createTaskModal" tabindex="-1" aria-labelledby="createTaskModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-xl">
		<div class="modal-content bg-body">
			<div class="modal-header border-secondary">
				<h5 class="modal-title" id="createTaskModalLabel"><i class="fa-solid fa-list-check me-2"></i> Create Task</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<!-- Target Feed -->
				<div class="mb-4 p-3 rounded border border-primary">
					<h6 class="text-primary mb-3">
						<i class="fa-solid fa-bullseye me-1"></i> Task Feed
					</h6>
					<div id="target-feed-loading" class="text-center py-3">
						<div class="spinner-border text-primary" role="status">
							<span class="visually-hidden">Loading...</span>
						</div>
					</div>
					<div id="target-feed-content" style="display: none;">
						<div class="feed-meta-inline">
							<div class="meta-inline-item">
								<i class="fa-solid fa-layer-group"></i>
								<span class="meta-inline-label">Group:</span>
								<span class="meta-inline-value" id="target-group"></span>
							</div>
							<div class="meta-inline-item">
								<i class="fa-solid fa-tv"></i>
								<span class="meta-inline-label">Channel:</span>
								<span class="meta-inline-value" id="target-channel"></span>
							</div>
							<div class="meta-inline-item">
								<i class="fa-solid fa-fingerprint"></i>
								<span class="meta-inline-label">tvg-id:</span>
								<span class="meta-inline-value" id="target-tvg-id"></span>
							</div>
							<div class="meta-inline-item">
								<i class="fa-solid fa-file"></i>
								<span class="meta-inline-label">File:</span>
								<span class="meta-inline-value" id="target-file"></span>
							</div>
						</div>
						<div class="feed-stream-inline">
							<div class="stream-inline-item">
								<i class="fa-solid fa-chart-simple"></i>
								<span class="stream-inline-label">Rel:</span>
								<span class="stream-inline-value" id="target-reliability"></span>
							</div>
							<div class="stream-inline-item">
								<span class="stream-inline-label">Res:</span>
								<span class="stream-inline-value" id="target-resolution"></span>
							</div>
							<div class="stream-inline-item">
								<span class="stream-inline-label">FPS:</span>
								<span class="stream-inline-value" id="target-fps"></span>
							</div>
							<div class="stream-inline-item">
								<span class="stream-inline-label">Codec:</span>
								<span class="stream-inline-value" id="target-codec"></span>
							</div>
						</div>
					</div>
				</div>

				<!-- Task Details Form -->
				<form id="createTaskForm">
					<input type="hidden" id="task-target-tvg-id" name="target_tvg_id">
					<input type="hidden" id="task-target-group" name="target_group">
					<input type="hidden" id="task-target-feed-id" name="target_feed_id">

					<div class="mb-3">
						<label for="task-category" class="form-label fw-bold">Task Type <span class="text-danger">*</span></label>
						<select class="form-select bg-body text-body border-secondary" id="task-category" name="category" required>
							<option value="">-- Select Task Type --</option>
							<option value="feed_replacement">Feed Replacement</option>
							<option value="feed_review">Feed Review</option>
							<option value="epg_adjustment">EPG Adjustment</option>
							<option value="other">Other</option>
						</select>
					</div>

					<!-- Alternative Feeds Section (shown for Feed Replacement/Review) -->
					<div id="alternative-feeds-section" style="display: none;">
						<div class="mb-3">
							<label class="form-label fw-bold">Select Alternative Feed <span class="text-danger">*</span></label>
							<div id="alternative-feeds-loading" class="text-center py-3">
								<div class="spinner-border text-primary" role="status">
									<span class="visually-hidden">Loading alternatives...</span>
								</div>
							</div>
							<div id="alternative-feeds-content" style="display: none;">
								<!-- Nav tabs -->
								<ul class="nav nav-tabs mb-2" id="altFeedsTabs" role="tablist">
									<li class="nav-item" role="presentation">
										<button class="nav-link active" id="alt-primary-tab" data-bs-toggle="tab" data-bs-target="#alt-primary-feeds" type="button" role="tab">
											<i class="fa-solid fa-equals me-1"></i> Primary Matches <span class="badge bg-secondary ms-1" id="alt-primary-count">0</span>
										</button>
									</li>
									<li class="nav-item" role="presentation" id="alt-assoc-tab-item" style="display: none;">
										<button class="nav-link" id="alt-association-tab" data-bs-toggle="tab" data-bs-target="#alt-association-feeds" type="button" role="tab">
											<i class="fa-solid fa-diagram-project me-1"></i> Association Matches <span class="badge bg-secondary ms-1" id="alt-assoc-count">0</span>
										</button>
									</li>
								</ul>

								<!-- Tab panes -->
								<div class="tab-content">
									<!-- Primary Feeds Tab -->
									<div class="tab-pane fade show active" id="alt-primary-feeds" role="tabpanel">
										<div class="table-responsive">
											<table class="table table-striped table-hover mb-0 align-middle" id="altPrimaryTable">
												<thead>
													<tr>
														<th style="width: 40px;"></th>
														<th>Group</th>
														<th>Channel</th>
														<th>Status</th>
														<th class="text-end">Rel %</th>
														<th class="text-end">Res</th>
														<th class="text-end">Class</th>
														<th class="text-end">FPS</th>
														<th>Codec</th>
														<th>File</th>
													</tr>
												</thead>
												<tbody id="alt-primary-tbody">
												</tbody>
											</table>
										</div>
									</div>

									<!-- Association Feeds Tab -->
									<div class="tab-pane fade" id="alt-association-feeds" role="tabpanel">
										<div id="alt-association-content"></div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<div class="mb-3">
						<label for="task-note" class="form-label fw-bold">Note (Optional)</label>
						<textarea class="form-control bg-body text-body border-secondary"
							id="task-note"
							name="note"
							rows="3"
							placeholder="Add any additional notes about this task..."></textarea>
					</div>
				</form>
			</div>
			<div class="modal-footer border-secondary">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
					<i class="fa-solid fa-times"></i> Cancel
				</button>
				<button type="button" class="btn btn-outline-primary" id="submitTask">
					<i class="fa-solid fa-plus-circle"></i> Create Task
				</button>
			</div>
		</div>
	</div>
</div>

<style>
	.alt-feed-row {
		cursor: pointer;
	}

	.alt-feed-row:hover {
		background-color: var(--bs-table-hover-bg) !important;
	}

	.alt-feed-row.selected {
		background-color: var(--bs-success-bg-subtle) !important;
	}
</style>

<!-- Stream Preview Modal -->
<div class="modal fade" id="streamPreviewModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">
					<i class="fa-solid fa-play-circle me-2"></i>
					Stream Preview
				</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<div id="preview-loading" class="text-center py-4">
					<div class="spinner-border text-primary" role="status">
						<span class="visually-hidden">Loading...</span>
					</div>
					<p class="mt-2 text-muted">Acquiring stream lock...</p>
				</div>

				<div id="preview-content" style="display:none;">
					<!-- Feed Status Alert (shown when feed previously failed) -->
					<div id="preview-failed-warning" class="alert alert-danger mb-3" style="display:none;">
						<div class="d-flex align-items-center">
							<i class="fa-solid fa-exclamation-triangle fa-2x me-3"></i>
							<div class="flex-grow-1">
								<h6 class="alert-heading mb-1">Feed Failed on Last Check</h6>
								<p class="mb-0 small">This feed was not working during the last automated check. It may work now, or playback might fail.</p>
							</div>
						</div>
					</div>

					<!-- Channel Info -->
					<div class="alert alert-info mb-3">
						<div class="d-flex justify-content-between align-items-start">
							<div class="flex-grow-1">
								<div class="d-flex align-items-center gap-2 mb-1">
									<strong id="preview-channel-name"></strong>
									<span id="preview-status-badge"></span>
								</div>
								<div class="small text-muted mt-1">
									<span id="preview-group"></span>
									<span id="preview-tvg-id" class="ms-2"></span>
								</div>
								<div class="small mt-2">
									<span id="preview-resolution-badge"></span>
									<span id="preview-resolution"></span> •
									<span id="preview-fps"></span> FPS •
									<span id="preview-codec"></span>
								</div>
								<div class="small mt-1" id="preview-reliability-container">
									<span id="preview-reliability"></span>
								</div>
							</div>
							<span class="badge bg-success" id="preview-lock-status">
								<i class="fa-solid fa-lock"></i> Locked
							</span>
						</div>
					</div>

					<!-- Video Player -->
					<div class="ratio ratio-16x9 bg-dark rounded">
						<video id="preview-video" controls autoplay muted playsinline style="display: block !important; visibility: visible !important; opacity: 1 !important; z-index: 1000 !important;">
							<source id="preview-source" type="application/x-mpegURL">
							Your browser doesn't support video playback.
						</video>
					</div>

					<!-- Checks Paused Warning -->
					<div class="alert alert-warning mt-3 mb-0">
						<i class="fa-solid fa-exclamation-triangle me-2"></i>
						<strong>Note:</strong> Automated feed checks are paused while this preview is active.
					</div>
				</div>

				<div id="preview-error" class="alert alert-danger" style="display:none;">
					<i class="fa-solid fa-exclamation-circle me-2"></i>
					<span id="preview-error-message"></span>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close & Release Lock</button>
			</div>
		</div>
	</div>
</div>

<footer class="text-end mt-1 mb-4 text-muted small">
	<div class="container">
		<a href="https://github.com/ottstreamscore/"
			target="_blank"
			class="text-muted text-decoration-none"
			title="View on GitHub">
			<i class="fab fa-github me-1"></i> GitHub
		</a>
	</div>
</footer>

<?php
function base_url_footer(): string
{
	$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
	$dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
	return ($dir === '.' ? '' : $dir);
}
$BASE = base_url_footer();
?>

<script>
	document.getElementById('theme-toggle-link').addEventListener('click', function(e) {
		e.preventDefault();
		const currentTheme = document.documentElement.getAttribute('data-bs-theme');
		const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
		document.documentElement.setAttribute('data-bs-theme', newTheme);
		localStorage.setItem('theme', newTheme);
	});
</script>

<script>
	let previewHeartbeatInterval = null;
	let previewModal = null;
	let currentHls = null;
	let currentMpegts = null;

	$(document).ready(function() {
		previewModal = new bootstrap.Modal(document.getElementById('streamPreviewModal'));
		$('#streamPreviewModal').on('hidden.bs.modal', function() {
			instantKill();
		});
	});

	$(document).on('click', '.btn-preview', function() {
		const feedId = $(this).data('feed-id');
		instantKill();
		setTimeout(() => startStreamPreview(feedId), 100);
	});

	function instantKill() {
		if (previewHeartbeatInterval) {
			clearInterval(previewHeartbeatInterval);
			previewHeartbeatInterval = null;
		}

		const video = document.getElementById('preview-video');
		if (video) {
			video.pause();
			video.src = '';
			video.load();
		}

		// Remove AC-3 warning banner if present
		$('#preview-audio-warning').remove();

		if (currentMpegts) {
			try {
				currentMpegts.pause();
				currentMpegts.unload();
				currentMpegts.detachMediaElement();
				currentMpegts.destroy();
			} catch (e) {}
			currentMpegts = null;
		}

		if (currentHls) {
			try {
				currentHls.stopLoad();
				currentHls.detachMedia();
				currentHls.destroy();
			} catch (e) {}
			currentHls = null;
		}
	}

	async function startStreamPreview(feedId) {
		$('#preview-loading').show();
		$('#preview-content').hide();
		$('#preview-error').hide();
		$('#preview-failed-warning').hide();
		previewModal.show();

		try {
			const lockResponse = await $.ajax({
				url: '<?= h($BASE) ?>/stream_preview_api.php',
				type: 'POST',
				data: {
					action: 'acquire_lock',
					feed_id: feedId
				},
				timeout: 10000
			});

			if (!lockResponse.success) {
				showPreviewError(lockResponse.error || 'Failed to acquire stream lock');
				return;
			}

			const streamResponse = await $.ajax({
				url: '<?= h($BASE) ?>/stream_preview_api.php',
				type: 'GET',
				data: {
					action: 'get_stream_url',
					feed_id: feedId
				},
				timeout: 10000
			});

			if (!streamResponse.success) {
				showPreviewError(streamResponse.error || 'Failed to load stream');
				instantKill();
				return;
			}

			$('#preview-channel-name').text(streamResponse.channel_name || 'Unknown Channel');
			$('#preview-group').text(streamResponse.group ? `Group: ${streamResponse.group}` : '');
			$('#preview-tvg-id').text(streamResponse.tvg_id ? `ID: ${streamResponse.tvg_id}` : '');

			const lastOk = streamResponse.last_ok;
			if (lastOk !== undefined && lastOk !== null) {
				if (parseInt(lastOk) === 1) {
					$('#preview-status-badge').html('<span class="badge bg-success">OK</span>');
					$('#preview-failed-warning').hide();
				} else {
					$('#preview-status-badge').html('<span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>FAILED</span>');
					$('#preview-failed-warning').show();
				}
			} else {
				$('#preview-status-badge').html('<span class="badge bg-secondary">Unknown</span>');
				$('#preview-failed-warning').hide();
			}

			const resolution = streamResponse.resolution || '?x?';
			$('#preview-resolution').text(resolution);

			if (resolution && resolution !== '?x?' && resolution.includes('x')) {
				const [w, h] = resolution.split('x').map(v => parseInt(v));
				if (w > 0 && h > 0) {
					let resClass = 'SD';
					let badgeClass = 'bg-secondary';

					if (h >= 2160 || w >= 3840) {
						resClass = '4K';
						badgeClass = 'bg-warning text-dark';
					} else if (h >= 1080) {
						resClass = 'FHD';
						badgeClass = 'bg-primary';
					} else if (h >= 720) {
						resClass = 'HD';
						badgeClass = 'bg-info text-dark';
					}

					$('#preview-resolution-badge').html(`<span class="badge ${badgeClass}">${resClass}</span> `);
				} else {
					$('#preview-resolution-badge').html('');
				}
			} else {
				$('#preview-resolution-badge').html('');
			}

			$('#preview-fps').text(streamResponse.fps || '?');
			$('#preview-codec').text(streamResponse.codec || 'Unknown');

			if (streamResponse.reliability !== undefined && streamResponse.reliability !== null) {
				const rel = parseFloat(streamResponse.reliability);
				let relClass = 'text-success';
				if (rel < 50) {
					relClass = 'text-danger';
				} else if (rel < 80) {
					relClass = 'text-warning';
				}
				$('#preview-reliability').html(`<strong class="${relClass}">Reliability: ${rel.toFixed(1)}%</strong>`);
			} else {
				$('#preview-reliability').text('');
			}

			const video = document.getElementById('preview-video');
			const source = document.getElementById('preview-source');

			// Remove any existing AC-3 warning banner
			$('#preview-audio-warning').remove();

			// FORCE HARD RESET of video element
			video.removeAttribute('src');
			video.load();
			while (video.firstChild) {
				video.removeChild(video.firstChild);
			}

			if (streamResponse.stream_type === 'mpegts') {
				if (currentHls) {
					try {
						currentHls.stopLoad();
						currentHls.detachMedia();
						currentHls.destroy();
					} catch (e) {}
					currentHls = null;
				}
				if (currentMpegts) {
					try {
						currentMpegts.pause();
						currentMpegts.unload();
						currentMpegts.detachMediaElement();
						currentMpegts.destroy();
					} catch (e) {}
					currentMpegts = null;
				}

				if (mpegts.getFeatureList().mseLivePlayback) {
					currentMpegts = mpegts.createPlayer({
						type: 'mpegts',
						isLive: true,
						url: streamResponse.url
					}, {
						enableWorker: false,
						enableStashBuffer: true,
						stashInitialSize: 1024,
						autoCleanupSourceBuffer: true,
						autoCleanupMaxBackwardDuration: 12,
						autoCleanupMinBackwardDuration: 8,
						fixAudioTimestampGap: true,
						liveBufferLatencyChasing: false,
						liveBufferLatencyChasingOnPaused: false
					});

					currentMpegts.attachMediaElement(video);
					currentMpegts.load();

					let ac3AudioDetected = false;

					currentMpegts.on(mpegts.Events.ERROR, (errorType, errorDetail, errorInfo) => {
						console.error('[Preview] mpegts.js error:', errorType, errorDetail, errorInfo);

						// If it's a MediaMSEError (usually AC-3 audio), show warning but continue with video
						if (errorDetail === 'MediaMSEError') {
							console.warn('[Preview] MediaMSEError detected - likely AC-3 audio. Continuing with video only.');
							ac3AudioDetected = true;

							// Show warning banner above video
							$('#preview-audio-warning').remove(); // Remove if already exists
							$('#preview-video').before(`
								<div id="preview-audio-warning" class="alert alert-warning mb-2" style="margin-bottom: 0.5rem !important;">
									<i class="fa-solid fa-volume-xmark me-2"></i>
									<strong>No Audio:</strong> This stream uses AC-3 (Dolby Digital) audio which is not supported by browsers. Video will play without audio.
								</div>
							`);

							return;
						}

						// For other errors, show error and stop
						showPreviewError(`Stream error: ${errorType} - ${errorDetail}`);
					});

					// Monitor playback state
					video.addEventListener('playing', () => {

						// macOS VideoToolbox workaround: Force seek to trigger rendering
						if (video.videoWidth > 0 && video.currentTime < 0.1) {
							setTimeout(() => {
								video.currentTime = 0.1;
							}, 100);
						}
					});
				} else {
					console.error('[Preview] MSE not supported');
					showPreviewError('Your browser does not support MPEG-TS playback (MSE required). Please use Chrome, Firefox, or Edge.');
					instantKill();
					return;
				}
			} else if (streamResponse.stream_type === 'hls' || Hls.isSupported()) {
				if (currentMpegts) {
					try {
						currentMpegts.pause();
						currentMpegts.unload();
						currentMpegts.detachMediaElement();
						currentMpegts.destroy();
					} catch (e) {}
					currentMpegts = null;
				}
				if (currentHls) {
					try {
						currentHls.stopLoad();
						currentHls.detachMedia();
						currentHls.destroy();
					} catch (e) {}
				}

				currentHls = new Hls({
					xhrSetup: function(xhr, url) {
						xhr.withCredentials = true;
					},
					enableWorker: true,
					lowLatencyMode: false
				});

				currentHls.loadSource(streamResponse.url);
				currentHls.attachMedia(video);

				currentHls.on(Hls.Events.ERROR, function(event, data) {
					console.error('[Preview] HLS error:', data);
					if (data.fatal) {
						switch (data.type) {
							case Hls.ErrorTypes.NETWORK_ERROR:
								console.error('[Preview] Fatal network error:', data.details);
								showPreviewError('Network error loading stream: ' + data.details);
								break;
							case Hls.ErrorTypes.MEDIA_ERROR:
								console.error('[Preview] Fatal media error:', data.details);
								showPreviewError('Media error playing stream: ' + data.details);
								currentHls.recoverMediaError();
								break;
							default:
								console.error('[Preview] Fatal error:', data.type, data.details);
								showPreviewError('Fatal error: ' + data.type + ' - ' + data.details);
								break;
						}
					}
				});
			} else if (video.canPlayType('application/vnd.apple.mpegurl')) {
				source.src = streamResponse.url;
				video.load();
			} else {
				console.error('[Preview] No HLS support detected');
				showPreviewError('Your browser does not support HLS playback. Please use Chrome, Firefox, or Safari.');
				instantKill();
				return;
			}

			$('#preview-loading').hide();
			$('#preview-content').show();
			startHeartbeat();

		} catch (error) {
			console.error('[Preview] Error:', error);
			if (error.statusText === 'timeout') {
				showPreviewError('Request timed out. Please try again.');
			} else {
				showPreviewError('Error: ' + (error.responseJSON?.error || error.message || 'Unknown error'));
			}
			instantKill();
		}
	}

	function startHeartbeat() {
		if (previewHeartbeatInterval) {
			clearInterval(previewHeartbeatInterval);
		}

		previewHeartbeatInterval = setInterval(async function() {
			try {
				const response = await $.ajax({
					url: '<?= h($BASE) ?>/stream_preview_api.php',
					type: 'POST',
					data: {
						action: 'heartbeat'
					}
				});

				if (!response.success) {
					console.error('Heartbeat failed:', response.error);
					$('#preview-lock-status').removeClass('bg-success').addClass('bg-warning').html('<i class="fa-solid fa-exclamation-triangle"></i> Lock Warning');
				}
			} catch (error) {
				console.error('Heartbeat error:', error);
				$('#preview-lock-status').removeClass('bg-success').addClass('bg-danger').html('<i class="fa-solid fa-xmark"></i> Lock Lost');
			}
		}, 10000);
	}

	function showPreviewError(message) {
		console.error('[Preview] Showing error:', message);
		$('#preview-loading').hide();
		$('#preview-content').hide();
		$('#preview-error-message').html(message);
		$('#preview-error').show();
	}

	window.addEventListener('beforeunload', function() {
		if (previewHeartbeatInterval) {
			clearInterval(previewHeartbeatInterval);
		}
		if (navigator.sendBeacon) {
			const formData = new FormData();
			formData.append('action', 'release_lock');
			navigator.sendBeacon('<?= h($BASE) ?>/stream_preview_api.php', formData);
		}
	});



	// Task creation modal functionality
	let currentTaskData = null;
	let suggestedFeedIdToPreselect = null;
	let suggestedGroupToPreselect = null;

	$(document).on('click', '.btn-create-task', function() {
		const $btn = $(this);
		const targetFeedId = $btn.data('feed-id');
		const targetGroup = $btn.data('group');
		const refTvgId = $btn.data('ref-tvg-id');
		const ref_group = $btn.data('ref-group');

		suggestedFeedIdToPreselect = $btn.data('suggested-feed-id') || null;
		suggestedGroupToPreselect = $btn.data('suggested-group') || null;
		const fromReports = $btn.data('from-reports') || false;

		// Destroy any existing DataTables first
		try {
			if ($.fn.DataTable.isDataTable('#altPrimaryTable')) {
				$('#altPrimaryTable').DataTable().destroy();
			}
			$('.assoc-alt-table').each(function() {
				if ($.fn.DataTable.isDataTable(this)) {
					$(this).DataTable().destroy();
				}
			});
		} catch (e) {
			console.log('Error destroying tables:', e);
		}

		// Clear all content
		$('#alt-primary-tbody').empty();
		$('#alt-association-content').empty();
		$('#alternative-feeds-content').hide();

		// Show loading state
		$('#target-feed-loading').show();
		$('#target-feed-content').hide();
		$('#alternative-feeds-section').hide();

		// Reset form
		$('#task-category').val('');
		$('#task-note').val('');

		// Show modal
		$('#createTaskModal').modal('show');

		// Fetch target feed AND alternatives in one call
		// Pass filter_ignores=1 if from reports page
		const apiParams = {
			feed_id: targetFeedId,
			group: targetGroup,
			ref_tvg_id: refTvgId,
			ref_group: ref_group
		};
		if (fromReports) {
			apiParams.filter_ignores = 1;
		}

		$.get('get_feed_alternatives.php', apiParams, function(response) {
			if (response.success) {
				currentTaskData = response;
				const feed = response.target_feed;

				// Populate target feed section
				$('#target-group').text(feed.group_title);
				$('#target-channel').text(feed.tvg_name);
				$('#target-tvg-id').text(feed.tvg_id);
				$('#target-file').text(feed.file);
				$('#target-reliability').text(feed.reliability + '%');
				$('#target-resolution').html(feed.resolution_html);
				$('#target-fps').text(feed.fps);
				$('#target-codec').text(feed.codec);

				// Store in form
				$('#task-target-feed-id').val(feed.feed_id);
				$('#task-target-tvg-id').val(feed.tvg_id);
				$('#task-target-group').val(feed.group_title);

				$('#target-feed-loading').hide();
				$('#target-feed-content').show();
			} else {
				alert('Error loading feed details: ' + response.message);
				$('#createTaskModal').modal('hide');
			}
		}, 'json').fail(function() {
			alert('Failed to load feed details.');
			$('#createTaskModal').modal('hide');
		});
	});

	// Task category change handler
	$('#task-category').on('change', function() {
		const category = $(this).val();

		if (category === 'feed_replacement' || category === 'feed_review') {
			if (!currentTaskData) {
				console.error('No task data available');
				return;
			}

			// Destroy existing DataTables if they exist
			try {
				if ($.fn.DataTable.isDataTable('#altPrimaryTable')) {
					$('#altPrimaryTable').DataTable().destroy();
				}
				$('.assoc-alt-table').each(function() {
					if ($.fn.DataTable.isDataTable(this)) {
						$(this).DataTable().destroy();
					}
				});
			} catch (e) {
				console.log('Error destroying tables on category change:', e);
			}

			// Clear content
			$('#alt-primary-tbody').empty();
			$('#alt-association-content').empty();

			// Show alternative feeds section
			$('#alternative-feeds-section').show();
			$('#alternative-feeds-loading').show();
			$('#alternative-feeds-content').hide();

			// Render alternatives (target feed already filtered by API)
			renderAlternativeFeeds(
				currentTaskData.alternatives.primary_matches,
				currentTaskData.alternatives.association_matches
			);

			$('#alternative-feeds-loading').hide();
			$('#alternative-feeds-content').show();

			// Pre-select suggested feed if provided
			if (suggestedFeedIdToPreselect) {
				setTimeout(() => {
					let $radio;
					// If group is specified, match on both feed_id and group
					if (suggestedGroupToPreselect) {
						$radio = $(`input[name="alternative_feed"][value="${suggestedFeedIdToPreselect}"][data-group="${suggestedGroupToPreselect}"]`);
					} else {
						// Fallback to just feed_id if no group specified
						$radio = $(`input[name="alternative_feed"][value="${suggestedFeedIdToPreselect}"]`);
					}

					if ($radio.length) {
						$radio.prop('checked', true);
						$radio.closest('tr').addClass('selected');
					}
				}, 200);
			}
		} else {
			// Hide alternative feeds for other task types
			$('#alternative-feeds-section').hide();
		}
	});

	function renderAlternativeFeeds(primaryMatches, associationMatches) {
		primaryMatches = primaryMatches || [];
		associationMatches = associationMatches || [];

		// Show the content
		$('#alternative-feeds-content').show();

		// Clear existing content (tables were already destroyed on modal open)
		$('#alt-primary-tbody').empty();
		$('#alt-association-content').empty();

		// Primary Matches
		if (primaryMatches && primaryMatches.length > 0) {
			$('#alt-primary-count').text(primaryMatches.length);

			primaryMatches.forEach(feed => {
				$('#alt-primary-tbody').append(createFeedRow(feed));
			});

			// Initialize DataTable
			setTimeout(() => {
				try {
					$('#altPrimaryTable').DataTable({
						pageLength: 10,
						order: [
							[4, 'desc'],
							[6, 'desc'],
							[7, 'desc']
						],
						searching: false,
						info: false,
						lengthChange: false
					});
				} catch (e) {
					console.error('Error initializing primary DataTable:', e);
				}
			}, 100);
		} else {
			$('#alt-primary-count').text(0);
			$('#alt-primary-tbody').html('<tr><td colspan="10" class="text-center text-muted py-4">No primary matches found</td></tr>');
		}

		// Association Matches
		if (associationMatches && associationMatches.length > 0) {
			let totalAssocCount = 0;

			associationMatches.forEach((assoc, idx) => {
				totalAssocCount += assoc.matches.length;
				const tableId = 'altAssocTable' + idx;

				let html = `
					<div class="card shadow-sm mb-3">
						<div class="card-header fw-semibold">
							<i class="fa-solid fa-link me-1"></i> ${escapeHtml(assoc.association_name)}
							<span class="badge bg-secondary ms-2">${assoc.matches.length} matches</span>
						</div>
						<div class="table-responsive" style="padding: 10pt;">
							<table class="table table-striped table-hover mb-0 align-middle assoc-alt-table" id="${tableId}">
								<thead>
									<tr>
										<th style="width: 40px;"></th>
										<th>Group</th>
										<th>Channel</th>
										<th>Status</th>
										<th class="text-end">Rel %</th>
										<th class="text-end">Res</th>
										<th class="text-end">Class</th>
										<th class="text-end">FPS</th>
										<th>Codec</th>
										<th>File</th>
									</tr>
								</thead>
								<tbody>
				`;

				assoc.matches.forEach(feed => {
					html += createFeedRow(feed);
				});

				html += `
								</tbody>
							</table>
						</div>
					</div>
				`;

				$('#alt-association-content').append(html);
			});

			// Initialize DataTables for association tables
			setTimeout(() => {
				associationMatches.forEach((assoc, idx) => {
					const tableId = 'altAssocTable' + idx;
					try {
						$('#' + tableId).DataTable({
							pageLength: 10,
							order: [
								[4, 'desc'],
								[6, 'desc'],
								[7, 'desc']
							],
							searching: false,
							info: false,
							lengthChange: false
						});
					} catch (e) {
						console.error('Error initializing DataTable for', tableId, e);
					}
				});
			}, 100);

			$('#alt-assoc-count').text(totalAssocCount);
			$('#alt-assoc-tab-item').show();
		} else {
			$('#alt-assoc-count').text(0);
			$('#alt-assoc-tab-item').hide();
		}
	}

	function createFeedRow(feed) {
		const feedId = feed.feed_id;
		const group = escapeHtml(feed.group_title);
		const channel = escapeHtml(feed.tvg_name);
		const reliability = feed.reliability;
		const resolutionFull = feed.resolution_html;
		const fps = escapeHtml(feed.fps);
		const codec = escapeHtml(feed.codec);
		const file = escapeHtml(feed.file || '—');
		const statusBadge = feed.status_badge || '<span class="badge bg-secondary">N/A</span>';

		const relNum = parseFloat(reliability) || 0;

		// Extract badge and dimensions separately
		const resMatch = resolutionFull.match(/badge bg-(\w+)/);
		const resClassMap = {
			'warning': 100,
			'primary': 85,
			'info': 70,
			'secondary': 50
		};
		const resClassPts = resClassMap[resMatch ? resMatch[1] : 'secondary'] || 50;

		// Class column: just the badge
		const resBadge = resolutionFull.split('</span>')[0] + '</span>';
		// Res column: just the dimensions
		const resDimensions = resolutionFull.split('</span>')[1] || '';

		return `
			<tr class="alt-feed-row" data-feed-id="${feedId}" data-group="${group}">
				<td>
					<input type="radio" name="alternative_feed" value="${feedId}" data-group="${group}">
				</td>
				<td class="no-wrap">${group}</td>
				<td class="no-wrap">${channel}</td>
				<td>${statusBadge}</td>
				<td class="text-end" data-order="${relNum}">${reliability}%</td>
				<td class="text-end">${resDimensions}</td>
				<td class="text-end" data-order="${resClassPts}">
					${resBadge}
				</td>
				<td class="text-end">${fps}</td>
				<td>${codec}</td>
				<td class="text-muted small">${file}</td>
			</tr>
		`;
	}

	// Row click handler to select radio button
	$(document).on('click', '.alt-feed-row', function() {
		$('.alt-feed-row').removeClass('selected');
		$(this).addClass('selected');
		$(this).find('input[type="radio"]').prop('checked', true);
	});

	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Submit task
	$('#submitTask').on('click', function() {
		const category = $('#task-category').val();

		if (!category) {
			alert('Please select a task type.');
			return;
		}

		// Check if alternative feed is required and selected
		if (category === 'feed_replacement' || category === 'feed_review') {
			const selectedFeed = $('input[name="alternative_feed"]:checked');
			if (selectedFeed.length === 0) {
				alert('Please select an alternative feed.');
				return;
			}
		}

		const targetFeedId = $('#task-target-feed-id').val();
		const targetTvgId = $('#task-target-tvg-id').val();
		const targetGroup = $('#task-target-group').val();


		let sugFeedId, sugGroup;

		if (category === 'feed_replacement' || category === 'feed_review') {
			// Use selected alternative feed
			const selectedFeed = $('input[name="alternative_feed"]:checked');
			sugFeedId = selectedFeed.val();
			sugGroup = selectedFeed.data('group');
		} else {
			// Use target feed as suggested feed
			sugFeedId = targetFeedId;
			sugGroup = targetGroup;
		}

		const formData = {
			ref_tvg_id: targetTvgId,
			ref_group: targetGroup,
			sug_feed_id: sugFeedId,
			sug_group: sugGroup,
			category: category,
			note: $('#task-note').val()
		};

		$.post('create_task.php', formData, function(response) {
			if (response.success) {
				alert('Task created successfully!');
				$('#createTaskModal').modal('hide');
			} else {
				alert('Error creating task: ' + response.message);
			}
		}, 'json').fail(function() {
			alert('Failed to create task. Please try again.');
		});
	});
</script>

</body>

</html>