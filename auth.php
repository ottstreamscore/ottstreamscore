<?php

/**
 * auth.php
 * Secure authentication system with CSRF protection, rate limiting, and session management
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
	// Secure session configuration
	ini_set('session.cookie_httponly', '1');
	ini_set('session.cookie_samesite', 'Strict');
	if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
		ini_set('session.cookie_secure', '1');
	}
	ini_set('session.use_strict_mode', '1');

	session_start();
}

// Session timeout (30 minutes)
const SESSION_TIMEOUT = 1800;

// Rate limiting constants
const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_TIME = 900; // 15 minutes in seconds
const ATTEMPT_WINDOW = 900; // Count attempts in last 15 minutes

/**
 * Check if user is logged in and session is valid
 */
function is_logged_in(): bool
{
	if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
		return false;
	}

	// Check session timeout
	if (isset($_SESSION['last_activity'])) {
		if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
			logout_user();
			return false;
		}
	}

	// Update last activity time
	$_SESSION['last_activity'] = time();

	return true;
}

/**
 * Require authentication - redirect to login if not authenticated
 */
function require_auth(): void
{
	if (!is_logged_in()) {
		$redirect = $_SERVER['REQUEST_URI'] ?? '/';
		header('Location: login.php?redirect=' . urlencode($redirect));
		exit;
	}
}

/**
 * Get current logged-in user ID
 */
function get_user_id(): ?int
{
	return $_SESSION['user_id'] ?? null;
}

/**
 * Get current logged-in username
 */
function get_username(): ?string
{
	return $_SESSION['username'] ?? null;
}

/**
 * Get client IP address
 */
function get_client_ip(): string
{
	// Check for proxy headers
	$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ??
		$_SERVER['HTTP_X_REAL_IP'] ??
		$_SERVER['REMOTE_ADDR'] ??
		'0.0.0.0';

	// If multiple IPs (proxy chain), get first one
	if (strpos($ip, ',') !== false) {
		$ip = trim(explode(',', $ip)[0]);
	}

	// Validate IP
	if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
		$ip = '0.0.0.0';
	}

	return $ip;
}

/**
 * Log login attempt
 */
function log_login_attempt(PDO $pdo, string $username, bool $success): void
{
	try {
		$stmt = $pdo->prepare("
            INSERT INTO login_attempts (ip_address, username, attempted_at, success)
            VALUES (?, ?, NOW(), ?)
        ");
		$stmt->execute([get_client_ip(), $username, $success ? 1 : 0]);
	} catch (Exception $e) {
		error_log("Failed to log login attempt: " . $e->getMessage());
	}
}

/**
 * Check if IP or username is locked out due to too many failed attempts
 */
function is_locked_out(PDO $pdo, string $username): array
{
	try {
		// Clean old attempts first
		$pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");

		// Check failed attempts by IP in last 15 minutes
		$stmt = $pdo->prepare("
            SELECT COUNT(*) as attempts, MAX(attempted_at) as last_attempt
            FROM login_attempts
            WHERE ip_address = ?
            AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
		$stmt->execute([get_client_ip(), ATTEMPT_WINDOW]);
		$ip_result = $stmt->fetch();

		// Check failed attempts by username in last 15 minutes
		$stmt = $pdo->prepare("
            SELECT COUNT(*) as attempts, MAX(attempted_at) as last_attempt
            FROM login_attempts
            WHERE username = ?
            AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
		$stmt->execute([$username, ATTEMPT_WINDOW]);
		$user_result = $stmt->fetch();

		$ip_attempts = (int)$ip_result['attempts'];
		$user_attempts = (int)$user_result['attempts'];

		// Locked out if either IP or username exceeds max attempts
		if ($ip_attempts >= MAX_LOGIN_ATTEMPTS || $user_attempts >= MAX_LOGIN_ATTEMPTS) {
			$last_attempt = strtotime($ip_attempts >= MAX_LOGIN_ATTEMPTS ?
				$ip_result['last_attempt'] :
				$user_result['last_attempt']);
			$lockout_expires = $last_attempt + LOCKOUT_TIME;
			$remaining = max(0, $lockout_expires - time());

			if ($remaining > 0) {
				return [
					'locked' => true,
					'remaining_seconds' => $remaining,
					'attempts' => max($ip_attempts, $user_attempts)
				];
			}
		}

		return [
			'locked' => false,
			'attempts' => max($ip_attempts, $user_attempts)
		];
	} catch (Exception $e) {
		error_log("Failed to check lockout: " . $e->getMessage());
		// On error, allow login (fail open for availability)
		return ['locked' => false, 'attempts' => 0];
	}
}

/**
 * Verify user credentials
 */
function verify_login(PDO $pdo, string $username, string $password): ?array
{
	// Sanitize username
	$username = sanitize_input($username);

	// Check rate limiting
	$lockout = is_locked_out($pdo, $username);
	if ($lockout['locked']) {
		log_login_attempt($pdo, $username, false);
		return null;
	}

	$stmt = $pdo->prepare("
        SELECT id, username, password_hash, email, is_active
        FROM users
        WHERE username = :username AND is_active = 1
    ");

	$stmt->execute(['username' => $username]);
	$user = $stmt->fetch();

	if ($user && password_verify($password, $user['password_hash'])) {
		// Successful login
		log_login_attempt($pdo, $username, true);

		// Update last login
		$update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
		$update->execute(['id' => $user['id']]);

		return $user;
	}

	// Failed login
	log_login_attempt($pdo, $username, false);
	return null;
}

/**
 * Login user and create session
 */
function login_user(array $user): void
{
	// Regenerate session ID to prevent session fixation
	session_regenerate_id(true);

	$_SESSION['user_id'] = $user['id'];
	$_SESSION['username'] = $user['username'];
	$_SESSION['email'] = $user['email'] ?? '';
	$_SESSION['login_time'] = time();
	$_SESSION['last_activity'] = time();

	// Generate CSRF token
	if (!isset($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
}

/**
 * Logout user and destroy session
 */
function logout_user(): void
{
	$_SESSION = [];

	if (isset($_COOKIE[session_name()])) {
		setcookie(session_name(), '', time() - 3600, '/');
	}

	session_destroy();
}

/**
 * Update user password
 */
function update_password(PDO $pdo, int $user_id, string $new_password): bool
{
	try {
		$hash = password_hash($new_password, PASSWORD_DEFAULT);
		$stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
		return $stmt->execute(['hash' => $hash, 'id' => $user_id]);
	} catch (PDOException $e) {
		error_log("Password update failed: " . $e->getMessage());
		return false;
	}
}

/**
 * Generate CSRF token
 */
function generate_csrf_token(): string
{
	if (!isset($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validate_csrf_token(string $token): bool
{
	if (!isset($_SESSION['csrf_token'])) {
		return false;
	}

	return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get CSRF token HTML input field
 */
function csrf_field(): string
{
	$token = generate_csrf_token();
	return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Require valid CSRF token for POST requests
 */
function require_csrf(): void
{
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$token = $_POST['csrf_token'] ?? '';
		if (!validate_csrf_token($token)) {
			http_response_code(403);
			die('Invalid CSRF token. Please refresh the page and try again.');
		}
	}
}

/**
 * Sanitize user input
 */
function sanitize_input(string $input): string
{
	// Trim whitespace
	$input = trim($input);

	// Remove null bytes
	$input = str_replace("\0", '', $input);

	// Limit length
	$input = substr($input, 0, 255);

	return $input;
}

/**
 * Sanitize output for HTML display
 */
function sanitize_output(string $output): string
{
	return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email format
 */
function validate_email(string $email): bool
{
	return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate username format (alphanumeric, underscore, dash, 3-50 chars)
 */
function validate_username(string $username): bool
{
	return preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username) === 1;
}

/**
 * Get session timeout in minutes
 */
function get_session_timeout_minutes(): int
{
	return (int)(SESSION_TIMEOUT / 60);
}

/**
 * Get remaining session time in seconds
 */
function get_remaining_session_time(): int
{
	if (!isset($_SESSION['last_activity'])) {
		return 0;
	}

	$elapsed = time() - $_SESSION['last_activity'];
	return max(0, SESSION_TIMEOUT - $elapsed);
}
