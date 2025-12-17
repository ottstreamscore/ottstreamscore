<?php

/**
 * migrate.php
 * Database migration script for OTT Stream Score
 * 
 * FOR USERS UPGRADING FROM v1.3 OR LATER ONLY
 * 
 * If you're upgrading from a version BEFORE v1.3:
 * Please read the install.md file included in version 1.3 for specific migration instructions.
 * 
 * This script adds the login_attempts table required for authentication rate limiting.
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
				<p>OTT Stream Score v1.3+</p>
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
						<p>The login_attempts table has been successfully created. This enables enhanced security features including:</p>
						<ul style="margin: 10px 0 0 20px; color: #6c757d; font-size: 14px;">
							<li>Rate limiting for login attempts</li>
							<li>Account lockout protection</li>
							<li>Failed login attempt tracking</li>
						</ul>
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
