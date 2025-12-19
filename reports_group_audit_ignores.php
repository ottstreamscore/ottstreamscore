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

// Check if ignores table exists
$hasIgnoresTable = false;
try {
	$pdo->query("SELECT 1 FROM group_audit_ignores LIMIT 1");
	$hasIgnoresTable = true;
} catch (Throwable $e) {
	// Table doesn't exist, return error
	echo json_encode([
		'error' => 'Ignores table not found. Please run migration: migrate.php'
	]);
	exit;
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : (isset($_GET['action']) ? trim((string)$_GET['action']) : '');

switch ($action) {
	case 'add':
		// Add an ignore
		$tvgId = isset($_POST['tvg_id']) ? trim((string)$_POST['tvg_id']) : '';
		$sourceGroup = isset($_POST['source_group']) ? trim((string)$_POST['source_group']) : '';
		$suggestedGroup = isset($_POST['suggested_group']) ? trim((string)$_POST['suggested_group']) : '';
		$suggestedFeedId = isset($_POST['suggested_feed_id']) ? (int)$_POST['suggested_feed_id'] : 0;

		if ($tvgId === '' || $sourceGroup === '' || $suggestedGroup === '' || $suggestedFeedId === 0) {
			echo json_encode(['error' => 'Missing required parameters']);
			exit;
		}

		try {
			$st = $pdo->prepare("
				INSERT INTO group_audit_ignores 
				(tvg_id, source_group, suggested_group, suggested_feed_id)
				VALUES (:tvg_id, :source_group, :suggested_group, :suggested_feed_id)
				ON DUPLICATE KEY UPDATE created_at = NOW()
			");
			$st->execute([
				':tvg_id' => $tvgId,
				':source_group' => $sourceGroup,
				':suggested_group' => $suggestedGroup,
				':suggested_feed_id' => $suggestedFeedId
			]);
			echo json_encode(['success' => true, 'message' => 'Ignore added successfully']);
		} catch (Throwable $e) {
			echo json_encode(['error' => 'Failed to add ignore: ' . $e->getMessage()]);
		}
		break;

	case 'list':
		// List all ignores, optionally filtered by group
		$sourceGroup = isset($_GET['source_group']) ? trim((string)$_GET['source_group']) : '';

		try {
			if ($sourceGroup !== '') {
				$st = $pdo->prepare("
					SELECT 
						i.*,
						c.tvg_name,
						c.tvg_logo
					FROM group_audit_ignores i
					LEFT JOIN channels c ON c.tvg_id COLLATE utf8mb4_unicode_ci = i.tvg_id
					WHERE i.source_group = :source_group
					GROUP BY i.id
					ORDER BY i.created_at DESC
				");
				$st->execute([':source_group' => $sourceGroup]);
			} else {
				$st = $pdo->query("
					SELECT 
						i.*,
						c.tvg_name,
						c.tvg_logo
					FROM group_audit_ignores i
					LEFT JOIN channels c ON c.tvg_id COLLATE utf8mb4_unicode_ci = i.tvg_id
					GROUP BY i.id
					ORDER BY i.source_group, i.created_at DESC
				");
			}

			$ignores = $st->fetchAll(PDO::FETCH_ASSOC);
			echo json_encode(['success' => true, 'ignores' => $ignores]);
		} catch (Throwable $e) {
			echo json_encode(['error' => 'Failed to list ignores: ' . $e->getMessage()]);
		}
		break;

	case 'delete':
		// Delete a specific ignore
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

		if ($id === 0) {
			echo json_encode(['error' => 'Missing ignore ID']);
			exit;
		}

		try {
			$st = $pdo->prepare("DELETE FROM group_audit_ignores WHERE id = :id");
			$st->execute([':id' => $id]);
			echo json_encode(['success' => true, 'message' => 'Ignore removed successfully']);
		} catch (Throwable $e) {
			echo json_encode(['error' => 'Failed to delete ignore: ' . $e->getMessage()]);
		}
		break;

	case 'clear':
		// Clear all ignores for a specific group
		$sourceGroup = isset($_POST['source_group']) ? trim((string)$_POST['source_group']) : '';

		if ($sourceGroup === '') {
			echo json_encode(['error' => 'Missing source_group parameter']);
			exit;
		}

		try {
			$st = $pdo->prepare("DELETE FROM group_audit_ignores WHERE source_group = :source_group");
			$st->execute([':source_group' => $sourceGroup]);
			$count = $st->rowCount();
			echo json_encode(['success' => true, 'message' => "Cleared {$count} ignores for group"]);
		} catch (Throwable $e) {
			echo json_encode(['error' => 'Failed to clear ignores: ' . $e->getMessage()]);
		}
		break;

	case 'clear_all':
		// Clear ALL ignores (use with caution)
		try {
			$st = $pdo->query("DELETE FROM group_audit_ignores");
			$count = $st->rowCount();
			echo json_encode(['success' => true, 'message' => "Cleared all {$count} ignores"]);
		} catch (Throwable $e) {
			echo json_encode(['error' => 'Failed to clear all ignores: ' . $e->getMessage()]);
		}
		break;

	default:
		echo json_encode(['error' => 'Invalid action. Valid actions: add, list, delete, clear, clear_all']);
		break;
}
