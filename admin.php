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

			case 'create_association':
				$name = sanitize_input($_POST['association_name'] ?? '');
				if (empty($name)) {
					$error = 'Association name is required';
				} else {
					try {
						$stmt = $pdo->prepare("INSERT INTO group_associations (name) VALUES (?)");
						$stmt->execute([$name]);
						$success = "Association '$name' created successfully";
					} catch (Exception $e) {
						$error = 'Failed to create association: ' . $e->getMessage();
					}
				}
				$tab = 'associations';
				break;

			case 'update_association_name':
				$id = (int)($_POST['association_id'] ?? 0);
				$name = sanitize_input($_POST['association_name'] ?? '');
				if ($id <= 0 || empty($name)) {
					$error = 'Invalid association data';
				} else {
					try {
						$stmt = $pdo->prepare("UPDATE group_associations SET name = ? WHERE id = ?");
						$stmt->execute([$name, $id]);
						$success = 'Association name updated successfully';
					} catch (Exception $e) {
						$error = 'Failed to update association: ' . $e->getMessage();
					}
				}
				$tab = 'associations';
				break;

			case 'delete_association':
				$id = (int)($_POST['association_id'] ?? 0);
				if ($id <= 0) {
					$error = 'Invalid association ID';
				} else {
					try {
						$stmt = $pdo->prepare("DELETE FROM group_associations WHERE id = ?");
						$stmt->execute([$id]);
						$success = 'Association deleted successfully';
					} catch (Exception $e) {
						$error = 'Failed to delete association: ' . $e->getMessage();
					}
				}
				$tab = 'associations';
				break;

			case 'add_prefix':
				$id = (int)($_POST['association_id'] ?? 0);
				$prefix = sanitize_input($_POST['prefix'] ?? '');
				if ($id <= 0 || empty($prefix)) {
					$error = 'Invalid data';
				} else {
					try {
						$stmt = $pdo->prepare("INSERT IGNORE INTO group_association_prefixes (association_id, prefix) VALUES (?, ?)");
						$stmt->execute([$id, $prefix]);
						$success = 'Prefix added successfully';
					} catch (Exception $e) {
						$error = 'Failed to add prefix: ' . $e->getMessage();
					}
				}
				$tab = 'associations';
				break;

			case 'remove_prefix':
				$prefix_id = (int)($_POST['prefix_id'] ?? 0);
				if ($prefix_id <= 0) {
					$error = 'Invalid prefix ID';
				} else {
					try {
						$stmt = $pdo->prepare("DELETE FROM group_association_prefixes WHERE id = ?");
						$stmt->execute([$prefix_id]);
						$success = 'Prefix removed successfully';
					} catch (Exception $e) {
						$error = 'Failed to remove prefix: ' . $e->getMessage();
					}
				}
				$tab = 'associations';
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

// Get all group associations with their prefixes
// Force a fresh connection to avoid any caching issues
$pdo = null;
$pdo = get_db_connection();
$associations = [];
try {
	// First get all associations without the join
	$stmt = $pdo->query("SELECT id, name, created_at FROM group_associations ORDER BY name");
	$associations = $stmt->fetchAll(PDO::FETCH_ASSOC);

	// Then get prefix count for each
	foreach ($associations as &$assoc) {
		$stmt = $pdo->prepare("SELECT COUNT(*) FROM group_association_prefixes WHERE association_id = ?");
		$stmt->execute([$assoc['id']]);
		$assoc['prefix_count'] = (int)$stmt->fetchColumn();
	}
	unset($assoc); // Break reference

	// Get prefixes for each association
	foreach ($associations as &$assoc) {
		$stmt = $pdo->prepare("
			SELECT id, prefix 
			FROM group_association_prefixes 
			WHERE association_id = ? 
			ORDER BY prefix
		");
		$stmt->execute([$assoc['id']]);
		$assoc['prefixes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// Get array of prefix strings for easy checking
		$assoc['prefix_list'] = array_column($assoc['prefixes'], 'prefix');
	}
	unset($assoc); // Break reference - CRITICAL!
} catch (Exception $e) {
	// Silently fail
	$associations = [];
}

// Get all available prefixes from channels with counts
$available_prefixes = [];
try {
	$stmt = $pdo->query("
		SELECT 
			CONCAT(SUBSTRING_INDEX(group_title, '|', 1), '|') as prefix,
			COUNT(DISTINCT id) as channel_count
		FROM channels 
		WHERE group_title LIKE '%|%'
		GROUP BY prefix
		ORDER BY prefix
	");
	$available_prefixes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
	$available_prefixes = [];
}

?>

<style>
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

	@media (max-width: 992px) {
		.admin-container {
			flex-direction: column;
		}

		.admin-sidebar {
			position: static;
			flex: 0 0 auto;
		}

		.two-col {
			grid-template-columns: 1fr;
		}
	}
</style>


<div class="row">
	<div class="header mb-3">
		<h2><i class="fa-solid fa-gear me-1"></i> Administration</h2>
	</div>

	<?php if ($success): ?>
		<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
	<?php endif; ?>

	<?php if ($error): ?>
		<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
	<?php endif; ?>

	<div class="admin-container">
		<!-- Sidebar Navigation -->
		<div class="admin-sidebar">
			<h5>Settings</h5>
			<div class="sidebar-nav">
				<a href="admin.php?tab=settings" class="sidebar-link <?php echo $tab === 'settings' ? 'active' : ''; ?>">
					<i class="fa-solid fa-gears"></i>
					<span>Application Settings</span>
				</a>
				<a href="admin.php?tab=playlist" class="sidebar-link <?php echo $tab === 'playlist' ? 'active' : ''; ?>">
					<i class="fa-solid fa-list"></i>
					<span>Sync Playlist & EPG</span>
				</a>
				<a href="admin.php?tab=associations" class="sidebar-link <?php echo $tab === 'associations' ? 'active' : ''; ?>">
					<i class="fa-solid fa-diagram-project"></i>
					<span>Group Associations</span>
				</a>
				<a href="admin.php?tab=creds" class="sidebar-link <?php echo $tab === 'creds' ? 'active' : ''; ?>">
					<i class="fa-solid fa-key"></i>
					<span>Stream Credentials</span>
				</a>
				<a href="admin.php?tab=users" class="sidebar-link <?php echo $tab === 'users' ? 'active' : ''; ?>">
					<i class="fa-solid fa-users"></i>
					<span>User Management</span>
				</a>
				<a href="admin.php?tab=database" class="sidebar-link <?php echo $tab === 'database' ? 'active' : ''; ?>">
					<i class="fa-solid fa-database"></i>
					<span>Database</span>
				</a>
			</div>

			<div class="sidebar-divider"></div>

			<h5>Account</h5>
			<div class="sidebar-nav">
				<a href="admin.php?tab=account" class="sidebar-link password-link <?php echo $tab === 'account' ? 'active' : ''; ?>">
					<i class="fa-solid fa-lock"></i>
					<span>Change Password</span>
				</a>
			</div>
		</div>

		<!-- Main Content -->
		<div class="admin-content">
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
						<h2 class="admin_section"><i class="fa-solid fa-list me-1"></i> Sync Playlist & EPG Settings</h2>
						<div class="text-muted">
							Configure your playlist URL and sync your M3U playlist into the database. Only imports <strong>LIVE</strong> entries (URLs containing <code>/live/</code>).
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

				<?php
				$savedUrl = get_setting('playlist_url', '');
				$lastSyncDate = get_setting('last_sync_date', '');
				?>

				<!-- URL Configuration Section -->
				<div class="card shadow-sm mb-3" id="url-section">
					<div class="card-header fw-semibold"><i class="fa-solid fa-link me-1"></i> Playlist URL</div>
					<div class="card-body">
						<?php echo csrf_field(); ?>
						<?php if (empty($savedUrl)): ?>
							<!-- No URL saved yet -->
							<div class="mb-3">
								<label for="playlist-url" class="form-label">Enter Playlist URL</label>
								<input type="url" id="playlist-url" class="form-control" placeholder="https://example.com/playlist.m3u">
								<small class="text-muted">Public URL to your M3U/M3U8 playlist file</small>
							</div>
							<button type="button" class="btn btn-primary" id="save-url-btn">
								<i class="fa-solid fa-save me-1"></i> Save URL
							</button>
						<?php else: ?>
							<!-- URL saved -->
							<div class="mb-3">
								<label class="form-label">Current Playlist URL</label>
								<div class="input-group">
									<input type="text" class="form-control" value="<?= h($savedUrl) ?>" readonly>
									<button type="button" class="btn btn-outline-warning" id="change-url-btn">
										<i class="fa-solid fa-edit me-1"></i> Change URL
									</button>
								</div>
							</div>
							<?php if ($lastSyncDate): ?>
								<div class="alert alert-info small mb-3">
									<i class="fa-solid fa-clock me-1"></i> Last sync: <strong><?= h(date('Y-m-d H:i:s', strtotime($lastSyncDate))) ?></strong>
								</div>
							<?php endif; ?>
							<button type="button" class="btn btn-outline-success" id="fetch-playlist-btn">
								<i class="fa-solid fa-download me-1"></i> Fetch Playlist
							</button>
						<?php endif; ?>
					</div>
				</div>

				<!-- Fetch Progress -->
				<div class="card shadow-sm mb-3" id="fetch-progress-section" style="display:none;">
					<div class="card-header fw-semibold"><i class="fa-solid fa-download me-1"></i> Downloading Playlist</div>
					<div class="card-body">
						<div class="progress" style="height: 30px;">
							<div id="fetch-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
								role="progressbar" style="width: 0%;">0%</div>
						</div>
						<div id="fetch-progress-status" class="text-center text-muted mt-2 small">Initializing download...</div>
					</div>
				</div>

				<!-- Preview Section -->
				<div class="card shadow-sm mb-3" id="preview-section" style="display:none;">
					<div class="card-header fw-semibold"><i class="fa-solid fa-eye me-1"></i> Playlist Preview</div>
					<div class="card-body">
						<div id="playlist-stats" class="alert alert-secondary mb-3">
							<!-- Populated by JavaScript -->
						</div>

						<!-- Credentials Section -->
						<div id="credentials-section">
							<div class="alert alert-info">
								<div class="d-flex justify-content-between align-items-center">
									<div>
										<strong>Current Stream Credentials:</strong><br>
										<span class="text-muted">Username:</span> <code id="current-username">—</code><br>
										<span class="text-muted">Password:</span> <code id="current-password">—</code>
									</div>
									<button type="button" class="btn btn-sm btn-warning" id="edit-credentials-btn">
										<i class="fa-solid fa-key me-1"></i> Change Credentials
									</button>
								</div>
							</div>

							<!-- Credential Edit Form -->
							<div id="credentials-form" style="display:none;" class="mb-3">
								<div class="card">
									<div class="card-header fw-semibold">Update Stream Credentials</div>
									<div class="card-body">
										<div class="row">
											<div class="col-md-6 mb-3">
												<label for="new-username" class="form-label">New Username</label>
												<input type="text" id="new-username" class="form-control" placeholder="Enter username">
											</div>
											<div class="col-md-6 mb-3">
												<label for="confirm-username" class="form-label">Confirm Username</label>
												<input type="text" id="confirm-username" class="form-control" placeholder="Re-enter username">
											</div>
										</div>
										<div class="row">
											<div class="col-md-6 mb-3">
												<label for="new-password" class="form-label">New Password</label>
												<input type="text" id="new-password" class="form-control" placeholder="Enter password">
											</div>
											<div class="col-md-6 mb-3">
												<label for="confirm-password" class="form-label">Confirm Password</label>
												<input type="text" id="confirm-password" class="form-control" placeholder="Re-enter password">
											</div>
										</div>
										<button type="button" class="btn btn-primary" id="save-credentials-btn">
											<i class="fa-solid fa-check me-1"></i> Apply New Credentials
										</button>
										<button type="button" class="btn btn-secondary" id="cancel-credentials-btn">
											<i class="fa-solid fa-times me-1"></i> Cancel
										</button>
									</div>
								</div>
							</div>
						</div>

						<div class="mt-3">
							<button type="button" class="btn btn-success" id="import-now-btn">
								<i class="fa-solid fa-arrows-rotate me-1"></i> Sync Now
							</button>
							<button type="button" class="btn btn-outline-danger" id="cancel-preview-btn">
								<i class="fa-solid fa-trash me-1"></i> Cancel
							</button>
						</div>

						<!-- Import Processing -->
						<div id="import-processing-container" style="display:none;" class="mt-4 text-center">
							<div class="mb-3">
								<i class="fa-solid fa-spinner fa-spin fa-3x text-primary"></i>
							</div>
							<div class="h5 text-muted">Processing playlist...</div>
							<div class="small text-muted"><strong>Please be patient.</storng> This process can take quite some time with large playlists. Results will show when processing is complete.</div>
						</div>
					</div>
				</div>


				<!-- Import Results -->
				<div class="card shadow-sm mb-3" id="import-results-container" style="display:none;">
					<div class="card-header fw-semibold"><i class="fa-solid fa-check-circle me-1"></i> Import Complete</div>
					<div class="card-body">
						<div class="alert alert-success mb-0">
							<div id="import-results-content"></div>
						</div>
						<button type="button" class="btn btn-outline-primary mt-3" onclick="window.location.reload();">
							<i class="fa-regular fa-circle-check"></i> Done
						</button>
					</div>
				</div>

				<!-- Change URL Modal -->
				<div id="change-url-modal" class="modal" style="display:none; position:fixed; z-index:1050; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4);">
					<div class="modal-dialog" style="margin:100px auto; max-width:600px;">
						<div class="modal-content" style="border-radius:8px; box-shadow:0 4px 6px rgba(0,0,0,0.1); padding:10pt; padding-top:0;">
							<div class="modal-header" style="border-bottom:1px solid #dee2e6; margin-bottom:15px; padding-bottom:15px;">
								<h5 class="modal-title" style="margin:0;"><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i> Change Playlist URL</h5>
								<button type="button" class="btn-close" onclick="document.getElementById('change-url-modal').style.display='none';" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
							</div>
							<div class="modal-body">
								<div class="alert alert-warning" style="font-weight:normal;">
									<strong>Warning:</strong> Changing the playlist URL will <span class="text-danger">PERMANENTLY DELETE</span> all current and historical playlist data, including channels, feeds, and monitoring history. If you only need to update your playlist credentials, use the <a href="admin.php?tab=creds">Change Stream Credentials</a> option in the preview section instead.
								</div>
								<div class="mb-3">
									<label for="new-playlist-url" class="form-label">New Playlist URL</label>
									<input type="url" id="new-playlist-url" class="form-control" placeholder="https://example.com/new-playlist.m3u">
								</div>
								<div class="form-check mb-3">
									<input type="checkbox" id="confirm-clear-data" class="form-check-input">
									<label for="confirm-clear-data" class="form-check-label">
										<strong>I understand this will clear all channels, feeds, and monitoring data</strong>
									</label>
								</div>
							</div>
							<div class="modal-footer" style="border-top:1px solid #dee2e6; margin-top:15px; padding-top:15px; display:flex; gap:10px; justify-content:flex-end;">
								<button type="button" class="btn btn-secondary" onclick="document.getElementById('change-url-modal').style.display='none';">Cancel</button>
								<button type="button" class="btn btn-danger" id="confirm-change-url-btn">
									<i class="fa-solid fa-trash me-1"></i> Clear All Playlist Data and Use New URL
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- EPG URL Configuration Section -->
				<?php
				$savedEpgUrl = get_setting('epg_url', '');
				$lastEpgSyncDate = get_setting('epg_last_sync_date', '');
				?>
				<div class="card shadow-sm mb-3" id="epg-url-section">
					<div class="card-header fw-semibold"><i class="fa-solid fa-link me-1"></i> EPG URL (optional)</div>
					<div class="card-body">
						<?php echo csrf_field(); ?>
						<div class="mb-3">
							<label for="epg-url" class="form-label">EPG XML URL</label>
							<input type="url" id="epg-url" class="form-control"
								placeholder="https://example.com/epg.xml"
								value="<?= h($savedEpgUrl) ?>">
							<small class="text-muted">Public URL to your EPG XML file (processed by cron). Accepted: .xml, .gz</small>
						</div>
						<button type="button" class="btn btn-outline-primary" id="save-epg-url-btn">
							<i class="fa-solid fa-save me-1"></i> Save EPG URL
						</button>

						<?php if (!empty($savedEpgUrl)): ?>
							<div class="alert alert-info small mt-3 mb-0" style="font-weight:normal;">
								<i class="fa-solid fa-clock me-1"></i>

								<?php if ($lastEpgSyncDate): ?>
									Last sync: <strong><?= h(date('Y-m-d H:i:s', strtotime($lastEpgSyncDate))) ?></strong>
								<?php else: ?>
									<strong>Not processed yet, pending cron</strong>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<div id="epg-save-success" class="alert alert-success small mt-3" style="display:none;">
							<i class="fa-solid fa-check-circle me-1"></i> EPG URL saved successfully
						</div>
					</div>
				</div>

				<!-- Cron Job Settings -->
				<div class="card shadow-sm mb-3">
					<div class="card-header fw-semibold" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#cron-settings-collapse">
						<i class="fa-solid fa-clock me-1"></i> Cron Job Settings
						<i class="fa-solid fa-chevron-down float-end"></i>
					</div>
					<div id="cron-settings-collapse" class="collapse">
						<div class="card-body" style="font-weight:normal;">
							<p>Remember to configure your server cron jobs:</p>
							<div class="mb-3">
								<strong>Feed Check Cron</strong> (Run every 5 minutes)
								<pre class="p-2 mt-1 mb-0" style="background: var(--bs-secondary-bg); border: 1px solid var(--bs-border-color); border-radius: 4px;"><code>*/5 * * * * /usr/bin/php /path/to/install/cron_check_feeds.php</code></pre>
							</div>
							<div>
								<strong>EPG Update Cron</strong> (Run twice daily at 12 AM and 12 PM)
								<pre class="p-2 mt-1 mb-0" style="background: var(--bs-secondary-bg); border: 1px solid var(--bs-border-color); border-radius: 4px;"><code>0 0,12 * * * /usr/bin/php /path/to/install/epg_cron.php</code></pre>
							</div>
						</div>
					</div>
				</div>


				<script>
					(function() {
						let pendingCredentials = null;

						checkForTempPlaylist();

						$('#save-url-btn').on('click', function() {
							const url = $('#playlist-url').val().trim();
							if (!url) {
								alert('Please enter a valid URL');
								return;
							}
							savePlaylistUrl(url, false);
						});

						$('#save-epg-url-btn').on('click', function() {
							const url = $('#epg-url').val().trim();
							if (!url) {
								alert('Please enter a valid EPG URL');
								return;
							}
							saveEpgUrl(url);
						});

						$('#change-url-btn').on('click', function() {
							$('#change-url-modal').show();
						});

						$('#confirm-change-url-btn').on('click', function() {
							const url = $('#new-playlist-url').val().trim();
							const confirmed = $('#confirm-clear-data').is(':checked');

							if (!url) {
								alert('Please enter a valid URL');
								return;
							}
							if (!confirmed) {
								alert('You must confirm that you understand all data will be cleared');
								return;
							}

							$('#change-url-modal').hide();
							savePlaylistUrl(url, true);
						});

						$('#fetch-playlist-btn').on('click', function() {
							fetchPlaylist();
						});

						$('#edit-credentials-btn').on('click', function() {
							$('#credentials-form').slideDown();
							$(this).hide();
						});

						$('#cancel-credentials-btn').on('click', function() {
							$('#credentials-form').slideUp();
							$('#edit-credentials-btn').show();
							clearCredentialFields();
						});

						$('#save-credentials-btn').on('click', function() {
							const username = $('#new-username').val().trim();
							const confirmUsername = $('#confirm-username').val().trim();
							const password = $('#new-password').val().trim();
							const confirmPassword = $('#confirm-password').val().trim();

							if (!username || !password) {
								alert('Username and password are required');
								return;
							}
							if (username !== confirmUsername) {
								alert('Usernames do not match');
								return;
							}
							if (password !== confirmPassword) {
								alert('Passwords do not match');
								return;
							}

							pendingCredentials = {
								username: username,
								password: password
							};
							$('#current-username').text(username);
							$('#current-password').text(password);
							$('#credentials-form').slideUp();
							$('#edit-credentials-btn').show();
							clearCredentialFields();

							alert('Credentials will be applied when you click "Sync Now"');
						});

						$('#import-now-btn').on('click', function() {
							startImport();
						});

						$('#cancel-preview-btn').on('click', function() {
							if (confirm('Cancel and remove downloaded playlist?')) {
								deleteTempPlaylist();
								resetToUrlSection();
							}
						});

						function savePlaylistUrl(url, clearData) {
							$.ajax({
								url: 'playlist_api.php?action=save_url',
								type: 'POST',
								data: {
									url: url,
									clear_data: clearData ? '1' : '0',
									csrf_token: $('input[name="csrf_token"]').first().val()
								},
								dataType: 'json',
								success: function(response) {
									if (response.success) {
										window.location.reload();
									} else {
										alert('Error: ' + response.error);
									}
								},
								error: function() {
									alert('Failed to save URL. Please try again.');
								}
							});
						}

						function saveEpgUrl(url) {
							$('#save-epg-url-btn').prop('disabled', true);
							$('#epg-save-success').hide();



							$.ajax({
								url: 'playlist_api.php?action=save_epg_url',
								type: 'POST',
								data: {
									epg_url: url,
									csrf_token: $('input[name="csrf_token"]').first().val(),
								},
								dataType: 'json',
								success: function(response) {
									$('#save-epg-url-btn').prop('disabled', false);
									if (response.success) {
										$('#epg-save-success').fadeIn();
										setTimeout(function() {
											window.location.reload();
										}, 700);
									} else {
										alert('Error: ' + response.error);
									}
								},
								error: function() {
									$('#save-epg-url-btn').prop('disabled', false);
									alert('Failed to save EPG URL. Please try again.');
								}
							});
						}

						function fetchPlaylist() {
							$('#fetch-playlist-btn').prop('disabled', true);
							$('#fetch-progress-section').show();
							$('#fetch-progress-bar').css('width', '0%').text('0%');
							$('#fetch-progress-status').text('Initializing download...');

							let downloadComplete = false;

							const progressInterval = setInterval(function() {
								if (downloadComplete) {
									clearInterval(progressInterval);
									return;
								}

								$.ajax({
									url: 'playlist_api.php?action=get_progress',
									type: 'GET',
									dataType: 'json',
									success: function(data) {
										if (data.success && data.total > 0 && data.downloaded > 0) {
											var percent = Math.round((data.downloaded / data.total) * 100);
											var downloadedMB = (data.downloaded / (1024 * 1024)).toFixed(2);
											var totalMB = (data.total / (1024 * 1024)).toFixed(2);

											$('#fetch-progress-bar')
												.removeClass('progress-bar-animated')
												.css('width', percent + '%')
												.text(percent + '%');
											$('#fetch-progress-status').text('Downloaded ' + downloadedMB + ' MB of ' + totalMB + ' MB');
										} else if (data.downloaded > 0) {
											var downloadedMB = (data.downloaded / (1024 * 1024)).toFixed(2);
											$('#fetch-progress-bar')
												.addClass('progress-bar-animated')
												.css('width', '100%')
												.text('Downloading...');
											$('#fetch-progress-status').text('Downloaded ' + downloadedMB + ' MB');
										}
									}
								});
							}, 500);

							$.ajax({
								url: 'upload_playlist.php',
								type: 'POST',
								dataType: 'json',
								success: function(response) {
									downloadComplete = true;
									clearInterval(progressInterval);
									$('#fetch-progress-section').hide();
									if (response.success) {
										loadPlaylistStats();
									} else {
										$('#fetch-playlist-btn').prop('disabled', false);
										alert('Error: ' + response.error);
									}
								},
								error: function() {
									downloadComplete = true;
									clearInterval(progressInterval);
									$('#fetch-progress-section').hide();
									$('#fetch-playlist-btn').prop('disabled', false);
									alert('Failed to fetch playlist. Please try again.');
								}
							});
						}

						function loadPlaylistStats() {
							$.ajax({
								url: 'playlist_api.php?action=get_stats',
								type: 'GET',
								dataType: 'json',
								success: function(response) {
									if (response.success) {
										showPreview(response);
									} else {
										alert('Error: ' + response.error);
										$('#fetch-playlist-btn').prop('disabled', false);
									}
								},
								error: function() {
									alert('Failed to load playlist stats');
									$('#fetch-playlist-btn').prop('disabled', false);
								}
							});
						}

						function showPreview(data) {
							$('#url-section').hide();
							$('#preview-section').show();
							$('#import-results-container').hide();

							const statsHtml = '<p class="mb-1"><strong>Total Entries:</strong> ' + data.totalEntries + '</p>' +
								'<p class="mb-1"><strong>Live Channels:</strong> ' + data.liveChannels + '</p>' +
								'<p class="mb-0"><strong>File Size:</strong> ' + data.fileSize + '</p>';
							$('#playlist-stats').html(statsHtml);

							$('#current-username').text(data.currentUsername || '—');
							$('#current-password').text(data.currentPassword || '—');
						}

						function startImport() {
							$('#import-now-btn').prop('disabled', true);
							$('#cancel-preview-btn').prop('disabled', true);
							$('#import-processing-container').show();
							$('#import-results-container').hide();

							const postData = {
								mode: 'sync',
								_ajax: '1'
							};

							if (pendingCredentials) {
								postData.new_username = pendingCredentials.username;
								postData.new_password = pendingCredentials.password;
							}

							$.ajax({
								url: 'import_handler.php',
								type: 'POST',
								data: postData,
								dataType: 'json',
								timeout: 300000,
								success: function(response) {
									console.log('Import response:', response);
									$('#import-processing-container').hide();

									if (response.status === 'completed' && response.ok) {
										deleteTempPlaylist();
										showImportResults(response);
									} else {
										alert('Import failed: ' + (response.message || 'Unknown error'));
										$('#import-now-btn').prop('disabled', false);
										$('#cancel-preview-btn').prop('disabled', false);
									}
								},
								error: function(xhr, status, error) {
									console.log('Import error - Status:', status, 'Error:', error);
									console.log('Response text:', xhr.responseText);
									$('#import-processing-container').hide();
									$('#import-now-btn').prop('disabled', false);
									$('#cancel-preview-btn').prop('disabled', false);
									if (status === 'timeout') {
										alert('Import timed out. The process may still be running. Check your database.');
									} else {
										alert('Import failed: ' + error);
									}
								}
							});
						}

						function showImportResults(response) {
							console.log('Showing import results with data:', response);

							$('#preview-section').hide();
							$('#import-results-container').show();

							let html = '';

							if (response.message) {
								html += '<p class="mb-3">' + response.message + '</p>';
							}

							if (response.stats && typeof response.stats === 'object' && Object.keys(response.stats).length > 0) {
								html += '<hr><div class="row small mt-3">';
								for (let key in response.stats) {
									html += '<div class="col-md-4 mb-3">';
									html += '<div class="text-muted">' + key + '</div>';
									html += '<div class="fw-semibold">' + response.stats[key] + '</div>';
									html += '</div>';
								}
								html += '</div>';
							} else {
								console.warn('No stats found in response or stats is empty');
							}

							$('#import-results-content').html(html);
							console.log('Results HTML inserted');
						}

						function resetToUrlSection() {
							$('#preview-section').hide();
							$('#import-results-container').hide();
							$('#url-section').show();
							$('#fetch-playlist-btn').prop('disabled', false);
							pendingCredentials = null;
						}

						function deleteTempPlaylist() {
							$.ajax({
								url: 'playlist_api.php?action=delete_temp',
								type: 'POST',
								dataType: 'json'
							});
						}

						function checkForTempPlaylist() {
							$.ajax({
								url: 'playlist_api.php?action=get_stats',
								type: 'GET',
								dataType: 'json',
								success: function(response) {
									if (response.success) {
										showPreview(response);
									}
								}
							});
						}

						function clearCredentialFields() {
							$('#new-username, #confirm-username, #new-password, #confirm-password').val('');
						}
					})();
				</script>

			<?php elseif ($tab === 'associations'): ?>

				<h2 class="admin_section"><i class="fa-solid fa-diagram-project me-1"></i> Group Associations</h2>

				<div class="settings-info mb-4">
					<i class="fa-solid fa-info-circle me-1"></i>
					Group regional prefixes (US|, UK|, CA|) to find channels with different but similar <strong>tvg-id</strong> values across regions. Less precise than tvg-id matching, but useful for discovering streams showing the same content from other regions.
				</div>

				<?php if (empty($associations)): ?>
					<!-- Empty State -->
					<div class="text-center py-5">
						<i class="fa-solid fa-diagram-project fa-4x text-muted mb-3" style="opacity: 0.3;"></i>
						<h4>No Group Associations</h4>
						<p class="text-muted">Create your first association to link channel prefixes across regions.</p>
						<button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#createAssociationModal">
							<i class="fa-solid fa-plus me-1"></i> Create Association
						</button>
					</div>
				<?php else: ?>
					<!-- Associations List -->
					<div class="mb-4">
						<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createAssociationModal">
							<i class="fa-solid fa-plus me-1"></i> Create Association
						</button>
					</div>

					<div class="row g-3">
						<?php foreach ($associations as $assoc): ?>
							<div class="col-md-6">
								<div class="card shadow-sm">
									<div class="card-header d-flex justify-content-between align-items-center">
										<div class="fw-semibold">
											<?= h($assoc['name']) ?>
											<span class="badge bg-secondary ms-2"><?= $assoc['prefix_count'] ?> prefix<?= $assoc['prefix_count'] != 1 ? 'es' : '' ?></span>
										</div>
										<div>
											<button type="button" class="btn btn-sm btn-outline-primary btn-edit-assoc me-1"
												data-id="<?= $assoc['id'] ?>"
												data-name="<?= h($assoc['name']) ?>">
												<i class="fa-solid fa-pen m"></i>
											</button>
											<button type="button" class="btn btn-sm btn-outline-danger btn-delete-assoc"
												data-id="<?= $assoc['id'] ?>"
												data-name="<?= h($assoc['name']) ?>">
												<i class="fa-solid fa-trash"></i>
											</button>
										</div>
									</div>
									<div class="card-body">
										<?php if (empty($assoc['prefixes'])): ?>
											<p class="text-muted small mb-0" style="margin-bottom:8pt !important;">No prefixes added yet</p>
										<?php else: ?>
											<div class="d-flex flex-wrap gap-2 mb-3">
												<?php foreach ($assoc['prefixes'] as $prefix): ?>
													<span class="badge bg-light text-dark border">
														<?= h($prefix['prefix']) ?>
														<button type="button" class="btn-close btn-close-sm ms-1"
															style="font-size: 0.6rem; vertical-align: middle;"
															onclick="removePrefix(<?= $prefix['id'] ?>)"></button>
													</span>
												<?php endforeach; ?>
											</div>
										<?php endif; ?>
										<button type="button" class="btn btn-sm btn-outline-secondary"
											data-bs-toggle="modal" data-bs-target="#addPrefixModal<?= $assoc['id'] ?>">
											<i class="fa-solid fa-plus me-1"></i> Add Prefix
										</button>
									</div>
								</div>
							</div>

							<!-- Add Prefix Modal for this association -->
							<div class="modal fade" id="addPrefixModal<?= $assoc['id'] ?>" tabindex="-1">
								<div class="modal-dialog modal-lg">
									<div class="modal-content">
										<div class="modal-header">
											<h5 class="modal-title">Add Prefixes to "<?= h($assoc['name']) ?>"</h5>
											<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
										</div>
										<div class="modal-body">
											<!-- Search box -->
											<div class="mb-3">
												<input type="text" class="form-control"
													id="prefixSearch<?= $assoc['id'] ?>"
													placeholder="Search prefixes..."
													onkeyup="filterPrefixes<?= $assoc['id'] ?>(this.value)">
											</div>

											<!-- Prefix list -->
											<form method="post" action="admin.php?tab=associations" id="addPrefixForm<?= $assoc['id'] ?>">
												<?= csrf_field() ?>
												<input type="hidden" name="action" value="add_prefix">
												<input type="hidden" name="association_id" value="<?= $assoc['id'] ?>">
												<input type="hidden" name="prefix" id="selectedPrefix<?= $assoc['id'] ?>">

												<div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px; padding: 10px; padding-left:20pt;">
													<?php if (empty($available_prefixes)): ?>
														<p class="text-muted text-center">No prefixes found in database</p>
													<?php else: ?>
														<?php foreach ($available_prefixes as $prefix_data):
															$prefix = $prefix_data['prefix'];
															$count = $prefix_data['channel_count'];
															$already_added = in_array($prefix, $assoc['prefix_list']);
														?>
															<div class="prefix-item<?= $assoc['id'] ?> form-check mb-2 p-2"
																style="border-radius: 4px; <?= $already_added ? 'opacity: 0.5; background: var(--bs-secondary-bg);' : 'cursor: pointer;' ?>"
																data-prefix="<?= h($prefix) ?>"
																<?= !$already_added ? 'onclick="selectPrefix' . $assoc['id'] . '(\'' . h($prefix) . '\')"' : '' ?>>
																<input class="form-check-input" type="radio"
																	name="prefix_radio"
																	value="<?= h($prefix) ?>"
																	id="prefix_<?= $assoc['id'] ?>_<?= h($prefix) ?>"
																	<?= $already_added ? 'disabled' : '' ?>>
																<label class="form-check-label w-100" for="prefix_<?= $assoc['id'] ?>_<?= h($prefix) ?>"
																	style="cursor: <?= $already_added ? 'not-allowed' : 'pointer' ?>">
																	<strong><?= h($prefix) ?></strong>
																	<span class="text-muted ms-2">(<?= $count ?> channels)</span>
																	<?php if ($already_added): ?>
																		<span class="badge bg-secondary ms-2">Already added</span>
																	<?php endif; ?>
																</label>
															</div>
														<?php endforeach; ?>
													<?php endif; ?>
												</div>
											</form>
										</div>
										<div class="modal-footer">
											<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
											<button type="button" class="btn btn-primary" onclick="submitAddPrefix<?= $assoc['id'] ?>()">
												Add Selected Prefix
											</button>
										</div>
									</div>
								</div>
							</div>

							<script>
								function filterPrefixes<?= $assoc['id'] ?>(searchTerm) {
									const items = document.querySelectorAll('.prefix-item<?= $assoc['id'] ?>');
									const search = searchTerm.toLowerCase();

									items.forEach(item => {
										const prefix = item.getAttribute('data-prefix').toLowerCase();
										if (prefix.includes(search)) {
											item.style.display = '';
										} else {
											item.style.display = 'none';
										}
									});
								}

								function selectPrefix<?= $assoc['id'] ?>(prefix) {
									document.getElementById('selectedPrefix<?= $assoc['id'] ?>').value = prefix;
									// Update radio button
									const radio = document.getElementById('prefix_<?= $assoc['id'] ?>_' + prefix);
									if (radio && !radio.disabled) {
										radio.checked = true;
									}
								}

								function submitAddPrefix<?= $assoc['id'] ?>() {
									const selected = document.getElementById('selectedPrefix<?= $assoc['id'] ?>').value;
									if (!selected) {
										alert('Please select a prefix');
										return;
									}
									document.getElementById('addPrefixForm<?= $assoc['id'] ?>').submit();
								}
							</script>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<!-- Create Association Modal -->
				<div class="modal fade" id="createAssociationModal" tabindex="-1">
					<div class="modal-dialog">
						<div class="modal-content">
							<form method="post" action="admin.php?tab=associations">
								<?= csrf_field() ?>
								<input type="hidden" name="action" value="create_association">
								<div class="modal-header">
									<h5 class="modal-title">Create New Association</h5>
									<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
								</div>
								<div class="modal-body">
									<label class="form-label">Association Name</label>
									<input type="text" name="association_name" class="form-control"
										placeholder="e.g., English Speaking Countries" required maxlength="100">
									<small class="text-muted">Give this association a descriptive name</small>
								</div>
								<div class="modal-footer">
									<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
									<button type="submit" class="btn btn-primary">Create</button>
								</div>
							</form>
						</div>
					</div>
				</div>

				<!-- Edit Association Name Modal -->
				<div class="modal fade" id="editAssociationModal" tabindex="-1">
					<div class="modal-dialog">
						<div class="modal-content">
							<form method="post" action="admin.php?tab=associations" id="editAssociationForm">
								<?= csrf_field() ?>
								<input type="hidden" name="action" value="update_association_name">
								<input type="hidden" name="association_id" id="edit_association_id">
								<div class="modal-header">
									<h5 class="modal-title">Edit Association Name</h5>
									<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
								</div>
								<div class="modal-body">
									<label class="form-label">Association Name</label>
									<input type="text" name="association_name" id="edit_association_name"
										class="form-control" required maxlength="100">
								</div>
								<div class="modal-footer">
									<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
									<button type="submit" class="btn btn-primary">Save Changes</button>
								</div>
							</form>
						</div>
					</div>
				</div>

				<script>
					// Clear create association modal when closed
					document.getElementById('createAssociationModal').addEventListener('hidden.bs.modal', function() {
						this.querySelector('input[name="association_name"]').value = '';
					});

					// Edit association button handler
					document.addEventListener('click', function(e) {
						if (e.target.closest('.btn-edit-assoc')) {
							const btn = e.target.closest('.btn-edit-assoc');
							const id = btn.getAttribute('data-id');
							const name = btn.getAttribute('data-name');
							document.getElementById('edit_association_id').value = id;
							document.getElementById('edit_association_name').value = name;
							new bootstrap.Modal(document.getElementById('editAssociationModal')).show();
						}
					});

					// Delete association button handler
					document.addEventListener('click', function(e) {
						if (e.target.closest('.btn-delete-assoc')) {
							const btn = e.target.closest('.btn-delete-assoc');
							const id = btn.getAttribute('data-id');
							const name = btn.getAttribute('data-name');

							if (!confirm('Delete association "' + name + '"? This will also remove all associated prefixes.')) {
								return;
							}

							const form = document.createElement('form');
							form.method = 'POST';
							form.action = 'admin.php?tab=associations';

							// Add CSRF field
							const csrfInput = document.createElement('input');
							csrfInput.type = 'hidden';
							csrfInput.name = 'csrf_token';
							csrfInput.value = '<?= $_SESSION["csrf_token"] ?? "" ?>';
							form.appendChild(csrfInput);

							// Add action field
							const actionInput = document.createElement('input');
							actionInput.type = 'hidden';
							actionInput.name = 'action';
							actionInput.value = 'delete_association';
							form.appendChild(actionInput);

							// Add association_id field
							const idInput = document.createElement('input');
							idInput.type = 'hidden';
							idInput.name = 'association_id';
							idInput.value = id;
							form.appendChild(idInput);

							document.body.appendChild(form);
							form.submit();
						}
					});

					function removePrefix(prefixId) {
						if (!confirm('Remove this prefix from the association?')) {
							return;
						}
						const form = document.createElement('form');
						form.method = 'POST';
						form.action = 'admin.php?tab=associations';

						// Add CSRF field
						const csrfInput = document.createElement('input');
						csrfInput.type = 'hidden';
						csrfInput.name = 'csrf_token';
						csrfInput.value = '<?= $_SESSION["csrf_token"] ?? "" ?>';
						form.appendChild(csrfInput);

						// Add action field
						const actionInput = document.createElement('input');
						actionInput.type = 'hidden';
						actionInput.name = 'action';
						actionInput.value = 'remove_prefix';
						form.appendChild(actionInput);

						// Add prefix_id field
						const prefixIdInput = document.createElement('input');
						prefixIdInput.type = 'hidden';
						prefixIdInput.name = 'prefix_id';
						prefixIdInput.value = prefixId;
						form.appendChild(prefixIdInput);

						document.body.appendChild(form);
						form.submit();
					}
				</script>

			<?php elseif ($tab === 'creds'): ?>

				<h2 class="admin_section"><i class="fa-solid fa-user-gear me-1"></i> Update Saved Stream Credentials</h2>

				<div class="settings-info">
					This updates all <code>/live/{user}/{pass}/</code> URLs that start with <code><?php echo htmlspecialchars(get_setting('stream_host', '')); ?></code> and
					regenerates <code>url_hash</code>.
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
		</div> <!-- .admin-content -->
	</div> <!-- .admin-container -->
</div> <!-- .row -->

<?php require_once __DIR__ . '/_bottom.php'; ?>