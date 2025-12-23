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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	echo json_encode(['success' => false, 'message' => 'Invalid request method']);
	exit;
}

$pdo = db();

try {
	$refTvgId = $_POST['ref_tvg_id'] ?? '';
	$refGroup = $_POST['ref_group'] ?? '';
	$sugFeedId = (int)($_POST['sug_feed_id'] ?? 0);
	$sugGroup = $_POST['sug_group'] ?? '';
	$category = $_POST['category'] ?? '';
	$note = $_POST['note'] ?? null;
	$userId = (int)$_SESSION['user_id'];

	// Validation
	if (empty($refTvgId) || empty($refGroup) || $sugFeedId <= 0 || empty($sugGroup) || empty($category)) {
		echo json_encode(['success' => false, 'message' => 'Missing required fields']);
		exit;
	}

	// Validate category
	$validCategories = ['feed_replacement', 'feed_review', 'epg_adjustment', 'other'];
	if (!in_array($category, $validCategories)) {
		echo json_encode(['success' => false, 'message' => 'Invalid category']);
		exit;
	}

	// Trim note
	if ($note !== null) {
		$note = trim($note);
		if ($note === '') $note = null;
	}

	// Insert task
	$stmt = $pdo->prepare("
        INSERT INTO editor_todo_list 
        (tvg_id, source_group, suggested_group, suggested_feed_id, created_by_user, category, note)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

	$stmt->execute([
		$refTvgId,
		$refGroup,
		$sugGroup,
		$sugFeedId,
		$userId,
		$category,
		$note
	]);

	echo json_encode([
		'success' => true,
		'message' => 'Task created successfully',
		'task_id' => (int)$pdo->lastInsertId()
	]);
} catch (Exception $e) {
	echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
