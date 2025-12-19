<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

try {
	require_once __DIR__ . '/_boot.php';

	if (!is_logged_in()) {
		header('Content-Type: application/json');
		http_response_code(401);
		echo json_encode(['error' => 'Unauthorized']);
		exit;
	}

	header('Content-Type: application/json');

	$playlistDir = __DIR__ . '/playlists';

	function formatBytes($bytes, $precision = 2)
	{
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= pow(1024, $pow);
		return round($bytes, $precision) . ' ' . $units[$pow];
	}

	// Check if directory exists
	if (!file_exists($playlistDir)) {
		echo json_encode(['success' => true, 'hasPlaylist' => false]);
		exit;
	}

	// Look for playlist files
	$files = glob($playlistDir . '/*.{m3u,m3u8}', GLOB_BRACE);

	if (empty($files)) {
		echo json_encode(['success' => true, 'hasPlaylist' => false]);
	} else {
		$file = $files[0];
		echo json_encode([
			'success' => true,
			'hasPlaylist' => true,
			'filename' => basename($file),
			'size' => filesize($file),
			'sizeFormatted' => formatBytes(filesize($file)),
			'uploaded' => date('Y-m-d H:i:s', filemtime($file))
		]);
	}
} catch (Throwable $e) {
	error_log('check_playlist.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
	header('Content-Type: application/json');
	http_response_code(500);
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
