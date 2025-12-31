<?php

declare(strict_types=1);

// epg_cron.php – EPG downloader + DB importer with compression support

chdir(__DIR__);

@ini_set('max_execution_time', '0');
@ini_set('default_socket_timeout', '60');
@ini_set('memory_limit', '512M');

// =================== HELPER FUNCTIONS ===================

function epg_log(string $msg): void
{
	$line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
	@file_put_contents(__DIR__ . '/epg_cron.log', $line, FILE_APPEND);
	echo $msg . PHP_EOL; // Also output to console for cron logs
}

function update_sync_status(PDO $pdo, string $status): void
{
	try {
		$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('epg_last_sync_date', :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
		$stmt->execute(['value' => $status]);
	} catch (Exception $e) {
		epg_log("Failed to update sync status: " . $e->getMessage());
	}
}

function detect_and_decompress(string $filepath): ?string
{
	$fh = @fopen($filepath, 'rb');
	if (!$fh) {
		epg_log("Cannot open file for decompression: $filepath");
		return null;
	}

	$header = fread($fh, 10);
	fclose($fh);

	if (strlen($header) < 2) {
		epg_log("File too short to detect format");
		return null;
	}

	// Check for gzip magic bytes (1f 8b)
	if (ord($header[0]) === 0x1f && ord($header[1]) === 0x8b) {
		$xmlContent = @file_get_contents('compress.zlib://' . $filepath);
		if ($xmlContent === false) {
			epg_log("Failed to decompress GZIP file");
			return null;
		}
		return $xmlContent;
	}

	// Check for zip magic bytes (50 4b)
	if (ord($header[0]) === 0x50 && ord($header[1]) === 0x4b) {
		$zip = new ZipArchive();
		if ($zip->open($filepath) !== true) {
			epg_log("Failed to open ZIP file");
			return null;
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$filename = $zip->getNameIndex($i);
			if (preg_match('/\.xml$/i', $filename)) {
				$xmlContent = $zip->getFromIndex($i);
				$zip->close();
				return $xmlContent;
			}
		}
		$zip->close();
		epg_log("No XML file found in ZIP archive");
		return null;
	}

	// Check if it's already XML
	if (strpos($header, '<?xml') !== false || strpos($header, '<tv') !== false) {
		return file_get_contents($filepath);
	}

	return null;
}

// Clean up old temp files from previous runs
function cleanup_old_temp_files(): void
{
	$playlistsDir = __DIR__ . '/playlists';
	if (!is_dir($playlistsDir)) {
		return;
	}

	$files = glob($playlistsDir . '/epg_temp_*');
	foreach ($files as $file) {
		// Delete files older than 1 hour
		if (is_file($file) && (time() - filemtime($file)) > 3600) {
			@unlink($file);
		}
	}
}

// =================== MAIN EXECUTION ===================

$tmpFile = null;
$xmlFile = null;
$pdo = null;

try {
	// Clean up old temp files first
	cleanup_old_temp_files();

	// Load boot file
	if (!file_exists(__DIR__ . '/_boot.php')) {
		throw new Exception("_boot.php not found in " . __DIR__);
	}

	require_once __DIR__ . '/_boot.php';

	// Verify required functions
	if (!function_exists('get_db_connection')) {
		throw new Exception("get_db_connection() function not found");
	}
	if (!function_exists('get_setting')) {
		throw new Exception("get_setting() function not found");
	}

	$pdo = get_db_connection();
	// Set longer timeouts
	try {
		$pdo->exec("SET SESSION wait_timeout = 28800");
		$pdo->exec("SET SESSION interactive_timeout = 28800");
		$pdo->exec("SET SESSION max_allowed_packet = 67108864");
	} catch (PDOException $e) {
		epg_log("Warning: Could not set session timeouts: " . $e->getMessage());
	}

	// Get or create timezone object - MUST always be set
	$tzObj = null;
	try {
		// Check if already set from _boot.php
		if (!isset($tzObj) || !($tzObj instanceof DateTimeZone)) {
			$timezone = 'UTC'; // default
			try {
				$timezone = get_setting('timezone', 'UTC');
			} catch (Exception $e) {
				epg_log("Could not get timezone setting: " . $e->getMessage());
			}

			try {
				$tzObj = new DateTimeZone($timezone);
			} catch (Exception $e) {
				$tzObj = new DateTimeZone('UTC');
			}
		} else {
			epg_log("Using existing timezone object: " . $tzObj->getName());
		}
	} catch (Exception $e) {
		// Ultimate fallback
		epg_log("Timezone initialization failed, forcing UTC: " . $e->getMessage());
		$tzObj = new DateTimeZone('UTC');
	}

	// Final safety check
	if (!($tzObj instanceof DateTimeZone)) {
		epg_log("CRITICAL: Forcing UTC timezone as last resort");
		$tzObj = new DateTimeZone('UTC');
	}

	// Get EPG URL
	$epgUrl = get_setting('epg_url', '');
	if (empty($epgUrl)) {
		throw new Exception("No EPG URL configured in settings");
	}


	// Check playlists directory
	$playlistsDir = __DIR__ . '/playlists';
	if (!is_dir($playlistsDir)) {
		throw new Exception("Playlists directory does not exist: $playlistsDir");
	}
	if (!is_writable($playlistsDir)) {
		throw new Exception("Playlists directory is not writable: $playlistsDir");
	}

	// Download EPG file
	$tmpFile = $playlistsDir . '/epg_temp_' . time() . '.dat';

	$ch = curl_init($epgUrl);
	$fh = fopen($tmpFile, 'w+b');
	if (!$fh) {
		throw new Exception("Cannot open temp file: $tmpFile");
	}

	curl_setopt_array($ch, [
		CURLOPT_FILE           => $fh,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT        => 300,
		CURLOPT_USERAGENT      => 'OTTStreamScore/2.0',
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => 0,
	]);

	$ok = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlErr = curl_error($ch);
	curl_close($ch);
	fclose($fh);

	if (!$ok || $httpCode !== 200) {
		throw new Exception("Download failed (HTTP $httpCode): $curlErr");
	}

	$fileSize = filesize($tmpFile);

	// Detect format and decompress
	$xmlContent = detect_and_decompress($tmpFile);
	if ($xmlContent === null) {
		throw new Exception("Failed to detect/decompress EPG file format");
	}


	// Save decompressed XML
	$xmlFile = $playlistsDir . '/epg_temp_' . time() . '.xml';
	if (file_put_contents($xmlFile, $xmlContent) === false) {
		throw new Exception("Failed to write decompressed XML to: $xmlFile");
	}
	unset($xmlContent);

	// Delete compressed file
	@unlink($tmpFile);
	$tmpFile = null;

	// Clean up old records
	$deletedCount = $pdo->exec("DELETE FROM epg_data WHERE start_timestamp < DATE_SUB(NOW(), INTERVAL 4 DAY)");

	// Parse XML
	$reader = new XMLReader();
	if (!$reader->open($xmlFile, null, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT)) {
		throw new Exception("Cannot open XML file with XMLReader");
	}

	// Prepare insert statement
	$insert = $pdo->prepare("
		INSERT IGNORE INTO epg_data 
			(tvg_id, start_timestamp, end_timestamp, title, description)
		VALUES 
			(:tvg_id, :start_timestamp, :end_timestamp, :title, :description)
	");

	$dateUnixNow = strtotime("yesterday");
	$accepted = 0;
	$skipped = 0;
	$batchCount = 0;
	$totalProcessed = 0;

	// Start transaction
	$pdo->beginTransaction();

	while ($reader->read()) {
		if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'programme') {
			$totalProcessed++;

			$node = $reader->expand();
			if (!$node) {
				$skipped++;
				continue;
			}

			$channelId = $node->getAttribute('channel');
			$rawStart = $node->getAttribute('start');
			$rawStop = $node->getAttribute('stop');

			// Parse timestamps
			$startTs = @strtotime($rawStart);
			$stopTs = @strtotime($rawStop);

			if ($startTs === false || $stopTs === false) {
				$skipped++;
				continue;
			}

			// Skip old programmes
			if ($startTs < $dateUnixNow) {
				$skipped++;
				continue;
			}

			// Extract title and description
			$title = '';
			$descr = '';

			for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
				if ($child->nodeType !== XML_ELEMENT_NODE) {
					continue;
				}
				if ($child->nodeName === 'title' && $title === '') {
					$title = $child->textContent;
				} elseif ($child->nodeName === 'desc' && $descr === '') {
					$descr = $child->textContent;
				}
			}

			// Convert to timezone
			$dtStart = (new DateTime('@' . $startTs))->setTimezone($tzObj);
			$dtStop = (new DateTime('@' . $stopTs))->setTimezone($tzObj);

			$startStr = $dtStart->format('Y-m-d H:i:s');
			$stopStr = $dtStop->format('Y-m-d H:i:s');

			// Insert record
			try {
				$insert->execute([
					':tvg_id' => $channelId,
					':start_timestamp' => $startStr,
					':end_timestamp' => $stopStr,
					':title' => $title,
					':description' => $descr,
				]);
				$accepted++;
				$batchCount++;
			} catch (PDOException $e) {
				epg_log("Insert error: " . $e->getMessage());
				$skipped++;
			}

			// Commit every 1000 rows to save progress
			if ($batchCount >= 1000) {
				$pdo->commit();
				//	epg_log("Progress: Imported $accepted records (total processed: $totalProcessed, skipped: $skipped)");

				// Ping connection
				try {
					$pdo->query('SELECT 1');
				} catch (PDOException $e) {
					epg_log("Connection lost, reconnecting...");
					$pdo = get_db_connection();
					$insert = $pdo->prepare("
						INSERT IGNORE INTO epg_data 
							(tvg_id, start_timestamp, end_timestamp, title, description)
						VALUES 
							(:tvg_id, :start_timestamp, :end_timestamp, :title, :description)
					");
				}

				// Start new transaction
				$pdo->beginTransaction();
				$batchCount = 0;
			}
		}
	}

	// Final commit for remaining records
	if ($batchCount > 0) {
		$pdo->commit();
		//	epg_log("Final commit: $batchCount remaining records");
	}

	$reader->close();

	// Delete temp XML file
	if ($xmlFile && file_exists($xmlFile)) {
		@unlink($xmlFile);
		//	epg_log("Deleted temp XML file");
	}

	// Update sync status
	update_sync_status($pdo, date('Y-m-d H:i:s'));
} catch (Exception $e) {
	epg_log("ERROR: " . $e->getMessage());
	epg_log("Stack trace: " . $e->getTraceAsString());

	// Rollback transaction if active
	if ($pdo) {
		try {
			$pdo->rollBack();
			//	epg_log("Transaction rolled back");
		} catch (PDOException $ex) {
			// Transaction wasn't active
		}
	}

	// Clean up temp files
	if ($tmpFile && file_exists($tmpFile)) {
		@unlink($tmpFile);
	}
	if ($xmlFile && file_exists($xmlFile)) {
		@unlink($xmlFile);
	}

	// Update failure status
	if ($pdo) {
		try {
			update_sync_status($pdo, 'failure');
		} catch (Exception $dbError) {
			epg_log("Failed to write failure status: " . $dbError->getMessage());
		}
	}

	echo "ERROR: " . $e->getMessage() . PHP_EOL;
	exit(1);
}
