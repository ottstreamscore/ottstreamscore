<?php

/**
 * setup.php
 * One-time setup script for OTT Stream Score
 * Works in both CLI and web browser
 * v1.5
 */

declare(strict_types=1);

// Detect if running in CLI or web
$is_cli = (php_sapi_name() === 'cli');

// Prevent running if already installed
if (file_exists(__DIR__ . '/.installed')) {
	if ($is_cli) {
		echo "\n========================================\n";
		echo "Already Installed\n";
		echo "========================================\n\n";
		echo "OTT Stream Score is already set up.\n";
		echo "To reconfigure, delete the .installed file and run setup.php again.\n";
	} else {
		echo "<!DOCTYPE html><html><head><title>Already Installed</title></head><body>";
		echo "<h1>Already Installed</h1>";
		echo "<p>OTT Stream Score is already set up.</p>";
		echo "<p>To reconfigure, delete the .installed file and run setup.php again.</p>";
		echo "</body></html>";
	}
	exit(0);
}

// Handle POST request (web form submission)
if (!$is_cli && $_SERVER['REQUEST_METHOD'] === 'POST') {
	// Process the form
	$step = $_POST['step'] ?? 'env';

	if ($step === 'complete') {
		// Run the installation
		try {
			$db_host = $_POST['db_host'];
			$db_port = $_POST['db_port'];
			$db_name = $_POST['db_name'];
			$db_user = $_POST['db_user'];
			$db_pass = $_POST['db_pass'];
			$stream_host = $_POST['stream_host'];
			$app_timezone = $_POST['app_timezone'] ?? 'America/New_York';
			$batch_size = $_POST['batch_size'] ?? '50';
			$lock_minutes = $_POST['lock_minutes'] ?? '10';
			$ok_recheck_hours = $_POST['ok_recheck_hours'] ?? '72';
			$fail_retry_min = $_POST['fail_retry_min'] ?? '30';
			$fail_retry_max = $_POST['fail_retry_max'] ?? '360';
			$admin_username = $_POST['admin_username'];
			$admin_password = $_POST['admin_password'];
			$admin_password_confirm = $_POST['admin_password_confirm'] ?? '';
			$admin_email = $_POST['admin_email'] ?? '';

			// Validate required fields
			$stream_host_trimmed = trim($stream_host);
			if (
				empty($db_host) || empty($db_name) || empty($db_user) ||
				empty($stream_host_trimmed) || $stream_host_trimmed === 'http://' || $stream_host_trimmed === 'https://' ||
				empty($admin_username) || empty($admin_password)
			) {
				throw new Exception("All required fields must be filled out. Stream Host cannot be just 'http://' - please provide a complete URL.");
			}

			// Validate password length
			if (strlen($admin_password) < 8) {
				throw new Exception("Password must be at least 8 characters long.");
			}

			// Validate password confirmation
			if ($admin_password !== $admin_password_confirm) {
				throw new Exception("Passwords do not match. Please try again.");
			}

			// Validate stream_host format
			$stream_host = rtrim($stream_host, '/'); // Remove trailing slash if present
			if (!filter_var($stream_host, FILTER_VALIDATE_URL) && !preg_match('/^https?:\/\/.+/', $stream_host)) {
				throw new Exception("Stream Host must be a valid URL (e.g., http://example.com)");
			}

			// Test database connection
			$dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
			$pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

			// Create tables
			$tables = [
				"CREATE TABLE IF NOT EXISTS `settings` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`setting_key` VARCHAR(100) NOT NULL UNIQUE,
			`setting_value` TEXT,
			`description` VARCHAR(255),
			`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX `idx_key` (`setting_key`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

				"INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES 
		('playlist_url', '', 'URL to hosted M3U playlist'),
		('last_sync_date', NULL, 'Last successful playlist sync timestamp'),
		('epg_last_sync_date', NULL, 'Last successful EPG sync timestamp'),
		('epg_url', '', 'URL to hosted EPG XML file')",

				"CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `email` VARCHAR(100),
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `last_login` DATETIME NULL,
        INDEX `idx_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

				"CREATE TABLE IF NOT EXISTS `epg_data` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tvg_id` VARCHAR(255) NOT NULL,
        `start_timestamp` DATETIME NOT NULL,
        `end_timestamp` DATETIME NOT NULL,
        `title` VARCHAR(500) NOT NULL,
        `description` TEXT,
        INDEX `idx_tvg_id` (`tvg_id`),
        INDEX `idx_start_time` (`start_timestamp`),
        INDEX `idx_tvg_start` (`tvg_id`, `start_timestamp`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

				"CREATE TABLE IF NOT EXISTS `login_attempts` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `ip_address` VARCHAR(45) NOT NULL,
        `username` VARCHAR(50) NOT NULL,
        `attempted_at` DATETIME NOT NULL,
        `success` TINYINT(1) NOT NULL DEFAULT 0,
        INDEX `idx_ip_time` (`ip_address`, `attempted_at`),
        INDEX `idx_username_time` (`username`, `attempted_at`),
        INDEX `idx_attempted_at` (`attempted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

				"CREATE TABLE IF NOT EXISTS `channels` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `tvg_id` VARCHAR(191) NOT NULL,
        `tvg_name` VARCHAR(255) NOT NULL,
        `tvg_logo` TEXT DEFAULT NULL,
        `group_title` VARCHAR(255) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_channel` (`tvg_id`, `group_title`),
        KEY `idx_name` (`tvg_name`),
        KEY `idx_group` (`group_title`),
        KEY `idx_channels_tvgid` (`tvg_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

				"CREATE TABLE IF NOT EXISTS `channel_feeds` (
        `channel_id` BIGINT(20) UNSIGNED NOT NULL,
        `feed_id` BIGINT(20) UNSIGNED NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_seen` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`channel_id`, `feed_id`),
        KEY `idx_feed_id` (`feed_id`),
        KEY `idx_channel_id` (`channel_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

				"CREATE TABLE IF NOT EXISTS `feeds` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `channel_id` BIGINT(20) UNSIGNED NOT NULL,
        `url` TEXT NOT NULL,
        `url_display` TEXT DEFAULT NULL,
        `url_hash` CHAR(40) NOT NULL,
        `is_live` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `last_checked_at` DATETIME DEFAULT NULL,
        `last_ok` TINYINT(1) DEFAULT NULL,
        `last_codec` VARCHAR(32) DEFAULT NULL,
        `last_w` INT(11) DEFAULT NULL,
        `last_h` INT(11) DEFAULT NULL,
        `last_fps` DECIMAL(6,2) DEFAULT NULL,
        `last_error` VARCHAR(255) DEFAULT NULL,
        `reliability_score` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        `quality_score` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `last_seen` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_url_hash` (`url_hash`),
        KEY `idx_channel` (`channel_id`),
        KEY `idx_last_ok` (`last_ok`),
        KEY `idx_quality` (`quality_score`),
        KEY `idx_feeds_channel` (`channel_id`),
        CONSTRAINT `fk_feeds_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

				"CREATE TABLE IF NOT EXISTS `feed_checks` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `feed_id` BIGINT(20) UNSIGNED NOT NULL,
        `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `ok` TINYINT(1) NOT NULL,
        `codec` VARCHAR(32) DEFAULT NULL,
        `w` INT(11) DEFAULT NULL,
        `h` INT(11) DEFAULT NULL,
        `fps` DECIMAL(6,2) DEFAULT NULL,
        `error` TEXT DEFAULT NULL,
        `raw_json` MEDIUMTEXT DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_feed_time` (`feed_id`, `checked_at`),
        KEY `idx_time` (`checked_at`),
        KEY `idx_feed_checks_feed_time` (`feed_id`, `checked_at`),
        CONSTRAINT `fk_checks_feed` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

				"CREATE TABLE IF NOT EXISTS `feed_check_queue` (
        `feed_id` BIGINT(20) UNSIGNED NOT NULL,
        `next_run_at` DATETIME NOT NULL,
        `locked_at` DATETIME DEFAULT NULL,
        `lock_token` CHAR(36) DEFAULT NULL,
        `attempts` INT(11) NOT NULL DEFAULT 0,
        `last_result_ok` TINYINT(1) DEFAULT NULL,
        `last_error` VARCHAR(255) DEFAULT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`feed_id`),
        KEY `idx_next` (`next_run_at`),
        KEY `idx_lock` (`locked_at`),
        KEY `idx_queue_next` (`next_run_at`),
        KEY `idx_queue_locked` (`locked_at`),
        CONSTRAINT `fk_queue_feed` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

				"CREATE TABLE IF NOT EXISTS `group_audit_ignores` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tvg_id` VARCHAR(255) NOT NULL,
    `source_group` VARCHAR(255) NOT NULL,
    `suggested_group` VARCHAR(255) NOT NULL,
    `suggested_feed_id` BIGINT(20) UNSIGNED NOT NULL,
    `suggested_tvg_name` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_ignore` (`tvg_id`, `source_group`, `suggested_feed_id`),
    INDEX `idx_tvg_source` (`tvg_id`, `source_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

				"CREATE TABLE IF NOT EXISTS `group_associations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

				"CREATE TABLE IF NOT EXISTS `group_association_prefixes` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `association_id` INT UNSIGNED NOT NULL,
        `prefix` VARCHAR(20) NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_association_prefix` (`association_id`, `prefix`),
        INDEX `idx_prefix` (`prefix`),
        INDEX `idx_association_id` (`association_id`),
        CONSTRAINT `fk_assoc_prefix_assoc` FOREIGN KEY (`association_id`) REFERENCES `group_associations` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

				"CREATE TABLE IF NOT EXISTS `feed_id_mapping` (
        `old_feed_id` BIGINT(20) UNSIGNED NOT NULL,
        `url_hash` VARCHAR(40) NOT NULL,
        `url` TEXT NOT NULL,
        PRIMARY KEY (`old_feed_id`),
        KEY `idx_url_hash` (`url_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

				"CREATE TABLE IF NOT EXISTS `stream_preview_lock` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `locked_by` VARCHAR(100) NOT NULL COMMENT 'Session ID of the user previewing',
        `locked_at` DATETIME NOT NULL COMMENT 'When the lock was acquired',
        `last_heartbeat` DATETIME NOT NULL COMMENT 'Last heartbeat timestamp',
        `feed_id` INT(11) NOT NULL COMMENT 'Feed ID being previewed',
        `channel_name` VARCHAR(255) DEFAULT NULL COMMENT 'Channel name for reference',
        PRIMARY KEY (`id`),
        UNIQUE KEY `single_lock` (`id`),
        KEY `idx_heartbeat` (`last_heartbeat`),
        KEY `idx_session` (`locked_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

				"CREATE TABLE IF NOT EXISTS `editor_todo_list` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `tvg_id` VARCHAR(255) NOT NULL,
        `source_group` VARCHAR(255) NOT NULL,
        `suggested_group` VARCHAR(255) NOT NULL,
        `suggested_feed_id` BIGINT(20) UNSIGNED NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_by_user` INT UNSIGNED NOT NULL,
        `category` ENUM('feed_replacement','feed_review','epg_adjustment','other') NOT NULL,
        `note` TEXT DEFAULT NULL,
        INDEX `idx_tvg_source` (`tvg_id`, `source_group`),
        INDEX `idx_category` (`category`),
        INDEX `idx_created_by` (`created_by_user`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

				"CREATE TABLE IF NOT EXISTS `editor_todo_list_log` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `original_todo_id` INT UNSIGNED NOT NULL,
        `tvg_id` VARCHAR(255) NOT NULL,
        `source_group` VARCHAR(255) NOT NULL,
        `suggested_group` VARCHAR(255) NOT NULL,
        `suggested_feed_id` BIGINT(20) UNSIGNED NOT NULL,
        `created_at` TIMESTAMP NOT NULL,
        `created_by_user` INT UNSIGNED NOT NULL,
        `category` ENUM('feed_replacement','feed_review','epg_adjustment','other') NOT NULL,
        `note` TEXT DEFAULT NULL,
        `completed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `completed_by_user` INT UNSIGNED NOT NULL,
        `completion_status` ENUM('completed','deleted') NOT NULL,
        INDEX `idx_original_todo` (`original_todo_id`),
        INDEX `idx_tvg_source` (`tvg_id`, `source_group`),
        INDEX `idx_category` (`category`),
        INDEX `idx_completion_status` (`completion_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"

			];

			foreach ($tables as $sql) {
				$pdo->exec($sql);
			}

			// Save database config
			$db_config = [
				'host' => $db_host,
				'port' => (int)$db_port,
				'name' => $db_name,
				'user' => $db_user,
				'pass' => $db_pass,
				'charset' => 'utf8mb4'
			];
			file_put_contents(__DIR__ . '/.db_bootstrap', json_encode($db_config));

			// Save settings
			// Split settings into database credentials and application settings
			$db_config = [
				'host' => $db_host,
				'port' => (int)$db_port,
				'name' => $db_name,
				'user' => $db_user,
				'pass' => $db_pass,
				'charset' => 'utf8mb4'
			];

			$app_settings = [
				'stream_host' => $stream_host,
				'app_timezone' => $app_timezone,
				'batch_size' => $batch_size,
				'lock_minutes' => $lock_minutes,
				'ok_recheck_hours' => $ok_recheck_hours,
				'fail_retry_min' => $fail_retry_min,
				'fail_retry_max' => $fail_retry_max
			];

			// Save database credentials to .db_bootstrap file ONLY
			$bootstrap_file = __DIR__ . '/.db_bootstrap';
			file_put_contents($bootstrap_file, json_encode($db_config, JSON_PRETTY_PRINT));
			chmod($bootstrap_file, 0600);

			// Save application settings to database
			$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
			foreach ($app_settings as $key => $value) {
				$stmt->execute([$key, $value, $value]);
			}

			// Create admin user

			// Check if username already exists
			$check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
			$check_stmt->execute([$admin_username]);
			if ($check_stmt->fetchColumn() > 0) {
				throw new Exception("Username '$admin_username' already exists. Please choose a different username.");
			}

			$hash = password_hash($admin_password, PASSWORD_DEFAULT);
			$stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)");
			$stmt->execute([$admin_username, $hash, $admin_email]);

			// Delete config.php if exists
			if (file_exists(__DIR__ . '/config.php')) {
				@unlink(__DIR__ . '/config.php');
			}

			// Create playlists directory
			$playlistsDir = __DIR__ . '/playlists';
			if (!file_exists($playlistsDir)) {
				mkdir($playlistsDir, 0700, true);
			}

			// Create index.php protection file
			$indexFile = $playlistsDir . '/index.php';
			if (!file_exists($indexFile)) {
				file_put_contents($indexFile, "<?php\nhttp_response_code(403);\ndie('Access denied');\n");
			}

			// Mark as installed
			file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

			// Success page
			echo "<!DOCTYPE html><html><head><title>Setup Complete</title>
            <style>body{font-family:sans-serif;max-width:600px;margin:50px auto;padding:20px;}
            .success{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:15px;border-radius:5px;margin:20px 0;}
            a{color:#007bff;text-decoration:none;} a:hover{text-decoration:underline;}</style>
            </head><body>
            <h1>✓ Setup Complete!</h1>
            <div class='success'>OTT Stream Score has been installed successfully!</div>
            <p><strong>Login URL:</strong> <a href='login.php'>login.php</a></p>
            <p><strong>Username:</strong> $admin_username</p>
            <h3>Next Steps:</h3>
            <ol>
            <li>Login to the application</li>
            <li>Import your M3U playlist</li>
            <li>Setup cron job for feed checking</li>
            <li>Access admin panel to manage settings</li>
            </ol>
            <p><a href='login.php'>Go to Login →</a></p>
            </body></html>";
			exit(0);
		} catch (Exception $e) {
			$error = $e->getMessage();
			// Preserve POST data for form repopulation
			$config_data = $_POST;
			// Don't show passwords in preserved data
			unset($config_data['admin_password']);
			unset($config_data['admin_password_confirm']);
		}
	}
}

// Show web form
if (!$is_cli) {
	// Check if config.php exists for upgrade detection
	$is_upgrade = false;

	// Only initialize config_data if it wasn't already set by error handler
	if (!isset($config_data)) {
		$config_data = [];
	}

	if (file_exists(__DIR__ . '/config.php') && empty($config_data)) {
		$config_content = file_get_contents(__DIR__ . '/config.php');
		if (
			preg_match("/const\s+DB_PASS\s*=\s*'([^']+)'/", $config_content, $pass_match) &&
			preg_match("/const\s+DB_USER\s*=\s*'([^']+)'/", $config_content, $user_match) &&
			preg_match("/const\s+DB_HOST\s*=\s*'([^']+)'/", $config_content, $host_match) &&
			preg_match("/const\s+DB_NAME\s*=\s*'([^']+)'/", $config_content, $name_match) &&
			preg_match("/const\s+DB_PORT\s*=\s*(\d+)/", $config_content, $port_match) &&
			preg_match("/const\s+STREAM_HOST\s*=\s*'([^']+)'/", $config_content, $stream_match)
		) {

			$db_user = $user_match[1];
			$db_pass = $pass_match[1];

			if ($db_user !== 'DB_USER' && $db_pass !== 'DB_PASS' && !empty($db_user) && !empty($db_pass)) {
				$is_upgrade = true;
				$config_data = [
					'db_host' => $host_match[1],
					'db_port' => $port_match[1],
					'db_name' => $name_match[1],
					'db_user' => $db_user,
					'db_pass' => $db_pass,
					'stream_host' => $stream_match[1],
					'app_timezone' => 'America/New_York',
					'batch_size' => '50',
					'lock_minutes' => '10',
					'ok_recheck_hours' => '72',
					'fail_retry_min' => '30',
					'fail_retry_max' => '360'
				];

				// Extract application settings if present
				if (preg_match("/const\s+APP_TZ\s*=\s*'([^']+)'/", $config_content, $m)) {
					$config_data['app_timezone'] = $m[1];
				}
				if (preg_match("/const\s+BATCH_SIZE\s*=\s*(\d+)/", $config_content, $m)) {
					$config_data['batch_size'] = $m[1];
				}
				if (preg_match("/const\s+LOCK_MINUTES\s*=\s*(\d+)/", $config_content, $m)) {
					$config_data['lock_minutes'] = $m[1];
				}
				if (preg_match("/const\s+OK_RECHECK_HOURS\s*=\s*(\d+)/", $config_content, $m)) {
					$config_data['ok_recheck_hours'] = $m[1];
				}
				if (preg_match("/const\s+FAIL_RETRY_MINUTES_MIN\s*=\s*(\d+)/", $config_content, $m)) {
					$config_data['fail_retry_min'] = $m[1];
				}
				if (preg_match("/const\s+FAIL_RETRY_MINUTES_MAX\s*=\s*(\d+)/", $config_content, $m)) {
					$config_data['fail_retry_max'] = $m[1];
				}
			}
		}
	}

?>
	<!DOCTYPE html>
	<html lang="en">

	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>OTT Stream Score - Setup</title>
		<style>
			* {
				margin: 0;
				padding: 0;
				box-sizing: border-box;
			}

			body {
				font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
				background: linear-gradient(135deg, #667eea 0%, #1ec7c0 100%);
				min-height: 100vh;
				padding: 20px;
			}

			.container {
				max-width: 600px;
				margin: 0 auto;
				background: white;
				border-radius: 12px;
				box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
				overflow: hidden;
			}

			.header {
				background: #212529;
				color: white;
				padding: 30px;
				text-align: center;
			}

			.content {
				padding: 30px;
			}

			h3 {
				color: #495057;
				font-size: 16px;
				margin-bottom: 15px;
				padding-bottom: 8px;
				border-bottom: 2px solid #e9ecef;
			}

			.form-group {
				margin-bottom: 20px;
			}

			.form-group label {
				display: block;
				margin-bottom: 6px;
				font-weight: 500;
				color: #495057;
			}

			.form-group input,
			.form-group select {
				width: 100%;
				padding: 10px 12px;
				border: 1px solid #ced4da;
				border-radius: 6px;
				font-size: 14px;
			}

			.form-group small {
				display: block;
				margin-top: 4px;
				color: #6c757d;
				font-size: 12px;
			}

			.btn {
				width: 100%;
				padding: 12px;
				background: #667eea;
				color: white;
				border: none;
				border-radius: 6px;
				font-size: 14px;
				font-weight: 500;
				cursor: pointer;
			}

			.btn:hover {
				background: #5568d3;
			}

			.alert {
				padding: 12px;
				border-radius: 6px;
				margin-bottom: 20px;
				background: #fff3cd;
				border: 1px solid #ffc107;
				color: #856404;
			}

			.install_logo {}
		</style>
	</head>

	<body>
		<div class="container">
			<div class="header">
				<img src="logo_header.png" alt="OTT Stream Score" class="install_logo">
				<p style="font-size:18pt; margin-top:10pt;">Setup Wizard (v1.5)</p>
			</div>

			<div class="content">
				<?php if (isset($error)): ?>
					<div class="alert" style="background: #f8d7da; border-color: #f5c2c7; color: #842029;">
						<strong>⚠ Error</strong><br>
						<?= htmlspecialchars($error) ?>
					</div>
				<?php endif; ?>

				<?php if ($is_upgrade): ?>
					<div class="alert">
						<strong>⚠ Upgrade Detected</strong><br>
						Found existing config.php. Your data will be preserved and settings migrated.
					</div>
				<?php else: ?>
					<p style="margin-bottom: 20px; color: #6c757d;">
						Configure your OTT Stream Score installation. All settings can be changed later via the admin panel.
					</p>
				<?php endif; ?>

				<form method="post">
					<input type="hidden" name="step" value="complete">

					<h3>Database Configuration</h3>

					<div class="form-group">
						<label>Database Host</label>
						<input type="text" name="db_host" value="<?= htmlspecialchars($config_data['db_host'] ?? 'localhost') ?>" required>
					</div>

					<div class="form-group">
						<label>Database Port</label>
						<input type="number" name="db_port" value="<?= htmlspecialchars($config_data['db_port'] ?? '3306') ?>" required>
					</div>

					<div class="form-group">
						<label>Database Name</label>
						<input type="text" name="db_name" value="<?= htmlspecialchars($config_data['db_name'] ?? '') ?>" required>
					</div>

					<div class="form-group">
						<label>Database User</label>
						<input type="text" name="db_user" value="<?= htmlspecialchars($config_data['db_user'] ?? '') ?>" required>
					</div>

					<div class="form-group">
						<label>Database Password</label>
						<input type="password" name="db_pass" value="<?= htmlspecialchars($config_data['db_pass'] ?? '') ?>" required>
					</div>

					<h3 style="margin-top:30px;">Application Settings</h3>

					<div class="form-group">
						<label>Stream Host *</label>
						<input type="text" name="stream_host" value="<?= htmlspecialchars($config_data['stream_host'] ?? '') ?>" placeholder="http://your-panel-domain.com" required>
						<small>Base URL for building authenticated live stream URLs (no trailing slash). If you are a reseller, this is the custom domain configured for your panel.</small>
					</div>

					<div class="form-group">
						<label>Timezone *</label>
						<select name="app_timezone" required>
							<?php
							$timezones = [
								'America/New_York',
								'America/Chicago',
								'America/Denver',
								'America/Los_Angeles',
								'America/Toronto',
								'America/Phoenix',
								'Europe/London',
								'Europe/Paris',
								'Europe/Berlin',
								'Asia/Tokyo',
								'Asia/Dubai',
								'Australia/Sydney',
								'UTC'
							];
							$current_tz = $config_data['app_timezone'] ?? 'America/New_York';
							foreach ($timezones as $tz) {
								$selected = ($tz === $current_tz) ? ' selected' : '';
								echo "<option value=\"$tz\"$selected>$tz</option>";
							}
							?>
						</select>
						<small>Default timezone for all application output and timestamps</small>
					</div>

					<h3 style="margin-top:30px;">Feed Monitoring Settings</h3>

					<div class="form-group">
						<label>Batch Size *</label>
						<input type="number" name="batch_size" value="<?= htmlspecialchars($config_data['batch_size'] ?? '50') ?>" min="1" max="500" required>
						<small>How many feeds to process in each batch run (cron execution). Higher = faster processing but more server load.</small>
					</div>

					<div class="form-group">
						<label>Lock Duration (minutes) *</label>
						<input type="number" name="lock_minutes" value="<?= htmlspecialchars($config_data['lock_minutes'] ?? '10') ?>" min="1" max="60" required>
						<small>Prevents concurrent batch processing. Should be longer than your typical batch run time.</small>
					</div>

					<div class="form-group">
						<label>Recheck Healthy Feeds (hours) *</label>
						<input type="number" name="ok_recheck_hours" value="<?= htmlspecialchars($config_data['ok_recheck_hours'] ?? '72') ?>" min="1" max="720" required>
						<small>How long to wait before rechecking streams that previously tested OK. Default: 72 hours (3 days).</small>
					</div>

					<div class="form-group">
						<label>Failed Feed Retry - Minimum (minutes) *</label>
						<input type="number" name="fail_retry_min" value="<?= htmlspecialchars($config_data['fail_retry_min'] ?? '30') ?>" min="1" max="1440" required>
						<small>Initial retry interval after a feed fails. System will retry failed feeds progressively slower.</small>
					</div>

					<div class="form-group">
						<label>Failed Feed Retry - Maximum (minutes) *</label>
						<input type="number" name="fail_retry_max" value="<?= htmlspecialchars($config_data['fail_retry_max'] ?? '360') ?>" min="1" max="1440" required>
						<small>Maximum retry interval cap for failed feeds. Default: 360 minutes (6 hours).</small>
					</div>

					<h3 style="margin-top:30px;">Create Admin User</h3>

					<div class="form-group">
						<label>Admin Username</label>
						<input type="text" name="admin_username" value="<?= htmlspecialchars($config_data['admin_username'] ?? '') ?>" required>
					</div>

					<div class="form-group">
						<label>Admin Password</label>
						<input type="password" name="admin_password" required>
						<small>Minimum 8 characters</small>
					</div>

					<div class="form-group">
						<label>Confirm Password</label>
						<input type="password" name="admin_password_confirm" required>
						<small>Re-enter your password</small>
					</div>

					<!-- NOT IN USE... YET 

					<div class="form-group">
						<label>Admin Email (optional)</label>
						<input type="email" name="admin_email">
					</div>

					-->

					<button type="submit" class="btn">Complete Setup</button>
				</form>
			</div>
		</div>
	</body>

	</html>
<?php
	exit(0);
}

?>