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
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/css/dataTables.bootstrap5.min.css">
	<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.10/js/jquery.dataTables.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/js/dataTables.bootstrap5.min.js"></script>

	<script>
		// Apply saved theme 
		(function() {
			const savedTheme = localStorage.getItem('theme') || 'light';
			document.documentElement.setAttribute('data-bs-theme', savedTheme);
		})();
	</script>

	<style>
		/* ============================================================================
		CSS VARIABLES
		============================================================================ */

		/* Light mode (default) */
		:root {
			--bg-primary: #ffffff;
			--bg-secondary: #f8f9fa;
			--bg-card: #ffffff;
			--text-primary: #212529;
			--text-secondary: #6c757d;
			--text-muted: #6c757d;
			--border-color: #dee2e6;
			--link-color: #0d6efd;
			--link-hover: #0a58ca;
			--table-hover: #f5f5f5;
			--shadow: rgba(0, 0, 0, 0.1);
			--logo-background: #a0a0a0;
		}

		/* Dark mode */
		[data-bs-theme="dark"] {
			--bg-primary: #212529;
			--bg-secondary: #343a40;
			--bg-card: #2c3034;
			--text-primary: #f8f9fa;
			--text-secondary: #adb5bd;
			--text-muted: #c7cbcdff;
			--border-color: #495057;
			--link-color: #6ea8fe;
			--link-hover: #8bb9fe;
			--table-hover: #3a3f44;
			--shadow: rgba(0, 0, 0, 0.3);
			--logo-background: #212529;
		}

		/* ============================================================================
		BASE ELEMENTS
		============================================================================ */

		body {
			background-color: var(--bg-primary);
		}

		a {
			color: var(--link-color);
		}

		a:hover {
			color: var(--link-hover);
		}

		.text-muted {
			color: var(--text-muted) !important;
		}

		/* ============================================================================
		CARDS
		============================================================================ */

		.card {
			background-color: var(--bg-card);
			border-color: var(--border-color);
			color: var(--text-primary);
		}

		.card,
		.shadow-sm {
			box-shadow: 0 0.125rem 0.25rem var(--shadow) !important;
		}

		/* ============================================================================
		TABLES
		============================================================================ */

		.table {
			--bs-table-bg: var(--bg-card);
			--bs-table-color: var(--text-primary);
			border-color: var(--border-color);
		}

		.table-hover>tbody>tr:hover {
			--bs-table-hover-bg: var(--table-hover);
		}

		/* Table Success Highlighting */
		#tvgTable tbody tr.table-success,
		#tvgTable tbody tr.table-success>* {
			background-color: #c7dbd2 !important;
			color: #0f5132 !important;
			--bs-table-bg: #c7dbd2 !important;
			--bs-table-color: #0f5132 !important;
		}

		[data-bs-theme="dark"] #tvgTable tbody tr.table-success,
		[data-bs-theme="dark"] #tvgTable tbody tr.table-success>* {
			background-color: #124730 !important;
			color: #c7dbd2 !important;
			--bs-table-bg: #124730 !important;
			--bs-table-color: #c7dbd2 !important;
		}

		/* ============================================================================
		FORMS
		============================================================================ */

		.form-control,
		.form-select {
			background-color: var(--bg-card);
			border-color: var(--border-color);
			color: var(--text-primary);
		}

		.form-control:focus,
		.form-select:focus {
			background-color: var(--bg-card);
			color: var(--text-primary);
			border-color: var(--link-color);
		}

		/* ============================================================================
		BADGES
		============================================================================ */

		[data-bs-theme="dark"] .badge.bg-light {
			background-color: #495057 !important;
			color: #f8f9fa !important;
		}

		[data-bs-theme="dark"] .badge.bg-secondary {
			background-color: #495057 !important;
		}

		/* ============================================================================
		NAVIGATION
		============================================================================ */

		.navbar-brand img {
			max-height: 60px;
			width: auto;
		}

		.navbar-nav .nav-link {
			padding: 0.5rem 1rem;
			margin: 0 0.25rem;
			transition: background-color 0.15s ease-in-out;
		}

		.navbar-nav .nav-link:hover {
			background-color: rgba(255, 255, 255, 0.05);
			border-radius: 0.25rem;
		}

		.navbar-nav .nav-link.active {
			font-weight: 600;
			background-color: rgba(255, 255, 255, 0.1);
			border-radius: 0.25rem;
		}

		[data-bs-theme="dark"] .navbar-nav .nav-link.active {
			background-color: rgba(255, 255, 255, 0.15);
		}

		/* ============================================================================
		THEME TOGGLE
		============================================================================ */

		.theme-icon-dark {
			display: inline;
		}

		.theme-icon-light {
			display: none;
		}

		[data-bs-theme="dark"] .theme-icon-dark {
			display: none;
		}

		[data-bs-theme="dark"] .theme-icon-light {
			display: inline;
		}

		/* ============================================================================
		DATATABLES
		============================================================================ */

		.dataTables_wrapper {
			color: var(--text-primary);
		}

		.dataTables_wrapper .dataTables_paginate .paginate_button {
			color: var(--text-primary) !important;
		}

		.dataTables_wrapper .dataTables_info {
			color: var(--text-secondary);
		}

		/* ============================================================================
		SPECIAL ELEMENTS
		============================================================================ */

		div#logo_holder {
			background: var(--bg-secondary);
			border-radius: 12px;
		}
	</style>

</head>

<body>

	<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
		<div class="container-fluid">
			<a class="navbar-brand" href="index.php"><img src="logo_header.png" alt="OTT Stream Score"></a>
			<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
				<span class="navbar-toggler-icon"></span>
			</button>
			<div class="collapse navbar-collapse" id="navbarNav">
				<!-- Changed me-auto to ms-auto to align right -->
				<ul class="navbar-nav ms-auto">
					<li class="nav-item">
						<a class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="index.php">
							<i class="fa-solid fa-gauge me-1"></i> Dashboard
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= ($currentPage ?? '') === 'channels' ? 'active' : '' ?>" href="channels.php">
							<i class="fa-solid fa-tv me-1"></i> Channels
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= ($currentPage ?? '') === 'feeds' ? 'active' : '' ?>" href="feeds.php">
							<i class="fa-solid fa-broadcast-tower me-1"></i> Feeds
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= ($currentPage ?? '') === 'reports' ? 'active' : '' ?>" href="reports.php">
							<i class="fa-solid fa-ranking-star me-1"></i> Reports
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= ($currentPage ?? '') === 'playlist' ? 'active' : '' ?>" href="process_playlist.php">
							<i class="fa-solid fa-file-import me-1"></i> Import Playlist
						</a>
					</li>
				</ul>

				<!-- Theme toggle button -->
				<button id="theme-toggle" class="btn btn-sm btn-outline-light ms-2" type="button">
					<span class="theme-icon-dark">🌙</span>
					<span class="theme-icon-light">☀️</span>
				</button>
			</div>
		</div>
	</nav>

	<main class="container py-4">