<?php

/**
 * admin.php
 * Admin panel for managing installation
 */

declare(strict_types=1);

$title = 'Admin';
$currentPage = 'admin';
require_once __DIR__ . '/_boot.php';

// require login authorization
require_auth();

require_once __DIR__ . '/_top.php';

$success = null;
$error = null;
$tab = $_GET['tab'] ?? 'settings';

// Flash message (POST→redirect→GET)
if (session_status() !== PHP_SESSION_ACTIVE) {
	@session_start();
}
$flash = $_SESSION['playlist_flash'] ?? null;
unset($_SESSION['playlist_flash']);

/* ============================================================================
PLAYLIST IMPORT HELPERS
============================================================================ */

// Get base directory (application root)
$baseDir = __DIR__;

// Get directory parameter (default to current directory)
$selectedDir = $_GET['dir'] ?? '.';

// Sanitize directory input - only allow alphanumeric, dash, underscore, and forward slash
$selectedDir = preg_replace('/[^a-zA-Z0-9\/_-]/', '', $selectedDir);
$selectedDir = trim($selectedDir, '/');

// Prevent directory traversal
if (str_contains($selectedDir, '..')) {
	$selectedDir = '.';
}

// Build full path
$fullDir = $selectedDir === '.' ? $baseDir : $baseDir . '/' . $selectedDir;

// Validate directory exists and is readable
if (!is_dir($fullDir) || !is_readable($fullDir)) {
	$selectedDir = '.';
	$fullDir = $baseDir;
}

// Find .m3u files in selected directory
$files = glob($fullDir . '/*.m3u') ?: [];
sort($files);

// Scan for subdirectories (one level deep for simplicity)
function scan_directories($baseDir, $maxDepth = 2)
{
	$dirs = ['.'];  // Always include current directory

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);
	$iterator->setMaxDepth($maxDepth);

	foreach ($iterator as $item) {
		if ($item->isDir()) {
			$relativePath = str_replace($baseDir . '/', '', $item->getPathname());
			// Skip hidden directories and system directories
			if (!str_starts_with(basename($relativePath), '.')) {
				$dirs[] = $relativePath;
			}
		}
	}

	sort($dirs);
	return $dirs;
}

$availableDirs = scan_directories($baseDir, 2);
function file_label(string $path): string
{
	$base = basename($path);
	$size = @filesize($path);
	$sizeTxt = $size !== false ? number_format($size / 1024 / 1024, 2) . ' MB' : '—';
	$mtime = @filemtime($path);
	$mtimeTxt = $mtime ? date('Y-m-d H:i:s', $mtime) : '—';
	return "{$base}  ({$sizeTxt}, updated {$mtimeTxt})";
}

/* ============================================================================
HANDLE FORM SUBMISSIONS
============================================================================ */

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// Validate CSRF token
	require_csrf();

	if (isset($_POST['action'])) {
		$pdo = get_db_connection();

		switch ($_POST['action']) {
			case 'update_settings':
				try {
					$settings_to_update = [
						'stream_host' => sanitize_input($_POST['stream_host'] ?? ''),
						'app_timezone' => sanitize_input($_POST['app_timezone'] ?? 'America/New_York'),
						'batch_size' => (int)($_POST['batch_size'] ?? 50),
						'lock_minutes' => (int)($_POST['lock_minutes'] ?? 10),
						'ok_recheck_hours' => (int)($_POST['ok_recheck_hours'] ?? 72),
						'fail_retry_min' => (int)($_POST['fail_retry_min'] ?? 30),
						'fail_retry_max' => (int)($_POST['fail_retry_max'] ?? 360)
					];

					$stmt = $pdo->prepare("
                        INSERT INTO settings (setting_key, setting_value)
                        VALUES (:key, :value)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                    ");

					foreach ($settings_to_update as $key => $value) {
						$stmt->execute(['key' => $key, 'value' => $value]);
					}

					$success = 'Settings updated successfully';
				} catch (Exception $e) {
					$error = 'Failed to update settings: ' . $e->getMessage();
				}
				break;

			case 'change_password':
				$current_password = $_POST['current_password'] ?? '';
				$new_password = $_POST['new_password'] ?? '';
				$confirm_password = $_POST['confirm_password'] ?? '';

				if (empty($current_password) || empty($new_password)) {
					$error = 'All password fields are required';
				} elseif ($new_password !== $confirm_password) {
					$error = 'New passwords do not match';
				} elseif (strlen($new_password) < 8) {
					$error = 'Password must be at least 8 characters';
				} else {
					try {
						// Verify current password
						$stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
						$stmt->execute(['id' => get_user_id()]);
						$user = $stmt->fetch();

						if ($user && password_verify($current_password, $user['password_hash'])) {
							if (update_password($pdo, get_user_id(), $new_password)) {
								$success = 'Password changed successfully';
							} else {
								$error = 'Failed to update password';
							}
						} else {
							$error = 'Current password is incorrect';
						}
					} catch (Exception $e) {
						$error = 'Password change failed: ' . $e->getMessage();
					}
				}
				$tab = 'account';
				break;

			case 'create_user':
				$new_username = $_POST['new_username'] ?? '';
				$new_password = $_POST['new_password'] ?? '';
				$new_password_confirm = $_POST['new_password_confirm'] ?? '';
				$new_email = $_POST['new_email'] ?? '';

				if (empty($new_username) || empty($new_password)) {
					$error = 'Username and password are required';
				} elseif ($new_password !== $new_password_confirm) {
					$error = 'Passwords do not match';
				} elseif (strlen($new_password) < 8) {
					$error = 'Password must be at least 8 characters';
				} elseif (!validate_username($new_username)) {
					$error = 'Username must be 3-50 characters and contain only letters, numbers, underscores, or dashes';
				} else {
					try {
						// Check if username already exists
						$check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
						$check->execute([$new_username]);
						if ($check->fetchColumn() > 0) {
							$error = "Username '$new_username' already exists";
						} else {
							$hash = password_hash($new_password, PASSWORD_DEFAULT);
							$stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)");
							$stmt->execute([$new_username, $hash, $new_email]);
							$success = "User '$new_username' created successfully";
						}
					} catch (Exception $e) {
						$error = 'Failed to create user: ' . $e->getMessage();
					}
				}
				$tab = 'users';
				break;

			case 'reset_lockout':
				$username = $_POST['username'] ?? '';
				if (empty($username)) {
					$error = 'Username is required';
				} else {
					try {
						// Delete failed login attempts for this username
						$stmt = $pdo->prepare("DELETE FROM login_attempts WHERE username = ? AND success = 0");
						$stmt->execute([$username]);
						$deleted = $stmt->rowCount();
						$success = "Reset lockout for '$username' - cleared $deleted failed attempt(s)";
					} catch (Exception $e) {
						$error = 'Failed to reset lockout: ' . $e->getMessage();
					}
				}
				$tab = 'users';
				break;

			case 'delete_user':
				$user_id = (int)($_POST['user_id'] ?? 0);
				$current_user_id = get_user_id();

				if ($user_id === $current_user_id) {
					$error = 'Cannot delete your own account';
				} elseif ($user_id <= 0) {
					$error = 'Invalid user ID';
				} else {
					try {
						// Get the first user created (by lowest ID or earliest created_at)
						$first_user_stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
						$first_user = $first_user_stmt->fetch();
						$first_user_id = $first_user['id'] ?? null;

						if ($user_id === $first_user_id) {
							$error = 'Cannot delete the primary user account created';
						} else {
							// Get username for confirmation message
							$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
							$stmt->execute([$user_id]);
							$user = $stmt->fetch();

							if ($user) {
								// Delete user
								$stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
								$stmt->execute([$user_id]);
								$success = "User '{$user['username']}' deleted successfully";
							} else {
								$error = 'User not found';
							}
						}
					} catch (Exception $e) {
						$error = 'Failed to delete user: ' . $e->getMessage();
					}
				}
				$tab = 'users';
				break;

			case 'update_database':
				try {
					$new_config = [
						'host' => sanitize_input($_POST['db_host'] ?? ''),
						'port' => (int)($_POST['db_port'] ?? 3306),
						'name' => sanitize_input($_POST['db_name'] ?? ''),
						'user' => sanitize_input($_POST['db_user'] ?? ''),
						'charset' => 'utf8mb4'
					];

					// Only update password if provided
					if (!empty($_POST['db_pass'])) {
						$new_config['pass'] = $_POST['db_pass']; // Don't sanitize passwords
					} else {
						// Read current password from .db_bootstrap (NOT from settings table)
						$bootstrap_file = __DIR__ . '/.db_bootstrap';
						if (file_exists($bootstrap_file)) {
							$current = json_decode(file_get_contents($bootstrap_file), true);
							$new_config['pass'] = $current['pass'] ?? '';
						}
					}

					// Test new connection
					$test_dsn = sprintf(
						'mysql:host=%s;port=%d;dbname=%s;charset=%s',
						$new_config['host'],
						$new_config['port'],
						$new_config['name'],
						$new_config['charset']
					);

					$test_pdo = new PDO($test_dsn, $new_config['user'], $new_config['pass'], [
						PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
					]);

					// save database connection information
					file_put_contents(__DIR__ . '/.db_bootstrap', json_encode($new_config, JSON_PRETTY_PRINT));
					chmod(__DIR__ . '/.db_bootstrap', 0600);

					$success = 'Database settings updated successfully';
				} catch (PDOException $e) {
					$error = 'Database connection test failed: ' . $e->getMessage();
				} catch (Exception $e) {
					$error = 'Failed to update database settings: ' . $e->getMessage();
				}
				$tab = 'database';
				break;

			case 'rotate_creds':
				$newUser = sanitize_input($_POST['username'] ?? '');
				$newPass = $_POST['password'] ?? ''; // Don't sanitize passwords
				$forceDue = isset($_POST['force_due']) && $_POST['force_due'] === '1';

				if ($newUser === '' || $newPass === '') {
					$error = 'Username and password are required.';
				} elseif (!preg_match('/^[A-Za-z0-9]+$/', $newUser) || !preg_match('/^[A-Za-z0-9]+$/', $newPass)) {
					$error = 'Username/password must be alphanumeric (no slashes/spaces).';
				} else {
					try {
						$host = get_setting('stream_host', 'http://localhost');
						$pattern = '(/live/)[^/]+/[^/]+/';

						$pdo->beginTransaction();

						// Update URLs + url_display + url_hash
						$sql = "
							UPDATE feeds
							SET
								url = REGEXP_REPLACE(url, :pat1, CONCAT('\\\\1', :u1, '/', :p1, '/')),
								url_display = REGEXP_REPLACE(
									COALESCE(url_display, url),
									:pat2,
									'/live/***/***/'
								),
								url_hash = SHA1(
									REGEXP_REPLACE(url, :pat3, CONCAT('\\\\1', :u2, '/', :p2, '/'))
								)
							WHERE
								url LIKE :like1
								OR url LIKE :like2
						";
						$st = $pdo->prepare($sql);
						$st->execute([
							':pat1' => $pattern,
							':u1'   => $newUser,
							':p1'   => $newPass,
							':pat2' => $pattern,
							':pat3' => $pattern,
							':u2'   => $newUser,
							':p2'   => $newPass,
							':like1' => $host . '/live/%',
							':like2' => str_replace('http://', 'https://', $host) . '/live/%',
						]);
						$affectedFeeds = $st->rowCount();

						// Force everything to be due now if requested
						$affectedQueue = 0;
						if ($forceDue) {
							$st2 = $pdo->prepare("
								UPDATE feed_check_queue q
								JOIN feeds f ON f.id = q.feed_id
								SET q.next_run_at = NOW(),
									q.locked_at = NULL
								WHERE f.url LIKE :like1 OR f.url LIKE :like2
							");
							$st2->execute([
								':like1' => $host . '/live/%',
								':like2' => str_replace('http://', 'https://', $host) . '/live/%',
							]);
							$affectedQueue = $st2->rowCount();
						}

						$pdo->commit();
						$success = "Updated {$affectedFeeds} feeds. " . ($forceDue ? "Forced {$affectedQueue} queued items due now." : "Queue items unchanged.");
					} catch (Throwable $e) {
						$pdo->rollBack();
						$error = 'Failed to rotate credentials: ' . $e->getMessage();
					}
				}
				$tab = 'creds';
				break;
		}
	}
}

/* ============================================================================
GET SAVED CONFIG FORM DATABASE
============================================================================ */

$settings = get_all_settings();
$settings_map = [];
foreach ($settings as $setting) {
	$settings_map[$setting['setting_key']] = $setting['setting_value'];
}

// Load database credentials from .db_bootstrap file (not from settings table)
$bootstrap_file = __DIR__ . '/.db_bootstrap';
if (file_exists($bootstrap_file)) {
	try {
		$db_config = json_decode(file_get_contents($bootstrap_file), true);
		if ($db_config && is_array($db_config)) {
			$settings_map['db_host'] = (string)($db_config['host'] ?? '');
			$settings_map['db_port'] = (string)($db_config['port'] ?? '3306');
			$settings_map['db_name'] = (string)($db_config['name'] ?? '');
			$settings_map['db_user'] = (string)($db_config['user'] ?? '');
			// Do NOT store db_pass in settings_map for security
		}
	} catch (Exception $e) {
		// Silently fail - form will show empty values
		$settings_map['db_host'] = '';
		$settings_map['db_port'] = '3306';
		$settings_map['db_name'] = '';
		$settings_map['db_user'] = '';
	}
}

?>

<style>
	.tabs {
		background: var(--bg-card);
		border-radius: 8px;
		box-shadow: 0 2px 4px var(--shadow);
		overflow: hidden;
	}

	.tab-nav {
		display: flex;
		background: var(--bg-secondary);
		border-bottom: 1px solid var(--border-color);
	}

	.tab-link {
		padding: 15px 25px;
		color: var(--text-secondary);
		text-decoration: none;
		border-bottom: 3px solid transparent;
		transition: all 0.2s;
	}

	.tab-link:hover {
		background: var(--table-hover);
		color: var(--text-primary);
	}

	.tab-link.active {
		color: #667eea;
		border-bottom-color: #667eea;
		background: var(--bg-card);
	}

	.tab-content {
		padding: 30px;
	}

	.alert {
		padding: 12px 16px;
		border-radius: 6px;
		margin-bottom: 20px;
	}

	.alert-success {
		background: #d4edda;
		color: #155724;
		border: 1px solid #c3e6cb;
	}

	[data-bs-theme="dark"] .alert-success {
		background: #1a3a2a;
		color: #75b798;
		border: 1px solid #2d5a3f;
	}

	.alert-danger {
		background: #f8d7da;
		color: #721c24;
		border: 1px solid #f5c6cb;
	}

	[data-bs-theme="dark"] .alert-danger {
		background: #3a1a1e;
		color: #e89aa3;
		border: 1px solid #5a2a2f;
	}

	.form-group {
		margin-bottom: 20px;
	}

	.form-group label {
		display: block;
		margin-bottom: 6px;
		font-weight: 500;
		color: var(--text-primary);
	}

	.form-group input,
	.form-group select {
		width: 100%;
		padding: 10px 12px;
		border: 1px solid var(--border-color);
		border-radius: 6px;
		font-size: 14px;
		background: var(--bg-card);
		color: var(--text-primary);
	}

	.form-group input:focus,
	.form-group select:focus {
		outline: none;
		border-color: #667eea;
		box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
		background: var(--bg-card);
		color: var(--text-primary);
	}

	.form-group small {
		display: block;
		margin-top: 4px;
		color: var(--text-secondary);
		font-size: 12px;
	}

	.two-col {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 20px;
	}

	h2 {
		margin-bottom: 20px;
		color: var(--text-primary);
		font-size: 20px;
	}

	h3 {
		margin: 30px 0 15px;
		color: var(--text-primary);
		font-size: 16px;
	}

	.settings-info {
		background: #e7f3ff;
		border: 1px solid #b3d9ff;
		color: #004085;
		padding: 15px;
		border-radius: 6px;
		margin-bottom: 20px;
	}

	[data-bs-theme="dark"] .settings-info {
		background: #1a2a3a;
		border: 1px solid #2d4a5f;
		color: #6ea8fe;
	}

	.admin_section {
		margin-bottom: 20pt;
	}

	@media (max-width: 768px) {
		.two-col {
			grid-template-columns: 1fr;
		}

		.header {
			flex-direction: column;
			gap: 15px;
			text-align: center;
		}
	}
</style>


<div class="row">
	<div class="header">
		<h2><i class="fa-solid fa-gear me-1"></i> Administration</h2>
	</div>

	<?php if ($success): ?>
		<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
	<?php endif; ?>

	<?php if ($error): ?>
		<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
	<?php endif; ?>

	<div class="tabs">
		<div class="tab-nav">
			<a href="admin.php?tab=settings" class="tab-link <?php echo $tab === 'settings' ? 'active' : ''; ?>">
				Application Settings
			</a>
			<a href="admin.php?tab=playlist" class="tab-link <?php echo $tab === 'playlist' ? 'active' : ''; ?>">
				Sync Playlist
			</a>
			<a href="admin.php?tab=creds" class="tab-link <?php echo $tab === 'creds' ? 'active' : ''; ?>">
				Update Stream Credentials
			</a>
			<a href="admin.php?tab=users" class="tab-link <?php echo $tab === 'users' ? 'active' : ''; ?>">
				User Management
			</a>
			<a href="admin.php?tab=database" class="tab-link <?php echo $tab === 'database' ? 'active' : ''; ?>">
				Database
			</a>
			<a href="admin.php?tab=account" class="tab-link <?php echo $tab === 'account' ? 'active' : ''; ?>">
				Change Password
			</a>
		</div>

		<div class="tab-content">
			<?php if ($tab === 'settings'): ?>
				<h2 class="admin_section"><i class="fa-solid fa-gears me-1"></i> Application Settings</h2>

				<form method="post" action="admin.php?tab=settings">
					<input type="hidden" name="action" value="update_settings">
					<?php echo csrf_field(); ?>

					<div class="form-group">
						<label for="stream_host">Stream Host *</label>
						<input type="text" id="stream_host" name="stream_host"
							value="<?php echo htmlspecialchars($settings_map['stream_host'] ?? ''); ?>" required>
						<small>Base URL for stream authentication (no trailing slash).</small>
					</div>

					<div class="form-group">
						<label for="app_timezone">Timezone *</label>
						<select id="app_timezone" name="app_timezone" required>
							<?php
							$timezones = [
								'US & Canada' => [
									'America/New_York' => 'Eastern Time',
									'America/Chicago' => 'Central Time',
									'America/Denver' => 'Mountain Time',
									'America/Phoenix' => 'Arizona',
									'America/Los_Angeles' => 'Pacific Time',
									'America/Anchorage' => 'Alaska',
									'Pacific/Honolulu' => 'Hawaii',
									'America/Toronto' => 'Toronto',
									'America/Vancouver' => 'Vancouver',
								],
								'Europe' => [
									'Europe/London' => 'London',
									'Europe/Paris' => 'Paris',
									'Europe/Berlin' => 'Berlin',
									'Europe/Madrid' => 'Madrid',
									'Europe/Rome' => 'Rome',
									'Europe/Amsterdam' => 'Amsterdam',
									'Europe/Brussels' => 'Brussels',
									'Europe/Vienna' => 'Vienna',
									'Europe/Stockholm' => 'Stockholm',
									'Europe/Warsaw' => 'Warsaw',
									'Europe/Moscow' => 'Moscow',
									'Europe/Istanbul' => 'Istanbul',
								],
								'Asia' => [
									'Asia/Dubai' => 'Dubai',
									'Asia/Karachi' => 'Karachi',
									'Asia/Kolkata' => 'Kolkata',
									'Asia/Bangkok' => 'Bangkok',
									'Asia/Singapore' => 'Singapore',
									'Asia/Hong_Kong' => 'Hong Kong',
									'Asia/Shanghai' => 'Shanghai',
									'Asia/Tokyo' => 'Tokyo',
									'Asia/Seoul' => 'Seoul',
								],
								'Australia' => [
									'Australia/Perth' => 'Perth',
									'Australia/Adelaide' => 'Adelaide',
									'Australia/Brisbane' => 'Brisbane',
									'Australia/Sydney' => 'Sydney',
									'Australia/Melbourne' => 'Melbourne',
								],
								'Latin America' => [
									'America/Mexico_City' => 'Mexico City',
									'America/Bogota' => 'Bogota',
									'America/Lima' => 'Lima',
									'America/Santiago' => 'Santiago',
									'America/Buenos_Aires' => 'Buenos Aires',
									'America/Sao_Paulo' => 'São Paulo',
								],
								'Africa' => [
									'Africa/Cairo' => 'Cairo',
									'Africa/Johannesburg' => 'Johannesburg',
									'Africa/Lagos' => 'Lagos',
									'Africa/Nairobi' => 'Nairobi',
								],
								'Other' => [
									'UTC' => 'UTC',
								],
							];

							$current_tz = $settings_map['app_timezone'] ?? 'America/New_York';

							foreach ($timezones as $region => $tz_list) {
								echo '<optgroup label="' . htmlspecialchars($region) . '">';
								foreach ($tz_list as $tz => $label) {
									$selected = $tz === $current_tz ? 'selected' : '';
									echo '<option value="' . htmlspecialchars($tz) . '" ' . $selected . '>' . htmlspecialchars($label) . '</option>';
								}
								echo '</optgroup>';
							}
							?>
						</select>
					</div>

					<h3>Monitoring Settings</h3>

					<div class="two-col">
						<div class="form-group">
							<label for="batch_size">Batch Size *</label>
							<input type="number" id="batch_size" name="batch_size"
								value="<?php echo htmlspecialchars($settings_map['batch_size'] ?? 50); ?>"
								min="1" max="500" required>
							<small>Feeds processed per cron run</small>
						</div>

						<div class="form-group">
							<label for="lock_minutes">Lock Duration (minutes) *</label>
							<input type="number" id="lock_minutes" name="lock_minutes"
								value="<?php echo htmlspecialchars($settings_map['lock_minutes'] ?? 10); ?>"
								min="1" max="60" required>
							<small>Prevent concurrent processing</small>
						</div>
					</div>

					<div class="form-group">
						<label for="ok_recheck_hours">Recheck Healthy Feeds (hours) *</label>
						<input type="number" id="ok_recheck_hours" name="ok_recheck_hours"
							value="<?php echo htmlspecialchars($settings_map['ok_recheck_hours'] ?? 72); ?>"
							min="1" max="720" required>
						<small>How long to wait before rechecking working streams</small>
					</div>

					<div class="two-col">
						<div class="form-group">
							<label for="fail_retry_min">Min Retry Interval (minutes) *</label>
							<input type="number" id="fail_retry_min" name="fail_retry_min"
								value="<?php echo htmlspecialchars($settings_map['fail_retry_min'] ?? 30); ?>"
								min="1" max="1440" required>
							<small>Initial retry interval for failed feeds</small>
						</div>

						<div class="form-group">
							<label for="fail_retry_max">Max Retry Interval (minutes) *</label>
							<input type="number" id="fail_retry_max" name="fail_retry_max"
								value="<?php echo htmlspecialchars($settings_map['fail_retry_max'] ?? 360); ?>"
								min="1" max="1440" required>
							<small>Maximum retry interval cap</small>
						</div>
					</div>

					<div style="margin-top: 30px;">
						<button type="submit" class="btn btn-outline-primary btn-md">Save Settings</button>
					</div>
				</form>

			<?php elseif ($tab === 'database'): ?>
				<h2 class="admin_section"><i class="fa-solid fa-database me-1"></i> Database Configuration</h2>

				<div class="settings-info">
					<strong>⚠️ Warning:</strong> Changing these settings may break the application if incorrect.
					The connection will be tested before saving.
				</div>

				<form method="post" action="admin.php?tab=database">
					<input type="hidden" name="action" value="update_database">
					<?php echo csrf_field(); ?>

					<div class="two-col">
						<div class="form-group">
							<label for="db_host">Database Host *</label>
							<input type="text" id="db_host" name="db_host"
								value="<?php echo htmlspecialchars($settings_map['db_host'] ?? ''); ?>" required>
						</div>

						<div class="form-group">
							<label for="db_port">Database Port *</label>
							<input type="number" id="db_port" name="db_port"
								value="<?php echo htmlspecialchars($settings_map['db_port'] ?? '3306'); ?>" required>
						</div>
					</div>

					<div class="form-group">
						<label for="db_name">Database Name *</label>
						<input type="text" id="db_name" name="db_name"
							value="<?php echo htmlspecialchars($settings_map['db_name'] ?? ''); ?>" required>
					</div>

					<div class="two-col">
						<div class="form-group">
							<label for="db_user">Database User *</label>
							<input type="text" id="db_user" name="db_user"
								value="<?php echo htmlspecialchars($settings_map['db_user'] ?? ''); ?>" required>
						</div>

						<div class="form-group">
							<label for="db_pass">Database Password</label>
							<input type="password" id="db_pass" name="db_pass" placeholder="Leave blank to keep current">
							<small>Leave blank to keep current password</small>
						</div>
					</div>

					<div>
						<button type="submit" class="btn btn-outline-primary btn-md">Test & Save Database Settings</button>
					</div>
				</form>

			<?php elseif ($tab === 'account'): ?>
				<h2 class="admin_section"><i class="fa-solid fa-key me-1"></i> Change Password</h2>

				<div class="settings-info">
					Logged in as: <strong><?php echo htmlspecialchars(get_username()); ?></strong>
				</div>

				<h3>Change Password</h3>

				<form method="post" action="admin.php?tab=account">
					<input type="hidden" name="action" value="change_password">
					<?php echo csrf_field(); ?>

					<div class="form-group">
						<label for="current_password">Current Password *</label>
						<input type="password" id="current_password" name="current_password" required>
					</div>

					<div class="form-group">
						<label for="new_password">New Password *</label>
						<input type="password" id="new_password" name="new_password" required>
						<small>Minimum 8 characters</small>
					</div>

					<div class="form-group">
						<label for="confirm_password">Confirm New Password *</label>
						<input type="password" id="confirm_password" name="confirm_password" required>
					</div>

					<div style="margin-top: 30px;">
						<button type="submit" class="btn btn-md btn-outline-primary">Change Password</button>
					</div>
				</form>

			<?php elseif ($tab === 'playlist'): ?>

				<div class="d-flex justify-content-between align-items-center mb-3">
					<div>
						<h2 class="admin_section"><i class="fa-solid fa-rotate me-1"></i> Sync Playlist</h2>
						<div class="text-muted">
							Upload and sync your M3U playlist into the database. Only imports <strong>LIVE</strong> entries (URLs containing <code>/live/</code>).
						</div>
					</div>
				</div>

				<?php if ($flash): ?>
					<div class="alert alert-<?= h($flash['ok'] ? 'success' : 'danger') ?> shadow-sm">
						<div class="fw-semibold mb-1">
							<?= h($flash['ok'] ? 'Playlist processed successfully' : 'Playlist processing failed') ?>
						</div>
						<div class="small">
							<?= nl2br(h((string)($flash['message'] ?? ''))) ?>
						</div>

						<?php if (!empty($flash['stats']) && is_array($flash['stats'])): ?>
							<hr>
							<div class="row small">
								<?php foreach ($flash['stats'] as $k => $v): ?>
									<div class="col-md-4 mb-2">
										<div class="text-muted"><?= h((string)$k) ?></div>
										<div class="fw-semibold"><?= h((string)$v) ?></div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<!-- Upload Section -->
				<div class="card shadow-sm mb-3" id="upload-section">
					<div class="card-header fw-semibold"><i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Playlist</div>
					<div class="card-body">
						<div class="mb-3">
							<input type="file" id="playlist-file" accept=".m3u,.m3u8" style="display:none;">
							<button type="button" class="btn btn-primary" id="select-file-btn">
								<i class="fa-solid fa-file me-1"></i> Select Playlist File
							</button>
							<button type="button" class="btn btn-success" id="upload-btn" style="display:none;">
								<i class="fa-solid fa-upload me-1"></i> Upload Playlist
							</button>
							<span id="selected-file-name" class="ms-2 text-muted"></span>
						</div>

						<!-- Upload Progress -->
						<div id="upload-progress-container" style="display:none;">
							<div class="progress" style="height: 25px;">
								<div id="upload-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
									role="progressbar" style="width: 0%;">0%</div>
							</div>
							<div id="upload-status" class="text-muted small mt-2"></div>
						</div>

						<div class="alert alert-info small mt-3 mb-0">
							<i class="fa-solid fa-info-circle me-1"></i>
							Supports large playlist files (80MB+). Upload happens in the background with progress tracking.
						</div>
					</div>
				</div>

				<!-- Import Section -->
				<div class="card shadow-sm mb-3" id="import-section" style="display:none;">
					<div class="card-header fw-semibold"><i class="fa-solid fa-file-import me-1"></i> Import Playlist</div>
					<div class="card-body">

						<!-- Playlist Info -->
						<div id="playlist-info" class="alert alert-secondary">
							<!-- Populated by JavaScript -->
						</div>

						<div class="mb-3">
							<button type="button" class="btn btn-success" id="start-import-btn">
								<i class="fa-solid fa-arrows-rotate me-1"></i> Sync Playlist
							</button>
							<button type="button" class="btn btn-danger" id="remove-playlist-btn">
								<i class="fa-solid fa-trash me-1"></i> Remove Playlist
							</button>
						</div>

						<!-- Import Processing Indicator -->
						<div id="import-processing-container" style="display:none;" class="mt-4 text-center">
							<div class="mb-3">
								<i class="fa-solid fa-spinner fa-spin fa-3x text-primary"></i>
							</div>
							<div class="h5 text-muted">Processing playlist...</div>
							<div class="small text-muted">This may take a few moments for large playlists</div>
						</div>

						<div class="alert alert-warning small mt-3 mb-0" id="import-warning">
							<i class="fa-solid fa-triangle-exclamation me-1"></i>
							Processing may take a few moments. You'll see a summary when complete.
						</div>
					</div>
				</div>

				<!-- Import Results -->
				<div class="card shadow-sm" id="import-results" style="display:none;">
					<div class="card-header fw-semibold"><i class="fa-solid fa-check-circle me-1"></i> Import Complete</div>
					<div class="card-body">
						<div id="results-content"></div>
						<button type="button" class="btn btn-primary mt-3" id="new-import-btn">
							<i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Another Playlist
						</button>
					</div>
				</div>

				<script>
					(function() {
						const CHUNK_SIZE = 1024 * 1024;
						let selectedFile = null;

						if ($('#upload-section').length) {
							checkForPlaylist();
						}

						$('#select-file-btn').on('click', function() {
							$('#playlist-file').click();
						});

						$('#playlist-file').on('change', function(e) {
							selectedFile = e.target.files[0];
							if (selectedFile) {
								const ext = selectedFile.name.split('.').pop().toLowerCase();
								if (ext !== 'm3u' && ext !== 'm3u8') {
									alert('Please select a valid M3U or M3U8 file.');
									selectedFile = null;
									return;
								}
								$('#selected-file-name').text(selectedFile.name + ' (' + formatBytes(selectedFile.size) + ')');
								$('#upload-btn').show();
							}
						});

						$('#upload-btn').on('click', function() {
							if (!selectedFile) return;
							uploadPlaylist(selectedFile);
						});

						$('#start-import-btn').on('click', function() {
							startImport();
						});

						$('#remove-playlist-btn').on('click', function() {
							if (confirm('Remove uploaded playlist and start over?')) {
								$.ajax({
									url: 'delete_playlist.php',
									type: 'POST',
									dataType: 'json',
									success: function() {
										showUploadSection();
									},
									error: function() {
										alert('Failed to remove playlist. Please try again.');
									}
								});
							}
						});

						$('#new-import-btn').on('click', function() {
							window.location.reload();
						});

						function checkForPlaylist() {
							$.ajax({
								url: 'check_playlist.php',
								type: 'GET',
								dataType: 'json',
								success: function(response) {
									if (response.success && response.hasPlaylist) {
										showImportSection(response);
									} else {
										showUploadSection();
									}
								},
								error: function() {
									showUploadSection();
								}
							});
						}

						function uploadPlaylist(file) {
							const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
							let currentChunk = 0;

							$('#upload-btn').hide();
							$('#select-file-btn').prop('disabled', true);
							$('#upload-progress-container').show();
							updateUploadProgress(0, 'Starting upload...');

							function uploadNextChunk() {
								const start = currentChunk * CHUNK_SIZE;
								const end = Math.min(start + CHUNK_SIZE, file.size);
								const chunk = file.slice(start, end);

								const formData = new FormData();
								formData.append('file', chunk);
								formData.append('fileName', file.name);
								formData.append('chunkIndex', currentChunk);
								formData.append('totalChunks', totalChunks);

								$.ajax({
									url: 'upload_playlist.php',
									type: 'POST',
									data: formData,
									processData: false,
									contentType: false,
									success: function(response) {
										if (response.success) {
											currentChunk++;
											const progress = Math.round((currentChunk / totalChunks) * 100);
											updateUploadProgress(progress, 'Uploading... ' + progress + '%');

											if (currentChunk < totalChunks) {
												uploadNextChunk();
											} else {
												updateUploadProgress(100, 'Upload complete!');
												setTimeout(function() {
													checkForPlaylist();
												}, 500);
											}
										} else {
											alert('Upload failed: ' + response.error);
											resetUploadUI();
										}
									},
									error: function() {
										alert('Upload failed. Please try again.');
										resetUploadUI();
									}
								});
							}
							uploadNextChunk();
						}

						function startImport() {
							// Hide buttons and warning
							$('#start-import-btn').prop('disabled', true);
							$('#remove-playlist-btn').prop('disabled', true);
							$('#import-warning').hide();

							// Show spinner
							$('#import-processing-container').show();

							$.ajax({
								url: 'import_handler.php',
								type: 'POST',
								data: {
									mode: 'sync',
									_ajax: '1'
								},
								dataType: 'json',
								timeout: 300000, // 5 minute timeout
								success: function(response) {
									// Hide spinner
									$('#import-processing-container').hide();

									// Check if completed
									if (response.status === 'completed') {
										showImportResults(response);
									} else {
										// Unexpected response
										alert('Import returned unexpected status: ' + (response.status || 'unknown'));
										$('#start-import-btn').prop('disabled', false);
										$('#remove-playlist-btn').prop('disabled', false);
										$('#import-warning').show();
									}
								},
								error: function(xhr, status, error) {
									// Hide spinner
									$('#import-processing-container').hide();
									$('#start-import-btn').prop('disabled', false);
									$('#remove-playlist-btn').prop('disabled', false);
									$('#import-warning').show();

									if (status === 'timeout') {
										alert('Import timed out. Check your database - the import may have completed.');
									} else {
										alert('Import failed: ' + error);
									}
								}
							});
						}

						function showImportResults(response) {
							$('#import-section').hide();
							$('#import-results').show();

							let html = '<div class="alert alert-' + (response.ok ? 'success' : 'danger') + '">';
							html += '<div class="fw-semibold mb-1">';
							html += response.ok ? '<i class="fa-solid fa-check-circle me-1"></i> Import Completed' : '<i class="fa-solid fa-times-circle me-1"></i> Import Failed';
							html += '</div>';

							if (response.message) {
								html += '<div class="small">' + response.message + '</div>';
							}

							if (response.stats) {
								html += '<hr><div class="row small">';
								for (let key in response.stats) {
									html += '<div class="col-md-4 mb-2">';
									html += '<div class="text-muted">' + key + '</div>';
									html += '<div class="fw-semibold">' + response.stats[key] + '</div>';
									html += '</div>';
								}
								html += '</div>';
							}
							html += '</div>';
							$('#results-content').html(html);

							// Delete playlist after showing results
							if (response.ok) {
								deletePlaylist();
							}
						}

						function deletePlaylist() {
							$.ajax({
								url: 'delete_playlist.php',
								type: 'POST',
								dataType: 'json'
							});
						}

						function showUploadSection() {
							$('#upload-section').show();
							$('#import-section').hide();
							$('#import-results').hide();
							resetUploadUI();
						}

						function showImportSection(playlistData) {
							$('#upload-section').hide();
							$('#import-section').show();
							$('#import-results').hide();
							$('#import-processing-container').hide();
							$('#import-warning').show();
							$('#start-import-btn').prop('disabled', false);
							$('#remove-playlist-btn').prop('disabled', false);

							const html = '<p class="mb-1"><i class="fa-solid fa-file me-1"></i> <strong>Filename:</strong> ' + playlistData.filename + '</p>' +
								'<p class="mb-1"><i class="fa-solid fa-database me-1"></i> <strong>Size:</strong> ' + playlistData.sizeFormatted + '</p>' +
								'<p class="mb-0"><i class="fa-solid fa-clock me-1"></i> <strong>Uploaded:</strong> ' + playlistData.uploaded + '</p>';
							$('#playlist-info').html(html);
						}

						function updateUploadProgress(percent, status) {
							$('#upload-progress-bar').css('width', percent + '%').text(percent + '%');
							$('#upload-status').text(status);
						}

						function resetUploadUI() {
							selectedFile = null;
							$('#playlist-file').val('');
							$('#selected-file-name').text('');
							$('#upload-btn').hide();
							$('#select-file-btn').prop('disabled', false);
							$('#upload-progress-container').hide();
							updateUploadProgress(0, '');
						}

						function formatBytes(bytes) {
							if (bytes === 0) return '0 Bytes';
							const k = 1024;
							const sizes = ['Bytes', 'KB', 'MB', 'GB'];
							const i = Math.floor(Math.log(bytes) / Math.log(k));
							return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
						}
					})();
				</script>

			<?php elseif ($tab === 'creds'): ?>

				<h2 class="admin_section"><i class="fa-solid fa-user-gear me-1"></i> Update Saved Stream Credentials</h2>

				<div class="settings-info">
					This updates all <code>/live/{user}/{pass}/</code> URLs that start with <code><?php echo htmlspecialchars(get_setting('stream_host', '')); ?></code>,
					regenerates <code>url_hash</code>, and keeps <code>url_display</code> masked.
				</div>

				<form method="post" action="admin.php?tab=creds">
					<input type="hidden" name="action" value="rotate_creds">
					<?php echo csrf_field(); ?>

					<div class="two-col">
						<div class="form-group">
							<label for="username">New Username *</label>
							<input type="text" id="username" name="username" required autocomplete="off" pattern="[A-Za-z0-9]+" title="Alphanumeric only">
							<small>Alphanumeric only (no slashes/spaces)</small>
						</div>

						<div class="form-group">
							<label for="password">New Password *</label>
							<input type="text" id="password" name="password" required autocomplete="off" pattern="[A-Za-z0-9]+" title="Alphanumeric only">
							<small>Alphanumeric only (no slashes/spaces)</small>
						</div>
					</div>

					<div class="form-group">
						<div style="display: flex; align-items: center; gap: 8px;">
							<input type="checkbox" name="force_due" value="1" id="force_due" checked style="width: auto; margin: 0;">
							<label for="force_due" style="margin: 0; font-weight: normal; cursor: pointer;">
								Force recheck now (sets all affected feeds to be checked immediately)
							</label>
						</div>
					</div>

					<div style="margin-top: 30px;">
						<button type="submit" class="btn btn-md btn-outline-primary">Rotate Credentials</button>
					</div>
				</form>

			<?php elseif ($tab === 'users'): ?>
				<h2 class="admin_section"><i class="fa-solid fa-users me-1"></i> User Management</h2>

				<?php
				// Get all users with their failed login attempt counts
				$pdo = get_db_connection();

				// Get first user ID
				$first_user_stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
				$first_user = $first_user_stmt->fetch();
				$first_user_id = $first_user['id'] ?? null;

				$stmt = $pdo->query("
					SELECT 
						u.id,
						u.username,
						u.last_login,
						u.created_at,
						COALESCE(
							(SELECT COUNT(*) 
							 FROM login_attempts la 
							 WHERE la.username = u.username 
							 AND la.success = 0 
							 AND la.attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
							), 0
						) as failed_attempts
					FROM users u
					ORDER BY u.id ASC
				");
				$users = $stmt->fetchAll();
				$current_user_id = get_user_id();
				?>

				<!-- User List -->
				<div class="card mb-4">
					<div class="card-header fw-semibold">
						<i class="fa-solid fa-list me-1"></i> All Users
					</div>
					<div class="card-body">
						<?php if (count($users) === 0): ?>
							<div class="alert alert-info">No users found.</div>
						<?php else: ?>
							<div class="table-responsive">
								<table class="table table-hover">
									<thead>
										<tr>
											<th>Username</th>
											<th>Last Login</th>
											<th>Invalid Login Attempts</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($users as $user): ?>
											<tr>
												<td>
													<strong><?php echo htmlspecialchars($user['username']); ?></strong>
													<?php if ($user['id'] == $current_user_id): ?>
														<span class="badge bg-primary ms-1">You</span>
													<?php endif; ?>
													<?php if ($user['id'] == $first_user_id): ?>
														<span class="badge bg-success ms-1">Primary User</span>
													<?php endif; ?>
												</td>
												<td>
													<?php
													if ($user['last_login']) {
														echo date('Y-m-d H:i:s', strtotime($user['last_login']));
													} else {
														echo '<span class="text-muted">Never</span>';
													}
													?>
												</td>
												<td>
													<?php if ($user['failed_attempts'] > 0): ?>
														<span class="badge bg-danger"><?php echo $user['failed_attempts']; ?></span>
														<a href="#" class="ms-2 small" onclick="event.preventDefault(); document.getElementById('viewAttemptsModal-<?php echo $user['id']; ?>').style.display='block';">
															View Log
														</a>
													<?php else: ?>
														<span class="text-success">0</span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ($user['failed_attempts'] > 0): ?>
														<form method="post" action="admin.php?tab=users" style="display:inline;">
															<input type="hidden" name="action" value="reset_lockout">
															<input type="hidden" name="username" value="<?php echo htmlspecialchars($user['username']); ?>">
															<?php echo csrf_field(); ?>
															<button type="submit" class="btn btn-sm btn-warning" title="Reset Lockout">
																<i class="fa-solid fa-unlock"></i> Reset Lockout
															</button>
														</form>
													<?php endif; ?>

													<?php if ($user['id'] != $current_user_id && $user['id'] != $first_user_id): ?>
														<button type="button" class="btn btn-sm btn-danger ms-1"
															onclick="document.getElementById('deleteModal-<?php echo $user['id']; ?>').style.display='block';"
															title="Delete User">
															<i class="fa-solid fa-trash"></i> Delete
														</button>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>

							<!-- Modals-->
							<?php foreach ($users as $user): ?>
								<!-- View Attempts Modal -->
								<?php if ($user['failed_attempts'] > 0): ?>
									<div id="viewAttemptsModal-<?php echo $user['id']; ?>" class="modal" style="display:none; position:fixed; z-index:1050; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4);">
										<div class="modal-dialog" style="margin:50px auto; max-width:600px;">
											<div class="modal-content" style="border-radius:8px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
												<div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; padding-bottom:15px; border-bottom:1px solid #dee2e6;">
													<h5 class="modal-title" style="margin:0;">Failed Login Attempts - <?php echo htmlspecialchars($user['username']); ?></h5>
													<button type="button" class="btn-close" onclick="document.getElementById('viewAttemptsModal-<?php echo $user['id']; ?>').style.display='none';" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
												</div>
												<div class="modal-body">
													<?php
													$attempts_stmt = $pdo->prepare("
													SELECT ip_address, attempted_at 
													FROM login_attempts 
													WHERE username = ? 
													AND success = 0 
													AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
													ORDER BY attempted_at DESC
												");
													$attempts_stmt->execute([$user['username']]);
													$attempts = $attempts_stmt->fetchAll();
													?>
													<?php if (count($attempts) > 0): ?>
														<table class="table table-sm">
															<thead>
																<tr>
																	<th>IP Address</th>
																	<th>Attempted At</th>
																</tr>
															</thead>
															<tbody>
																<?php foreach ($attempts as $attempt): ?>
																	<tr>
																		<td><?php echo htmlspecialchars($attempt['ip_address']); ?></td>
																		<td><?php echo date('Y-m-d H:i:s', strtotime($attempt['attempted_at'])); ?></td>
																	</tr>
																<?php endforeach; ?>
															</tbody>
														</table>
														<small class="text-muted">Showing attempts from last 15 minutes</small>
													<?php else: ?>
														<p>No failed attempts in the last 15 minutes.</p>
													<?php endif; ?>
												</div>
												<div class="modal-footer" style="margin-top:15px; padding-top:15px; border-top:1px solid #dee2e6;">
													<button type="button" class="btn btn-secondary" onclick="document.getElementById('viewAttemptsModal-<?php echo $user['id']; ?>').style.display='none';">Close</button>
												</div>
											</div>
										</div>
									</div>
								<?php endif; ?>

								<!-- Delete Confirmation Modal -->
								<?php if ($user['id'] != $current_user_id && $user['id'] != $first_user_id): ?>
									<div id="deleteModal-<?php echo $user['id']; ?>" class="modal" style="display:none; position:fixed; z-index:1050; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4);">
										<div class="modal-dialog" style="margin:100px auto; max-width:500px;">
											<div class="modal-content" style="border-radius:8px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
												<div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; padding-bottom:15px; border-bottom:1px solid #dee2e6;">
													<h5 class="modal-title" style="margin:0;">Confirm Delete</h5>
													<button type="button" class="btn-close" onclick="document.getElementById('deleteModal-<?php echo $user['id']; ?>').style.display='none';" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
												</div>
												<div class="modal-body">
													<p>Are you sure you want to delete user <strong><?php echo htmlspecialchars($user['username']); ?></strong>?</p>
													<p class="text-danger"><i class="fa-solid fa-triangle-exclamation"></i> This action cannot be undone.</p>
												</div>
												<div class="modal-footer" style="margin-top:15px; padding-top:15px; border-top:1px solid #dee2e6; display:flex; gap:10px; justify-content:flex-end;">
													<button type="button" class="btn btn-secondary" onclick="document.getElementById('deleteModal-<?php echo $user['id']; ?>').style.display='none';">Cancel</button>
													<form method="post" action="admin.php?tab=users" style="display:inline; margin:0;">
														<input type="hidden" name="action" value="delete_user">
														<input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
														<?php echo csrf_field(); ?>
														<button type="submit" class="btn btn-danger">
															<i class="fa-solid fa-trash"></i> Delete User
														</button>
													</form>
												</div>
											</div>
										</div>
									</div>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<!-- Create New User -->
				<div class="card">
					<div class="card-header fw-semibold">
						<i class="fa-solid fa-user-plus me-1"></i> Create New User
					</div>
					<div class="card-body">
						<form method="post" action="admin.php?tab=users">
							<input type="hidden" name="action" value="create_user">
							<?php echo csrf_field(); ?>

							<div class="form-group">
								<label for="new_username">Username *</label>
								<input type="text" id="new_username" name="new_username" required
									pattern="[a-zA-Z0-9_-]{3,50}"
									title="3-50 characters, alphanumeric, underscore, or dash only">
								<small>3-50 characters, alphanumeric, underscore, or dash only</small>
							</div>

							<div class="two-col">
								<div class="form-group">
									<label for="new_password">Password *</label>
									<input type="password" id="new_password" name="new_password" required minlength="8">
									<small>Minimum 8 characters</small>
								</div>

								<div class="form-group">
									<label for="new_password_confirm">Confirm Password *</label>
									<input type="password" id="new_password_confirm" name="new_password_confirm" required minlength="8">
									<small>Re-enter password</small>
								</div>
							</div>

							<div style="margin-top: 20px;">
								<button type="submit" class="btn btn-md btn-outline-primary">
									<i class="fa-solid fa-user-plus me-1"></i> Create User
								</button>
							</div>
						</form>

						<script>
							// Client-side password confirmation validation
							document.getElementById('new_password_confirm').addEventListener('input', function() {
								const password = document.getElementById('new_password').value;
								const confirm = this.value;
								if (password !== confirm) {
									this.setCustomValidity('Passwords do not match');
								} else {
									this.setCustomValidity('');
								}
							});
						</script>
					</div>
				</div>

			<?php endif; ?>
		</div>
	</div>
</div>

<?php require_once __DIR__ . '/_bottom.php'; ?>