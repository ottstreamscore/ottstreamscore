<?php
// require auth
require_auth();
?>
</main>

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
						liveBufferLatencyChasing: false, // allows more buffering
						liveBufferLatencyChasingOnPaused: false
					});

					currentMpegts.attachMediaElement(video);
					currentMpegts.load();

					currentMpegts.on(mpegts.Events.ERROR, (errorType, errorDetail, errorInfo) => {
						console.error('[Preview] mpegts.js error:', errorType, errorDetail, errorInfo);
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

						// Check if time is actually progressing
						setTimeout(() => {
							//	console.log('[Preview] 2 seconds later - currentTime:', video.currentTime.toFixed(2), 'paused:', video.paused);
						}, 2000);
					});
					video.addEventListener('timeupdate', function logTime() {
						video.removeEventListener('timeupdate', logTime); // Only log first one
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
		$('#preview-error-message').text(message);
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
</script>

</body>

</html>