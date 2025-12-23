<?php

/**
 * migrate.php
 * Database migration script for OTT Stream Score to version 1.5
 * 
 * FOR USERS UPGRADING FROM v1.3 OR LATER ONLY
 * 
 * If you're upgrading from a version BEFORE v1.3:
 * Please read the install.md file included in version 1.3 for specific migration instructions.
 * 
 * This script adds tables required post v1.3
 */

declare(strict_types=1);

// Detect if running in CLI or web
$is_cli = (php_sapi_name() === 'cli');

// Check if database configuration exists
if (!file_exists(__DIR__ . '/.db_bootstrap')) {
	$error_msg = "Database configuration not found. Please run setup.php first.";
	if ($is_cli) {
		echo "\n" . str_repeat("=", 70) . "\n";
		echo "❌ ERROR\n";
		echo str_repeat("=", 70) . "\n";
		echo "$error_msg\n";
		echo str_repeat("=", 70) . "\n\n";
		exit(1);
	} else {
		die("<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error</h1><p>$error_msg</p></body></html>");
	}
}

// Load database configuration
$db_config = json_decode(file_get_contents(__DIR__ . '/.db_bootstrap'), true);

if (!$db_config || !isset($db_config['host'], $db_config['name'], $db_config['user'])) {
	$error_msg = "Invalid database configuration. Please check your .db_bootstrap file.";
	if ($is_cli) {
		echo "\n❌ Error: $error_msg\n\n";
		exit(1);
	} else {
		die("<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error</h1><p>$error_msg</p></body></html>");
	}
}

// Connect to database
try {
	$dsn = sprintf(
		'mysql:host=%s;port=%d;dbname=%s;charset=%s',
		$db_config['host'],
		$db_config['port'] ?? 3306,
		$db_config['name'],
		$db_config['charset'] ?? 'utf8mb4'
	);

	$pdo = new PDO($dsn, $db_config['user'], $db_config['pass'] ?? '', [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
	]);
} catch (PDOException $e) {
	$error_msg = "Database connection failed: " . $e->getMessage();
	if ($is_cli) {
		echo "\n❌ Error: $error_msg\n\n";
		exit(1);
	} else {
		die("<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error</h1><p>$error_msg</p></body></html>");
	}
}

// Verify this is a v1.3+ installation by checking for required tables
$required_tables = ['users', 'settings', 'feeds', 'channels'];
$missing_tables = [];

foreach ($required_tables as $table) {
	$result = $pdo->query("SHOW TABLES LIKE '$table'");
	if ($result->rowCount() === 0) {
		$missing_tables[] = $table;
	}
}

if (!empty($missing_tables)) {
	$error_msg = "This migration script is for v1.3+ installations only.\n\n";
	$error_msg .= "Missing required tables: " . implode(', ', $missing_tables) . "\n\n";
	$error_msg .= "If you're upgrading from a version BEFORE v1.3, please read the install.md\n";
	$error_msg .= "file included in version 1.3 for specific migration instructions.";

	if ($is_cli) {
		echo "\n" . str_repeat("=", 70) . "\n";
		echo "⚠️  INCOMPATIBLE VERSION\n";
		echo str_repeat("=", 70) . "\n";
		echo "$error_msg\n";
		echo str_repeat("=", 70) . "\n\n";
		exit(1);
	} else {
		die("<!DOCTYPE html><html><head><title>Incompatible Version</title></head><body><h1>⚠️ Incompatible Version</h1><pre>$error_msg</pre></body></html>");
	}
}

// Run migrations
$migrations = [];

// Migration 1: Create login_attempts table
try {
	$result = $pdo->query("SHOW TABLES LIKE 'login_attempts'");
	$table_exists = $result->rowCount() > 0;

	if (!$table_exists) {
		$sql = "CREATE TABLE `login_attempts` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`ip_address` VARCHAR(45) NOT NULL,
			`username` VARCHAR(50) NOT NULL,
			`attempted_at` DATETIME NOT NULL,
			`success` TINYINT(1) NOT NULL DEFAULT 0,
			INDEX `idx_ip_time` (`ip_address`, `attempted_at`),
			INDEX `idx_username_time` (`username`, `attempted_at`),
			INDEX `idx_attempted_at` (`attempted_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

		$pdo->exec($sql);
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created login_attempts table'
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'login_attempts table already exists'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to create login_attempts table: " . $e->getMessage()
	];
}

// Migration 2: Create feed_id_mapping table
try {
	$result = $pdo->query("SHOW TABLES LIKE 'feed_id_mapping'");
	$table_exists = $result->rowCount() > 0;

	if (!$table_exists) {
		$sql = "CREATE TABLE `feed_id_mapping` (
			`old_feed_id` BIGINT(20) UNSIGNED NOT NULL,
			`url_hash` VARCHAR(40) NOT NULL,
			`url` TEXT NOT NULL,
			PRIMARY KEY (`old_feed_id`),
			KEY `idx_url_hash` (`url_hash`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

		$pdo->exec($sql);
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created feed_id_mapping table'
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'feed_id_mapping table already exists'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to create feed_id_mapping table: " . $e->getMessage()
	];
}

// Migration 3: Create stream_preview_lock table
try {
	$result = $pdo->query("SHOW TABLES LIKE 'stream_preview_lock'");
	$table_exists = $result->rowCount() > 0;

	if (!$table_exists) {
		$sql = "CREATE TABLE `stream_preview_lock` (
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
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

		$pdo->exec($sql);
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created stream_preview_lock table'
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'stream_preview_lock table already exists'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to create stream_preview_lock table: " . $e->getMessage()
	];
}

// Migration: Create group_associations table
try {
	$result = $pdo->query("SHOW TABLES LIKE 'group_associations'");
	$table_exists = $result->rowCount() > 0;

	if (!$table_exists) {
		$sql = "CREATE TABLE `group_associations` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`name` VARCHAR(100) NOT NULL,
			`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX `idx_name` (`name`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

		$pdo->exec($sql);
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created group_associations table'
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'group_associations table already exists'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to create group_associations table: " . $e->getMessage()
	];
}

// Migration: Create group_association_prefixes table
try {
	$result = $pdo->query("SHOW TABLES LIKE 'group_association_prefixes'");
	$table_exists = $result->rowCount() > 0;

	if (!$table_exists) {
		$sql = "CREATE TABLE `group_association_prefixes` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`association_id` INT UNSIGNED NOT NULL,
			`prefix` VARCHAR(20) NOT NULL,
			`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY `unique_association_prefix` (`association_id`, `prefix`),
			INDEX `idx_prefix` (`prefix`),
			INDEX `idx_association_id` (`association_id`),
			CONSTRAINT `fk_assoc_prefix_assoc` FOREIGN KEY (`association_id`) REFERENCES `group_associations` (`id`) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

		$pdo->exec($sql);
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created group_association_prefixes table'
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'group_association_prefixes table already exists'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to create group_association_prefixes table: " . $e->getMessage()
	];
}

// Migration: Create group_audit_ignores table
try {
	$result = $pdo->query("SHOW TABLES LIKE 'group_audit_ignores'");
	$table_exists = $result->rowCount() > 0;

	if (!$table_exists) {
		$sql = "CREATE TABLE `group_audit_ignores` (
			`id` INT AUTO_INCREMENT PRIMARY KEY,
			`tvg_id` VARCHAR(255) NOT NULL,
			`source_group` VARCHAR(255) NOT NULL,
			`suggested_group` VARCHAR(255) NOT NULL,
			`suggested_feed_id` BIGINT(20) UNSIGNED NOT NULL,
			`suggested_tvg_name` VARCHAR(255) DEFAULT NULL,
			`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY `unique_ignore` (`tvg_id`, `source_group`, `suggested_feed_id`),
			INDEX `idx_tvg_source` (`tvg_id`, `source_group`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

		$pdo->exec($sql);
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created group_audit_ignores table'
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'group_audit_ignores table already exists'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to create group_audit_ignores table: " . $e->getMessage()
	];
}

// Migration: Create editor_todo_list table
try {
	$result = $pdo->query("SHOW TABLES LIKE 'editor_todo_list'");
	$table_exists = $result->rowCount() > 0;

	if (!$table_exists) {
		$sql = "CREATE TABLE `editor_todo_list` (
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
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

		$pdo->exec($sql);
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created editor_todo_list table'
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'editor_todo_list table already exists'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to create editor_todo_list table: " . $e->getMessage()
	];
}

// Migration: Create editor_todo_list_log table
try {
	$result = $pdo->query("SHOW TABLES LIKE 'editor_todo_list_log'");
	$table_exists = $result->rowCount() > 0;

	if (!$table_exists) {
		$sql = "CREATE TABLE `editor_todo_list_log` (
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
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

		$pdo->exec($sql);
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created editor_todo_list_log table'
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'editor_todo_list_log table already exists'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to create editor_todo_list_log table: " . $e->getMessage()
	];
}

try {
	$result = $pdo->query("SHOW TABLES LIKE 'epg_data'");
	$table_exists = $result->rowCount() > 0;

	if (!$table_exists) {
		$sql = "CREATE TABLE `epg_data` (
			`id` INT AUTO_INCREMENT PRIMARY KEY,
			`tvg_id` VARCHAR(255) NOT NULL,
			`start_timestamp` DATETIME NOT NULL,
			`end_timestamp` DATETIME NOT NULL,
			`title` VARCHAR(500) NOT NULL,
			`description` TEXT,
			INDEX `idx_tvg_id` (`tvg_id`),
			INDEX `idx_start_time` (`start_timestamp`),
			INDEX `idx_tvg_start` (`tvg_id`, `start_timestamp`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

		$pdo->exec($sql);
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created epg_data table'
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'epg_data table already exists'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to create epg_data table: " . $e->getMessage()
	];
}
// Migration: Add new playlist-related settings
try {
	$sql = "INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES 
		('playlist_url', '', 'URL to hosted M3U playlist'),
		('last_sync_date', NULL, 'Last successful playlist sync timestamp'),
		('epg_last_sync_date', NULL, 'Last successful EPG sync timestamp'),
		('epg_url', '', 'URL to hosted EPG XML file')";

	$stmt = $pdo->prepare($sql);
	$stmt->execute();
	$rowsAffected = $stmt->rowCount();

	if ($rowsAffected > 0) {
		$migrations[] = [
			'status' => 'success',
			'message' => "Added $rowsAffected new playlist settings"
		];
	} else {
		$migrations[] = [
			'status' => 'skipped',
			'message' => 'Playlist settings already exist'
		];
	}
} catch (PDOException $e) {
	$migrations[] = [
		'status' => 'error',
		'message' => "Failed to add playlist settings: " . $e->getMessage()
	];
}

// Create playlists directory
$playlistsDir = __DIR__ . '/playlists';
if (!file_exists($playlistsDir)) {
	if (mkdir($playlistsDir, 0700, true)) {
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created playlists directory'
		];
	} else {
		$migrations[] = [
			'status' => 'error',
			'message' => 'Failed to create playlists directory'
		];
	}
} else {
	$migrations[] = [
		'status' => 'skipped',
		'message' => 'playlists directory already exists'
	];
}

// Create index.php protection file
$indexFile = $playlistsDir . '/index.php';
if (!file_exists($indexFile)) {
	if (file_put_contents($indexFile, "<?php\nhttp_response_code(403);\ndie('Access denied');\n")) {
		$migrations[] = [
			'status' => 'success',
			'message' => 'Created playlists/index.php protection file'
		];
	} else {
		$migrations[] = [
			'status' => 'error',
			'message' => 'Failed to create playlists/index.php protection file'
		];
	}
} else {
	$migrations[] = [
		'status' => 'skipped',
		'message' => 'playlists/index.php already exists'
	];
}

// Check if any migrations failed
$has_errors = false;
foreach ($migrations as $migration) {
	if ($migration['status'] === 'error') {
		$has_errors = true;
		break;
	}
}

// Output results
if ($is_cli) {
	echo "\n" . str_repeat("=", 70) . "\n";
	echo "OTT Stream Score - Database Migration (v1.3+)\n";
	echo str_repeat("=", 70) . "\n\n";

	foreach ($migrations as $migration) {
		$icon = match ($migration['status']) {
			'success' => '✅',
			'skipped' => 'ℹ️ ',
			'error' => '❌',
			default => '•'
		};
		echo "$icon {$migration['message']}\n";
	}

	echo "\n" . str_repeat("=", 70) . "\n";
	if ($has_errors) {
		echo "❌ Migration completed with errors\n";
		echo str_repeat("=", 70) . "\n\n";
		exit(1);
	} else {
		echo "✅ Migration completed successfully!\n";
		echo str_repeat("=", 70) . "\n\n";
		exit(0);
	}
} else {
	// Web output
?>
	<!DOCTYPE html>
	<html lang="en">

	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Database Migration - OTT Stream Score</title>
		<style>
			* {
				margin: 0;
				padding: 0;
				box-sizing: border-box;
			}

			body {
				font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
				background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
				min-height: 100vh;
				display: flex;
				align-items: center;
				justify-content: center;
				padding: 20px;
			}

			.container {
				background: white;
				border-radius: 12px;
				box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
				max-width: 700px;
				width: 100%;
				overflow: hidden;
			}

			.header {
				background: <?php echo $has_errors ? '#dc3545' : '#667eea'; ?>;
				color: white;
				padding: 30px;
				text-align: center;
			}

			.header h1 {
				font-size: 28px;
				font-weight: 600;
				margin-bottom: 5px;
			}

			.header p {
				font-size: 14px;
				opacity: 0.9;
			}

			.content {
				padding: 30px;
			}

			.status-box {
				background: <?php echo $has_errors ? '#f8d7da' : '#d4edda'; ?>;
				border: 1px solid <?php echo $has_errors ? '#f5c2c7' : '#c3e6cb'; ?>;
				border-radius: 8px;
				padding: 20px;
				margin-bottom: 25px;
			}

			.status-box h2 {
				color: <?php echo $has_errors ? '#842029' : '#155724'; ?>;
				font-size: 20px;
				margin-bottom: 15px;
				display: flex;
				align-items: center;
			}

			.status-box h2::before {
				content: "<?php echo $has_errors ? '✕' : '✓'; ?>";
				display: inline-block;
				width: 32px;
				height: 32px;
				background: <?php echo $has_errors ? '#dc3545' : '#28a745'; ?>;
				color: white;
				border-radius: 50%;
				text-align: center;
				line-height: 32px;
				margin-right: 12px;
				font-weight: bold;
				font-size: 18px;
			}

			.migration-list {
				list-style: none;
				margin-top: 20px;
			}

			.migration-list li {
				padding: 12px 0;
				border-bottom: 1px solid #e9ecef;
				font-size: 15px;
				display: flex;
				align-items: center;
			}

			.migration-list li:last-child {
				border-bottom: none;
			}

			.migration-list li::before {
				margin-right: 12px;
				font-size: 18px;
			}

			.migration-success::before {
				content: "✅";
			}

			.migration-skipped::before {
				content: "ℹ️";
			}

			.migration-error::before {
				content: "❌";
			}

			.info-box {
				background: #f8f9fa;
				border-left: 4px solid #667eea;
				padding: 15px;
				margin-top: 25px;
				border-radius: 4px;
			}

			.info-box h3 {
				color: #495057;
				font-size: 16px;
				margin-bottom: 10px;
			}

			.info-box p {
				color: #6c757d;
				font-size: 14px;
				line-height: 1.6;
				margin: 5px 0;
			}

			.btn {
				display: inline-block;
				padding: 12px 24px;
				background: #667eea;
				color: white;
				text-decoration: none;
				border-radius: 6px;
				font-weight: 500;
				margin-top: 20px;
				transition: background 0.3s;
			}

			.btn:hover {
				background: #5568d3;
			}

			.warning-box {
				background: #fff3cd;
				border: 1px solid #ffc107;
				border-radius: 8px;
				padding: 20px;
				margin-bottom: 25px;
			}

			.warning-box h3 {
				color: #856404;
				font-size: 18px;
				margin-bottom: 10px;
			}

			.warning-box p {
				color: #856404;
				font-size: 14px;
				line-height: 1.6;
			}

			.warning-box strong {
				font-weight: 600;
			}
		</style>
	</head>

	<body>
		<div class="container">
			<div class="header">
				<h1><?php echo $has_errors ? '⚠️ Migration Errors' : '✅ Migration Complete'; ?></h1>
				<p>OTT Stream Score v1.5</p>
			</div>

			<div class="content">
				<div class="status-box">
					<h2><?php echo $has_errors ? 'Completed with Errors' : 'Successfully Updated'; ?></h2>

					<ul class="migration-list">
						<?php foreach ($migrations as $migration): ?>
							<li class="migration-<?php echo $migration['status']; ?>">
								<?php echo htmlspecialchars($migration['message']); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<?php if (!$has_errors): ?>
					<div class="info-box">
						<h3>🎉 Your database has been updated!</h3>
					</div>
					<a href="admin.php?tab=users" class="btn">Go to User Management</a>
				<?php else: ?>
					<div class="warning-box">
						<h3>⚠️ Action Required</h3>
						<p>Some migrations failed to complete. Please review the errors above and ensure your database user has the necessary permissions to create tables.</p>
						<p style="margin-top: 10px;"><strong>If the problem persists, you may need to manually run the SQL statements or contact your system administrator.</strong></p>
					</div>
				<?php endif; ?>

				<div class="info-box" style="margin-top: 25px;">
					<h3>ℹ️ Upgrading from before v1.3?</h3>
					<p>This migration script is only for installations running v1.3 or later.</p>
					<p style="margin-top: 8px;">If you're upgrading from a version <strong>before v1.3</strong>, please read the <code>install.md</code> file included in version 1.3 for specific migration instructions.</p>
				</div>
			</div>
		</div>
	</body>

	</html>
<?php
	exit($has_errors ? 1 : 0);
}
