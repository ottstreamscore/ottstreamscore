<?php

declare(strict_types=1);

ob_start();
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

function jsonResponse(bool $success, string $message = '', array $data = []): void
{
	echo json_encode([
		'success' => $success,
		'message' => $message,
		'data' => $data
	]);
	exit;
}

function uuidv4(): string
{
	$data = random_bytes(16);
	$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
	$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
	return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function probeStreamWithFFprobe(string $streamUrl, int $timeout = 10): array
{
	$cmd = [
		'ffprobe',
		'-v',
		'quiet',
		'-print_format',
		'json',
		'-show_streams',
		'-select_streams',
		'v:0',
		'-show_entries',
		'stream=codec_name,width,height,avg_frame_rate',
		'-timeout',
		(string)($timeout * 1_000_000),
		$streamUrl
	];
	$command = implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1';
	$output = shell_exec($command);

	if (!$output) return ['ok' => false, 'error' => 'ffprobe returned no output'];

	$start = strpos($output, '{');
	$end = strrpos($output, '}');
	if ($start === false || $end === false || $end <= $start) {
		return ['ok' => false, 'error' => 'ffprobe did not return JSON', 'raw' => $output];
	}
	$json = substr($output, $start, $end - $start + 1);

	$data = json_decode($json, true);
	if (!is_array($data)) return ['ok' => false, 'error' => 'Invalid ffprobe JSON', 'raw' => $output];

	$v = $data['streams'][0] ?? null;
	if (!$v) return ['ok' => false, 'error' => 'No video stream detected', 'raw_json' => $json];

	$fps = null;
	if (!empty($v['avg_frame_rate']) && $v['avg_frame_rate'] !== '0/0') {
		[$n, $d] = array_map('intval', explode('/', $v['avg_frame_rate']));
		if ($d > 0) $fps = round($n / $d, 2);
	}

	return [
		'ok' => true,
		'codec' => $v['codec_name'] ?? null,
		'w' => $v['width'] ?? null,
		'h' => $v['height'] ?? null,
		'fps' => $fps,
		'raw_json' => $json
	];
}

function compute_reliability(PDO $pdo, int $feedId, int $windowHours = 168): float
{
	$st = $pdo->prepare("
		SELECT
			SUM(CASE WHEN ok=1 THEN 1 ELSE 0 END) AS ok_count,
			COUNT(*) AS total_count
		FROM feed_checks
		WHERE feed_id=:id AND checked_at >= DATE_SUB(NOW(), INTERVAL :h HOUR)
	");
	$st->execute([':id' => $feedId, ':h' => $windowHours]);
	$row = $st->fetch() ?: ['ok_count' => 0, 'total_count' => 0];
	$total = (int)$row['total_count'];
	if ($total === 0) return 0.0;
	return round(((int)$row['ok_count'] / $total) * 100.0, 2);
}

function compute_quality(float $reliability, ?int $w, ?int $h, ?float $fps): float
{
	$resScore = 0;
	if ($w && $h) {
		$px = $w * $h;
		if ($px >= 1920 * 1080) $resScore = 30;
		elseif ($px >= 1280 * 720) $resScore = 20;
		elseif ($px >= 854 * 480) $resScore = 10;
		else $resScore = 5;
	}
	$fpsScore = 0;
	if ($fps !== null) {
		if ($fps >= 50) $fpsScore = 10;
		elseif ($fps >= 30) $fpsScore = 8;
		elseif ($fps >= 25) $fpsScore = 6;
		elseif ($fps >= 15) $fpsScore = 3;
	}

	return round(($reliability * 1000.0) + ($resScore * 10.0) + $fpsScore, 2);
}

// Load settings
$pdo = db();
$settingsSt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $settingsSt->fetch(PDO::FETCH_ASSOC)) {
	$settings[$row['setting_key']] = $row['setting_value'];
}

$LOCK_MINUTES = (int)($settings['lock_minutes'] ?? 10);
$OK_RECHECK_HOURS = (int)($settings['ok_recheck_hours'] ?? 72);

// Get feed ID
$feedId = (int)($_POST['feed_id'] ?? 0);
if ($feedId <= 0) {
	jsonResponse(false, 'Invalid feed ID');
}

// Get feed info
$st = $pdo->prepare("SELECT id, url FROM feeds WHERE id = :id LIMIT 1");
$st->execute([':id' => $feedId]);
$feed = $st->fetch(PDO::FETCH_ASSOC);

if (!$feed) {
	jsonResponse(false, 'Feed not found');
}

$token = uuidv4();

// Try to acquire lock
$pdo->beginTransaction();

// Release stale locks first
$pdo->exec("
	UPDATE feed_check_queue
	SET locked_at=NULL, lock_token=NULL
	WHERE locked_at IS NOT NULL 
	AND locked_at < DATE_SUB(NOW(), INTERVAL {$LOCK_MINUTES} MINUTE)
");

// Check if this specific feed is already locked
$lockCheck = $pdo->prepare("
	SELECT locked_at, lock_token 
	FROM feed_check_queue 
	WHERE feed_id = :id 
	AND locked_at IS NOT NULL
");
$lockCheck->execute([':id' => $feedId]);
$existingLock = $lockCheck->fetch();

if ($existingLock) {
	$pdo->rollBack();
	jsonResponse(false, 'Feed is currently being checked by the automated system. Please try again in a moment.');
}

// Acquire lock for this feed
$lockSt = $pdo->prepare("
	UPDATE feed_check_queue
	SET locked_at=NOW(), lock_token=:token
	WHERE feed_id=:id
");
$lockSt->execute([':token' => $token, ':id' => $feedId]);

$pdo->commit();

// Perform the check
$url = (string)$feed['url'];
$res = probeStreamWithFFprobe($url, 12);

// Write history row
$ins = $pdo->prepare("
	INSERT INTO feed_checks (feed_id, checked_at, ok, codec, w, h, fps, error, raw_json)
	VALUES (:feed_id, NOW(), :ok, :codec, :w, :h, :fps, :error, :raw_json)
");
$ins->execute([
	':feed_id' => $feedId,
	':ok' => $res['ok'] ? 1 : 0,
	':codec' => $res['codec'] ?? null,
	':w' => $res['w'] ?? null,
	':h' => $res['h'] ?? null,
	':fps' => $res['fps'] ?? null,
	':error' => $res['ok'] ? null : substr((string)($res['error'] ?? 'error'), 0, 255),
	':raw_json' => $res['raw_json'] ?? ($res['raw'] ?? null),
]);

// Get the ID of the inserted check
$checkId = (int)$pdo->lastInsertId();

// Compute scores
$reliability = compute_reliability($pdo, $feedId, 168);
$quality = compute_quality($reliability, $res['w'] ?? null, $res['h'] ?? null, $res['fps'] ?? null);

// Update feed snapshot
$up = $pdo->prepare("
	UPDATE feeds
	SET last_checked_at=NOW(),
		last_ok=:ok,
		last_codec=:codec,
		last_w=:w,
		last_h=:h,
		last_fps=:fps,
		last_error=:err,
		reliability_score=:rel,
		quality_score=:qs
	WHERE id=:id
");
$up->execute([
	':ok' => $res['ok'] ? 1 : 0,
	':codec' => $res['codec'] ?? null,
	':w' => $res['w'] ?? null,
	':h' => $res['h'] ?? null,
	':fps' => $res['fps'] ?? null,
	':err' => $res['ok'] ? null : substr((string)($res['error'] ?? 'error'), 0, 255),
	':rel' => $reliability,
	':qs' => $quality,
	':id' => $feedId,
]);

// Release lock and schedule next run
$next = $pdo->prepare("
	UPDATE feed_check_queue
	SET next_run_at = DATE_ADD(NOW(), INTERVAL {$OK_RECHECK_HOURS} HOUR),
		locked_at=NULL, 
		lock_token=NULL,
		attempts=0,
		last_result_ok=:ok,
		last_error=:err
	WHERE feed_id=:id AND lock_token=:tok
");
$next->execute([
	':ok' => $res['ok'] ? 1 : 0,
	':err' => $res['ok'] ? null : substr((string)($res['error'] ?? 'error'), 0, 255),
	':id' => $feedId,
	':tok' => $token
]);

// Prepare response data
jsonResponse(true, 'Feed check completed successfully', [
	'check_id' => $checkId,
	'ok' => $res['ok'],
	'codec' => $res['codec'] ?? null,
	'width' => $res['w'] ?? null,
	'height' => $res['h'] ?? null,
	'fps' => $res['fps'] ?? null,
	'error' => $res['error'] ?? null,
	'reliability' => $reliability,
	'quality' => $quality,
	'timestamp' => date('Y-m-d H:i:s')
]);
