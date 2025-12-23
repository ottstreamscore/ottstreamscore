<?php

declare(strict_types=1);

// epg_cron.php — EPG downloader + DB importer with compression support

chdir(__DIR__);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/epg_cron_errors.log');

@ini_set('max_execution_time', '0');
@ini_set('default_socket_timeout', '60');
@ini_set('memory_limit', '512M');

require_once __DIR__ . '/_boot.php';

// =================== HELPER FUNCTIONS ===================

function epg_log(string $msg): void
{
	$line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
	@file_put_contents(__DIR__ . '/epg_cron.log', $line, FILE_APPEND);
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
	// Read first few bytes to detect format
	$fh = @fopen($filepath, 'rb');
	if (!$fh) {
		return null;
	}

	$header = fread($fh, 10);
	fclose($fh);

	if (strlen($header) < 2) {
		return null;
	}

	// Check for gzip magic bytes (1f 8b)
	if (ord($header[0]) === 0x1f && ord($header[1]) === 0x8b) {
		$xmlContent = @file_get_contents('compress.zlib://' . $filepath);
		if ($xmlContent === false) {
			return null;
		}
		return $xmlContent;
	}

	// Check for zip magic bytes (50 4b)
	if (ord($header[0]) === 0x50 && ord($header[1]) === 0x4b) {
		$zip = new ZipArchive();
		if ($zip->open($filepath) !== true) {
			return null;
		}

		// Find first XML file in zip
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$filename = $zip->getNameIndex($i);
			if (preg_match('/\.xml$/i', $filename)) {
				$xmlContent = $zip->getFromIndex($i);
				$zip->close();
				return $xmlContent;
			}
		}
		$zip->close();
		return null;
	}

	// Check if it's already XML
	if (strpos($header, '<?xml') !== false || strpos($header, '<tv') !== false) {
		return file_get_contents($filepath);
	}

	return null;
}

// =================== MAIN EXECUTION ===================

try {
	$pdo = get_db_connection();

	// Set longer timeouts for long-running import
	try {
		$pdo->exec("SET SESSION wait_timeout = 28800"); // 8 hours
		$pdo->exec("SET SESSION interactive_timeout = 28800");
		$pdo->exec("SET SESSION max_allowed_packet = 67108864"); // 64MB
	} catch (PDOException $e) {
		epg_log("Warning: Could not set session timeouts: " . $e->getMessage());
	}

	// Get EPG URL from settings
	$epgUrl = get_setting('epg_url', '');
	if (empty($epgUrl)) {
		throw new Exception("No EPG URL configured in settings");
	}

	// Download to playlists directory
	$tmpFile = __DIR__ . '/playlists/epg_temp_' . time() . '.dat';

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
		CURLOPT_USERAGENT      => 'WhisTV/1.0',
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

	// Detect format and decompress if needed
	$xmlContent = detect_and_decompress($tmpFile);
	if ($xmlContent === null) {
		throw new Exception("Failed to detect/decompress EPG file format");
	}

	// Save decompressed XML to temp file
	$xmlFile = __DIR__ . '/playlists/epg_temp_' . time() . '.xml';
	file_put_contents($xmlFile, $xmlContent);
	unset($xmlContent); // Free memory

	// Delete original download
	@unlink($tmpFile);

	// Clean up stale records (older than 4 days) BEFORE importing new data
	// This allows incremental updates while keeping recent programmes
	$deletedCount = $pdo->exec("DELETE FROM epg_data WHERE start_timestamp < DATE_SUB(NOW(), INTERVAL 4 DAY)");

	// Parse XML with XMLReader
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

	// Only keep programmes starting from "yesterday"
	$dateUnixNow = strtotime("yesterday");

	$accepted = 0;
	$skipped = 0;
	$batchCount = 0;
	$rowsSinceLastPing = 0;

	$pdo->beginTransaction();

	while ($reader->read()) {
		if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'programme') {
			$node = $reader->expand();
			if (!$node) {
				$skipped++;
				continue;
			}

			$channelId = $node->getAttribute('channel');
			$rawStart = $node->getAttribute('start');
			$rawStop = $node->getAttribute('stop');

			// Parse XMLTV timestamps
			$startTs = strtotime($rawStart);
			$stopTs = strtotime($rawStop);

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

			// Convert to configured timezone
			$dtStart = (new DateTime('@' . $startTs))->setTimezone($tzObj);
			$dtStop = (new DateTime('@' . $stopTs))->setTimezone($tzObj);

			$startStr = $dtStart->format('Y-m-d H:i:s');
			$stopStr = $dtStop->format('Y-m-d H:i:s');

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
				$rowsSinceLastPing++;
			} catch (PDOException $e) {
				epg_log("Insert error: " . $e->getMessage());
			}

			// Commit batch and ping connection every 500 rows
			if ($batchCount >= 500) {
				$pdo->commit();

				// Ping connection to keep it alive
				try {
					$pdo->query('SELECT 1');
				} catch (PDOException $e) {
					epg_log("Connection lost, attempting to reconnect");
					$pdo = get_db_connection();
					$insert = $pdo->prepare("
						INSERT IGNORE INTO epg_data 
							(tvg_id, start_timestamp, end_timestamp, title, description)
						VALUES 
							(:tvg_id, :start_timestamp, :end_timestamp, :title, :description)
					");
				}

				$pdo->beginTransaction();
				$batchCount = 0;
			}

			// Ping connection every 2500 rows even if not committing
			if ($rowsSinceLastPing >= 2500) {
				try {
					$pdo->query('SELECT 1');
					$rowsSinceLastPing = 0;
				} catch (PDOException $e) {
					// Connection lost mid-transaction
					epg_log("Connection lost during processing");
					throw $e;
				}
			}
		}
	}

	$reader->close();
	$pdo->commit();

	// Delete temp XML file
	@unlink($xmlFile);

	// Update success timestamp
	update_sync_status($pdo, date('Y-m-d H:i:s'));

	echo "EPG import complete. Added $accepted programmes, skipped $skipped." . PHP_EOL;
} catch (Exception $e) {
	epg_log("ERROR: " . $e->getMessage());

	// Cleanup temp files
	if (isset($tmpFile) && file_exists($tmpFile)) {
		@unlink($tmpFile);
	}
	if (isset($xmlFile) && file_exists($xmlFile)) {
		@unlink($xmlFile);
	}

	// ALWAYS attempt to write failure status, even if pdo failed earlier
	try {
		if (!isset($pdo)) {
			// Try to get connection if we don't have it
			require_once __DIR__ . '/_boot.php';
			$pdo = get_db_connection();
		}
		update_sync_status($pdo, 'failure');
		epg_log("Failure status written to database");
	} catch (Exception $dbError) {
		// Log but don't fail if we can't write to DB
		epg_log("Failed to write failure status to database: " . $dbError->getMessage());
	}

	echo "ERROR: " . $e->getMessage() . PHP_EOL;
	exit(1);
}
