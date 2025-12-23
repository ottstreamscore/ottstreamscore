<?php

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

// Require login authorization
if (!is_logged_in()) {
	header('Content-Type: application/json');
	http_response_code(401);
	echo json_encode(['error' => 'Unauthorized']);
	exit;
}


header('Content-Type: application/json');

$tvgId = trim($_GET['tvg_id'] ?? '');

if (empty($tvgId)) {
	echo json_encode(['success' => false, 'error' => 'tvg_id required']);
	exit;
}

try {
	$pdo = get_db_connection();

	// Get current time in app timezone
	$appTimezone = get_setting('app_timezone', 'America/New_York');
	$now = new DateTime('now', new DateTimeZone($appTimezone));
	$nowStr = $now->format('Y-m-d H:i:s');

	// Query EPG data for this tvg_id around current time
	// Get current program + 2 programs before + 2 programs after
	$stmt = $pdo->prepare("
		(
			SELECT 
				tvg_id,
				title,
				description,
				DATE_FORMAT(start_timestamp, '%H:%i') as start_time,
				DATE_FORMAT(end_timestamp, '%H:%i') as end_time,
				start_timestamp,
				end_timestamp,
				CASE 
					WHEN :now1 BETWEEN start_timestamp AND end_timestamp THEN 1
					ELSE 0
				END as is_current
			FROM epg_data
			WHERE tvg_id = :tvg_id1
				AND start_timestamp <= :now2
			ORDER BY start_timestamp DESC
			LIMIT 2
		)
		UNION ALL
		(
			SELECT 
				tvg_id,
				title,
				description,
				DATE_FORMAT(start_timestamp, '%H:%i') as start_time,
				DATE_FORMAT(end_timestamp, '%H:%i') as end_time,
				start_timestamp,
				end_timestamp,
				CASE 
					WHEN :now3 BETWEEN start_timestamp AND end_timestamp THEN 1
					ELSE 0
				END as is_current
			FROM epg_data
			WHERE tvg_id = :tvg_id2
				AND start_timestamp > :now4
			ORDER BY start_timestamp ASC
			LIMIT 3
		)
		ORDER BY start_timestamp ASC
	");

	$stmt->execute([
		':tvg_id1' => $tvgId,
		':tvg_id2' => $tvgId,
		':now1' => $nowStr,
		':now2' => $nowStr,
		':now3' => $nowStr,
		':now4' => $nowStr
	]);

	$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// Convert is_current to boolean
	foreach ($programs as &$program) {
		$program['is_current'] = (bool)$program['is_current'];
		// Remove the full timestamp from output
		unset($program['start_timestamp']);
		unset($program['end_timestamp']);
	}

	echo json_encode([
		'success' => true,
		'programs' => $programs,
		'tvg_id' => $tvgId
	]);
} catch (Exception $e) {
	error_log("EPG fetch error: " . $e->getMessage());
	echo json_encode([
		'success' => false,
		'error' => 'Failed to fetch EPG data'
	]);
}
