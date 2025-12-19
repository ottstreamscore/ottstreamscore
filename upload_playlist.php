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

	// Ensure playlists directory exists
	$playlistDir = __DIR__ . '/playlists';
	if (!file_exists($playlistDir)) {
		if (!mkdir($playlistDir, 0700, true)) {
			echo json_encode(['success' => false, 'error' => 'Failed to create playlists directory']);
			exit;
		}

		// Create index.php to prevent directory browsing (works on all servers)
		file_put_contents($playlistDir . '/index.php', "<?php\nhttp_response_code(403);\ndie('Access denied');\n");
	}

	// Handle chunked upload
	if (isset($_FILES['file'])) {
		$file = $_FILES['file'];

		// Get chunk information
		$chunkIndex = isset($_POST['chunkIndex']) ? intval($_POST['chunkIndex']) : 0;
		$totalChunks = isset($_POST['totalChunks']) ? intval($_POST['totalChunks']) : 1;
		$fileName = isset($_POST['fileName']) ? basename($_POST['fileName']) : $file['name'];

		// Validate file type using original filename
		$allowedExtensions = ['m3u', 'm3u8'];
		$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		if (!in_array($fileExtension, $allowedExtensions)) {
			echo json_encode(['success' => false, 'error' => 'Invalid file type. Only .m3u and .m3u8 files are allowed.']);
			exit;
		}

		// Sanitize filename - keep original name but remove dangerous characters
		$safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
		$targetFile = $playlistDir . '/' . $safeFileName;
		$tempFile = $targetFile . '.tmp';

		// Check for errors
		if ($file['error'] !== UPLOAD_ERR_OK) {
			// Clean up partial upload
			if (file_exists($tempFile)) {
				unlink($tempFile);
			}
			echo json_encode(['success' => false, 'error' => 'Upload error: ' . $file['error']]);
			exit;
		}

		// Append chunk to temp file
		$mode = ($chunkIndex === 0) ? 'wb' : 'ab';
		$out = fopen($tempFile, $mode);
		$in = fopen($file['tmp_name'], 'rb');

		if ($out && $in) {
			while ($chunk = fread($in, 8192)) {
				fwrite($out, $chunk);
			}
			fclose($in);
			fclose($out);

			// If this is the last chunk, finalize
			if ($chunkIndex === $totalChunks - 1) {
				// Remove any existing playlist
				if (file_exists($targetFile)) {
					unlink($targetFile);
				}

				// Move temp file to final location
				if (rename($tempFile, $targetFile)) {
					echo json_encode([
						'success' => true,
						'message' => 'Playlist uploaded successfully',
						'filename' => $safeFileName,
						'size' => filesize($targetFile)
					]);
				} else {
					echo json_encode(['success' => false, 'error' => 'Failed to finalize upload']);
				}
			} else {
				// More chunks to come
				echo json_encode([
					'success' => true,
					'chunk' => $chunkIndex + 1,
					'total' => $totalChunks
				]);
			}
		} else {
			// Clean up on failure
			if (file_exists($tempFile)) {
				unlink($tempFile);
			}
			echo json_encode(['success' => false, 'error' => 'Failed to write file']);
		}
	} else {
		echo json_encode(['success' => false, 'error' => 'No file uploaded']);
	}
} catch (Throwable $e) {
	error_log('upload_playlist.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
	header('Content-Type: application/json');
	http_response_code(500);
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
