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

header('Content-Type: application/json');

$pdo = db();

// Helper functions
function res_class_helper(?int $w, ?int $h): array
{
	$w = $w ?: 0;
	$h = $h ?: 0;
	$pixels = $w * $h;
	if ($w <= 0 || $h <= 0) return ['Unknown', 40, 0];
	if ($h >= 2160 || $w >= 3840) return ['4K', 100, $pixels];
	if ($h >= 1080) return ['FHD', 85, $pixels];
	if ($h >= 720)  return ['HD', 70, $pixels];
	return ['SD', 50, $pixels];
}

function ts_filename_helper(?string $url): string
{
	$url = (string)$url;
	$path = parse_url($url, PHP_URL_PATH);
	$path = is_string($path) ? $path : '';
	$base = $path !== '' ? basename($path) : '';
	if ($base === '' && $url !== '') $base = basename($url);
	return $base !== '' ? $base : '—';
}

function format_feed_response($feed): array
{
	$w = $feed['last_w'] !== null ? (int)$feed['last_w'] : null;
	$h = $feed['last_h'] !== null ? (int)$feed['last_h'] : null;
	[$cls] = res_class_helper($w, $h);

	$res = ($w && $h) ? ($w . '×' . $h) : '—';
	$fps = $feed['last_fps'] !== null ? number_format((float)$feed['last_fps'], 2) : '—';
	$rel = $feed['reliability_score'] !== null ? number_format((float)$feed['reliability_score'], 2) : '—';
	$codec = $feed['last_codec'] ? (string)$feed['last_codec'] : '—';
	$file = ts_filename_helper((string)$feed['url_any']);

	$resBadgeMap = [
		'4K'      => 'bg-warning text-dark',
		'FHD'     => 'bg-primary',
		'HD'      => 'bg-info text-dark',
		'SD'      => 'bg-secondary',
		'Unknown' => 'bg-light text-dark',
	];
	$badgeClass = $resBadgeMap[$cls] ?? $resBadgeMap['Unknown'];
	$resolutionHtml = '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($cls) . '</span> <span class="text-muted ms-1">' . htmlspecialchars($res) . '</span>';

	return [
		'feed_id' => (int)$feed['feed_id'],
		'group_title' => (string)$feed['group_title'],
		'tvg_name' => (string)$feed['tvg_name'],
		'tvg_id' => (string)$feed['tvg_id'],
		'file' => $file,
		'reliability' => $rel,
		'resolution_html' => $resolutionHtml,
		'fps' => $fps,
		'codec' => $codec
	];
}

try {
	// === SCHEMA DETECTION ===
	$hasJunctionTable = false;
	try {
		$pdo->query("SELECT 1 FROM channel_feeds LIMIT 1");
		$hasJunctionTable = true;
	} catch (Throwable $e) {
		$hasJunctionTable = false;
	}

	// Check if looking up by feed_id or by tvg_id+group
	$feedId = (int)($_GET['feed_id'] ?? 0);
	$tvgId = $_GET['tvg_id'] ?? '';
	$group = $_GET['group'] ?? '';
	$getBest = isset($_GET['get_best']);

	if ($feedId > 0) {
		// Lookup by feed_id
		if ($hasJunctionTable) {
			$stmt = $pdo->prepare("
				SELECT 
					f.id as feed_id,
					f.last_ok,
					f.reliability_score,
					f.last_w,
					f.last_h,
					f.last_fps,
					f.last_codec,
					COALESCE(f.url_display, f.url) AS url_any,
					c.group_title,
					c.tvg_name,
					c.tvg_id
				FROM feeds f
				JOIN channel_feeds cf ON cf.feed_id = f.id
				JOIN channels c ON c.id = cf.channel_id
				WHERE f.id = ?
				LIMIT 1
			");
		} else {
			$stmt = $pdo->prepare("
				SELECT 
					f.id as feed_id,
					f.last_ok,
					f.reliability_score,
					f.last_w,
					f.last_h,
					f.last_fps,
					f.last_codec,
					COALESCE(f.url_display, f.url) AS url_any,
					c.group_title,
					c.tvg_name,
					c.tvg_id
				FROM feeds f
				JOIN channels c ON c.id = f.channel_id
				WHERE f.id = ?
				LIMIT 1
			");
		}

		$stmt->execute([$feedId]);
		$feed = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$feed) {
			echo json_encode(['success' => false, 'message' => 'Feed not found']);
			exit;
		}

		echo json_encode(['success' => true, 'feed' => format_feed_response($feed)]);
	} elseif ($tvgId && $group && $getBest) {
		// Lookup best feed by tvg_id + group
		if ($hasJunctionTable) {
			$stmt = $pdo->prepare("
				SELECT 
					f.id as feed_id,
					f.last_ok,
					f.reliability_score,
					f.last_w,
					f.last_h,
					f.last_fps,
					f.last_codec,
					COALESCE(f.url_display, f.url) AS url_any,
					c.group_title,
					c.tvg_name,
					c.tvg_id,
					(COALESCE(f.last_w,0) * COALESCE(f.last_h,0)) AS pixels
				FROM channels c
				JOIN channel_feeds cf ON cf.channel_id = c.id
				JOIN feeds f ON f.id = cf.feed_id
				WHERE c.tvg_id = ? AND c.group_title = ?
				ORDER BY 
					COALESCE(f.reliability_score,0) DESC,
					pixels DESC,
					COALESCE(f.last_fps,0) DESC,
					f.last_ok DESC
				LIMIT 1
			");
		} else {
			$stmt = $pdo->prepare("
				SELECT 
					f.id as feed_id,
					f.last_ok,
					f.reliability_score,
					f.last_w,
					f.last_h,
					f.last_fps,
					f.last_codec,
					COALESCE(f.url_display, f.url) AS url_any,
					c.group_title,
					c.tvg_name,
					c.tvg_id,
					(COALESCE(f.last_w,0) * COALESCE(f.last_h,0)) AS pixels
				FROM feeds f
				JOIN channels c ON c.id = f.channel_id
				WHERE c.tvg_id = ? AND c.group_title = ?
				ORDER BY 
					COALESCE(f.reliability_score,0) DESC,
					pixels DESC,
					COALESCE(f.last_fps,0) DESC,
					f.last_ok DESC
				LIMIT 1
			");
		}

		$stmt->execute([$tvgId, $group]);
		$feed = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$feed) {
			echo json_encode(['success' => false, 'message' => 'Feed not found']);
			exit;
		}

		echo json_encode(['success' => true, 'feed' => format_feed_response($feed)]);
	} else {
		echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
	}
} catch (Exception $e) {
	echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
