<?php

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

// Require authentication
require_auth();

// CRITICAL: Close session immediately to prevent blocking
session_write_close();

$pdo = db();

// CRITICAL: Disable ALL timeouts for streaming
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('max_input_time', '-1');
ignore_user_abort(false); // Exit if client disconnects (cleanup)

// Verify active lock exists for this session
$sessionId = session_id();
$lockCheck = $pdo->prepare("
    SELECT feed_id 
    FROM stream_preview_lock 
    WHERE locked_by = :session_id 
    AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
");
$lockCheck->execute([':session_id' => $sessionId]);
$lock = $lockCheck->fetch(PDO::FETCH_ASSOC);

if (!$lock) {
	http_response_code(403);
	header('Content-Type: application/json');
	echo json_encode(['error' => 'No active stream lock. Please start a preview first.']);
	exit;
}

// Get and validate feed ID
$feedId = (int)($_GET['feed_id'] ?? 0);
if ($feedId !== (int)$lock['feed_id']) {
	http_response_code(403);
	header('Content-Type: application/json');
	echo json_encode(['error' => 'Feed ID does not match active lock.']);
	exit;
}

// Get feed URL from database
$stmt = $pdo->prepare("SELECT url FROM feeds WHERE id = :feed_id");
$stmt->execute([':feed_id' => $feedId]);
$feed = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$feed) {
	http_response_code(404);
	header('Content-Type: application/json');
	echo json_encode(['error' => 'Feed not found']);
	exit;
}

$baseUrl = $feed['url'];
$path = $_GET['path'] ?? '';

// ============================================================================
// EARLY DETECTION: Check if this is a .ts stream
// If so, stream it immediately without buffering
// ============================================================================
$isTsUrl = preg_match('/\.ts(\?|$)/i', $baseUrl);

if ($isTsUrl && !$path) {

	// CRITICAL: Disable all timeouts for live streaming
	set_time_limit(0);
	ini_set('max_execution_time', '0');
	ini_set('max_input_time', '-1');
	ignore_user_abort(true); // Keep streaming even if client disconnects briefly

	header('Content-Type: video/mp2t');
	header('Accept-Ranges: bytes');
	header('Cache-Control: no-cache');
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Expose-Headers: Content-Length, Content-Range');
	header('X-Accel-Buffering: no'); // Disable nginx buffering if present

	while (ob_get_level()) ob_end_clean();

	$ch = curl_init($baseUrl);
	$headers = ['User-Agent: Mozilla/5.0'];

	if (isset($_SERVER['HTTP_RANGE'])) {
		$headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
		http_response_code(206);
	} else {
		http_response_code(200);
	}

	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => false,
		CURLOPT_HEADER => false,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_BUFFERSIZE => 16384,
		CURLOPT_WRITEFUNCTION => function ($ch, $data) {
			echo $data;
			if (ob_get_level()) flush();
			return strlen($data);
		},
		CURLOPT_HTTPHEADER => $headers
	]);

	curl_exec($ch);
	curl_close($ch);
	exit;
}
// ============================================================================

// Check if we're requesting a .ts segment (even via path parameter)
$isSegmentRequest = preg_match('/\.ts(\?|$)/i', $path ?: $baseUrl);

// Construct target URL
if ($path) {
	$decodedPath = urldecode($path);

	// Handle absolute URLs
	if (strpos($decodedPath, 'http') === 0) {
		$targetUrl = $decodedPath;
	} else {
		// Handle relative URLs
		$parsedBase = parse_url($baseUrl);
		$baseDir = dirname($parsedBase['path'] ?? '/');

		$targetUrl = $parsedBase['scheme'] . '://' . $parsedBase['host'];
		if (isset($parsedBase['port'])) {
			$targetUrl .= ':' . $parsedBase['port'];
		}
		$targetUrl .= rtrim($baseDir, '/') . '/' . ltrim($decodedPath, '/');
	}
} else {
	$targetUrl = $baseUrl;
}

// If this is a .ts segment, stream it directly without buffering
if ($isSegmentRequest) {

	header('Content-Type: video/mp2t');
	header('Accept-Ranges: bytes');
	header('Cache-Control: no-cache');
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Expose-Headers: Content-Length, Content-Range');

	while (ob_get_level()) ob_end_clean();

	$ch = curl_init($targetUrl);
	$headers = ['User-Agent: Mozilla/5.0'];

	if (isset($_SERVER['HTTP_RANGE'])) {
		$headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
		http_response_code(206);
	} else {
		http_response_code(200);
	}

	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => false,
		CURLOPT_HEADER => false,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_BUFFERSIZE => 16384,
		CURLOPT_WRITEFUNCTION => function ($ch, $data) {
			echo $data;
			flush();
			return strlen($data);
		},
		CURLOPT_HTTPHEADER => $headers
	]);

	curl_exec($ch);
	curl_close($ch);
	exit;
}

// Initialize cURL for non-streaming content (m3u8, etc)
$ch = curl_init($targetUrl);

// Build headers array
$headers = [
	'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
];

// Handle Range requests (for video seeking)
if (isset($_SERVER['HTTP_RANGE'])) {
	$headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_MAXREDIRS => 5,
	CURLOPT_SSL_VERIFYPEER => false,
	CURLOPT_SSL_VERIFYHOST => false,
	CURLOPT_CONNECTTIMEOUT => 10,
	CURLOPT_TIMEOUT => 60,
	CURLOPT_HTTPHEADER => $headers,
	CURLOPT_HEADER => true,
	CURLOPT_BUFFERSIZE => 16384
]);

// Execute request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$error = curl_error($ch);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);


if ($response === false) {
	error_log("Stream Proxy: cURL error: " . $error);
	http_response_code(502);
	header('Content-Type: application/json');
	echo json_encode(['error' => 'Failed to fetch stream: ' . $error]);
	exit;
}

// Split headers and body
$headerString = substr($response, 0, $headerSize);
$content = substr($response, $headerSize);

// Parse headers
$responseHeaders = [];
$headerLines = explode("\r\n", $headerString);
foreach ($headerLines as $headerLine) {
	if (strpos($headerLine, ':') !== false) {
		list($name, $value) = explode(':', $headerLine, 2);
		$responseHeaders[strtolower(trim($name))] = trim($value);
	}
}

$contentType = $responseHeaders['content-type'] ?? '';

// Check if this is an HLS manifest
$isManifest = (
	strpos($contentType, 'application/vnd.apple.mpegurl') !== false ||
	strpos($contentType, 'application/x-mpegurl') !== false ||
	strpos($contentType, 'audio/mpegurl') !== false ||
	strpos($targetUrl, '.m3u8') !== false ||
	strpos($content, '#EXTM3U') === 0
);

// Check if this is raw MPEG-TS stream
$isTsStream = (
	strpos($contentType, 'video/mp2t') !== false ||
	strpos($contentType, 'video/mpeg') !== false ||
	preg_match('/\.ts(\?|$)/i', $targetUrl)
);

// Handle non-200 responses
if ($httpCode !== 200 && $httpCode !== 206) {
	error_log("Stream Proxy: Non-200 response: $httpCode");
	http_response_code($httpCode);
	header('Content-Type: application/json');
	echo json_encode(['error' => "Stream returned HTTP $httpCode"]);
	exit;
}

// ============================================================================
// HANDLE RAW .TS STREAMS
// For raw MPEG-TS streams, we need to stream the content continuously
// ============================================================================
if ($isTsStream && !$isManifest) {

	// For raw TS, we need to re-fetch with streaming enabled (not buffered)
	// Close the buffered connection and start streaming

	// Set up streaming headers
	header('Content-Type: video/mp2t');
	header('Accept-Ranges: bytes');
	header('Cache-Control: no-cache');
	header('X-Content-Type-Options: nosniff');

	// Handle range requests for seeking
	if (isset($_SERVER['HTTP_RANGE'])) {
		// Parse range header
		if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
			$start = (int)$matches[1];
			$end = $matches[2] !== '' ? (int)$matches[2] : null;

			http_response_code(206); // Partial Content

			// Build range request
			$rangeHeader = "bytes=$start-" . ($end !== null ? $end : '');

			// Re-fetch with range
			$ch = curl_init($targetUrl);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_HEADER => false,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_WRITEFUNCTION => function ($ch, $data) {
					echo $data;
					flush();
					return strlen($data);
				},
				CURLOPT_HTTPHEADER => [
					'Range: ' . $rangeHeader,
					'User-Agent: Mozilla/5.0'
				]
			]);

			if (ob_get_level()) ob_end_clean();
			curl_exec($ch);
			curl_close($ch);
			exit;
		}
	}

	// Full content streaming (no range)
	http_response_code(200);

	// Re-fetch with streaming
	$ch = curl_init($targetUrl);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => false,
		CURLOPT_HEADER => false,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_BUFFERSIZE => 65536, // 64KB chunks
		CURLOPT_WRITEFUNCTION => function ($ch, $data) {
			echo $data;
			flush();
			return strlen($data);
		},
		CURLOPT_HTTPHEADER => [
			'User-Agent: Mozilla/5.0'
		]
	]);

	if (ob_get_level()) ob_end_clean();
	curl_exec($ch);
	curl_close($ch);
	exit;
}
// ============================================================================


if ($isManifest) {
	// Rewrite manifest URLs to go through our proxy
	$content = rewriteM3U8($content, $feedId, $targetUrl);
	header('Content-Type: application/vnd.apple.mpegurl');
	header('Content-Length: ' . strlen($content));
} else {
	// Forward appropriate headers
	$forwardHeaders = [
		'content-type',
		'content-length',
		'content-range',
		'accept-ranges',
		'cache-control',
		'last-modified',
		'etag'
	];

	foreach ($forwardHeaders as $headerName) {
		if (isset($responseHeaders[$headerName])) {
			$capitalizedName = implode('-', array_map('ucfirst', explode('-', $headerName)));
			header($capitalizedName . ': ' . $responseHeaders[$headerName]);
		}
	}

	// Set appropriate HTTP response code
	http_response_code($httpCode);
}

// Additional headers for MSE and CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	// Handle preflight
	http_response_code(200);
	exit;
}

// Disable output buffering
if (ob_get_level()) {
	ob_end_clean();
}

// Output content
echo $content;
exit;

/**
 * Rewrite M3U8 manifest URLs to point back through our proxy
 */
function rewriteM3U8(string $content, int $feedId, string $baseUrl): string
{
	$lines = explode("\n", $content);
	$output = [];

	foreach ($lines as $line) {
		$line = rtrim($line, "\r");

		// Skip comments and empty lines
		if (empty($line) || strpos($line, '#') === 0) {
			$output[] = $line;
			continue;
		}

		// This is a URL line - rewrite it
		$trimmedLine = trim($line);

		if (empty($trimmedLine)) {
			$output[] = $line;
			continue;
		}

		// Determine if it's an absolute or relative URL
		if (strpos($trimmedLine, 'http://') === 0 || strpos($trimmedLine, 'https://') === 0) {
			// Absolute URL
			$encodedPath = urlencode($trimmedLine);
		} else {
			// Relative URL
			$encodedPath = urlencode($trimmedLine);
		}

		// Rewrite to go through our proxy
		$proxyUrl = 'stream_proxy.php?feed_id=' . $feedId . '&path=' . $encodedPath;
		$output[] = $proxyUrl;
	}

	return implode("\n", $output);
}
