<?php

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

// Require authentication
require_auth();

$pdo = db();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
	switch ($action) {
		case 'acquire_lock':
			// Try to acquire the preview lock
			$sessionId = session_id();
			$feedId = (int)($_POST['feed_id'] ?? 0);

			// CRITICAL: Close session to prevent blocking subsequent requests
			session_write_close();

			if ($feedId <= 0) {
				echo json_encode(['success' => false, 'error' => 'Invalid feed ID']);
				exit;
			}

			// FORCE DELETE ALL LOCKS - enable rapid switching
			$pdo->query("DELETE FROM stream_preview_lock");

			// Get channel name for the feed
			$stmt = $pdo->prepare("
                SELECT c.tvg_name 
                FROM feeds f
                LEFT JOIN channels c ON c.id = f.channel_id
                WHERE f.id = :feed_id
            ");
			$stmt->execute([':feed_id' => $feedId]);
			$channelName = $stmt->fetchColumn() ?: null;

			// Acquire lock
			$stmt = $pdo->prepare("
                INSERT INTO stream_preview_lock (locked_by, locked_at, last_heartbeat, feed_id, channel_name)
                VALUES (:session_id, NOW(), NOW(), :feed_id, :channel_name)
            ");
			$stmt->execute([
				':session_id' => $sessionId,
				':feed_id' => $feedId,
				':channel_name' => $channelName
			]);

			echo json_encode(['success' => true, 'message' => 'Lock acquired']);
			break;

		case 'heartbeat':
			// Update heartbeat to keep lock alive
			$sessionId = session_id();
			session_write_close();

			$stmt = $pdo->prepare("
                UPDATE stream_preview_lock 
                SET last_heartbeat = NOW() 
                WHERE locked_by = :session_id
            ");
			$result = $stmt->execute([':session_id' => $sessionId]);

			// Check if lock still exists
			$lockExists = $pdo->prepare("
                SELECT COUNT(*) FROM stream_preview_lock WHERE locked_by = :session_id
            ");
			$lockExists->execute([':session_id' => $sessionId]);

			if ($lockExists->fetchColumn() > 0) {
				echo json_encode(['success' => true, 'message' => 'Heartbeat updated']);
			} else {
				echo json_encode(['success' => false, 'error' => 'Lock no longer exists']);
			}
			break;

		case 'release_lock':
			// Release the lock
			$sessionId = session_id();
			session_write_close();

			$stmt = $pdo->prepare("DELETE FROM stream_preview_lock WHERE locked_by = :session_id");
			$stmt->execute([':session_id' => $sessionId]);

			echo json_encode(['success' => true, 'message' => 'Lock released']);
			break;

		case 'get_stream_url':
			// Get the stream URL and info for preview
			$feedId = (int)($_GET['feed_id'] ?? 0);

			if ($feedId <= 0) {
				echo json_encode(['success' => false, 'error' => 'Invalid feed ID']);
				exit;
			}

			session_write_close();

			// Get feed details
			$stmt = $pdo->prepare("
                SELECT 
                    f.url, 
                    f.last_w, 
                    f.last_h, 
                    f.last_fps, 
                    f.last_codec,
                    f.reliability_score,
                    c.tvg_name,
                    c.tvg_id,
                    c.group_title
                FROM feeds f
                LEFT JOIN channels c ON c.id = f.channel_id
                WHERE f.id = :feed_id
            ");
			$stmt->execute([':feed_id' => $feedId]);
			$feed = $stmt->fetch(PDO::FETCH_ASSOC);

			if (!$feed) {
				echo json_encode(['success' => false, 'error' => 'Feed not found']);
				exit;
			}

			// Return PROXY URL instead of direct URL
			$proxyUrl = 'stream_proxy.php?feed_id=' . $feedId;

			// Detect stream type from URL
			$streamType = 'unknown';
			if (preg_match('/\.m3u8(\?|$)/i', $feed['url'])) {
				$streamType = 'hls';
			} elseif (preg_match('/\.ts(\?|$)/i', $feed['url'])) {
				$streamType = 'mpegts';
			}

			echo json_encode([
				'success' => true,
				'url' => $proxyUrl,
				'stream_type' => $streamType,
				'original_url' => $feed['url'],
				'channel_name' => $feed['tvg_name'] ?? 'Unknown Channel',
				'tvg_id' => $feed['tvg_id'] ?? '',
				'group' => $feed['group_title'] ?? '',
				'resolution' => ($feed['last_w'] ? (int)$feed['last_w'] : '?') . 'x' . ($feed['last_h'] ? (int)$feed['last_h'] : '?'),
				'width' => $feed['last_w'],
				'height' => $feed['last_h'],
				'fps' => $feed['last_fps'] ? round((float)$feed['last_fps'], 2) : null,
				'codec' => $feed['last_codec'] ?? 'Unknown',
				'reliability' => $feed['reliability_score'] ? round((float)$feed['reliability_score'], 1) : null
			]);
			break;

		case 'check_lock_status':
			// Check if there's an active lock
			$lockCheck = $pdo->query("
                SELECT locked_by, feed_id, channel_name, locked_at
                FROM stream_preview_lock 
                WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
            ")->fetch(PDO::FETCH_ASSOC);

			echo json_encode([
				'success' => true,
				'locked' => (bool)$lockCheck,
				'lock_info' => $lockCheck ?: null
			]);
			break;

		default:
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => 'Invalid action']);
			break;
	}
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'error' => 'Server error: ' . $e->getMessage(),
		'file' => basename($e->getFile()),
		'line' => $e->getLine()
	]);
}
