<?php

declare(strict_types=1);
require_once __DIR__ . '/_boot.php';
?>
<!doctype html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= h($title ?? 'OTT Stream Tester (Admin)') ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

	<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
		<div class="container-fluid">
			<a class="navbar-brand" href="index.php">OTT Stream Score</a>
			<div class="navbar-nav">
				<a class="nav-link" href="index.php">Dashboard</a>
				<a class="nav-link" href="channels.php">Channels</a>
				<a class="nav-link" href="feeds.php">Feeds</a>
				<a class="nav-link" href="reports.php">Reports</a>
				<a class="nav-link" href="rotate_creds.php">Rotate Creds</a>
				<a class="nav-link" href="process_playlist.php">Process Playlist</a>
			</div>
		</div>
	</nav>

	<main class="container py-4">