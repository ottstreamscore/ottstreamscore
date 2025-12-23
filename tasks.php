<?php

declare(strict_types=1);

$title = 'Tasks';
$currentPage = 'tasks';
require_once __DIR__ . '/_boot.php';

// require login authorization
require_auth();

$pdo = db();

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	if (!is_logged_in()) {
		header('Content-Type: application/json');
		http_response_code(401);
		echo json_encode(['error' => 'Unauthorized']);
		exit;
	}

	header('Content-Type: application/json');

	$action = $_POST['action'] ?? '';

	try {
		// Handle log-specific actions first (don't require task_id)
		if ($action === 'delete_log') {
			$logId = (int)($_POST['log_id'] ?? 0);
			if ($logId <= 0) {
				echo json_encode(['success' => false, 'message' => 'Invalid log ID']);
				exit;
			}

			$deleteLogStmt = $pdo->prepare("DELETE FROM editor_todo_list_log WHERE id = ?");
			$deleteLogStmt->execute([$logId]);

			echo json_encode(['success' => true, 'message' => 'Log item deleted']);
			exit;
		}

		if ($action === 'clear_log') {
			$pdo->exec("DELETE FROM editor_todo_list_log");
			echo json_encode(['success' => true, 'message' => 'Log cleared']);
			exit;
		}

		// For task actions, validate task_id
		$taskId = (int)($_POST['task_id'] ?? 0);

		if ($taskId <= 0) {
			echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
			exit;
		}

		// Get the task
		$stmt = $pdo->prepare("SELECT * FROM editor_todo_list WHERE id = ?");
		$stmt->execute([$taskId]);
		$task = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$task) {
			echo json_encode(['success' => false, 'message' => 'Task not found']);
			exit;
		}

		$userId = (int)$_SESSION['user_id'];

		if ($action === 'complete') {
			// Insert to log
			$logStmt = $pdo->prepare("
				INSERT INTO editor_todo_list_log 
				(original_todo_id, tvg_id, source_group, suggested_group, suggested_feed_id, 
				 created_at, created_by_user, category, note, completed_by_user, completion_status)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
			");
			$logStmt->execute([
				$task['id'],
				$task['tvg_id'],
				$task['source_group'],
				$task['suggested_group'],
				$task['suggested_feed_id'],
				$task['created_at'],
				$task['created_by_user'],
				$task['category'],
				$task['note'],
				$userId
			]);

			$logId = $pdo->lastInsertId();

			// Delete from active
			$deleteStmt = $pdo->prepare("DELETE FROM editor_todo_list WHERE id = ?");
			$deleteStmt->execute([$taskId]);

			echo json_encode(['success' => true, 'message' => 'Task marked as complete', 'log_id' => $logId]);

			exit;
		} elseif ($action === 'delete') {
			// Insert to log
			$logStmt = $pdo->prepare("
				INSERT INTO editor_todo_list_log 
				(original_todo_id, tvg_id, source_group, suggested_group, suggested_feed_id, 
				 created_at, created_by_user, category, note, completed_by_user, completion_status)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'deleted')
			");

			$logStmt->execute([
				$task['id'],
				$task['tvg_id'],
				$task['source_group'],
				$task['suggested_group'],
				$task['suggested_feed_id'],
				$task['created_at'],
				$task['created_by_user'],
				$task['category'],
				$task['note'],
				$userId
			]);

			$logId = $pdo->lastInsertId();

			// Delete from active
			$deleteStmt = $pdo->prepare("DELETE FROM editor_todo_list WHERE id = ?");
			$deleteStmt->execute([$taskId]);

			echo json_encode(['success' => true, 'message' => 'Task deleted', 'log_id' => $logId]);
			exit;
		}

		echo json_encode(['success' => false, 'message' => 'Invalid action']);
		exit;
	} catch (Exception $e) {
		echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
		exit;
	}
}

require_once __DIR__ . '/_top.php';

// Get all active tasks with user and feed info
$tasksQuery = "
	SELECT 
		t.*,
		u.username as creator_username,
		sf.id as suggested_feed_id_data,
		sf.last_ok as suggested_last_ok,
		sf.reliability_score as suggested_reliability,
		sf.last_w as suggested_w,
		sf.last_h as suggested_h,
		sf.last_fps as suggested_fps,
		sf.last_codec as suggested_codec,
		COALESCE(sf.url_display, sf.url) AS suggested_url,
		sc.tvg_name as suggested_channel_name,
		sc.tvg_id as suggested_tvg_id
	FROM editor_todo_list t
	LEFT JOIN users u ON u.id = t.created_by_user
	LEFT JOIN feeds sf ON sf.id = t.suggested_feed_id
	LEFT JOIN channels sc ON sc.id = sf.channel_id
	ORDER BY t.created_at DESC
";

$tasks = $pdo->query($tasksQuery)->fetchAll(PDO::FETCH_ASSOC);

// Get log items
$logQuery = "
	SELECT 
		l.*,
		u1.username as creator_username,
		u2.username as completer_username
	FROM editor_todo_list_log l
	LEFT JOIN users u1 ON u1.id = l.created_by_user
	LEFT JOIN users u2 ON u2.id = l.completed_by_user
	ORDER BY l.completed_at DESC
";
$logItems = $pdo->query($logQuery)->fetchAll(PDO::FETCH_ASSOC);

// Get unique groups from active tasks
$groupsQuery = "SELECT DISTINCT source_group FROM editor_todo_list ORDER BY source_group";
$groups = $pdo->query($groupsQuery)->fetchAll(PDO::FETCH_COLUMN);

// Get all users for filter
$usersQuery = "SELECT id, username FROM users WHERE is_active = 1 ORDER BY username";
$users = $pdo->query($usersQuery)->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
if (!function_exists('res_class')) {
	function res_class(?int $w, ?int $h): array
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
}

if (!function_exists('res_badge')) {
	function res_badge(string $cls): string
	{
		$clsU = strtoupper($cls);

		$map = [
			'4K'      => 'bg-warning text-dark',
			'FHD'     => 'bg-primary',
			'HD'      => 'bg-info text-dark',
			'SD'      => 'bg-secondary',
			'UNKNOWN' => 'bg-light text-dark',
		];
		$key = $clsU;
		$badgeClass = $map[$key] ?? $map['UNKNOWN'];
		return '<span class="badge ' . $badgeClass . '">' . h($clsU) . '</span>';
	}
}

if (!function_exists('ts_filename')) {
	function ts_filename(?string $url): string
	{
		$url = (string)$url;
		$path = parse_url($url, PHP_URL_PATH);
		$path = is_string($path) ? $path : '';
		$base = $path !== '' ? basename($path) : '';
		if ($base === '' && $url !== '') $base = basename($url);
		return $base !== '' ? $base : '—';
	}
}

function category_badge(string $category): string
{
	$map = [
		'feed_replacement' => 'bg-danger',
		'feed_review' => 'bg-warning text-dark',
		'epg_adjustment' => 'bg-info text-dark',
		'other' => 'bg-secondary'
	];

	$labels = [
		'feed_replacement' => 'Feed Replacement',
		'feed_review' => 'Feed Review',
		'epg_adjustment' => 'EPG Adjustment',
		'other' => 'Other'
	];

	$class = $map[$category] ?? $map['other'];
	$label = $labels[$category] ?? ucfirst($category);

	return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

function status_badge(string $status): string
{
	$map = [
		'completed' => 'bg-success',
		'deleted' => 'bg-secondary'
	];

	$labels = [
		'completed' => 'Completed',
		'deleted' => 'Deleted'
	];

	$class = $map[$status] ?? 'bg-secondary';
	$label = $labels[$status] ?? ucfirst($status);

	return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

?>

<div class="row g-3">
	<!-- Sidebar -->
	<div class="col-lg-3">
		<div class="card shadow-sm">
			<div class="card-header fw-semibold"><i class="fa-solid fa-filter me-1"></i> Filters</div>
			<div class="card-body">
				<!-- Search -->
				<label class="form-label small text-muted mb-1">Search</label>
				<input type="text" class="form-control mb-3" id="searchTodo" placeholder="Search channel, group, notes...">

				<!-- Group Filter -->
				<div class="mb-3">
					<label class="form-label small text-muted mb-1">Filter by Group</label>
					<select class="form-select" id="filterGroup">
						<option value="">All Groups</option>
						<?php foreach ($groups as $group): ?>
							<option value="<?= h($group) ?>"><?= h($group) ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Task Type Filter -->
				<div class="mb-3">
					<div class="form-label small text-muted mb-1">Task Type</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" value="feed_replacement" id="filterFeedReplacement" checked>
						<label class="form-check-label" for="filterFeedReplacement">
							Feed Replacement
						</label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" value="feed_review" id="filterFeedReview" checked>
						<label class="form-check-label" for="filterFeedReview">
							Feed Review
						</label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" value="epg_adjustment" id="filterEPGAdjustment" checked>
						<label class="form-check-label" for="filterEPGAdjustment">
							EPG Adjustment
						</label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" value="other" id="filterOther" checked>
						<label class="form-check-label" for="filterOther">
							Other
						</label>
					</div>
				</div>

				<!-- Created By Filter -->
				<div class="mb-3">
					<label class="form-label small text-muted mb-1">Created By</label>
					<select class="form-select" id="filterCreatedBy">
						<option value="">All Users</option>
						<?php foreach ($users as $user): ?>
							<option value="<?= h((string)$user['id']) ?>"><?= h($user['username']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Date Range -->
				<div class="mb-3">
					<label class="form-label small text-muted mb-1">Date Range</label>
					<select class="form-select" id="filterDateRange">
						<option value="">All Time</option>
						<option value="today">Today</option>
						<option value="week">This Week</option>
						<option value="month">This Month</option>
					</select>
				</div>

				<div class="d-grid gap-2">
					<button class="btn btn-dark" id="resetFilters">Reset Filters</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Main Content -->
	<div class="col-lg-9">
		<div class="card shadow-sm">
			<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
				<div class="fw-semibold"><i class="fa-solid fa-tasks me-1"></i> Tasks</div>
			</div>
			<div class="card-body">
				<!-- Tabs -->
				<ul class="nav nav-tabs mb-3" id="todoTabs" role="tablist">
					<li class="nav-item" role="presentation">
						<button class="nav-link active" id="active-tab" data-bs-toggle="tab" data-bs-target="#active-todos" type="button" role="tab">
							Active Tasks <span class="badge bg-primary ms-2" id="activeCount"><?= count($tasks) ?></span>
						</button>
					</li>
					<li class="nav-item" role="presentation">
						<button class="nav-link" id="log-tab" data-bs-toggle="tab" data-bs-target="#log-todos" type="button" role="tab">
							Completed/Deleted Log <span class="badge bg-secondary ms-2" id="logCount">0</span>
						</button>
					</li>
				</ul>

				<!-- Tab Content -->
				<div class="tab-content" id="todoTabContent">
					<!-- Active Tasks Tab -->
					<div class="tab-pane fade show active" id="active-todos" role="tabpanel">
						<div class="mb-3 d-flex justify-content-between align-items-center">
							<div>
								<select class="form-select form-select-sm d-inline-block w-auto" id="sortBy">
									<option value="created_desc">Newest First</option>
									<option value="created_asc">Oldest First</option>
									<option value="group_asc">Group A-Z</option>
									<option value="category">By Category</option>
								</select>
							</div>
						</div>

						<!-- Task Items Container -->
						<div id="activeTodosList">
							<?php if (empty($tasks)): ?>
								<div class="alert alert-info border-info">
									<i class="fa-solid fa-info-circle"></i> No active task items found.
								</div>
							<?php else: ?>
								<?php foreach ($tasks as $task): ?>
									<?php
									// Get current feed info by querying channel + feed
									$currentFeedStmt = $pdo->prepare("
										SELECT c.*, f.*,
											COALESCE(f.url_display, f.url) AS url_any
										FROM channels c
										JOIN channel_feeds cf ON cf.channel_id = c.id
										JOIN feeds f ON f.id = cf.feed_id
										WHERE c.tvg_id = ? AND c.group_title = ?
										ORDER BY f.reliability_score DESC
										LIMIT 1
									");

									$currentFeedStmt->execute([$task['tvg_id'], $task['source_group']]);
									$currentFeed = $currentFeedStmt->fetch(PDO::FETCH_ASSOC);

									// Suggested feed data
									$sugW = $task['suggested_w'] !== null ? (int)$task['suggested_w'] : null;
									$sugH = $task['suggested_h'] !== null ? (int)$task['suggested_h'] : null;
									[$sugCls] = res_class($sugW, $sugH);
									$sugRes = ($sugW && $sugH) ? ($sugW . '×' . $sugH) : '—';
									$sugFps = $task['suggested_fps'] !== null ? number_format((float)$task['suggested_fps'], 2) : '—';
									$sugRel = $task['suggested_reliability'] !== null ? number_format((float)$task['suggested_reliability'], 2) : '—';
									$sugCodec = $task['suggested_codec'] ? (string)$task['suggested_codec'] : '—';
									$sugFile = ts_filename((string)$task['suggested_url']);

									// Current feed data
									$curW = null;
									$curH = null;
									$curCls = 'Unknown';
									$curRes = '—';
									$curFps = '—';
									$curRel = '—';
									$curCodec = '—';
									$curFile = '—';
									$curFeedId = null;
									$curChannelName = '—';

									if ($currentFeed) {
										$curW = $currentFeed['last_w'] !== null ? (int)$currentFeed['last_w'] : null;
										$curH = $currentFeed['last_h'] !== null ? (int)$currentFeed['last_h'] : null;
										[$curCls] = res_class($curW, $curH);
										$curRes = ($curW && $curH) ? ($curW . '×' . $curH) : '—';
										$curFps = $currentFeed['last_fps'] !== null ? number_format((float)$currentFeed['last_fps'], 2) : '—';
										$curRel = $currentFeed['reliability_score'] !== null ? number_format((float)$currentFeed['reliability_score'], 2) : '—';
										$curCodec = $currentFeed['last_codec'] ? (string)$currentFeed['last_codec'] : '—';
										$curFile = ts_filename((string)$currentFeed['url_any']);
										$curFeedId = (int)$currentFeed['id'];
										$curChannelName = (string)$currentFeed['tvg_name'];
									}

									$creatorName = $task['creator_username'] ?? '[Deleted User]';
									?>
									<div class="task-accordion-item task-card"
										data-category="<?= h($task['category']) ?>"
										data-group="<?= h($task['source_group']) ?>"
										data-creator="<?= h((string)$task['created_by_user']) ?>"
										data-created="<?= h($task['created_at']) ?>"
										data-search-text="<?= h(strtolower($task['source_group'] . ' ' . $task['tvg_id'] . ' ' . $curChannelName . ' ' . ($task['note'] ?? ''))) ?>"
										data-todo-id="<?= (int)$task['id'] ?>">

										<!-- Accordion Header (Collapsed View) -->
										<div class="task-accordion-header">
											<div class="task-accordion-toggle">
												<i class="fa-solid fa-chevron-right"></i>
											</div>
											<div class="task-accordion-summary">
												<div class="task-synopsis">
													<!-- Main title line: Note + Badge -->
													<div class="task-synopsis-title d-flex justify-content-between align-items-start mb-2">
														<div class="flex-grow-1">
															<?php if ($task['note']): ?>
																<span class="fw-semibold"><?= h(mb_substr($task['note'], 0, 80)) ?><?= mb_strlen($task['note']) > 80 ? '...' : '' ?></span>
															<?php else: ?>
																<span class="fw-semibold text-muted">[No note provided]</span>
															<?php endif; ?>
														</div>
														<div class="ms-3 flex-shrink-0 task-header-badge">
															<?= category_badge($task['category']) ?>
														</div>
													</div>

													<!-- Group and Channel inline -->
													<div class="task-synopsis-meta small">
														<i class="fa-solid fa-layer-group me-1"></i>
														<span class="text-muted"><?= h($task['source_group']) ?></span>
														<span class="mx-2 text-muted">|</span>
														<i class="fa-solid fa-tv me-1"></i>
														<span class="fw-semibold"><?= h($curChannelName) ?></span>
													</div>
												</div>
											</div>
										</div>

										<!-- Accordion Body (Expanded View) -->
										<div class="task-accordion-body">
											<!-- Header: Type Badge -->
											<div class="mb-2">
												<?= category_badge($task['category']) ?>
											</div>

											<!-- Current Feed Info -->
											<div class="mb-2">
												<h6 class="mb-2 mt-3 text-primary">Task Feed:</h6>
												<div class="feed-meta-inline mb-1">
													<div class="meta-inline-item">
														<i class="fa-solid fa-layer-group"></i>
														<span class="meta-inline-label">Group:</span>
														<span class="meta-inline-value"><?= h($task['source_group']) ?></span>
													</div>
													<div class="meta-inline-item">
														<i class="fa-solid fa-tv"></i>
														<span class="meta-inline-label">Channel:</span>
														<span class="meta-inline-value"><?= h($curChannelName) ?></span>
													</div>
													<div class="meta-inline-item">
														<i class="fa-solid fa-fingerprint"></i>
														<span class="meta-inline-label">tvg-id:</span>
														<span class="meta-inline-value"><?= h($task['tvg_id']) ?></span>
													</div>
													<div class="meta-inline-item">
														<i class="fa-solid fa-file"></i>
														<span class="meta-inline-label">File:</span>
														<span class="meta-inline-value">
															<?php if ($curFeedId): ?>
																<a href="feed_history.php?feed_id=<?= $curFeedId ?>" target="_blank" class="text-decoration-none">
																	<?= h($curFile) ?>
																</a>
															<?php else: ?>
																<?= h($curFile) ?>
															<?php endif; ?>
														</span>
													</div>
												</div>
												<div class="feed-stream-inline">
													<div class="stream-inline-item">
														<i class="fa-solid fa-chart-simple"></i>
														<span class="stream-inline-label">Rel:</span>
														<span class="stream-inline-value"><?= h($curRel) ?>%</span>
													</div>
													<div class="stream-inline-item">
														<span class="stream-inline-label">Res:</span>
														<span class="stream-inline-value">
															<?= res_badge($curCls) ?> <span class="text-muted ms-1"><?= h($curRes) ?></span>
														</span>
													</div>
													<div class="stream-inline-item">
														<span class="stream-inline-label">FPS:</span>
														<span class="stream-inline-value"><?= h($curFps) ?></span>
													</div>
													<div class="stream-inline-item">
														<span class="stream-inline-label">Codec:</span>
														<span class="stream-inline-value"><?= h($curCodec) ?></span>
													</div>
													<?php if ($curFeedId): ?>
														<div class="stream-inline-item">
															<button type="button" class="btn btn-outline-success btn-sm btn-preview"
																data-feed-id="<?= $curFeedId ?>"
																style="padding: 0.125rem 0.375rem; font-size: 0.75rem;">
																<i class="fa-solid fa-play"></i>
															</button>
														</div>
													<?php endif; ?>
												</div>
											</div>

											<?php if (in_array($task['category'], ['feed_replacement', 'feed_review'])): ?>
												<!-- Divider -->
												<hr class="my-2 border-secondary">

												<!-- Suggested Feed -->
												<div class="mb-4 suggested-feed-box p-3 rounded border border-success">
													<h6 class="text-success mb-2">
														<span class="mb-2">Review Feed:</span>

													</h6>
													<div class="feed-meta-inline mb-1">
														<div class="meta-inline-item">
															<i class="fa-solid fa-layer-group"></i>
															<span class="meta-inline-label">Group:</span>
															<span class="meta-inline-value"><?= h($task['suggested_group']) ?></span>
														</div>
														<div class="meta-inline-item">
															<i class="fa-solid fa-tv"></i>
															<span class="meta-inline-label">Channel:</span>
															<span class="meta-inline-value"><?= h($task['suggested_channel_name'] ?? '—') ?></span>
														</div>
														<div class="meta-inline-item">
															<i class="fa-solid fa-fingerprint"></i>
															<span class="meta-inline-label">tvg-id:</span>
															<span class="meta-inline-value"><?= h($task['suggested_tvg_id'] ?? '—') ?></span>
														</div>
														<div class="meta-inline-item">
															<i class="fa-solid fa-file"></i>
															<span class="meta-inline-label">File:</span>
															<span class="meta-inline-value">
																<a href="feed_history.php?feed_id=<?= (int)$task['suggested_feed_id'] ?>" target="_blank" class="text-decoration-none">
																	<?= h($sugFile) ?>
																</a>
															</span>
														</div>
													</div>
													<div class="feed-stream-inline">
														<div class="stream-inline-item">
															<i class="fa-solid fa-chart-simple"></i>
															<span class="stream-inline-label">Rel:</span>
															<span class="stream-inline-value"><?= h($sugRel) ?>%</span>
														</div>
														<div class="stream-inline-item">
															<span class="stream-inline-label">Res:</span>
															<span class="stream-inline-value">
																<?= res_badge($sugCls) ?> <span class="text-muted ms-1"><?= h($sugRes) ?></span>
															</span>
														</div>
														<div class="stream-inline-item">
															<span class="stream-inline-label">FPS:</span>
															<span class="stream-inline-value"><?= h($sugFps) ?></span>
														</div>
														<div class="stream-inline-item">
															<span class="stream-inline-label">Codec:</span>
															<span class="stream-inline-value"><?= h($sugCodec) ?></span>
														</div>
														<div class="stream-inline-item">
															<button type="button" class="btn btn-outline-success btn-sm btn-preview"
																data-feed-id="<?= (int)$task['suggested_feed_id'] ?>"
																style="padding: 0.125rem 0.375rem; font-size: 0.75rem;">
																<i class="fa-solid fa-play"></i>
															</button>
														</div>
													</div>
												</div>
											<?php endif; ?>

											<?php if ($task['note']): ?>
												<!-- Note -->
												<div class="mb-3">
													<div class="mb-2 mt-3"><strong>Note:</strong></div>
													<p class="mb-0 text-muted fst-italic ps-3 border-start border-3 border-secondary">
														<?= h($task['note']) ?>
													</p>
												</div>
											<?php endif; ?>

											<!-- Footer: Metadata & Actions -->
											<div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top border-secondary">
												<div class="text-muted small">
													<i class="fa-solid fa-person-fill"></i> Created by <strong><?= h($creatorName) ?></strong>
													<span class="mx-2">•</span>
													<i class="fa-solid fa-calendar-event"></i> <?= fmt_dt($task['created_at']) ?>
												</div>
												<div class="d-flex gap-2">
													<button class="btn btn-success btn-sm btn-complete-task" data-task-id="<?= (int)$task['id'] ?>">
														<i class="fa-solid fa-check-circle"></i> Mark Complete
													</button>
													<button class="btn btn-danger btn-sm btn-delete-task" data-task-id="<?= (int)$task['id'] ?>">
														<i class="fa-solid fa-trash"></i> Delete
													</button>
												</div>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>

					<!-- Log Tab -->
					<div class="tab-pane fade" id="log-todos" role="tabpanel">
						<div class="mb-3 d-flex justify-content-between align-items-center">
							<div class="flex-grow-1">
								<input type="text" class="form-control form-control-sm" id="searchLog" placeholder="Search log items..." style="max-width: 400px;">
							</div>
							<div class="d-flex gap-2">
								<button class="btn btn-outline-danger btn-sm" id="clearLog">
									<i class="fa-solid fa-trash-can"></i> Clear All Log
								</button>
							</div>
						</div>

						<!-- Log Items Container -->
						<div id="logTodosList">
							<?php if (empty($logItems)): ?>
								<div class="alert alert-info">
									<i class="fa-solid fa-info-circle"></i> No log items found.
								</div>
							<?php else: ?>
								<?php foreach ($logItems as $logItem): ?>
									<?php
									$creatorName = $logItem['creator_username'] ?? '[Unknown]';
									$completerName = $logItem['completer_username'] ?? '[Unknown]';
									?>
									<div class="log-item"
										data-log-id="<?= (int)$logItem['id'] ?>"
										data-category="<?= h($logItem['category']) ?>"
										data-status="<?= h($logItem['completion_status']) ?>"
										data-search-text="<?= h(strtolower(($logItem['note'] ?? '') . ' ' . $logItem['source_group'] . ' ' . $logItem['tvg_id'])) ?>">

										<div class="log-item-header">
											<div class="flex-grow-1">
												<div class="log-item-title">
													<?php if ($logItem['note']): ?>
														<?= h($logItem['note']) ?>
													<?php else: ?>
														<span class="text-muted">[No note provided]</span>
													<?php endif; ?>
												</div>
												<div class="log-item-meta">
													<i class="fa-solid fa-layer-group me-1"></i>
													<span><?= h($logItem['source_group']) ?></span>
													<span class="mx-2">|</span>
													<i class="fa-solid fa-tv me-1"></i>
													<span><?= h($logItem['tvg_id']) ?></span>
												</div>
											</div>
											<div class="log-item-badges">
												<?= category_badge($logItem['category']) ?>
												<?= status_badge($logItem['completion_status']) ?>
											</div>
										</div>

										<div class="log-item-footer">
											<div>
												<i class="fa-solid fa-user"></i> Created by <strong><?= h($creatorName) ?></strong>
												<span class="mx-2">•</span>
												<i class="fa-solid fa-check-circle"></i> <?= ucfirst($logItem['completion_status']) ?> by <strong><?= h($completerName) ?></strong>
												<span class="mx-2">•</span>
												<i class="fa-solid fa-clock"></i> <?= fmt_dt($logItem['completed_at']) ?>
											</div>
											<div>
												<button class="btn btn-danger btn-sm btn-delete-log" data-log-id="<?= (int)$logItem['id'] ?>">
													<i class="fa-solid fa-trash"></i>
												</button>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
	$(document).ready(function() {

		// Update log count on page load
		$('#logCount').text($('.log-item').length);

		// Accordion toggle functionality
		$(document).on('click', '.task-accordion-header', function(e) {

			// Don't toggle if clicking on buttons inside
			if ($(e.target).closest('button').length > 0) {
				return;
			}

			const $header = $(this);
			const $body = $header.next('.task-accordion-body');

			// Toggle this accordion
			$('.task-accordion-header').not($header).removeClass('expanded');
			$('.task-accordion-body').not($body).removeClass('show');

			$header.toggleClass('expanded');
			$body.toggleClass('show');
		});

		// Filter and search functionality
		function filterTasks() {
			const searchText = $('#searchTodo').val().toLowerCase();
			const selectedGroup = $('#filterGroup').val();
			const selectedCreator = $('#filterCreatedBy').val();
			const dateRange = $('#filterDateRange').val();

			const selectedCategories = [];
			$('.form-check-input:checked').each(function() {
				selectedCategories.push($(this).val());
			});

			let visibleCount = 0;
			let totalTasks = $('.task-card').length;

			$('.task-card').each(function() {
				const $card = $(this);
				const category = $card.data('category');
				const group = $card.data('group');
				const creator = String($card.data('creator'));
				const created = $card.data('created');
				const searchableText = $card.data('search-text');

				let show = true;

				// Category filter
				if (!selectedCategories.includes(category)) {
					show = false;
				}

				// Group filter
				if (selectedGroup && group !== selectedGroup) {
					show = false;
				}

				// Creator filter
				if (selectedCreator && creator !== selectedCreator) {
					show = false;
				}

				// Search filter
				if (searchText && searchableText.indexOf(searchText) === -1) {
					show = false;
				}

				// Date range filter
				if (dateRange && created) {
					const createdDate = new Date(created);
					const now = new Date();

					if (dateRange === 'today') {
						const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
						if (createdDate < today) show = false;
					} else if (dateRange === 'week') {
						const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
						if (createdDate < weekAgo) show = false;
					} else if (dateRange === 'month') {
						const monthAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
						if (createdDate < monthAgo) show = false;
					}
				}

				if (show) {
					$card.show();
					visibleCount++;
				} else {
					$card.hide();
				}
			});

			$('#activeCount').text(visibleCount);

			// Show/hide empty message
			const $container = $('#activeTodosList');
			if (totalTasks === 0) {
				// No tasks at all
				if ($container.find('.alert-info').length === 0) {
					$container.html('<div class="alert alert-info border-info"><i class="fa-solid fa-info-circle"></i> No active task items found.</div>');
				}
			} else {
				// Remove empty message if tasks exist
				$container.find('.alert-info').remove();
			}
		}

		// Sort functionality
		function sortTasks() {
			const sortBy = $('#sortBy').val();
			const $container = $('#activeTodosList');
			const $cards = $('.task-card').detach();

			$cards.sort(function(a, b) {
				const $a = $(a);
				const $b = $(b);

				if (sortBy === 'created_desc') {
					return new Date($b.data('created')) - new Date($a.data('created'));
				} else if (sortBy === 'created_asc') {
					return new Date($a.data('created')) - new Date($b.data('created'));
				} else if (sortBy === 'group_asc') {
					return $a.data('group').localeCompare($b.data('group'));
				} else if (sortBy === 'category') {
					return $a.data('category').localeCompare($b.data('category'));
				}
				return 0;
			});

			$container.append($cards);
		}

		// Event listeners
		$('#searchTodo').on('input', function() {
			filterTasks();
		});

		$('#filterGroup').on('change', function() {
			filterTasks();
		});

		$('#filterCreatedBy').on('change', function() {
			filterTasks();
		});

		$('#filterDateRange').on('change', function() {
			filterTasks();
		});

		$('.form-check-input').on('change', function() {
			filterTasks();
		});

		$('#sortBy').on('change', function() {
			sortTasks();
		});

		$('#resetFilters').on('click', function() {
			$('#searchTodo').val('');
			$('#filterGroup').val('');
			$('#filterCreatedBy').val('');
			$('#filterDateRange').val('');
			$('.form-check-input').prop('checked', true);
			filterTasks();
		});

		// Helper function to add item to log
		function addToLog(data) {

			// Get category badge HTML
			const categoryBadges = {
				'feed_replacement': '<span class="badge bg-danger">Feed Replacement</span>',
				'feed_review': '<span class="badge bg-warning text-dark">Feed Review</span>',
				'epg_adjustment': '<span class="badge bg-info text-dark">EPG Adjustment</span>',
				'other': '<span class="badge bg-secondary">Other</span>'
			};

			const statusBadges = {
				'completed': '<span class="badge bg-success">Completed</span>',
				'deleted': '<span class="badge bg-secondary">Deleted</span>'
			};

			const categoryBadge = categoryBadges[data.category] || categoryBadges['other'];
			const statusBadge = statusBadges[data.status] || statusBadges['deleted'];

			const noteText = data.note || '[No note provided]';
			const noteClass = data.note ? '' : 'text-muted';
			const currentTime = new Date().toLocaleString();
			const searchText = (noteText + ' ' + data.group + ' ' + data.channel).toLowerCase();

			const logItemHtml = `
				<div class="log-item" 
					data-log-id="${data.id}"
					data-category="${data.category}"
					data-status="${data.status}"
					data-search-text="${searchText}"
					style="display: none;">
					
					<div class="log-item-header">
						<div class="flex-grow-1">
							<div class="log-item-title ${noteClass}">
								${noteText}
							</div>
							<div class="log-item-meta">
								<i class="fa-solid fa-layer-group me-1"></i>
								<span>${data.group}</span>
								<span class="mx-2">|</span>
								<i class="fa-solid fa-tv me-1"></i>
								<span>${data.channel}</span>
							</div>
						</div>
						<div class="log-item-badges">
							${categoryBadge}
							${statusBadge}
						</div>
					</div>

					<div class="log-item-footer">
						<div>
							<i class="fa-solid fa-user"></i> Created by <strong>${data.creator}</strong>
							<span class="mx-2">•</span>
							<i class="fa-solid fa-check-circle"></i> ${data.status.charAt(0).toUpperCase() + data.status.slice(1)} by <strong>${data.completer}</strong>
							<span class="mx-2">•</span>
							<i class="fa-solid fa-clock"></i> ${currentTime}
						</div>
						<div>
							<button class="btn btn-danger btn-sm btn-delete-log" data-log-id="${data.id}">
								<i class="fa-solid fa-trash"></i>
							</button>
						</div>
					</div>
				</div>
			`;

			// Check if log list is empty
			const $logList = $('#logTodosList');
			if ($logList.find('.alert').length > 0) {

				// Replace empty message with log items container
				$logList.empty();
			}

			// Prepend to log list and fade in
			$logList.prepend(logItemHtml);
			$logList.find('.log-item').first().fadeIn(300);

			// Update log count
			const logCount = $('.log-item').length;
			$('#logCount').text(logCount);
		}

		// Complete task
		$(document).on('click', '.btn-complete-task', function() {
			const taskId = $(this).data('task-id');
			const $card = $(this).closest('.task-card');

			if (!confirm('Mark this task as complete?')) {
				return;
			}

			// Get task data for creating log entry
			const note = $card.find('.task-synopsis-title .fw-semibold').first().text().trim();
			const category = $card.data('category');
			const group = $card.data('group');

			// Extract channel name from the synopsis meta
			const channelText = $card.find('.task-synopsis-meta .fw-semibold').text().trim();

			// Get creator info from the footer (inside accordion body)
			const creatorText = $card.find('.task-accordion-body .text-muted.small strong').first().text().trim() || 'Unknown';

			$.post('tasks.php', {
				action: 'complete',
				task_id: taskId
			}, function(response) {
				if (response.success) {

					// Remove from active tasks
					$card.fadeOut(300, function() {
						$(this).remove();
						filterTasks();
					});

					// Add to log tab
					addToLog({
						note: note,
						id: response.log_id,
						category: category,
						status: 'completed',
						group: group,
						channel: channelText,
						creator: creatorText,
						completer: '<?= h($_SESSION['username'] ?? 'You') ?>'
					});
				} else {
					alert('Error: ' + response.message);
				}
			}, 'json').fail(function(xhr, status, error) {
				console.error('Complete failed:', status, error);
				alert('Failed to complete task. Please try again.');
			});
		});

		// Delete task
		$(document).on('click', '.btn-delete-task', function() {
			const taskId = $(this).data('task-id');
			const $card = $(this).closest('.task-card');

			if (!confirm('Delete this task? It will be moved to the log.')) {
				return;
			}

			// Get task data for creating log entry
			const note = $card.find('.task-synopsis-title .fw-semibold').first().text().trim();
			const category = $card.data('category');
			const group = $card.data('group');

			// Extract channel name from the synopsis meta
			const channelText = $card.find('.task-synopsis-meta .fw-semibold').text().trim();

			// Get creator info from the footer (inside accordion body)
			const creatorText = $card.find('.task-accordion-body .text-muted.small strong').first().text().trim() || 'Unknown';

			$.post('tasks.php', {
				action: 'delete',
				task_id: taskId
			}, function(response) {
				if (response.success) {
					// Remove from active tasks
					$card.fadeOut(300, function() {
						$(this).remove();
						filterTasks();
					});

					// Add to log tab
					addToLog({
						note: note,
						id: response.log_id,
						category: category,
						status: 'deleted',
						group: group,
						channel: channelText,
						creator: creatorText,
						completer: '<?= h($_SESSION['username'] ?? 'You') ?>'
					});
				} else {
					alert('Error: ' + response.message);
				}
			}, 'json').fail(function(xhr, status, error) {
				console.error('Delete failed:', status, error);
				alert('Failed to delete task. Please try again.');
			});
		});

		// Log search functionality
		$('#searchLog').on('input', function() {
			const searchText = $(this).val().toLowerCase();
			let visibleCount = 0;

			$('.log-item').each(function() {
				const $item = $(this);
				const searchableText = $item.data('search-text');

				if (searchText === '' || searchableText.indexOf(searchText) !== -1) {
					$item.show();
					visibleCount++;
				} else {
					$item.hide();
				}
			});

			$('#logCount').text(visibleCount);
		});

		// Delete individual log item
		$(document).on('click', '.btn-delete-log', function() {
			const logId = $(this).data('log-id');
			const $item = $(this).closest('.log-item');

			if (!confirm('Permanently delete this log item?')) {
				return;
			}

			$.post('tasks.php', {
				action: 'delete_log',
				log_id: logId
			}, function(response) {
				if (response.success) {
					$item.fadeOut(300, function() {
						$(this).remove();
						const remaining = $('.log-item:visible').length;
						$('#logCount').text(remaining);

						// Show message if no items left
						if ($('.log-item').length === 0) {
							$('#logTodosList').html('<div class="alert alert-info"><i class="fa-solid fa-info-circle"></i> No log items found.</div>');
						}
					});
				} else {
					alert('Error: ' + response.message);
				}
			}, 'json').fail(function() {
				alert('Failed to delete log item. Please try again.');
			});
		});

		// Clear entire log
		$('#clearLog').on('click', function() {
			if (!confirm('Are you sure you want to clear the ENTIRE log? This cannot be undone!')) {
				return;
			}

			// Double confirmation for safety
			if (!confirm('This will permanently delete ALL log items. Are you absolutely sure?')) {
				return;
			}

			$.post('tasks.php', {
				action: 'clear_log'
			}, function(response) {
				if (response.success) {
					$('.log-item').fadeOut(300, function() {
						$('#logTodosList').html('<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> Log cleared successfully.</div>');
						$('#logCount').text('0');
					});
				} else {
					alert('Error: ' + response.message);
				}
			}, 'json').fail(function() {
				alert('Failed to clear log. Please try again.');
			});
		});
	});
</script>

<?php require_once __DIR__ . '/_bottom.php'; ?>