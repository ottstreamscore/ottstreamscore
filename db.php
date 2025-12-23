<?php

/**
 * db.php
 * Database connection helper
 * Reads configuration from database settings table
 */

declare(strict_types=1);

$timezone = get_setting('app_timezone', 'America/New_York');
if ($timezone) {
	date_default_timezone_set($timezone);
}

/**
 * Get database configuration from .installed file or fallback
 */
function get_db_config(): array
{
	static $config = null;

	if ($config !== null) {
		return $config;
	}

	// For installation phase, use temporary config from session
	if (session_status() === PHP_SESSION_NONE) {
		session_start();
	}

	if (isset($_SESSION['db_config'])) {
		$config = $_SESSION['db_config'];
		return $config;
	}

	// Read from database through bootstrap connection
	$bootstrap_file = __DIR__ . '/.db_bootstrap';

	if (file_exists($bootstrap_file)) {
		$bootstrap = json_decode(file_get_contents($bootstrap_file), true);
		if ($bootstrap) {
			$config = $bootstrap;
			return $config;
		}
	}

	// Fallback to old config.php if it exists
	if (file_exists(__DIR__ . '/config.php')) {
		require_once __DIR__ . '/config.php';
		$config = [
			'host' => DB_HOST ?? 'localhost',
			'port' => DB_PORT ?? 3306,
			'name' => DB_NAME ?? '',
			'user' => DB_USER ?? '',
			'pass' => DB_PASS ?? '',
			'charset' => DB_CHARSET ?? 'utf8mb4'
		];

		// Save as bootstrap for future use
		file_put_contents($bootstrap_file, json_encode($config));

		return $config;
	}

	throw new RuntimeException('Database configuration not found. Please run setup.php');
}

/**
 * Create database connection
 */
function get_db_connection(): PDO
{
	static $pdo = null;

	if ($pdo !== null) {
		return $pdo;
	}

	$config = get_db_config();

	$dsn = sprintf(
		'mysql:host=%s;port=%d;dbname=%s;charset=%s',
		$config['host'],
		$config['port'],
		$config['name'],
		$config['charset']
	);

	try {
		$pdo = new PDO($dsn, $config['user'], $config['pass'], [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false
		]);

		return $pdo;
	} catch (PDOException $e) {
		error_log("Database connection failed: " . $e->getMessage());
		throw new RuntimeException('Database connection failed. Check configuration.');
	}
}

/**
 * Get setting value from database
 */
function get_setting(string $key, $default = null)
{
	static $settings = null;

	// Load all settings once
	if ($settings === null) {
		try {
			$pdo = get_db_connection();
			$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
			$settings = [];
			while ($row = $stmt->fetch()) {
				$settings[$row['setting_key']] = $row['setting_value'];
			}
		} catch (Exception $e) {
			error_log("Failed to load settings: " . $e->getMessage());
			$settings = [];
		}
	}

	return $settings[$key] ?? $default;
}

/**
 * Set setting value in database
 */
function set_setting(string $key, $value, string $description = ''): bool
{
	try {
		$pdo = get_db_connection();
		$stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, description)
            VALUES (:key, :value, :desc)
            ON DUPLICATE KEY UPDATE setting_value = :value
        ");

		return $stmt->execute([
			'key' => $key,
			'value' => $value,
			'desc' => $description
		]);
	} catch (Exception $e) {
		error_log("Failed to set setting: " . $e->getMessage());
		return false;
	}
}

/**
 * Get all settings
 */
function get_all_settings(): array
{
	try {
		$pdo = get_db_connection();
		$stmt = $pdo->query("SELECT setting_key, setting_value, description FROM settings ORDER BY setting_key");
		return $stmt->fetchAll();
	} catch (Exception $e) {
		error_log("Failed to get all settings: " . $e->getMessage());
		return [];
	}
}

// Legacy compatibility - define constants if needed
if (!defined('STREAM_HOST')) {
	define('STREAM_HOST', get_setting('stream_host', 'http://localhost'));
}
if (!defined('APP_TZ')) {
	define('APP_TZ', get_setting('app_timezone', 'America/New_York'));
}
if (!defined('BATCH_SIZE')) {
	define('BATCH_SIZE', (int)get_setting('batch_size', 50));
}
if (!defined('LOCK_MINUTES')) {
	define('LOCK_MINUTES', (int)get_setting('lock_minutes', 10));
}
if (!defined('OK_RECHECK_HOURS')) {
	define('OK_RECHECK_HOURS', (int)get_setting('ok_recheck_hours', 72));
}
if (!defined('FAIL_RETRY_MINUTES_MIN')) {
	define('FAIL_RETRY_MINUTES_MIN', (int)get_setting('fail_retry_min', 30));
}
if (!defined('FAIL_RETRY_MINUTES_MAX')) {
	define('FAIL_RETRY_MINUTES_MAX', (int)get_setting('fail_retry_max', 360));
}

/**
 * Legacy helper - alias for get_db_connection()
 */
function db(): PDO
{
	return get_db_connection();
}
