<?php

declare(strict_types=1);
?>
<!doctype html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= h($title ?? 'OTT Stream Tester') ?></title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/css/dataTables.bootstrap5.min.css">
	<link rel="stylesheet" href="style.css">
	<link rel="stylesheet" href="custom.css">
	<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.10/js/jquery.dataTables.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.10/js/dataTables.bootstrap5.min.js"></script>
	<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
	<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
	<script src="https://cdn.jsdelivr.net/npm/mpegts.js@latest"></script>

	<script>
		// Apply saved theme 
		(function() {
			const savedTheme = localStorage.getItem('theme') || 'dark';
			document.documentElement.setAttribute('data-bs-theme', savedTheme);
		})();
	</script>

</head>

<body>

	<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
		<div class="container-fluid">
			<a class="navbar-brand" href="index.php"><img src="logo_header.png" alt="OTT Stream Score"></a>
			<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
				<span class="navbar-toggler-icon"></span>
			</button>
			<div class="collapse navbar-collapse" id="navbarNav">
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
						<a class="nav-link <?= ($currentPage ?? '') === 'tasks' ? 'active' : '' ?>" href="tasks.php">
							<i class="fa-solid fa-list-check me-1"></i> Tasks
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link <?= ($currentPage ?? '') === 'admin' ? 'active' : '' ?>" href="admin.php">
							<i class="fa-solid fa-gear me-1"></i> Admin
						</a>
					</li>

					<!-- User Dropdown -->
					<li class="nav-item dropdown">
						<a style="color:#20c6c0;" class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
							<i class="fa-solid fa-user-circle me-1"></i> <?= h(get_username() ?? 'User') ?>
						</a>
						<ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
							<li>
								<a class="dropdown-item" href="#" id="theme-toggle-link">
									<i class="fa-solid fa-circle-half-stroke me-2"></i>
									<span class="theme-label-dark">Switch to Light Mode</span>
									<span class="theme-label-light">Switch to Dark Mode</span>
								</a>
							</li>
							<li>
								<hr class="dropdown-divider">
							</li>
							<li>
								<a class="dropdown-item text-danger" href="logout.php">
									<i class="fa-solid fa-right-from-bracket me-2"></i> Logout
								</a>
							</li>
						</ul>
					</li>
				</ul>
			</div>
		</div>
	</nav>

	<main class="container py-4">