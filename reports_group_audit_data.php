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
header('Content-Type: application/json; charset=utf-8');

try {

	// === HELPER FUNCTIONS ===
	function clamp_int($v, int $min, int $max, int $default): int
	{
		if (!is_numeric($v)) return $default;
		$n = (int)$v;
		if ($n < $min) return $min;
		if ($n > $max) return $max;
		return $n;
	}

	function rank_score($lastOk, $rel, ?int $w, ?int $h, $fps): float
	{
		$relN = ($rel !== null) ? (float)$rel : 0.0;

		// Resolution points
		$w = $w ?: 0;
		$h = $h ?: 0;
		$resPts = 0;
		if ($h >= 2160 || $w >= 3840) $resPts = 100;
		elseif ($h >= 1080) $resPts = 85;
		elseif ($h >= 720) $resPts = 70;
		elseif ($h > 0) $resPts = 50;
		else $resPts = 40;

		$fpsN = ($fps !== null) ? (float)$fps : 0.0;
		$fpsPts = $fpsN <= 0 ? 0.0 : min(100.0, ($fpsN / 30.0) * 100.0);

		$score = ($relN * 0.60) + ($resPts * 0.25) + ($fpsPts * 0.15);

		if ($lastOk !== null && (int)$lastOk === 0) {
			$score -= 15.0;
		}

		if ($score < 0) $score = 0;
		if ($score > 100) $score = 100;

		return round($score, 1);
	}

	function res_class(?int $w, ?int $h): string
	{
		if (!$w || !$h) return 'Unknown';
		$px = $w * $h;
		if ($h >= 2160 || $px >= 3840 * 2160) return '4K';
		if ($h >= 1080 || $px >= 1920 * 1080) return 'FHD';
		if ($h >= 720  || $px >= 1280 * 720)  return 'HD';
		return 'SD';
	}

	// === SCHEMA DETECTION ===
	$hasJunctionTable = false;
	try {
		$pdo->query("SELECT 1 FROM channel_feeds LIMIT 1");
		$hasJunctionTable = true;
	} catch (Throwable $e) {
		$hasJunctionTable = false;
	}

	// Check if ignores table exists
	$hasIgnoresTable = false;
	try {
		$pdo->query("SELECT 1 FROM group_audit_ignores LIMIT 1");
		$hasIgnoresTable = true;
	} catch (Throwable $e) {
		$hasIgnoresTable = false;
	}

	// === PARAMETERS ===
	$group = isset($_GET['group']) ? trim((string)$_GET['group']) : '';
	$dateRange = isset($_GET['date_range']) ? trim((string)$_GET['date_range']) : 'all';
	$customFrom = isset($_GET['custom_from']) ? trim((string)$_GET['custom_from']) : '';
	$customTo = isset($_GET['custom_to']) ? trim((string)$_GET['custom_to']) : '';

	if ($group === '') {
		echo json_encode(['error' => 'Group parameter required']);
		exit;
	}

	// === BUILD DATE FILTER ===
	$dateFilter = '';
	$dateParams = [];

	switch ($dateRange) {
		case '7':
			$dateFilter = 'AND fc.checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
			break;
		case '30':
			$dateFilter = 'AND fc.checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
			break;
		case '90':
			$dateFilter = 'AND fc.checked_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)';
			break;
		case 'custom':
			if ($customFrom !== '') {
				$dateFilter .= ' AND fc.checked_at >= :date_from';
				$dateParams[':date_from'] = $customFrom . ' 00:00:00';
			}
			if ($customTo !== '') {
				$dateFilter .= ' AND fc.checked_at <= :date_to';
				$dateParams[':date_to'] = $customTo . ' 23:59:59';
			}
			break;
		default: // 'all'
			$dateFilter = '';
			break;
	}

	// === GET ALL UNIQUE TVG_IDS IN THIS GROUP ===
	$tvgIds = [];
	$st = $pdo->prepare("
		SELECT DISTINCT c.tvg_id, c.tvg_name, c.tvg_logo
		FROM channels c
		WHERE c.group_title = :group
		AND c.tvg_id IS NOT NULL
		AND c.tvg_id <> ''
		ORDER BY c.tvg_name
	");
	$st->execute([':group' => $group]);
	$channelsInGroup = $st->fetchAll(PDO::FETCH_ASSOC);

	$results = [];

	// === FOR EACH CHANNEL, ANALYZE FEEDS ===
	foreach ($channelsInGroup as $channelInfo) {
		$tvgId = $channelInfo['tvg_id'];
		$tvgName = $channelInfo['tvg_name'];
		$tvgLogo = $channelInfo['tvg_logo'];

		// Get best feed in current group based on HISTORICAL data
		if ($hasJunctionTable) {
			$feedQuery = "
				SELECT 
					f.id AS feed_id,
					c.group_title,
					AVG(CASE WHEN fc.ok = 1 THEN 100 ELSE 0 END) AS avg_reliability,
					AVG(fc.w) AS avg_w,
					AVG(fc.h) AS avg_h,
					AVG(fc.fps) AS avg_fps,
					MAX(fc.checked_at) AS last_checked,
					COUNT(fc.id) AS check_count,
					f.last_ok,
					f.reliability_score,
					f.last_w,
					f.last_h,
					f.last_fps,
					f.last_codec
				FROM channels c
				JOIN channel_feeds cf ON cf.channel_id = c.id
				JOIN feeds f ON f.id = cf.feed_id
				LEFT JOIN feed_checks fc ON fc.feed_id = f.id {$dateFilter}
				WHERE c.tvg_id = :tvg_id
				AND c.group_title = :group
				GROUP BY f.id, c.group_title, f.last_ok, f.reliability_score, f.last_w, f.last_h, f.last_fps, f.last_codec
				HAVING check_count > 0
				ORDER BY avg_reliability DESC, avg_w DESC, avg_h DESC, avg_fps DESC
				LIMIT 1
			";
		} else {
			$feedQuery = "
				SELECT 
					f.id AS feed_id,
					c.group_title,
					AVG(CASE WHEN fc.ok = 1 THEN 100 ELSE 0 END) AS avg_reliability,
					AVG(fc.w) AS avg_w,
					AVG(fc.h) AS avg_h,
					AVG(fc.fps) AS avg_fps,
					MAX(fc.checked_at) AS last_checked,
					COUNT(fc.id) AS check_count,
					f.last_ok,
					f.reliability_score,
					f.last_w,
					f.last_h,
					f.last_fps,
					f.last_codec
				FROM channels c
				JOIN feeds f ON f.channel_id = c.id
				LEFT JOIN feed_checks fc ON fc.feed_id = f.id {$dateFilter}
				WHERE c.tvg_id = :tvg_id
				AND c.group_title = :group
				GROUP BY f.id, c.group_title, f.last_ok, f.reliability_score, f.last_w, f.last_h, f.last_fps, f.last_codec
				HAVING check_count > 0
				ORDER BY avg_reliability DESC, avg_w DESC, avg_h DESC, avg_fps DESC
				LIMIT 1
			";
		}

		$params = array_merge([':tvg_id' => $tvgId, ':group' => $group], $dateParams);
		$st = $pdo->prepare($feedQuery);
		$st->execute($params);
		$currentBest = $st->fetch(PDO::FETCH_ASSOC);

		// Get best feeds from OTHER groups
		if ($hasJunctionTable) {
			$otherFeedsQuery = "
			SELECT 
				f.id AS feed_id,
				c.group_title,
				c.tvg_name,
				AVG(CASE WHEN fc.ok = 1 THEN 100 ELSE 0 END) AS avg_reliability,
				AVG(fc.w) AS avg_w,
				AVG(fc.h) AS avg_h,
				AVG(fc.fps) AS avg_fps,
				MAX(fc.checked_at) AS last_checked,
				COUNT(fc.id) AS check_count,
				f.last_ok,
				f.reliability_score,
				f.last_w,
				f.last_h,
				f.last_fps,
				f.last_codec
			FROM channels c
			JOIN channel_feeds cf ON cf.channel_id = c.id
			JOIN feeds f ON f.id = cf.feed_id
			LEFT JOIN feed_checks fc ON fc.feed_id = f.id {$dateFilter}
			WHERE c.tvg_id = :tvg_id
			  AND c.group_title <> :group
			GROUP BY f.id, c.group_title, c.tvg_name, f.last_ok, f.reliability_score, f.last_w, f.last_h, f.last_fps, f.last_codec
			HAVING check_count > 0
			ORDER BY avg_reliability DESC, avg_w DESC, avg_h DESC, avg_fps DESC
		";
		} else {
			$otherFeedsQuery = "
			SELECT 
				f.id AS feed_id,
				c.group_title,
				c.tvg_name,
				AVG(CASE WHEN fc.ok = 1 THEN 100 ELSE 0 END) AS avg_reliability,
				AVG(fc.w) AS avg_w,
				AVG(fc.h) AS avg_h,
				AVG(fc.fps) AS avg_fps,
				MAX(fc.checked_at) AS last_checked,
				COUNT(fc.id) AS check_count,
				f.last_ok,
				f.reliability_score,
				f.last_w,
				f.last_h,
				f.last_fps,
				f.last_codec
			FROM channels c
			JOIN feeds f ON f.channel_id = c.id
			LEFT JOIN feed_checks fc ON fc.feed_id = f.id {$dateFilter}
			WHERE c.tvg_id = :tvg_id
			  AND c.group_title <> :group
			GROUP BY f.id, c.group_title, c.tvg_name, f.last_ok, f.reliability_score, f.last_w, f.last_h, f.last_fps, f.last_codec
			HAVING check_count > 0
			ORDER BY avg_reliability DESC, avg_w DESC, avg_h DESC, avg_fps DESC
		";
		}

		$st = $pdo->prepare($otherFeedsQuery);
		$st->execute($params);
		$otherFeeds = $st->fetchAll(PDO::FETCH_ASSOC);

		// Calculate scores
		$currentScore = 0;
		if ($currentBest) {
			$currentScore = rank_score(
				$currentBest['last_ok'],
				$currentBest['avg_reliability'],
				(int)$currentBest['avg_w'],
				(int)$currentBest['avg_h'],
				$currentBest['avg_fps']
			);
		}

		// Find better alternatives
		$alternatives = [];
		foreach ($otherFeeds as $feed) {
			$feedScore = rank_score(
				$feed['last_ok'],
				$feed['avg_reliability'],
				(int)$feed['avg_w'],
				(int)$feed['avg_h'],
				$feed['avg_fps']
			);

			// Check if ignored
			$isIgnored = false;
			if ($hasIgnoresTable) {
				$ignoreCheck = $pdo->prepare("
				SELECT 1 FROM group_audit_ignores
				WHERE tvg_id = :tvg_id
				  AND source_group = :source_group
				  AND suggested_feed_id = :feed_id
				LIMIT 1
			");
				$ignoreCheck->execute([
					':tvg_id' => $tvgId,
					':source_group' => $group,
					':feed_id' => $feed['feed_id']
				]);
				$isIgnored = (bool)$ignoreCheck->fetchColumn();
			}

			if ($feedScore > $currentScore && !$isIgnored) {
				$alternatives[] = [
					'group' => $feed['group_title'],
					'tvg_name' => $feed['tvg_name'],
					'feed_id' => $feed['feed_id'],
					'score' => $feedScore,
					'reliability' => round((float)$feed['avg_reliability'], 1),
					'resolution' => res_class((int)$feed['avg_w'], (int)$feed['avg_h']),
					'res_display' => round((float)$feed['avg_w']) . '×' . round((float)$feed['avg_h']),
					'fps' => round((float)$feed['avg_fps'], 1),
					'check_count' => $feed['check_count'],
					'last_checked' => $feed['last_checked']
				];
			}
		}

		// Sort alternatives by score descending
		usort($alternatives, function ($a, $b) {
			return $b['score'] <=> $a['score'];
		});

		// Limit to top 5 alternatives
		$alternatives = array_slice($alternatives, 0, 5);

		// Determine status
		$status = 'optimal'; // green
		if (!$currentBest) {
			$status = 'no_data'; // gray
		} elseif (count($alternatives) > 0) {
			$status = 'suboptimal'; // yellow/orange
		}

		$results[] = [
			'tvg_id' => $tvgId,
			'tvg_name' => $tvgName,
			'tvg_logo' => $tvgLogo,
			'status' => $status,
			'current_score' => $currentScore,
			'current_feed_id' => $currentBest ? $currentBest['feed_id'] : null,
			'current_reliability' => $currentBest ? round((float)$currentBest['avg_reliability'], 1) : null,
			'current_resolution' => $currentBest ? res_class((int)$currentBest['avg_w'], (int)$currentBest['avg_h']) : null,
			'current_res_display' => $currentBest ? round((float)$currentBest['avg_w']) . '×' . round((float)$currentBest['avg_h']) : null,
			'current_fps' => $currentBest ? round((float)$currentBest['avg_fps'], 1) : null,
			'current_check_count' => $currentBest ? $currentBest['check_count'] : 0,
			'alternatives' => $alternatives
		];
	}

	echo json_encode([
		'success' => true,
		'group' => $group,
		'date_range' => $dateRange,
		'total_channels' => count($results),
		'channels' => $results
	], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
	$errorLog = __DIR__ . '/group_audit_error.log';
	$timestamp = date('Y-m-d H:i:s');
	$errorMsg = "[$timestamp] " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
	$errorMsg .= "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
	file_put_contents($errorLog, $errorMsg, FILE_APPEND);

	http_response_code(500);
	echo json_encode([
		'error' => 'Audit failed: ' . $e->getMessage(),
		'file' => basename($e->getFile()),
		'line' => $e->getLine()
	], JSON_UNESCAPED_SLASHES);
}
