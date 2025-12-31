<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/import_errors.log');
error_reporting(E_ALL);

set_time_limit(0);

require_once __DIR__ . '/_boot.php';

if (!is_logged_in()) {
	header('Content-Type: application/json');
	http_response_code(401);
	echo json_encode(['error' => 'Unauthorized']);
	exit;
}

$pdo = db();

if (session_status() !== PHP_SESSION_ACTIVE) {
	@session_start();
}

$isAjax = isset($_POST['_ajax']) && $_POST['_ajax'] === '1';

// Batch processing actions
if (isset($_POST['action'])) {
	if ($_POST['action'] === 'start_import') {
		startBatchImport();
		exit;
	} elseif ($_POST['action'] === 'process_batch') {
		processBatch();
		exit;
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
	$playlistDir = __DIR__ . '/playlists';
	$playlistFiles = glob($playlistDir . '/*.{m3u,m3u8}', GLOB_BRACE);

	if (empty($playlistFiles)) {
		if ($isAjax) {
			header('Content-Type: application/json');
			echo json_encode([
				'success' => false,
				'ok' => false,
				'error' => 'No playlist file found. Please upload a playlist first.'
			]);
			exit;
		} else {
			$_SESSION['flash'] = [
				'ok' => false,
				'message' => 'No playlist file found. Please upload a playlist first.'
			];
			header('Location: admin.php?tab=playlist');
			exit;
		}
	}

	$playlistPath = $playlistFiles[0];
	$_POST['playlist'] = basename($playlistPath);
	$_POST['directory'] = 'playlists';
}

function redirect_back(array $flash): void
{
	global $isAjax;

	if ($isAjax) {
		header('Content-Type: application/json');
		echo json_encode([
			'success' => $flash['ok'],
			'ok' => $flash['ok'],
			'status' => $flash['ok'] ? 'completed' : 'error',
			'message' => $flash['message'] ?? '',
			'stats' => $flash['stats'] ?? null,
			'error' => !$flash['ok'] ? ($flash['message'] ?? 'Unknown error') : null
		]);
		exit;
	} else {
		$_SESSION['playlist_flash'] = $flash;
		header('Location: admin.php?tab=playlist');
		exit;
	}
}

function cut(string $s, int $max): string
{
	$s = trim($s);
	return strlen($s) > $max ? substr($s, 0, $max) : $s;
}

function parse_attr(string $line, string $key): ?string
{
	// matches key="value" (tolerates spaces)
	if (preg_match('/\b' . preg_quote($key, '/') . '="([^"]*)"/i', $line, $m)) {
		return $m[1];
	}
	return null;
}

function group_from_group_title(?string $groupTitle, ?string $tvgName): string
{
	$g = trim((string)$groupTitle);
	if ($g === '') return '';
	$n = trim((string)$tvgName);
	if ($n !== '') {
		$patterns = [
			' - ' . $n,
			' | ' . $n,
			' ' . $n,
		];
		foreach ($patterns as $p) {
			if (str_ends_with($g, $p)) {
				$g = rtrim(substr($g, 0, -strlen($p)));
				break;
			}
		}
	}
	return $g;
}


try {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		redirect_back([
			'ok' => false,
			'message' => 'Invalid request method.',
		]);
	}

	$playlistBase = cut((string)($_POST['playlist'] ?? ''), 255);
	$directory = cut((string)($_POST['directory'] ?? '.'), 255);
	$mode = 'sync';

	// Sanitize directory - only allow safe characters
	$directory = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $directory);
	$directory = trim($directory, '/');

	// Prevent directory traversal
	if (str_contains($directory, '..') || str_contains($playlistBase, '..')) {
		redirect_back([
			'ok' => false,
			'message' => 'Invalid directory or filename.',
		]);
	}

	if ($playlistBase === '' || str_contains($playlistBase, '/') || str_contains($playlistBase, '\\')) {
		redirect_back([
			'ok' => false,
			'message' => 'Invalid playlist filename.',
		]);
	}

	// Build full path
	$baseDir = __DIR__;
	$fullDir = $directory === '.' ? $baseDir : $baseDir . '/' . $directory;

	// Validate directory
	if (!is_dir($fullDir) || !is_readable($fullDir)) {
		redirect_back([
			'ok' => false,
			'message' => 'Invalid or inaccessible directory.',
		]);
	}

	$playlistPath = $fullDir . '/' . $playlistBase;

	if (!is_file($playlistPath) || !is_readable($playlistPath)) {
		redirect_back([
			'ok' => false,
			'message' => "Playlist file not found: {$directory}/{$playlistBase}",
		]);
	}

	// Handle credential replacement if provided
	$newUsername = trim((string)($_POST['new_username'] ?? ''));
	$newPassword = trim((string)($_POST['new_password'] ?? ''));

	if ($newUsername !== '' && $newPassword !== '') {
		$content = file_get_contents($playlistPath);
		if ($content === false) {
			redirect_back([
				'ok' => false,
				'message' => 'Unable to read playlist file for credential replacement',
			]);
		}

		$pattern = '#(/live/)[^/]+/[^/]+/#';
		$replacement = '${1}' . $newUsername . '/' . $newPassword . '/';
		$content = preg_replace($pattern, $replacement, $content);

		if (file_put_contents($playlistPath, $content) === false) {
			redirect_back([
				'ok' => false,
				'message' => 'Unable to write updated credentials to playlist file',
			]);
		}
	}

	$fh = fopen($playlistPath, 'rb');
	if (!$fh) {
		redirect_back([
			'ok' => false,
			'message' => "Unable to open playlist file: {$playlistBase}",
		]);
	}

	// === SCHEMA DETECTION & MIGRATION ===

	// Check if channel_feeds table exists
	$hasJunctionTable = false;
	try {
		$pdo->query("SELECT 1 FROM channel_feeds LIMIT 1");
		$hasJunctionTable = true;
	} catch (Throwable $e) {
		// Table doesn't exist, create it
		try {
			$pdo->exec("
				CREATE TABLE IF NOT EXISTS channel_feeds (
					channel_id BIGINT(20) UNSIGNED NOT NULL,
					feed_id BIGINT(20) UNSIGNED NOT NULL,
					created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					last_seen TIMESTAMP NULL DEFAULT NULL,
					PRIMARY KEY (channel_id, feed_id),
					KEY idx_feed_id (feed_id),
					KEY idx_channel_id (channel_id)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
			");
			$hasJunctionTable = true;
		} catch (Throwable $e2) {
			// Failed to create table
			$hasJunctionTable = false;
		}
	}

	// Check if feeds table still has channel_id (old schema)
	$hasOldChannelIdColumn = false;
	try {
		$pdo->query("SELECT channel_id FROM feeds LIMIT 1");
		$hasOldChannelIdColumn = true;

		// If we have junction table AND old column, migrate data once
		if ($hasJunctionTable) {
			// Check if migration is needed (channel_feeds is empty but feeds has data)
			$cfCount = (int)$pdo->query("SELECT COUNT(*) FROM channel_feeds")->fetchColumn();
			$feedsCount = (int)$pdo->query("SELECT COUNT(*) FROM feeds WHERE channel_id IS NOT NULL")->fetchColumn();

			if ($cfCount === 0 && $feedsCount > 0) {
				// Migrate existing data
				$pdo->exec("
					INSERT IGNORE INTO channel_feeds (channel_id, feed_id, created_at)
					SELECT channel_id, id, created_at
					FROM feeds
					WHERE channel_id IS NOT NULL
				");
			}
		}
	} catch (Throwable $e) {
		$hasOldChannelIdColumn = false;
	}

	// Check if url_display column exists
	$hasUrlDisplayCol = false;
	try {
		$pdo->query("SELECT url_display FROM feeds LIMIT 1");
		$hasUrlDisplayCol = true;
	} catch (Throwable $e) {
		$hasUrlDisplayCol = false;
	}

	// Check if catch_up column exists
	$hasCatchUpCols = false;
	try {
		$pdo->query("SELECT catch_up, catch_up_days FROM feeds LIMIT 1");
		$hasCatchUpCols = true;
	} catch (Throwable $e) {
		$hasCatchUpCols = false;
	}


	// Check if last_seen column exists on feeds table (old cleanup method)
	$hasFeedsLastSeenCol = false;
	try {
		$pdo->query("SELECT last_seen FROM feeds LIMIT 1");
		$hasFeedsLastSeenCol = true;
	} catch (Throwable $e) {
		$hasFeedsLastSeenCol = false;
	}

	// Check if last_seen exists on channel_feeds (new cleanup method)
	$hasJunctionLastSeenCol = false;
	if ($hasJunctionTable) {
		try {
			$pdo->query("SELECT last_seen FROM channel_feeds LIMIT 1");
			$hasJunctionLastSeenCol = true;
		} catch (Throwable $e) {
			// Try to add it
			try {
				$pdo->exec("ALTER TABLE channel_feeds ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL");
				$hasJunctionLastSeenCol = true;
			} catch (Throwable $e2) {
				$hasJunctionLastSeenCol = false;
			}
		}
	}

	// === PREPARE STATEMENTS ===

	$stFindChannel = $pdo->prepare("
		SELECT id FROM channels
		WHERE tvg_id = :tvg_id AND group_title = :group_title
		LIMIT 1
	");

	$stInsertChannel = $pdo->prepare("
		INSERT INTO channels (tvg_id, tvg_name, tvg_logo, group_title)
		VALUES (:tvg_id, :tvg_name, :tvg_logo, :group_title)
	");

	$stUpdateChannel = $pdo->prepare("
		UPDATE channels
		SET tvg_name = :tvg_name, tvg_logo = :tvg_logo
		WHERE id = :id
	");

	$stFindFeed = $pdo->prepare("SELECT id FROM feeds WHERE url_hash = :h LIMIT 1");

	// Build INSERT statement based on available columns
	$insertCols = ['url', 'url_hash'];
	$insertVals = [':url', ':h'];
	if ($hasUrlDisplayCol) {
		$insertCols[] = 'url_display';
		$insertVals[] = ':url_display';
	}
	if ($hasCatchUpCols) {
		$insertCols[] = 'catch_up';
		$insertCols[] = 'catch_up_days';
		$insertVals[] = ':catch_up';
		$insertVals[] = ':catch_up_days';
	}
	if ($hasOldChannelIdColumn) {
		$insertCols[] = 'channel_id';
		$insertVals[] = ':channel_id';
	}

	$stInsertFeed = $pdo->prepare(sprintf(
		"INSERT INTO feeds (%s) VALUES (%s)",
		implode(', ', $insertCols),
		implode(', ', $insertVals)
	));

	// Build UPDATE statement based on available columns
	$updateParts = ['url = :url'];
	if ($hasUrlDisplayCol) {
		$updateParts[] = 'url_display = :url_display';
	}
	if ($hasCatchUpCols) {
		$updateParts[] = 'catch_up = :catch_up';
		$updateParts[] = 'catch_up_days = :catch_up_days';
	}

	$stUpdateFeed = $pdo->prepare(sprintf(
		"UPDATE feeds SET %s WHERE id = :id",
		implode(', ', $updateParts)
	));

	// Statements for junction table management
	$stFindChannelFeed = null;
	$stInsertChannelFeed = null;
	$stMarkChannelFeedSeen = null;

	if ($hasJunctionTable) {
		$stFindChannelFeed = $pdo->prepare("
			SELECT 1 FROM channel_feeds
			WHERE channel_id = :channel_id AND feed_id = :feed_id
			LIMIT 1
		");

		$stInsertChannelFeed = $pdo->prepare("
			INSERT INTO channel_feeds (channel_id, feed_id)
			VALUES (:channel_id, :feed_id)
		");

		if ($hasJunctionLastSeenCol) {
			$stMarkChannelFeedSeen = $pdo->prepare("
				UPDATE channel_feeds
				SET last_seen = CURRENT_TIMESTAMP
				WHERE channel_id = :channel_id AND feed_id = :feed_id
			");
		}
	}

	// Counters
	$lines = 0;
	$extinf = 0;
	$live = 0;
	$skippedNonLive = 0;
	$channelsInserted = 0;
	$channelsUpdated = 0;
	$feedsInserted = 0;
	$feedsUpdated = 0;
	$feedsSkippedExisting = 0;
	$associationsCreated = 0;
	$associationsDeleted = 0;
	$orphanedFeeds = 0;
	$queueEntriesAdded = 0;

	$current = null;

	$pdo->beginTransaction();

	// Check managed hosting limit
	$is_managed_hosting = get_setting('managed_hosting', '');
	$managed_hosting_limit = 50000;
	$feedsSkippedLimit = 0;
	$current_feed_count = 0;

	if ($is_managed_hosting === '1' || $is_managed_hosting === 1) {
		// Count current feeds in database
		$current_feed_count = (int)$pdo->query("SELECT COUNT(*) FROM feeds")->fetchColumn();
	}

	// Mark all channel_feeds associations as stale (if using new schema with last_seen)
	if ($hasJunctionTable && $hasJunctionLastSeenCol) {
		$pdo->exec("UPDATE channel_feeds SET last_seen = NULL");
	}


	while (($line = fgets($fh)) !== false) {
		$lines++;
		$line = trim($line);
		if ($line === '') continue;

		if (str_starts_with($line, '#EXTINF:')) {
			$extinf++;

			$tvgId = parse_attr($line, 'tvg-id') ?? '';
			$tvgName = parse_attr($line, 'tvg-name') ?? '';
			$tvgLogo = parse_attr($line, 'tvg-logo') ?? '';
			$groupTitle = parse_attr($line, 'group-title') ?? '';

			// Parse catchup attributes
			$catchUpType = parse_attr($line, 'catchup-type');
			$catchUpDays = parse_attr($line, 'catchup-days');

			$groupTitle = group_from_group_title($groupTitle, $tvgName);

			$current = [
				'tvg_id' => cut($tvgId, 255),
				'tvg_name' => cut($tvgName, 255),
				'tvg_logo' => cut($tvgLogo, 500),
				'group_title' => cut($groupTitle, 255),
				'catch_up' => $catchUpType ? cut($catchUpType, 50) : null,
				'catch_up_days' => $catchUpDays ? cut($catchUpDays, 10) : null,
			];
			continue;
		}

		// URL line (not comment)
		if ($line[0] === '#') continue;
		if (!$current) continue;

		$url = $line;

		// LIVE only
		if (stripos($url, '/live/') === false) {
			$skippedNonLive++;
			$current = null;
			continue;
		}

		$live++;


		// ensure we have at least tvg_name
		if ($current['tvg_name'] === '') {
			$current['tvg_name'] = 'Unknown';
		}
		if ($current['tvg_id'] === '') {
			$current['tvg_id'] = 'dummy-' . substr(sha1($current['tvg_name'] . '|' . $url), 0, 10);
		}

		// === UPSERT CHANNEL ===
		$stFindChannel->execute([
			':tvg_id' => $current['tvg_id'],
			':group_title' => $current['group_title'],
		]);
		$channelId = (int)($stFindChannel->fetchColumn() ?: 0);

		if ($channelId <= 0) {
			$stInsertChannel->execute([
				':tvg_id' => $current['tvg_id'],
				':tvg_name' => $current['tvg_name'],
				':tvg_logo' => $current['tvg_logo'],
				':group_title' => $current['group_title'],
			]);
			$channelId = (int)$pdo->lastInsertId();
			$channelsInserted++;
		} else {
			$stUpdateChannel->execute([
				':tvg_name' => $current['tvg_name'],
				':tvg_logo' => $current['tvg_logo'],
				':id' => $channelId,
			]);
			$channelsUpdated += ($stUpdateChannel->rowCount() > 0) ? 1 : 0;
		}

		// === UPSERT FEED ===
		$h = sha1($url);
		$feedId = 0;
		$currentChannelFeedId = 0;

		// With junction table: find feed by channel association first
		if ($hasJunctionTable) {
			// Find existing feed for this channel
			$stFindByChannel = $pdo->prepare("
				SELECT f.id FROM feeds f
				INNER JOIN channel_feeds cf ON cf.feed_id = f.id
				WHERE cf.channel_id = :channel_id
				LIMIT 1
			");
			$stFindByChannel->execute([':channel_id' => $channelId]);
			$currentChannelFeedId = (int)($stFindByChannel->fetchColumn() ?: 0);
		}

		// Check if the new URL already exists in feeds table
		$stFindFeed->execute([':h' => $h]);
		$existingFeedId = (int)($stFindFeed->fetchColumn() ?: 0);

		if ($existingFeedId > 0) {
			// URL already exists
			$feedId = $existingFeedId;

			if ($currentChannelFeedId > 0 && $currentChannelFeedId !== $existingFeedId) {
				// Channel is switching to a different feed
				// The old association will be cleaned up by the stale deletion

				// BUT we still need to update the catch_up values for this existing feed
				$updateParams = [':id' => $feedId];
				$updateParts = [];

				if ($hasUrlDisplayCol) {
					$updateParams[':url_display'] = basename(parse_url($url, PHP_URL_PATH) ?: $url);
					$updateParts[] = 'url_display = :url_display';
				}
				if ($hasCatchUpCols) {
					$updateParams[':catch_up'] = $current['catch_up'];
					$updateParams[':catch_up_days'] = $current['catch_up_days'];
					$updateParts[] = 'catch_up = :catch_up';
					$updateParts[] = 'catch_up_days = :catch_up_days';
				}

				if (!empty($updateParts)) {
					$pdo->prepare(sprintf("UPDATE feeds SET %s WHERE id = :id", implode(', ', $updateParts)))
						->execute($updateParams);
				}

				$feedsUpdated++;
			} else {
				// Same feed, update url_display and catchup info if needed
				$updateParams = [':id' => $feedId];
				$updateParts = [];

				if ($hasUrlDisplayCol) {
					$updateParams[':url_display'] = basename(parse_url($url, PHP_URL_PATH) ?: $url);
					$updateParts[] = 'url_display = :url_display';
				}
				if ($hasCatchUpCols) {
					$updateParams[':catch_up'] = $current['catch_up'];
					$updateParams[':catch_up_days'] = $current['catch_up_days'];
					$updateParts[] = 'catch_up = :catch_up';
					$updateParts[] = 'catch_up_days = :catch_up_days';
				}

				if (!empty($updateParts)) {
					$pdo->prepare(sprintf("UPDATE feeds SET %s WHERE id = :id", implode(', ', $updateParts)))
						->execute($updateParams);
				}
			}
		} elseif ($currentChannelFeedId > 0) {
			// Channel has an existing feed, and URL doesn't exist elsewhere
			// Update the existing feed with new URL
			$feedId = $currentChannelFeedId;

			$params = [
				':url' => $url,
				':id' => $feedId,
			];
			if ($hasUrlDisplayCol) {
				$params[':url_display'] = basename(parse_url($url, PHP_URL_PATH) ?: $url);
			}
			if ($hasCatchUpCols) {
				$params[':catch_up'] = $current['catch_up'];
				$params[':catch_up_days'] = $current['catch_up_days'];
			}

			$stUpdateFeed->execute($params);

			// Also update url_hash in case URL changed
			$pdo->prepare("UPDATE feeds SET url_hash = :h WHERE id = :id")
				->execute([':h' => $h, ':id' => $feedId]);

			$feedsUpdated++;
		} else {

			// Check managed hosting limit before inserting
			if ($is_managed_hosting === '1' || $is_managed_hosting === 1) {
				if ($current_feed_count >= $managed_hosting_limit) {
					// Skip this feed - limit reached
					$feedsSkippedLimit++;
					$current = null;
					continue;
				}
			}

			// No existing feed - insert new one
			$params = [
				':url' => $url,
				':h' => $h,
			];
			if ($hasUrlDisplayCol) {
				$params[':url_display'] = basename(parse_url($url, PHP_URL_PATH) ?: $url);
			}
			if ($hasCatchUpCols) {
				$params[':catch_up'] = $current['catch_up'];
				$params[':catch_up_days'] = $current['catch_up_days'];
			}
			// Include channel_id if column exists (regardless of junction table)
			if ($hasOldChannelIdColumn) {
				$params[':channel_id'] = $channelId;
			}

			$stInsertFeed->execute($params);
			$feedId = (int)$pdo->lastInsertId();
			$feedsInserted++;

			if ($is_managed_hosting === '1' || $is_managed_hosting === 1) {
				$current_feed_count++; // Increment count for managed hosting
			}

			// Add new feed to check queue
			if ($hasJunctionTable) {
				try {
					$stmt = $pdo->prepare("
						INSERT IGNORE INTO feed_check_queue (feed_id, next_run_at, locked_at, lock_token, attempts, last_result_ok, last_error)
						VALUES (:feed_id, NOW(), NULL, NULL, 0, NULL, NULL)
					");
					$stmt->execute([':feed_id' => $feedId]);
					$queueEntriesAdded += $stmt->rowCount();
				} catch (Throwable $e) {
					// Queue table might not exist, ignore
				}
			}
		}

		// === CREATE CHANNEL-FEED ASSOCIATION (new schema) ===
		if ($hasJunctionTable && $feedId > 0 && $channelId > 0) {
			// Check if association already exists
			$stFindChannelFeed->execute([
				':channel_id' => $channelId,
				':feed_id' => $feedId,
			]);
			$exists = $stFindChannelFeed->fetchColumn();

			if (!$exists) {
				// Create new association
				try {
					$stInsertChannelFeed->execute([
						':channel_id' => $channelId,
						':feed_id' => $feedId,
					]);
					$associationsCreated++;
				} catch (Throwable $e) {
					// Ignore duplicate key errors (race condition)
				}
			}

			// Mark association as seen
			if ($hasJunctionLastSeenCol) {
				$stMarkChannelFeedSeen->execute([
					':channel_id' => $channelId,
					':feed_id' => $feedId,
				]);
			}
		}

		$current = null;
	}

	fclose($fh);

	// === CLEANUP: Delete stale associations (new schema) ===
	if ($hasJunctionTable && $hasJunctionLastSeenCol) {
		$stmt = $pdo->prepare("DELETE FROM channel_feeds WHERE last_seen IS NULL");
		$stmt->execute();
		$associationsDeleted = $stmt->rowCount();

		// NOTE: We do NOT delete orphaned feeds automatically because they may have
		// valuable check history. Manual cleanup can be done separately if needed.
	}

	$pdo->commit();

	// Update last_sync_date
	try {
		$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('last_sync_date', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()");
		$stmt->execute();
	} catch (Throwable $e) {
		// Non-critical, continue
	}

	$stats = [
		'Playlist file' => $playlistBase,
		'Mode' => 'Sync',
		'Schema' => $hasJunctionTable ? 'Junction table (many-to-many)' : 'Legacy (one-to-one)',
		'Lines read' => number_format($lines),
		'EXTINF entries' => number_format($extinf),
		'LIVE URLs imported' => number_format($live),
		'Skipped (non-live)' => number_format($skippedNonLive),
		'Channels inserted' => number_format($channelsInserted),
		'Channels updated' => number_format($channelsUpdated),
		'Feeds inserted' => number_format($feedsInserted),
		'Feeds updated' => number_format($feedsUpdated),
		'Feeds skipped (existing)' => number_format($feedsSkippedExisting),
	];

	if ($feedsSkippedLimit > 0) {
		$stats['Feeds skipped (limit)'] = number_format($feedsSkippedLimit) . ' - Managed hosting allows maximum 50,000 feeds';
	}

	if ($hasJunctionTable) {
		$stats['Associations created'] = number_format($associationsCreated);
		$stats['Associations deleted (removed)'] = number_format($associationsDeleted);
		if ($orphanedFeeds > 0) {
			$stats['Orphaned feeds deleted'] = number_format($orphanedFeeds);
		}
		if ($queueEntriesAdded > 0) {
			$stats['Queue entries added'] = number_format($queueEntriesAdded);
		}
	}

	redirect_back([
		'ok' => true,
		'message' => "Sync complete. You can safely run this again whenever your playlist changes.",
		'stats' => $stats,
	]);
} catch (Throwable $e) {
	try {
		if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
	} catch (Throwable $e2) {
	}

	redirect_back([
		'ok' => false,
		'message' => $e->getMessage(),
	]);
}

// ============================================================================
// BATCH PROCESSING FUNCTIONS
// ============================================================================

function startBatchImport(): void
{
	global $pdo;

	require_csrf();
	header('Content-Type: application/json');

	if (empty($_POST['playlist'])) {
		$playlistDir = __DIR__ . '/playlists';
		$playlistFiles = glob($playlistDir . '/*.{m3u,m3u8}', GLOB_BRACE);
		if (empty($playlistFiles)) {
			echo json_encode(['success' => false, 'error' => 'No playlist file found']);
			exit;
		}
		$_POST['playlist'] = basename($playlistFiles[0]);
		$_POST['directory'] = 'playlists';
	}

	$playlistBase = cut((string)($_POST['playlist'] ?? ''), 255);
	$directory = cut((string)($_POST['directory'] ?? '.'), 255);
	$newUsername = trim((string)($_POST['new_username'] ?? ''));
	$newPassword = trim((string)($_POST['new_password'] ?? ''));

	$directory = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $directory);
	$directory = trim($directory, '/');

	if (str_contains($directory, '..') || str_contains($playlistBase, '..')) {
		echo json_encode(['success' => false, 'error' => 'Invalid directory or filename']);
		exit;
	}

	if ($playlistBase === '' || str_contains($playlistBase, '/') || str_contains($playlistBase, '\\')) {
		echo json_encode(['success' => false, 'error' => 'Invalid playlist filename']);
		exit;
	}

	$baseDir = __DIR__;
	$fullDir = $directory === '.' ? $baseDir : $baseDir . '/' . $directory;

	if (!is_dir($fullDir) || !is_readable($fullDir)) {
		echo json_encode(['success' => false, 'error' => 'Invalid directory']);
		exit;
	}

	$playlistPath = $fullDir . '/' . $playlistBase;

	if (!is_file($playlistPath) || !is_readable($playlistPath)) {
		echo json_encode(['success' => false, 'error' => 'Playlist file not found']);
		exit;
	}

	if ($newUsername !== '' && $newPassword !== '') {
		$content = file_get_contents($playlistPath);
		if ($content === false) {
			echo json_encode(['success' => false, 'error' => 'Unable to read playlist']);
			exit;
		}
		$pattern = '#(/live/)[^/]+/[^/]+/#';
		$replacement = '${1}' . $newUsername . '/' . $newPassword . '/';
		$content = preg_replace($pattern, $replacement, $content);
		if (file_put_contents($playlistPath, $content) === false) {
			echo json_encode(['success' => false, 'error' => 'Unable to update credentials']);
			exit;
		}
	}

	try {
		$entries = parsePlaylistEntries($playlistPath);

		if (empty($entries)) {
			echo json_encode(['success' => false, 'error' => 'No valid entries']);
			exit;
		}

		$sessionId = uniqid('import_', true);
		$sessionFile = __DIR__ . '/playlists/import_session_' . $sessionId . '.json';

		$sessionData = [
			'id' => $sessionId,
			'playlist_file' => $playlistBase,
			'total_entries' => count($entries),
			'processed' => 0,
			'batch_size' => 5000,
			'entries' => $entries,
			'stats' => [
				'channelsInserted' => 0,
				'channelsUpdated' => 0,
				'feedsInserted' => 0,
				'feedsUpdated' => 0,
				'feedsSkipped' => 0,
				'associationsCreated' => 0,
				'associationsDeleted' => 0,
				'queueAdded' => 0,
			],
			'started_at' => time(),
		];

		file_put_contents($sessionFile, json_encode($sessionData));

		echo json_encode([
			'success' => true,
			'session_id' => $sessionId,
			'total_entries' => count($entries),
			'batch_size' => 10000,
		]);
	} catch (Exception $e) {
		echo json_encode(['success' => false, 'error' => $e->getMessage()]);
	}
}

function parsePlaylistEntries(string $playlistPath): array
{
	$fh = fopen($playlistPath, 'rb');
	if (!$fh) throw new Exception("Cannot open playlist");

	$firstLine = fgets($fh);
	if ($firstLine === false || !str_starts_with(trim($firstLine), '#EXTM3U')) {
		fclose($fh);
		throw new Exception("Invalid playlist format");
	}

	$entries = [];
	$current = null;

	while (($line = fgets($fh)) !== false) {
		$line = trim($line);
		if (empty($line)) continue;

		if (str_starts_with($line, '#EXTINF:')) {
			$current = [
				'tvg_id' => parse_attr($line, 'tvg-id') ?? '',
				'tvg_name' => parse_attr($line, 'tvg-name') ?? '',
				'tvg_logo' => parse_attr($line, 'tvg-logo') ?? '',
				'group_title' => parse_attr($line, 'group-title') ?? '',
				'catch_up' => parse_attr($line, 'catchup') ?? null,
				'catch_up_days' => parse_attr($line, 'catchup-days') ?? null,
			];
			$current['group_title'] = group_from_group_title($current['group_title'], $current['tvg_name']);
			continue;
		}

		if ($line[0] === '#' || !$current) continue;

		$url = $line;
		if (stripos($url, '/live/') === false) {
			$current = null;
			continue;
		}

		if ($current['tvg_name'] === '') $current['tvg_name'] = 'Unknown';
		if ($current['tvg_id'] === '') $current['tvg_id'] = 'dummy-' . substr(sha1($current['tvg_name'] . '|' . $url), 0, 10);

		$current['url'] = $url;
		$entries[] = $current;
		$current = null;
	}

	fclose($fh);
	return $entries;
}

function processBatch(): void
{
	global $pdo;

	require_csrf();
	header('Content-Type: application/json');

	$sessionId = $_POST['session_id'] ?? '';
	$sessionFile = __DIR__ . '/playlists/import_session_' . $sessionId . '.json';

	if (!file_exists($sessionFile)) {
		echo json_encode(['success' => false, 'error' => 'Invalid session']);
		exit;
	}

	$sessionData = json_decode(file_get_contents($sessionFile), true);
	if (!$sessionData) {
		echo json_encode(['success' => false, 'error' => 'Corrupted session']);
		exit;
	}

	$batchStart = $sessionData['processed'];
	$batchSize = $sessionData['batch_size'];
	$batch = array_slice($sessionData['entries'], $batchStart, $batchSize);

	if (empty($batch)) {
		finalizeBatch($sessionData, $sessionFile);
		exit;
	}

	// Check schema once
	static $hasJunctionTable = null;
	static $hasOldChannelIdColumn = null;
	static $hasUrlDisplayCol = null;
	static $hasCatchUpCols = null;
	static $hasJunctionLastSeenCol = null;

	if ($hasJunctionTable === null) {
		try {
			$pdo->query("SELECT 1 FROM channel_feeds LIMIT 1");
			$hasJunctionTable = true;
			try {
				$pdo->query("SELECT last_seen FROM channel_feeds LIMIT 1");
				$hasJunctionLastSeenCol = true;
			} catch (Throwable $e) {
				$hasJunctionLastSeenCol = false;
			}
		} catch (Throwable $e) {
			$hasJunctionTable = false;
			$hasJunctionLastSeenCol = false;
		}

		try {
			$pdo->query("SELECT channel_id FROM feeds LIMIT 1");
			$hasOldChannelIdColumn = true;
		} catch (Throwable $e) {
			$hasOldChannelIdColumn = false;
		}

		try {
			$pdo->query("SELECT url_display FROM feeds LIMIT 1");
			$hasUrlDisplayCol = true;
		} catch (Throwable $e) {
			$hasUrlDisplayCol = false;
		}

		try {
			$pdo->query("SELECT catch_up, catch_up_days FROM feeds LIMIT 1");
			$hasCatchUpCols = true;
		} catch (Throwable $e) {
			$hasCatchUpCols = false;
		}
	}

	try {

		$pdo->beginTransaction();

		// Check managed hosting limit
		$is_managed_hosting = get_setting('managed_hosting', '');
		$managed_hosting_limit = 50000;
		$current_feed_count = 0;

		if ($is_managed_hosting === '1' || $is_managed_hosting === 1) {
			// Count current feeds in database
			$current_feed_count = (int)$pdo->query("SELECT COUNT(*) FROM feeds")->fetchColumn();
		}

		if ($batchStart === 0 && $hasJunctionTable && $hasJunctionLastSeenCol) {
			$pdo->exec("UPDATE channel_feeds SET last_seen = NULL");
		}

		$stFindChannel = $pdo->prepare("SELECT id FROM channels WHERE tvg_id = :tvg_id AND group_title = :group_title LIMIT 1");
		$stInsertChannel = $pdo->prepare("INSERT INTO channels (tvg_id, tvg_name, tvg_logo, group_title) VALUES (:tvg_id, :tvg_name, :tvg_logo, :group_title)");
		$stUpdateChannel = $pdo->prepare("UPDATE channels SET tvg_name = :tvg_name, tvg_logo = :tvg_logo WHERE id = :id");
		$stFindFeed = $pdo->prepare("SELECT id FROM feeds WHERE url_hash = :h LIMIT 1");

		$updateFeedSql = "UPDATE feeds SET url = :url";
		if ($hasUrlDisplayCol) $updateFeedSql .= ", url_display = :url_display";
		if ($hasCatchUpCols) $updateFeedSql .= ", catch_up = :catch_up, catch_up_days = :catch_up_days";
		$updateFeedSql .= " WHERE id = :id";
		$stUpdateFeed = $pdo->prepare($updateFeedSql);

		$insertFeedSql = "INSERT INTO feeds (url, url_hash";
		if ($hasUrlDisplayCol) $insertFeedSql .= ", url_display";
		if ($hasCatchUpCols) $insertFeedSql .= ", catch_up, catch_up_days";
		if ($hasOldChannelIdColumn) $insertFeedSql .= ", channel_id";
		$insertFeedSql .= ") VALUES (:url, :h";
		if ($hasUrlDisplayCol) $insertFeedSql .= ", :url_display";
		if ($hasCatchUpCols) $insertFeedSql .= ", :catch_up, :catch_up_days";
		if ($hasOldChannelIdColumn) $insertFeedSql .= ", :channel_id";
		$insertFeedSql .= ")";
		$stInsertFeed = $pdo->prepare($insertFeedSql);

		if ($hasJunctionTable) {
			$stFindChannelFeed = $pdo->prepare("SELECT 1 FROM channel_feeds WHERE channel_id = :channel_id AND feed_id = :feed_id");
			$stInsertChannelFeed = $pdo->prepare("INSERT INTO channel_feeds (channel_id, feed_id) VALUES (:channel_id, :feed_id)");
			if ($hasJunctionLastSeenCol) {
				$stMarkChannelFeedSeen = $pdo->prepare("UPDATE channel_feeds SET last_seen = NOW() WHERE channel_id = :channel_id AND feed_id = :feed_id");
			}
		}

		foreach ($batch as $entry) {
			$url = $entry['url'];

			// Save stream_host from first URL
			static $streamHostSaved = false;
			if (!$streamHostSaved && preg_match('#^(https?://[^/]+)#', $url, $m)) {
				try {
					$pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('stream_host', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$m[1], $m[1]]);
					$streamHostSaved = true;
				} catch (Throwable $e) {
				}
			}

			$stFindChannel->execute([':tvg_id' => $entry['tvg_id'], ':group_title' => $entry['group_title']]);
			$channelId = (int)($stFindChannel->fetchColumn() ?: 0);

			if ($channelId <= 0) {
				$stInsertChannel->execute([
					':tvg_id' => $entry['tvg_id'],
					':tvg_name' => $entry['tvg_name'],
					':tvg_logo' => $entry['tvg_logo'],
					':group_title' => $entry['group_title'],
				]);
				$channelId = (int)$pdo->lastInsertId();
				$sessionData['stats']['channelsInserted']++;
			} else {
				$stUpdateChannel->execute([':tvg_name' => $entry['tvg_name'], ':tvg_logo' => $entry['tvg_logo'], ':id' => $channelId]);
				if ($stUpdateChannel->rowCount() > 0) $sessionData['stats']['channelsUpdated']++;
			}

			$h = sha1($url);
			$feedId = 0;
			$currentChannelFeedId = 0;

			if ($hasJunctionTable) {
				$stFindByChannel = $pdo->prepare("SELECT f.id FROM feeds f INNER JOIN channel_feeds cf ON cf.feed_id = f.id WHERE cf.channel_id = :channel_id LIMIT 1");
				$stFindByChannel->execute([':channel_id' => $channelId]);
				$currentChannelFeedId = (int)($stFindByChannel->fetchColumn() ?: 0);
			}

			$stFindFeed->execute([':h' => $h]);
			$existingFeedId = (int)($stFindFeed->fetchColumn() ?: 0);

			if ($existingFeedId > 0) {
				$feedId = $existingFeedId;
				$sessionData['stats']['feedsSkipped']++;
			} elseif ($currentChannelFeedId > 0) {
				$feedId = $currentChannelFeedId;
				$params = [':url' => $url, ':id' => $feedId];
				if ($hasUrlDisplayCol) $params[':url_display'] = basename(parse_url($url, PHP_URL_PATH) ?: $url);
				if ($hasCatchUpCols) {
					$params[':catch_up'] = $entry['catch_up'];
					$params[':catch_up_days'] = $entry['catch_up_days'];
				}
				$stUpdateFeed->execute($params);
				$pdo->prepare("UPDATE feeds SET url_hash = :h WHERE id = :id")->execute([':h' => $h, ':id' => $feedId]);
				$sessionData['stats']['feedsUpdated']++;
			} else {

				// Check managed hosting limit before inserting
				if ($is_managed_hosting === '1' || $is_managed_hosting === 1) {
					if ($current_feed_count >= $managed_hosting_limit) {
						// Skip this feed - limit reached
						if (!isset($sessionData['stats']['feedsSkippedLimit'])) {
							$sessionData['stats']['feedsSkippedLimit'] = 0;
						}
						$sessionData['stats']['feedsSkippedLimit']++;
						continue; // Skip to next entry
					}
				}

				$params = [':url' => $url, ':h' => $h];

				if ($hasUrlDisplayCol) $params[':url_display'] = basename(parse_url($url, PHP_URL_PATH) ?: $url);
				if ($hasCatchUpCols) {
					$params[':catch_up'] = $entry['catch_up'];
					$params[':catch_up_days'] = $entry['catch_up_days'];
				}
				if ($hasOldChannelIdColumn) $params[':channel_id'] = $channelId;
				$stInsertFeed->execute($params);
				$feedId = (int)$pdo->lastInsertId();
				$sessionData['stats']['feedsInserted']++;

				if ($is_managed_hosting === '1' || $is_managed_hosting === 1) {
					$current_feed_count++; // Increment count for managed hosting
				}

				if ($hasJunctionTable) {
					try {
						$stmt = $pdo->prepare("INSERT IGNORE INTO feed_check_queue (feed_id, next_run_at, locked_at, lock_token, attempts, last_result_ok, last_error) VALUES (:feed_id, NOW(), NULL, NULL, 0, NULL, NULL)");
						$stmt->execute([':feed_id' => $feedId]);
						$sessionData['stats']['queueAdded'] += $stmt->rowCount();
					} catch (Throwable $e) {
					}
				}
			}

			if ($hasJunctionTable && $feedId > 0 && $channelId > 0) {
				$stFindChannelFeed->execute([':channel_id' => $channelId, ':feed_id' => $feedId]);
				$exists = $stFindChannelFeed->fetchColumn();

				if (!$exists) {
					try {
						$stInsertChannelFeed->execute([':channel_id' => $channelId, ':feed_id' => $feedId]);
						$sessionData['stats']['associationsCreated']++;
					} catch (Throwable $e) {
					}
				}

				if ($hasJunctionLastSeenCol) {
					$stMarkChannelFeedSeen->execute([':channel_id' => $channelId, ':feed_id' => $feedId]);
				}
			}
		}

		$pdo->commit();

		$sessionData['processed'] += count($batch);
		file_put_contents($sessionFile, json_encode($sessionData));

		$percentComplete = round(($sessionData['processed'] / $sessionData['total_entries']) * 100, 1);

		echo json_encode([
			'success' => true,
			'processed' => $sessionData['processed'],
			'total' => $sessionData['total_entries'],
			'percent' => $percentComplete,
			'complete' => false,
		]);
	} catch (Throwable $e) {
		try {
			if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
		} catch (Throwable $e2) {
		}

		echo json_encode(['success' => false, 'error' => $e->getMessage()]);
	}
}

function finalizeBatch(array $sessionData, string $sessionFile): void
{
	global $pdo;

	try {
		$hasJunctionTable = false;
		$hasJunctionLastSeenCol = false;

		try {
			$pdo->query("SELECT 1 FROM channel_feeds LIMIT 1");
			$hasJunctionTable = true;
			try {
				$pdo->query("SELECT last_seen FROM channel_feeds LIMIT 1");
				$hasJunctionLastSeenCol = true;
			} catch (Throwable $e) {
			}
		} catch (Throwable $e) {
		}

		if ($hasJunctionTable && $hasJunctionLastSeenCol) {
			$stmt = $pdo->prepare("DELETE FROM channel_feeds WHERE last_seen IS NULL");
			$stmt->execute();
			$sessionData['stats']['associationsDeleted'] = $stmt->rowCount();
		}

		try {
			$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('last_sync_date', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()");
			$stmt->execute();
		} catch (Throwable $e) {
		}

		// Match EXACT format from original (lines 674-698)
		$stats = [
			'Playlist file' => $sessionData['playlist_file'],
			'Mode' => 'Sync (Batch)',
			'Schema' => $hasJunctionTable ? 'Junction table (many-to-many)' : 'Legacy (one-to-one)',
			'LIVE URLs imported' => number_format($sessionData['total_entries']),
			'Channels inserted' => number_format($sessionData['stats']['channelsInserted']),
			'Channels updated' => number_format($sessionData['stats']['channelsUpdated']),
			'Feeds inserted' => number_format($sessionData['stats']['feedsInserted']),
			'Feeds updated' => number_format($sessionData['stats']['feedsUpdated']),
			'Feeds skipped (existing)' => number_format($sessionData['stats']['feedsSkipped']),
		];

		if (isset($sessionData['stats']['feedsSkippedLimit']) && $sessionData['stats']['feedsSkippedLimit'] > 0) {
			$stats['Feeds skipped (limit)'] = number_format($sessionData['stats']['feedsSkippedLimit']) . ' - Managed hosting allows maximum 50,000 feeds';
		}

		if ($hasJunctionTable) {
			$stats['Associations created'] = number_format($sessionData['stats']['associationsCreated']);
			$stats['Associations deleted (removed)'] = number_format($sessionData['stats']['associationsDeleted']);
			if ($sessionData['stats']['queueAdded'] > 0) {
				$stats['Queue entries added'] = number_format($sessionData['stats']['queueAdded']);
			}
		}

		@unlink($sessionFile);

		echo json_encode([
			'success' => true,
			'complete' => true,
			'ok' => true,
			'status' => 'completed',
			'message' => 'Sync complete. You can safely run this again whenever your playlist changes.',
			'stats' => $stats,
		]);
	} catch (Throwable $e) {
		echo json_encode(['success' => false, 'error' => $e->getMessage()]);
	}
}
