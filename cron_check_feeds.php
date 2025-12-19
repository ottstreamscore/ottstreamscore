<?php

declare(strict_types=1);
require __DIR__ . '/db.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

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
	// reliability = % ok over last 7 days by default (168h)
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
	// Priority: Reliability > Resolution > FPS
	// Put reliability on a dominant scale so it always wins ties.
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

function next_run_for_failure(int $attempts): int
{
	// minutes: 30, 60, 120, 240, 360...
	$m = FAIL_RETRY_MINUTES_MIN * (2 ** max(0, $attempts - 1));
	return (int)min($m, FAIL_RETRY_MINUTES_MAX);
}

$pdo = db();
$token = uuidv4();

// ============================================================================
// STREAM PREVIEW LOCK CHECK
// Skip feed checks if a user is actively previewing a stream
// ============================================================================

try {
	// Clean up any stale stream preview locks (older than 30 seconds without heartbeat)
	$deletedLocks = $pdo->exec("
		DELETE FROM stream_preview_lock 
		WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 30 SECOND)
	");

	// Check for active stream preview
	$lockCount = $pdo->query("
		SELECT COUNT(*) 
		FROM stream_preview_lock 
		WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
	")->fetchColumn();

	if ($lockCount > 0) {
		// Get lock details for logging
		$lockDetails = $pdo->query("
			SELECT feed_id, channel_name, locked_by, 
			       TIMESTAMPDIFF(SECOND, last_heartbeat, NOW()) as seconds_since_heartbeat
			FROM stream_preview_lock 
			WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
			LIMIT 1
		")->fetch(PDO::FETCH_ASSOC);

		$message = sprintf(
			"Feed Check Cron: Skipping - active stream preview detected (Feed ID: %d, Channel: %s, Session: %s, Heartbeat: %ds ago)",
			$lockDetails['feed_id'] ?? 0,
			$lockDetails['channel_name'] ?? 'Unknown',
			substr($lockDetails['locked_by'] ?? 'unknown', 0, 8),
			$lockDetails['seconds_since_heartbeat'] ?? 0
		);
		error_log($message);
		exit(0);
	}
} catch (Throwable $e) {
	// If stream_preview_lock table doesn't exist yet, continue normally
	error_log("Feed Check Cron: Stream preview lock check failed (table may not exist): " . $e->getMessage());
}

// ============================================================================
// PROCEED WITH NORMAL FEED CHECKING
// ============================================================================

// Claim work (simple lock)
$pdo->beginTransaction();

// release stale locks
$pdo->exec("
  UPDATE feed_check_queue
  SET locked_at=NULL, lock_token=NULL
  WHERE locked_at IS NOT NULL AND locked_at < DATE_SUB(NOW(), INTERVAL " . LOCK_MINUTES . " MINUTE)
");

// select due feeds
$st = $pdo->prepare("
  SELECT q.feed_id, f.url
  FROM feed_check_queue q
  JOIN feeds f ON f.id=q.feed_id
  WHERE q.next_run_at <= NOW()
    AND (q.locked_at IS NULL)
  ORDER BY q.next_run_at ASC
  LIMIT " . BATCH_SIZE . "
  FOR UPDATE
");
$st->execute();
$rows = $st->fetchAll();

if (!$rows) {
	$pdo->commit();
	exit; // nothing to do
}

// lock them
$ids = array_column($rows, 'feed_id');
$in = implode(',', array_fill(0, count($ids), '?'));
$lockSt = $pdo->prepare("
  UPDATE feed_check_queue
  SET locked_at=NOW(), lock_token=?
  WHERE feed_id IN ($in)
");
$lockSt->execute(array_merge([$token], $ids));
$pdo->commit();

// Track processing stats
$processedCount = 0;
$successCount = 0;
$failCount = 0;
$startTime = microtime(true);

// Process
foreach ($rows as $r) {
	// ========================================================================
	// RE-CHECK FOR STREAM PREVIEW LOCK BEFORE EACH FEED
	// If a preview started mid-cycle, stop immediately to avoid conflicts
	// ========================================================================
	try {
		$lockRecheckCount = $pdo->query("
			SELECT COUNT(*) 
			FROM stream_preview_lock 
			WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
		")->fetchColumn();

		if ($lockRecheckCount > 0) {

			// Release all locks we acquired for this batch
			$releaseLocks = $pdo->prepare("
				UPDATE feed_check_queue
				SET locked_at = NULL, lock_token = NULL
				WHERE lock_token = :token
			");
			$releaseLocks->execute([':token' => $token]);

			exit(0); // Exit cleanly
		}
	} catch (Throwable $e) {
		// Continue if table doesn't exist
	}
	// ========================================================================

	$feedId = (int)$r['feed_id'];
	$url = (string)$r['url'];

	$res = probeStreamWithFFprobe($url, 12);
	$processedCount++;

	if ($res['ok']) {
		$successCount++;
	} else {
		$failCount++;
	}

	// write history row
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

	// compute scores
	$reliability = compute_reliability($pdo, $feedId, 168);
	$quality = compute_quality($reliability, $res['w'] ?? null, $res['h'] ?? null, $res['fps'] ?? null);

	// update feed snapshot
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

	// schedule next run
	if ($res['ok']) {
		$next = $pdo->prepare("
      UPDATE feed_check_queue
      SET next_run_at = DATE_ADD(NOW(), INTERVAL " . OK_RECHECK_HOURS . " HOUR),
          locked_at=NULL, lock_token=NULL,
          attempts=0,
          last_result_ok=1,
          last_error=NULL
      WHERE feed_id=:id AND lock_token=:tok
    ");
		$next->execute([':id' => $feedId, ':tok' => $token]);
	} else {
		// get attempts then backoff
		$pdo->prepare("UPDATE feed_check_queue SET attempts=attempts+1 WHERE feed_id=:id")->execute([':id' => $feedId]);
		$stA = $pdo->prepare("SELECT attempts FROM feed_check_queue WHERE feed_id=:id");
		$stA->execute([':id' => $feedId]);
		$attempts = (int)$stA->fetchColumn();
		$delayMin = next_run_for_failure($attempts);

		$next = $pdo->prepare("
      UPDATE feed_check_queue
      SET next_run_at = DATE_ADD(NOW(), INTERVAL :m MINUTE),
          locked_at=NULL, lock_token=NULL,
          last_result_ok=0,
          last_error=:err
      WHERE feed_id=:id AND lock_token=:tok
    ");
		$next->execute([
			':m' => $delayMin,
			':err' => substr((string)($res['error'] ?? 'error'), 0, 255),
			':id' => $feedId,
			':tok' => $token
		]);
	}
}

// ============================================================================
// SUMMARY LOGGING (OPTIONAL - OFF BY DEFAULT)
// ============================================================================

$duration = round(microtime(true) - $startTime, 2);
$summary = sprintf(
	"Feed Check Cron: Completed - %d feeds processed (%d successful, %d failed) in %.2fs (avg %.2fs per feed)",
	$processedCount,
	$successCount,
	$failCount,
	$duration,
	$processedCount > 0 ? round($duration / $processedCount, 2) : 0
);

// error_log($summary);
