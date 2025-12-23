<?php

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

if (!is_logged_in()) {
	header('Content-Type: application/json');
	http_response_code(401);
	echo json_encode(['success' => false, 'error' => 'Unauthorized']);
	exit;
}

session_write_close();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	echo json_encode(['success' => false, 'error' => 'Invalid request method']);
	exit;
}

$pdo = get_db_connection();
$playlistUrl = get_setting('playlist_url', '');

if (empty($playlistUrl)) {
	echo json_encode(['success' => false, 'error' => 'No playlist URL configured']);
	exit;
}

$outputFile = __DIR__ . '/playlists/playlist_temp.m3u';
$progressFile = __DIR__ . '/playlists/download_progress.json';

if (!is_dir(__DIR__ . '/playlists')) {
	mkdir(__DIR__ . '/playlists', 0755, true);
}

file_put_contents($progressFile, json_encode([
	'downloaded' => 0,
	'total' => 0,
	'status' => 'starting'
]));

$ch = curl_init($playlistUrl);
$fp = fopen($outputFile, 'w');

if (!$fp) {
	echo json_encode(['success' => false, 'error' => 'Cannot create temp file']);
	exit;
}

$updateCount = 0;

// Use CURL's native progress function
curl_setopt($ch, CURLOPT_NOPROGRESS, false);
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($progressFile, &$updateCount) {
	$updateCount++;
	// Update every 5 calls to reduce file I/O
	if ($updateCount % 5 === 0) {
		file_put_contents($progressFile, json_encode([
			'downloaded' => $downloaded,
			'total' => $download_size,
			'status' => 'downloading'
		]));
	}
	return 0;
});

curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);
fclose($fp);

@unlink($progressFile);

if (!$result) {
	@unlink($outputFile);
	echo json_encode(['success' => false, 'error' => 'Download failed: ' . $error]);
	exit;
}

if ($httpCode !== 200) {
	@unlink($outputFile);
	echo json_encode(['success' => false, 'error' => 'HTTP Error ' . $httpCode]);
	exit;
}

if (!file_exists($outputFile) || filesize($outputFile) === 0) {
	@unlink($outputFile);
	echo json_encode(['success' => false, 'error' => 'Downloaded file is empty']);
	exit;
}

echo json_encode(['success' => true, 'message' => 'Playlist downloaded successfully']);
