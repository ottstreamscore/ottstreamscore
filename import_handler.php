<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

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
		SET tvg_name = :tvg_name,
		    tvg_logo = :tvg_logo
		WHERE id = :id
	");

	// Feed lookup by url_hash only (feeds are now URL-unique)
	$stFindFeed = $pdo->prepare("
		SELECT id FROM feeds
		WHERE url_hash = :h
		LIMIT 1
	");

	// Insert feed - FIXED: Include channel_id if column exists
	if ($hasUrlDisplayCol) {
		if ($hasOldChannelIdColumn) {
			// Has channel_id column (include it)
			$stInsertFeed = $pdo->prepare("
				INSERT INTO feeds (channel_id, url, url_hash, url_display)
				VALUES (:channel_id, :url, :h, :url_display)
			");
		} else {
			// No channel_id column (pure new schema)
			$stInsertFeed = $pdo->prepare("
				INSERT INTO feeds (url, url_hash, url_display)
				VALUES (:url, :h, :url_display)
			");
		}
	} else {
		if ($hasOldChannelIdColumn) {
			$stInsertFeed = $pdo->prepare("
				INSERT INTO feeds (channel_id, url, url_hash)
				VALUES (:channel_id, :url, :h)
			");
		} else {
			$stInsertFeed = $pdo->prepare("
				INSERT INTO feeds (url, url_hash)
				VALUES (:url, :h)
			");
		}
	}

	// Update feed
	if ($hasUrlDisplayCol) {
		$stUpdateFeed = $pdo->prepare("
			UPDATE feeds
			SET url = :url, url_display = :url_display
			WHERE id = :id
		");
	} else {
		$stUpdateFeed = $pdo->prepare("
			UPDATE feeds
			SET url = :url
			WHERE id = :id
		");
	}

	// Junction table operations
	if ($hasJunctionTable) {
		$stFindChannelFeed = $pdo->prepare("
			SELECT 1 FROM channel_feeds
			WHERE channel_id = :channel_id AND feed_id = :feed_id
			LIMIT 1
		");

		$stInsertChannelFeed = $pdo->prepare("
			INSERT IGNORE INTO channel_feeds (channel_id, feed_id)
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

			$groupTitle = group_from_group_title($groupTitle, $tvgName);

			$current = [
				'tvg_id' => cut($tvgId, 255),
				'tvg_name' => cut($tvgName, 255),
				'tvg_logo' => cut($tvgLogo, 500),
				'group_title' => cut($groupTitle, 255),
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

			// If this channel had a different feed, we need to handle the switch
			if ($currentChannelFeedId > 0 && $currentChannelFeedId !== $existingFeedId) {
				// Channel is switching to a different feed
				// The old association will be cleaned up by the stale deletion
				$feedsUpdated++;
			} else {
				// Same feed, update url_display if needed
				if ($hasUrlDisplayCol) {
					$params = [
						':url_display' => basename(parse_url($url, PHP_URL_PATH) ?: $url),
						':id' => $feedId,
					];
					$pdo->prepare("UPDATE feeds SET url_display = :url_display WHERE id = :id")
						->execute($params);
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

			$stUpdateFeed->execute($params);

			// Also update url_hash in case URL changed
			$pdo->prepare("UPDATE feeds SET url_hash = :h WHERE id = :id")
				->execute([':h' => $h, ':id' => $feedId]);

			$feedsUpdated++;
		} else {
			// No existing feed - insert new one
			$params = [
				':url' => $url,
				':h' => $h,
			];
			if ($hasUrlDisplayCol) {
				$params[':url_display'] = basename(parse_url($url, PHP_URL_PATH) ?: $url);
			}
			// Include channel_id if column exists (regardless of junction table)
			if ($hasOldChannelIdColumn) {
				$params[':channel_id'] = $channelId;
			}

			$stInsertFeed->execute($params);
			$feedId = (int)$pdo->lastInsertId();
			$feedsInserted++;

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
