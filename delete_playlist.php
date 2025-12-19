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

	// Find and delete playlist files
	$files = glob($playlistDir . '/*.{m3u,m3u8}', GLOB_BRACE);

	if (empty($files)) {
		echo json_encode(['success' => true, 'message' => 'No playlist to delete']);
		exit;
	}

	$deleted = [];
	$errors = [];

	foreach ($files as $file) {
		if (unlink($file)) {
			$deleted[] = basename($file);
		} else {
			$errors[] = basename($file);
		}
	}

	// Also clean up any temp files
	$tempFiles = glob($playlistDir . '/*.tmp');
	foreach ($tempFiles as $tempFile) {
		@unlink($tempFile);
	}

	if (empty($errors)) {
		echo json_encode([
			'success' => true,
			'message' => 'Playlist deleted successfully',
			'deleted' => $deleted
		]);
	} else {
		echo json_encode([
			'success' => false,
			'error' => 'Failed to delete some files',
			'deleted' => $deleted,
			'failed' => $errors
		]);
	}
} catch (Throwable $e) {
	error_log('delete_playlist.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
	header('Content-Type: application/json');
	http_response_code(500);
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
