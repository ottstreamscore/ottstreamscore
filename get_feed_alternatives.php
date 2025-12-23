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

function format_feed_for_task($feed): array
{
	$w = $feed['last_w'] !== null ? (int)$feed['last_w'] : null;
	$h = $feed['last_h'] !== null ? (int)$feed['last_h'] : null;
	[$cls] = res_class_helper($w, $h);

	$res = ($w && $h) ? ($w . '×' . $h) : '—';
	$fps = $feed['last_fps'] !== null ? number_format((float)$feed['last_fps'], 2) : '—';
	$rel = $feed['reliability_score'] !== null ? number_format((float)$feed['reliability_score'], 2) : '—';
	$codec = $feed['last_codec'] ? (string)$feed['last_codec'] : '—';
	$file = ts_filename_helper((string)($feed['url_any'] ?? ''));

	$resBadgeMap = [
		'4K'      => 'bg-warning text-dark',
		'FHD'     => 'bg-primary',
		'HD'      => 'bg-info text-dark',
		'SD'      => 'bg-secondary',
		'Unknown' => 'bg-light text-dark',
	];
	$badgeClass = $resBadgeMap[$cls] ?? $resBadgeMap['Unknown'];
	$resolutionHtml = '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($cls) . '</span> <span class="text-muted ms-1">' . htmlspecialchars($res) . '</span>';

	// Status badge
	$statusBadge = '';
	if (isset($feed['last_ok'])) {
		if ($feed['last_ok'] == 1) {
			$statusBadge = '<span class="badge bg-success"><i class="fa-solid fa-check"></i></span>';
		} else {
			$statusBadge = '<span class="badge bg-danger"><i class="fa-solid fa-xmark"></i></span>';
		}
	}

	return [
		'feed_id' => (int)$feed['feed_id'],
		'group_title' => (string)$feed['group_title'],
		'tvg_name' => (string)$feed['tvg_name'],
		'tvg_id' => (string)$feed['tvg_id'],
		'file' => $file,
		'reliability' => $rel,
		'resolution_html' => $resolutionHtml,
		'fps' => $fps,
		'codec' => $codec,
		'status_badge' => $statusBadge,
		'last_ok' => isset($feed['last_ok']) ? (int)$feed['last_ok'] : null
	];
}

try {
	$feedId = (int)($_GET['feed_id'] ?? 0);
	$group = isset($_GET['group']) ? trim((string)$_GET['group']) : '';
	$filterIgnores = isset($_GET['filter_ignores']) ? (bool)$_GET['filter_ignores'] : false;
	$refTvgId = isset($_GET['ref_tvg_id']) ? trim((string)$_GET['ref_tvg_id']) : '';
	$refGroup = isset($_GET['ref_group']) ? trim((string)$_GET['ref_group']) : '';

	if ($feedId <= 0) {
		echo json_encode(['success' => false, 'message' => 'Invalid feed_id']);
		exit;
	}

	if ($group === '') {
		echo json_encode(['success' => false, 'message' => 'Group parameter required']);
		exit;
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

	// === GET TARGET FEED INFO ===
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
			  AND c.group_title = ?
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
			  AND c.group_title = ?
			LIMIT 1
		");
	}

	$stmt->execute([$feedId, $group]);
	$targetFeed = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$targetFeed) {
		echo json_encode(['success' => false, 'message' => 'Target feed not found']);
		exit;
	}

	$tvgId = $refTvgId !== '' ? $refTvgId : $targetFeed['tvg_id'];
	$group = $targetFeed['group_title'];
	$associationGroup = $refGroup !== '' ? $refGroup : $group;

	// === PRIMARY MATCHES (same tvg-id) ===
	if ($hasJunctionTable) {
		$st = $pdo->prepare("
			SELECT
				f.id AS feed_id,
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
			WHERE c.tvg_id = :tvg
			ORDER BY
				COALESCE(f.reliability_score,0) DESC,
				pixels DESC,
				COALESCE(f.last_fps,0) DESC,
				f.last_ok DESC
		");
	} else {
		$st = $pdo->prepare("
			SELECT
				f.id AS feed_id,
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
			JOIN feeds f ON f.channel_id = c.id
			WHERE c.tvg_id = :tvg
			ORDER BY
				COALESCE(f.reliability_score,0) DESC,
				pixels DESC,
				COALESCE(f.last_fps,0) DESC,
				f.last_ok DESC
		");
	}

	$st->execute([':tvg' => $tvgId]);
	$primaryRows = $st->fetchAll(PDO::FETCH_ASSOC);

	$primaryMatches = [];
	foreach ($primaryRows as $row) {
		// Skip the target feed itself
		if ((int)$row['feed_id'] === $feedId) {
			continue;
		}

		// Check if this feed is ignored (only if filtering is enabled)
		if ($filterIgnores && $hasIgnoresTable) {
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
				':feed_id' => (int)$row['feed_id']
			]);
			$isIgnored = (bool)$ignoreCheck->fetchColumn();

			if ($isIgnored) {
				continue; // Skip ignored feeds when filtering is enabled
			}
		}

		$primaryMatches[] = format_feed_for_task($row);
	}

	// === ASSOCIATION MATCHES ===
	$associationMatches = [];

	$clickedPrefix = '';
	if (strpos($associationGroup, '|') !== false) {
		$clickedPrefix = substr($associationGroup, 0, strpos($associationGroup, '|') + 1);
	}

	$tvgIdBase = $tvgId;
	if (strpos($tvgId, '.') !== false) {
		$tvgIdBase = substr($tvgId, 0, strpos($tvgId, '.'));
	}

	if ($clickedPrefix !== '' && $tvgIdBase !== '') {
		$stAssoc = $pdo->prepare("
			SELECT DISTINCT ga.id, ga.name
			FROM group_associations ga
			JOIN group_association_prefixes gap ON gap.association_id = ga.id
			WHERE gap.prefix = ?
		");
		$stAssoc->execute([$clickedPrefix]);
		$associations = $stAssoc->fetchAll(PDO::FETCH_ASSOC);

		foreach ($associations as $assoc) {
			$stPrefixes = $pdo->prepare("
				SELECT prefix
				FROM group_association_prefixes
				WHERE association_id = ? AND prefix != ?
			");
			$stPrefixes->execute([$assoc['id'], $clickedPrefix]);
			$otherPrefixes = $stPrefixes->fetchAll(PDO::FETCH_COLUMN);

			if (empty($otherPrefixes)) {
				continue;
			}

			$prefixPlaceholders = implode(',', array_fill(0, count($otherPrefixes), '?'));

			$params = $otherPrefixes;
			$params[] = '%' . $tvgIdBase . '%';
			$params[] = $tvgId;
			$params[] = $tvgId;

			if ($hasJunctionTable) {
				$sql = "
					SELECT
						f.id AS feed_id,
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
					WHERE CONCAT(SUBSTRING_INDEX(c.group_title, '|', 1), '|') IN ($prefixPlaceholders)
					AND (
						c.tvg_id LIKE ?
						OR ? LIKE CONCAT('%', SUBSTRING_INDEX(c.tvg_id, '.', 1), '%')
					)
					AND c.tvg_id != ?
					ORDER BY
						COALESCE(f.reliability_score,0) DESC,
						pixels DESC,
						COALESCE(f.last_fps,0) DESC,
						f.last_ok DESC
				";
			} else {
				$sql = "
					SELECT
						f.id AS feed_id,
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
					JOIN feeds f ON f.channel_id = c.id
					WHERE CONCAT(SUBSTRING_INDEX(c.group_title, '|', 1), '|') IN ($prefixPlaceholders)
					AND (
						c.tvg_id LIKE ?
						OR ? LIKE CONCAT('%', SUBSTRING_INDEX(c.tvg_id, '.', 1), '%')
					)
					AND c.tvg_id != ?
					ORDER BY
						COALESCE(f.reliability_score,0) DESC,
						pixels DESC,
						COALESCE(f.last_fps,0) DESC,
						f.last_ok DESC
				";
			}

			$stMatch = $pdo->prepare($sql);
			$stMatch->execute($params);
			$matches = $stMatch->fetchAll(PDO::FETCH_ASSOC);

			if (!empty($matches)) {
				$formattedMatches = [];
				foreach ($matches as $match) {
					// Skip the target feed itself
					if ((int)$match['feed_id'] === $feedId) {
						continue;
					}

					// Check if this feed is ignored (only if filtering is enabled)
					if ($filterIgnores && $hasIgnoresTable) {
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
							':feed_id' => (int)$match['feed_id']
						]);
						$isIgnored = (bool)$ignoreCheck->fetchColumn();

						if ($isIgnored) {
							continue; // Skip ignored feeds when filtering is enabled
						}
					}

					$formattedMatches[] = format_feed_for_task($match);
				}

				// Only add association group if it has matches after filtering
				if (!empty($formattedMatches)) {
					$associationMatches[] = [
						'association_name' => $assoc['name'],
						'matches' => $formattedMatches
					];
				}
			}
		}
	}

	echo json_encode([
		'success' => true,
		'target_feed' => format_feed_for_task($targetFeed),
		'alternatives' => [
			'primary_matches' => $primaryMatches,
			'association_matches' => $associationMatches
		]
	]);
} catch (Exception $e) {
	echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
