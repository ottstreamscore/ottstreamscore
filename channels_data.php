<?php

declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

try {
	require_once __DIR__ . '/_boot.php';
	$pdo = db();

	header('Content-Type: application/json; charset=utf-8');

	function clamp_int($v, int $min, int $max, int $default): int
	{
		if (!is_numeric($v)) return $default;
		$n = (int)$v;
		if ($n < $min) return $min;
		if ($n > $max) return $max;
		return $n;
	}
	function cut(string $s, int $max): string
	{
		$s = trim($s);
		return strlen($s) > $max ? substr($s, 0, $max) : $s;
	}

	$draw   = clamp_int($_GET['draw'] ?? 1, 1, 1000000000, 1);
	$start  = clamp_int($_GET['start'] ?? 0, 0, 1000000000, 0);
	$length = clamp_int($_GET['length'] ?? 50, 1, 250, 50);

	$q     = cut((string)($_GET['q'] ?? ''), 120);
	$group = cut((string)($_GET['group'] ?? ''), 200);

	// ----- WHERE -----
	$where = [];
	$params = [];

	if ($q !== '') {
		$where[] = '(c.tvg_name LIKE :q OR c.tvg_id LIKE :q)';
		$params[':q'] = "%{$q}%";
	}
	if ($group !== '') {
		$where[] = 'c.group_title = :g';
		$params[':g'] = $group;
	}

	$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

	// ----- ORDER (server-side) -----
	// DataTables column index mapping:
	// 0 logo (no sort)
	// 1 group
	// 2 name
	// 3 tvg_id
	// 4 feeds
	// 5 last_checked
	$colMap = [
		1 => 'c.group_title',
		2 => 'c.tvg_name',
		3 => 'c.tvg_id',
		4 => 'feed_count',
		5 => 'last_checked'
	];

	$orderBy = 'c.tvg_name ASC'; // default

	if (!empty($_GET['order'][0]['column'])) {
		$idx = (int)$_GET['order'][0]['column'];
		$dir = strtolower((string)($_GET['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

		if (isset($colMap[$idx])) {
			$orderBy = $colMap[$idx] . " " . $dir;
		}
	}

	// ----- TOTALS -----
	$total = (int)$pdo->query("SELECT COUNT(*) FROM channels")->fetchColumn();

	$sqlFiltered = "SELECT COUNT(*) FROM channels c {$whereSql}";
	$st = $pdo->prepare($sqlFiltered);
	foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
	$st->execute();
	$filtered = (int)$st->fetchColumn();

	// NOTE: Do NOT bind LIMIT/OFFSET; cast + inject ints (safe)
	$limitSql = "LIMIT {$length} OFFSET {$start}";

	// ----- DATA -----
	$sqlData = "
		SELECT
			c.id,
			c.group_title,
			c.tvg_name,
			c.tvg_id,
			c.tvg_logo,
			(SELECT COUNT(*) FROM feeds f WHERE f.channel_id = c.id) AS feed_count,
			(SELECT MAX(last_checked_at) FROM feeds f2 WHERE f2.channel_id = c.id) AS last_checked
		FROM channels c
		{$whereSql}
		ORDER BY {$orderBy}
		{$limitSql}
	";

	$st = $pdo->prepare($sqlData);
	foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
	$st->execute();

	$data = [];
	while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
		$id = (int)$r['id'];

		$logo = (string)($r['tvg_logo'] ?? '');
		$logoHtml = $logo !== ''
			? '<img src="' . h($logo) . '" style="width:40px" loading="lazy">'
			: '';

		$groupTitle = (string)($r['group_title'] ?? '');

		$data[] = [
			'logo' => $logoHtml,
			'group' => '<a href="feeds.php?group=' . urlencode($groupTitle) . '">' . h($groupTitle) . '</a>',
			'name'  => '<a href="channel.php?id=' . $id . '">' . h((string)$r['tvg_name']) . '</a>',
			'tvg_id' => h((string)$r['tvg_id']),
			'feeds'  => (int)$r['feed_count'],
			'last_checked' => !empty($r['last_checked']) ? fmt_dt((string)$r['last_checked']) : '—',
		];
	}

	$response = [
		'draw' => $draw,
		'recordsTotal' => $total,
		'recordsFiltered' => $filtered,
		'data' => $data
	];

	if (ob_get_length()) ob_clean();
	echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
	if (ob_get_length()) ob_clean();
	http_response_code(500);
	echo json_encode([
		'draw' => (int)($_GET['draw'] ?? 1),
		'recordsTotal' => 0,
		'recordsFiltered' => 0,
		'data' => [],
		'error' => $e->getMessage(),
	], JSON_UNESCAPED_SLASHES);
}
