<?php

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

if (!is_logged_in()) {
	header('Content-Type: application/json');
	http_response_code(401);
	echo json_encode(['success' => false, 'error' => 'Unauthorized']);
	exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
	case 'save_url':
		handleSaveUrl();
		break;

	case 'save_epg_url':
		handleSaveEpgUrl();
		break;

	case 'get_stats':
		handleGetStats();
		break;

	case 'get_progress':
		handleGetProgress();
		break;

	case 'delete_temp':
		handleDeleteTemp();
		break;

	default:
		echo json_encode(['success' => false, 'error' => 'Invalid action']);
		break;
}

function handleSaveUrl(): void
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		echo json_encode(['success' => false, 'error' => 'Invalid request method']);
		exit;
	}

	require_csrf();

	$url = trim($_POST['url'] ?? '');
	$clearData = isset($_POST['clear_data']) && $_POST['clear_data'] === '1';

	if (empty($url)) {
		echo json_encode(['success' => false, 'error' => 'URL is required']);
		exit;
	}

	if (!filter_var($url, FILTER_VALIDATE_URL)) {
		echo json_encode(['success' => false, 'error' => 'Invalid URL format']);
		exit;
	}

	$pdo = get_db_connection();

	if ($clearData) {
		try {
			$pdo->beginTransaction();

			// Disable foreign key checks temporarily
			$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

			// Use DELETE instead of TRUNCATE since TRUNCATE implicitly commits
			$pdo->exec("DELETE FROM channels");
			$pdo->exec("DELETE FROM channel_feeds");
			$pdo->exec("DELETE FROM feeds");
			$pdo->exec("DELETE FROM feed_checks");
			$pdo->exec("DELETE FROM feed_check_queue");
			$pdo->exec("DELETE FROM feed_id_mapping");

			// Re-enable foreign key checks
			$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

			$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('last_sync_date', NULL) ON DUPLICATE KEY UPDATE setting_value = NULL");
			$stmt->execute();

			$pdo->commit();
		} catch (Exception $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			// Make sure to re-enable foreign key checks even on error
			try {
				$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
			} catch (Exception $e2) {
				// Ignore
			}
			echo json_encode(['success' => false, 'error' => 'Failed to clear data: ' . $e->getMessage()]);
			exit;
		}
	}

	try {
		$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('playlist_url', :url) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
		$stmt->execute(['url' => $url]);

		echo json_encode(['success' => true, 'message' => 'Playlist URL saved successfully']);
	} catch (Exception $e) {
		echo json_encode(['success' => false, 'error' => 'Failed to save URL: ' . $e->getMessage()]);
	}
}

function handleSaveEpgUrl(): void
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		echo json_encode(['success' => false, 'error' => 'Invalid request method']);
		exit;
	}

	require_csrf();

	$epgUrl = trim($_POST['epg_url'] ?? '');

	if (empty($epgUrl)) {
		echo json_encode(['success' => false, 'error' => 'EPG URL is required']);
		exit;
	}

	if (!filter_var($epgUrl, FILTER_VALIDATE_URL)) {
		echo json_encode(['success' => false, 'error' => 'Invalid EPG URL format']);
		exit;
	}

	try {
		$pdo = get_db_connection();
		$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('epg_url', :url) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
		$stmt->execute(['url' => $epgUrl]);

		echo json_encode(['success' => true, 'message' => 'EPG URL saved successfully']);
	} catch (Exception $e) {
		echo json_encode(['success' => false, 'error' => 'Failed to save EPG URL: ' . $e->getMessage()]);
	}
}

function handleGetStats(): void
{
	$playlistFile = __DIR__ . '/playlists/playlist_temp.m3u';

	if (!file_exists($playlistFile)) {
		echo json_encode(['success' => false, 'error' => 'No playlist file found']);
		exit;
	}

	$fh = fopen($playlistFile, 'r');
	if (!$fh) {
		echo json_encode(['success' => false, 'error' => 'Cannot read playlist file']);
		exit;
	}

	$firstLine = fgets($fh);
	if ($firstLine === false || !str_starts_with(trim($firstLine), '#EXTM3U')) {
		fclose($fh);
		echo json_encode(['success' => false, 'error' => 'Invalid playlist format']);
		exit;
	}

	$totalEntries = 0;
	$liveChannels = 0;
	$currentUsername = null;
	$currentPassword = null;

	while (($line = fgets($fh)) !== false) {
		$line = trim($line);

		if (str_starts_with($line, '#EXTINF:')) {
			$totalEntries++;
		} elseif (!empty($line) && $line[0] !== '#') {
			if (stripos($line, '/live/') !== false) {
				$liveChannels++;

				if ($currentUsername === null && preg_match('#/live/([^/]+)/([^/]+)/#', $line, $matches)) {
					$currentUsername = $matches[1];
					$currentPassword = $matches[2];
				}
			}
		}
	}

	fclose($fh);

	$fileSize = filesize($playlistFile);
	$fileSizeFormatted = $fileSize < 1024 * 1024
		? round($fileSize / 1024, 2) . ' KB'
		: round($fileSize / (1024 * 1024), 2) . ' MB';

	echo json_encode([
		'success' => true,
		'totalEntries' => $totalEntries,
		'liveChannels' => $liveChannels,
		'fileSize' => $fileSizeFormatted,
		'currentUsername' => $currentUsername ?? '',
		'currentPassword' => $currentPassword ?? ''
	]);
}

function handleGetProgress(): void
{
	$progressFile = __DIR__ . '/playlists/download_progress.json';

	if (file_exists($progressFile)) {
		$progress = json_decode(file_get_contents($progressFile), true);
		echo json_encode([
			'success' => true,
			'downloaded' => $progress['downloaded'] ?? 0,
			'total' => $progress['total'] ?? 0,
			'status' => $progress['status'] ?? 'unknown'
		]);
	} else {
		echo json_encode(['success' => true, 'downloaded' => 0, 'total' => 0, 'status' => 'idle']);
	}
}

function handleDeleteTemp(): void
{
	$playlistFile = __DIR__ . '/playlists/playlist_temp.m3u';

	if (file_exists($playlistFile)) {
		@unlink($playlistFile);
	}

	echo json_encode(['success' => true]);
}
