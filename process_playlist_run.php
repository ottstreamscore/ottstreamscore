<?php

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
$pdo = db();

if (session_status() !== PHP_SESSION_ACTIVE) {
	@session_start();
}

function redirect_back(array $flash): void
{
	$_SESSION['playlist_flash'] = $flash;
	header('Location: process_playlist.php');
	exit;
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
	$mode = cut((string)($_POST['mode'] ?? 'sync'), 30);
	if (!in_array($mode, ['sync', 'insert_only'], true)) $mode = 'sync';

	if ($playlistBase === '' || str_contains($playlistBase, '/') || str_contains($playlistBase, '\\')) {
		redirect_back([
			'ok' => false,
			'message' => 'Invalid playlist filename.',
		]);
	}

	$playlistPath = __DIR__ . '/' . $playlistBase;
	if (!is_file($playlistPath) || !is_readable($playlistPath)) {
		redirect_back([
			'ok' => false,
			'message' => "Playlist file not found or not readable: {$playlistBase}",
		]);
	}

	$fh = fopen($playlistPath, 'rb');
	if (!$fh) {
		redirect_back([
			'ok' => false,
			'message' => "Unable to open playlist file: {$playlistBase}",
		]);
	}

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

	$hasUrlDisplayCol = false;
	try {
		$pdo->query("SELECT url_display FROM feeds LIMIT 1");
		$hasUrlDisplayCol = true;
	} catch (Throwable $e) {
		$hasUrlDisplayCol = false;
	}

	// Check if last_seen column exists, add it if missing
	$hasLastSeenCol = false;
	try {
		$pdo->query("SELECT last_seen FROM feeds LIMIT 1");
		$hasLastSeenCol = true;
	} catch (Throwable $e) {
		// Column doesn't exist, try to add it
		try {
			$pdo->exec("ALTER TABLE feeds ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL");
			$hasLastSeenCol = true;
		} catch (Throwable $e2) {
			// Failed to add column, continue without cleanup functionality
			$hasLastSeenCol = false;
		}
	}

	$stFindFeed = $pdo->prepare("
		SELECT id FROM feeds
		WHERE url_hash = :h
		LIMIT 1
	");

	if ($hasUrlDisplayCol) {
		$stInsertFeed = $pdo->prepare("
			INSERT INTO feeds (channel_id, url, url_hash, url_display)
			VALUES (:channel_id, :url, :h, :url_display)
		");
		$stUpdateFeed = $pdo->prepare("
			UPDATE feeds
			SET channel_id = :channel_id,
			    url = :url,
			    url_display = :url_display
			WHERE id = :id
		");
	} else {
		$stInsertFeed = $pdo->prepare("
			INSERT INTO feeds (channel_id, url, url_hash)
			VALUES (:channel_id, :url, :h)
		");
		$stUpdateFeed = $pdo->prepare("
			UPDATE feeds
			SET channel_id = :channel_id,
			    url = :url
			WHERE id = :id
		");
	}

	// Prepare statement to mark feed as seen
	if ($hasLastSeenCol) {
		$stMarkSeen = $pdo->prepare("
			UPDATE feeds
			SET last_seen = CURRENT_TIMESTAMP
			WHERE id = :id
		");
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
	$feedsDeleted = 0;

	$current = null;

	$pdo->beginTransaction();

	// Mark all feeds as stale at the start (if column exists)
	if ($hasLastSeenCol) {
		$pdo->exec("UPDATE feeds SET last_seen = NULL");
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
			// you have dummy ids in some cases; allow empty -> normalize to dummy hash
			$current['tvg_id'] = 'dummy-' . substr(sha1($current['tvg_name'] . '|' . $url), 0, 10);
		}

		// upsert channel instance (tvg_id + group_title)
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
			// if name or logo changed, update them
			$stUpdateChannel->execute([
				':tvg_name' => $current['tvg_name'],
				':tvg_logo' => $current['tvg_logo'],
				':id' => $channelId,
			]);
			$channelsUpdated += ($stUpdateChannel->rowCount() > 0) ? 1 : 0;
		}

		// feed identity by url_hash
		$h = sha1($url);

		$stFindFeed->execute([':h' => $h]);
		$feedId = (int)($stFindFeed->fetchColumn() ?: 0);

		if ($feedId <= 0) {
			// insert feed
			if ($hasUrlDisplayCol) {
				$stInsertFeed->execute([
					':channel_id' => $channelId,
					':url' => $url,
					':h' => $h,
					':url_display' => basename(parse_url($url, PHP_URL_PATH) ?: $url),
				]);
			} else {
				$stInsertFeed->execute([
					':channel_id' => $channelId,
					':url' => $url,
					':h' => $h,
				]);
			}
			$feedId = (int)$pdo->lastInsertId();
			$feedsInserted++;
		} else {
			if ($mode === 'insert_only') {
				$feedsSkippedExisting++;
			} else {
				// sync: update channel association + url (in case host/user/pass changes)
				if ($hasUrlDisplayCol) {
					$stUpdateFeed->execute([
						':channel_id' => $channelId,
						':url' => $url,
						':url_display' => basename(parse_url($url, PHP_URL_PATH) ?: $url),
						':id' => $feedId,
					]);
				} else {
					$stUpdateFeed->execute([
						':channel_id' => $channelId,
						':url' => $url,
						':id' => $feedId,
					]);
				}
				$feedsUpdated += ($stUpdateFeed->rowCount() > 0) ? 1 : 0;
			}
		}

		// Mark this feed as seen in this import
		if ($hasLastSeenCol && $feedId > 0) {
			$stMarkSeen->execute([':id' => $feedId]);
		}

		$current = null;
	}

	fclose($fh);

	// Delete feeds that weren't in this playlist (if column exists)
	if ($hasLastSeenCol) {
		$stmt = $pdo->prepare("DELETE FROM feeds WHERE last_seen IS NULL");
		$stmt->execute();
		$feedsDeleted = $stmt->rowCount();
	}

	$pdo->commit();

	$stats = [
		'Playlist file' => $playlistBase,
		'Mode' => $mode === 'sync' ? 'Sync (insert + update)' : 'Insert only (skip existing)',
		'Lines read' => number_format($lines),
		'EXTINF entries' => number_format($extinf),
		'LIVE URLs imported' => number_format($live),
		'Skipped (non-live)' => number_format($skippedNonLive),
		'Channels inserted' => number_format($channelsInserted),
		'Channels updated' => number_format($channelsUpdated),
		'Feeds inserted' => number_format($feedsInserted),
		'Feeds updated' => number_format($feedsUpdated),
		'Feeds skipped (existing)' => number_format($feedsSkippedExisting),
		'Feeds deleted (removed from playlist)' => number_format($feedsDeleted),
	];

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
