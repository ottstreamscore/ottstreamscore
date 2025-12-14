<?php

declare(strict_types=1);

$title = 'Process Playlist';
require_once __DIR__ . '/_top.php';

// Flash message (POST→redirect→GET)
if (session_status() !== PHP_SESSION_ACTIVE) {
	@session_start();
}
$flash = $_SESSION['playlist_flash'] ?? null;
unset($_SESSION['playlist_flash']);

// Find .m3u files in THIS directory (same directory as this script)
$dir = __DIR__;
$files = glob($dir . '/*.m3u') ?: [];
sort($files);

function file_label(string $path): string
{
	$base = basename($path);
	$size = @filesize($path);
	$sizeTxt = $size !== false ? number_format($size / 1024 / 1024, 2) . ' MB' : '—';
	$mtime = @filemtime($path);
	$mtimeTxt = $mtime ? date('Y-m-d H:i:s', $mtime) : '—';
	return "{$base}  ({$sizeTxt}, updated {$mtimeTxt})";
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
	<div>
		<div class="h4 mb-1">Process Playlist</div>
		<div class="text-muted small">
			This tool <strong>syncs</strong> a playlist into your database and can be run any time you update/replace your .m3u file.
			It only imports <strong>LIVE</strong> entries (URLs containing <code>/live/</code>).
		</div>
	</div>
</div>

<?php if ($flash): ?>
	<div class="alert alert-<?= h($flash['ok'] ? 'success' : 'danger') ?> shadow-sm">
		<div class="fw-semibold mb-1">
			<?= h($flash['ok'] ? 'Playlist processed successfully' : 'Playlist processing failed') ?>
		</div>
		<div class="small">
			<?= nl2br(h((string)($flash['message'] ?? ''))) ?>
		</div>

		<?php if (!empty($flash['stats']) && is_array($flash['stats'])): ?>
			<hr>
			<div class="row small">
				<?php foreach ($flash['stats'] as $k => $v): ?>
					<div class="col-md-4 mb-2">
						<div class="text-muted"><?= h((string)$k) ?></div>
						<div class="fw-semibold"><?= h((string)$v) ?></div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<div class="card shadow-sm">
	<div class="card-header fw-semibold">Run a sync</div>
	<div class="card-body">

		<?php if (!$files): ?>
			<div class="alert alert-warning mb-0">
				No <code>.m3u</code> files found in this directory:
				<code><?= h(__DIR__) ?></code><br>
				Upload a playlist file here (example: <code>playlist.m3u</code>) and reload this page.
			</div>
		<?php else: ?>

			<form method="post" action="process_playlist_run.php" class="row g-3">
				<div class="col-lg-8">
					<label class="form-label">Playlist file</label>
					<select name="playlist" class="form-select" required>
						<?php foreach ($files as $full): ?>
							<?php $base = basename($full); ?>
							<option value="<?= h($base) ?>"><?= h(file_label($full)) ?></option>
						<?php endforeach; ?>
					</select>
					<div class="form-text">
						The file must be in this directory. You can replace the file and re-run sync any time.
					</div>
				</div>

				<div class="col-lg-4">
					<label class="form-label">Behavior</label>
					<select name="mode" class="form-select">
						<option value="sync" selected>Sync (insert new + update changed)</option>
						<option value="insert_only">Insert only (skip existing)</option>
					</select>
					<div class="form-text">
						Sync is the normal option for re-importing updated playlists.
					</div>
				</div>

				<div class="col-12">
					<div class="d-flex gap-2">
						<button type="submit" class="btn btn-dark">
							Process playlist
						</button>
						<a href="process_playlist.php" class="btn btn-outline-secondary">Refresh</a>
					</div>
					<div class="text-muted small mt-2">
						After processing completes, you’ll be redirected back here with a summary. Refreshing the page will <strong>not</strong> re-run the import.
					</div>
				</div>
			</form>

		<?php endif; ?>
	</div>
</div>

<?php require_once __DIR__ . '/_bottom.php'; ?>