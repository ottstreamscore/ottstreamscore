<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

try {
	$pdo = db();

	// DataTables parameters
	$draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
	$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
	$length = isset($_GET['length']) ? (int)$_GET['length'] : 50;
	$orderCol = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 9;
	$orderDir = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'desc';

	// Filters - handle both initial load (no filters) and filtered requests
	$q = '';
	$group = '';
	$status = ['ok' => 1, 'fail' => 1, 'unknown' => 1];
	$qual = ['k4' => 0, 'fhd' => 0, 'hd' => 0, 'sd' => 0];
	$hide = ['ppv' => 0, 't247' => 0];

	if (isset($_GET['filters']) && is_array($_GET['filters'])) {
		$filters = $_GET['filters'];
		$q = isset($filters['q']) ? (string)$filters['q'] : '';
		$group = isset($filters['group']) ? (string)$filters['group'] : '';

		if (isset($filters['status']) && is_array($filters['status'])) {
			$status = $filters['status'];
		}
		if (isset($filters['qual']) && is_array($filters['qual'])) {
			$qual = $filters['qual'];
		}
		if (isset($filters['hide']) && is_array($filters['hide'])) {
			$hide = $filters['hide'];
		}
	}

	// Map column index to column name
	$columns = [
		0 => 'last_ok',
		1 => 'res_class',
		2 => 'group_title',
		3 => 'tvg_name',
		4 => 'last_fps',
		5 => 'last_codec',
		6 => 'last_checked_at',
		7 => 'file',
		8 => 'history',
		9 => 'status_rank',
		10 => 'res_rank',
		11 => 'checked_ts'
	];
	$orderColumn = isset($columns[$orderCol]) ? $columns[$orderCol] : 'status_rank';

	// === SCHEMA DETECTION ===
	$hasJunctionTable = false;
	try {
		$pdo->query("SELECT 1 FROM channel_feeds LIMIT 1");
		$hasJunctionTable = true;
	} catch (Throwable $e) {
		$hasJunctionTable = false;
	}

	// Build WHERE clause
	$where = [];
	$params = [];

	// Search
	if ($q !== '') {
		$where[] = "(c.tvg_name LIKE :q1 OR c.tvg_id LIKE :q2)";
		$params[':q1'] = '%' . $q . '%';
		$params[':q2'] = '%' . $q . '%';
	}

	// Group filter
	if ($group !== '') {
		$where[] = "c.group_title = :grp";
		$params[':grp'] = $group;
	}

	// Status filter
	$statusFilter = [];
	if (isset($status['ok']) && (int)$status['ok'] === 1) $statusFilter[] = 'f.last_ok=1';
	if (isset($status['fail']) && (int)$status['fail'] === 1) $statusFilter[] = 'f.last_ok=0';
	if (isset($status['unknown']) && (int)$status['unknown'] === 1) $statusFilter[] = 'f.last_ok IS NULL';
	if (!empty($statusFilter)) {
		$where[] = '(' . implode(' OR ', $statusFilter) . ')';
	}

	// Quality filter
	$qualFilters = [];
	if (isset($qual['k4']) && (int)$qual['k4'] === 1) {
		$qualFilters[] = '(COALESCE(f.last_w,0) * COALESCE(f.last_h,0) >= 3840*2160)';
	}
	if (isset($qual['fhd']) && (int)$qual['fhd'] === 1) {
		$qualFilters[] = '(COALESCE(f.last_h,0) >= 1080 AND COALESCE(f.last_w,0) * COALESCE(f.last_h,0) < 3840*2160)';
	}
	if (isset($qual['hd']) && (int)$qual['hd'] === 1) {
		$qualFilters[] = '(COALESCE(f.last_h,0) >= 720 AND COALESCE(f.last_h,0) < 1080)';
	}
	if (isset($qual['sd']) && (int)$qual['sd'] === 1) {
		$qualFilters[] = '(COALESCE(f.last_h,0) > 0 AND COALESCE(f.last_h,0) < 720)';
	}
	if (!empty($qualFilters)) {
		$where[] = '(' . implode(' OR ', $qualFilters) . ')';
	}

	// Hide PPV/24-7
	if (isset($hide['ppv']) && (int)$hide['ppv'] === 1) {
		$where[] = "(c.tvg_name NOT LIKE '%PPV%' AND c.group_title NOT LIKE '%PPV%')";
	}
	if (isset($hide['t247']) && (int)$hide['t247'] === 1) {
		$where[] = "(c.tvg_name NOT LIKE '%24/7%' AND c.group_title NOT LIKE '%24/7%')";
	}

	$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

	// Base query
	if ($hasJunctionTable) {
		$baseFrom = "
			FROM feeds f
			JOIN channel_feeds cf ON cf.feed_id = f.id
			JOIN channels c ON c.id = cf.channel_id
		";
	} else {
		$baseFrom = "
			FROM feeds f
			JOIN channels c ON c.id = f.channel_id
		";
	}

	// Total records (without filters)
	$totalStmt = $pdo->query("SELECT COUNT(*) FROM feeds");
	$recordsTotal = (int)$totalStmt->fetchColumn();

	// Filtered records
	$filteredStmt = $pdo->prepare("SELECT COUNT(*) $baseFrom $whereSQL");
	if (!empty($params)) {
		$filteredStmt->execute($params);
	} else {
		$filteredStmt->execute();
	}
	$recordsFiltered = (int)$filteredStmt->fetchColumn();

	// Order by mapping
	$orderByMap = [
		'last_ok' => 'CASE WHEN f.last_ok=1 THEN 2 WHEN f.last_ok=0 THEN 1 ELSE 0 END',
		'res_class' => '(COALESCE(f.last_w,0) * COALESCE(f.last_h,0))',
		'group_title' => 'c.group_title',
		'tvg_name' => 'c.tvg_name',
		'last_fps' => 'COALESCE(f.last_fps,0)',
		'last_codec' => 'f.last_codec',
		'last_checked_at' => 'f.last_checked_at',
		'file' => 'f.url',
		'history' => 'f.last_checked_at',
		'status_rank' => 'CASE WHEN f.last_ok=1 THEN 2 WHEN f.last_ok=0 THEN 1 ELSE 0 END',
		'res_rank' => '(COALESCE(f.last_w,0) * COALESCE(f.last_h,0))',
		'checked_ts' => 'f.last_checked_at'
	];
	$orderBySQL = isset($orderByMap[$orderColumn]) ? $orderByMap[$orderColumn] : 'f.last_checked_at';
	$orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

	// Main query
	$dataStmt = $pdo->prepare("
		SELECT 
			f.id AS feed_id,
			c.id AS channel_id,
			c.group_title,
			c.tvg_name,
			c.tvg_id,
			f.last_ok,
			f.last_w,
			f.last_h,
			f.last_fps,
			f.last_codec,
			f.last_checked_at,
			COALESCE(f.url_display, f.url) AS url_any,
			(COALESCE(f.last_w,0) * COALESCE(f.last_h,0)) AS pixels
		$baseFrom
		$whereSQL
		ORDER BY $orderBySQL $orderDir
		LIMIT :start, :length
	");

	// Bind all WHERE params first
	foreach ($params as $k => $v) {
		$dataStmt->bindValue($k, $v);
	}
	// Then bind LIMIT params
	$dataStmt->bindValue(':start', $start, PDO::PARAM_INT);
	$dataStmt->bindValue(':length', $length, PDO::PARAM_INT);

	$dataStmt->execute();
	$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

	// Helper functions
	function status_badge($lastOk): string
	{
		if ($lastOk === null) return '<span class="badge bg-secondary">unknown</span>';
		return ((int)$lastOk === 1)
			? '<span class="badge bg-success">ok</span>'
			: '<span class="badge bg-danger">fail</span>';
	}

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
		$badgeClass = isset($map[$clsU]) ? $map[$clsU] : $map['UNKNOWN'];
		return '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($clsU) . '</span>';
	}

	function ts_filename(?string $url): string
	{
		$url = (string)$url;
		$path = parse_url($url, PHP_URL_PATH);
		$path = is_string($path) ? $path : '';
		$base = $path !== '' ? basename($path) : '';
		if ($base === '' && $url !== '') $base = basename($url);
		return $base !== '' ? $base : '—';
	}

	// Format data for DataTables
	$data = [];
	foreach ($rows as $r) {
		$w = $r['last_w'] !== null ? (int)$r['last_w'] : null;
		$h = $r['last_h'] !== null ? (int)$r['last_h'] : null;

		[$cls, $clsPts, $px] = res_class($w, $h);

		$fps = ($r['last_fps'] !== null) ? (float)$r['last_fps'] : null;
		$fpsTxt = ($fps !== null) ? number_format($fps, 2) : '—';

		$codec = $r['last_codec'] ? htmlspecialchars((string)$r['last_codec']) : '—';
		$file = ts_filename((string)$r['url_any']);

		$groupTitle = htmlspecialchars((string)$r['group_title']);
		$groupLink  = 'feeds.php?group=' . urlencode((string)$r['group_title']);
		$groupHtml = '<a class="text-decoration-none" href="' . $groupLink . '">' . $groupTitle . '</a>';

		$channelHtml = htmlspecialchars((string)$r['tvg_name']);
		if ($r['tvg_id']) {
			$channelLink = 'channel.php?id=' . (int)$r['channel_id'];
			$channelHtml = '<a class="text-decoration-none" href="' . $channelLink . '">' . $channelHtml . '</a>';
		}

		$checkedAt = $r['last_checked_at'];
		$checkedTxt = $checkedAt ? date('Y-m-d H:i', strtotime($checkedAt)) : '—';
		$checkedTs = $checkedAt ? strtotime($checkedAt) : 0;

		$statusRank = $r['last_ok'] === null ? 0 : ((int)$r['last_ok'] === 1 ? 2 : 1);

		$historyLink = '<a href="feed_history.php?feed_id=' . (int)$r['feed_id'] . '" ' .
			'class="btn btn-sm btn-outline-primary" title="View check history">' . ' View</a>';

		$data[] = [
			'status_badge' => status_badge($r['last_ok']),
			'qual_badge' => res_badge($cls),
			'group_html' => $groupHtml,
			'channel_html' => $channelHtml,
			'fps' => '<div class="text-end">' . $fpsTxt . '</div>',
			'codec' => $codec,
			'last_checked' => $checkedTxt,
			'file' => '<span class="text-muted small">' . htmlspecialchars($file) . '</span>',
			'status_rank' => $statusRank,
			'res_rank' => $px,
			'checked_ts' => $checkedTs,
			'history' => $historyLink
		];
	}

	echo json_encode([
		'draw' => $draw,
		'recordsTotal' => $recordsTotal,
		'recordsFiltered' => $recordsFiltered,
		'data' => $data
	]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode([
		'error' => $e->getMessage(),
		'file' => $e->getFile(),
		'line' => $e->getLine()
	]);
}
