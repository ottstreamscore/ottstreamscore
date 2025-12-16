<?php

/**
 * login.php
 * Secure user login page with CSRF protection and rate limiting
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// If already logged in, redirect to dashboard
if (is_logged_in()) {
	header('Location: index.php');
	exit;
}

$error = null;
$lockout_message = null;
$redirect = $_GET['redirect'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// Validate CSRF token
	if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
		$error = 'Invalid security token. Please refresh the page and try again.';
	} else {
		$username = sanitize_input($_POST['username'] ?? '');
		$password = $_POST['password'] ?? '';

		// Validate inputs
		if (empty($username) || empty($password)) {
			$error = 'Username and password are required';
		} elseif (!validate_username($username)) {
			$error = 'Invalid username format';
		} else {
			try {
				$pdo = get_db_connection();

				// Check if locked out BEFORE attempting login
				$lockout = is_locked_out($pdo, $username);
				if ($lockout['locked']) {
					$minutes = ceil($lockout['remaining_seconds'] / 60);
					$lockout_message = "Too many failed login attempts. Please try again in $minutes minute(s).";
				} else {
					$user = verify_login($pdo, $username, $password);

					if ($user) {
						login_user($user);
						header('Location: ' . $redirect);
						exit;
					} else {
						// Check if THIS failure caused a lockout
						$lockout_check = is_locked_out($pdo, $username);
						if ($lockout_check['locked']) {
							$minutes = ceil($lockout_check['remaining_seconds'] / 60);
							$lockout_message = "Too many failed login attempts. Account locked for $minutes minute(s).";
						} else {
							$remaining = MAX_LOGIN_ATTEMPTS - $lockout_check['attempts'];
							if ($remaining <= 2 && $remaining > 0) {
								$error = "Invalid username or password. $remaining attempt(s) remaining before lockout.";
							} else {
								$error = 'Invalid username or password';
							}
						}
					}
				}
			} catch (Exception $e) {
				$error = 'Login failed. Please try again.';
				error_log("Login error: " . $e->getMessage());
			}
		}
	}
}

// Generate CSRF token for form
$csrf_token = generate_csrf_token();

?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Login - OTT Stream Score</title>
	<style>
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}

		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			background: linear-gradient(135deg, #667eea 0%, #1ec7c0 100%);
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 20px;
		}

		.login-container {
			background: white;
			border-radius: 12px;
			box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
			width: 100%;
			max-width: 400px;
			overflow: hidden;
		}

		.login-header {
			background: #212529;
			color: white;
			padding-top: 30px;
			padding-bottom: 30px;
			text-align: center;
		}

		.login-header h1 {
			font-size: 28px;
			margin-bottom: 10px;
		}

		.login-header p {
			opacity: 0.9;
			font-size: 14px;
		}

		.login-body {
			padding: 40px 30px;
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

		.form-group input {
			width: 100%;
			padding: 12px 14px;
			border: 1px solid #ced4da;
			border-radius: 6px;
			font-size: 14px;
		}

		.form-group input:focus {
			outline: none;
			border-color: #667eea;
			box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
			transition: all 0.2s;
		}

		.btn:hover {
			background: #5568d3;
		}

		.btn:disabled {
			background: #6c757d;
			cursor: not-allowed;
		}

		.alert {
			padding: 12px 16px;
			border-radius: 6px;
			margin-bottom: 20px;
		}

		.alert-danger {
			background: #f8d7da;
			color: #721c24;
			border: 1px solid #f5c6cb;
		}

		.alert-warning {
			background: #fff3cd;
			color: #856404;
			border: 1px solid #ffc107;
		}

		.login-footer {
			text-align: center;
			padding: 20px;
			background: #f8f9fa;
			font-size: 12px;
			color: #6c757d;
		}

		.security-info {
			margin-top: 15px;
			padding: 10px;
			background: #e7f3ff;
			border: 1px solid #b3d9ff;
			border-radius: 4px;
			font-size: 12px;
			color: #004085;
		}
	</style>
</head>

<body>
	<div class="login-container">
		<div class="login-header">
			<img src="logo_header.png" alt="OTT Stream Score">
			<p style="font-size:14pt; margin-top:10pt;">Please login to continue</p>
		</div>

		<div class="login-body">
			<?php if ($lockout_message): ?>
				<div class="alert alert-warning">
					<strong>⚠️ Account Locked</strong><br>
					<?php echo sanitize_output($lockout_message); ?>
				</div>
			<?php endif; ?>

			<?php if ($error): ?>
				<div class="alert alert-danger"><?php echo sanitize_output($error); ?></div>
			<?php endif; ?>

			<form method="post" action="login.php<?php echo $redirect !== 'index.php' ? '?redirect=' . urlencode($redirect) : ''; ?>">
				<?php echo csrf_field(); ?>

				<div class="form-group">
					<label for="username">Username</label>
					<input type="text" id="username" name="username"
						value="<?php echo isset($_POST['username']) ? sanitize_output($_POST['username']) : ''; ?>"
						required autofocus autocomplete="username">
				</div>

				<div class="form-group">
					<label for="password">Password</label>
					<input type="password" id="password" name="password" required autocomplete="current-password">
				</div>

				<button type="submit" class="btn" <?php echo $lockout_message ? 'disabled' : ''; ?>>
					Login
				</button>

				<div class="security-info">
					⏱️ Session timeout: <?php echo get_session_timeout_minutes(); ?> minutes<br>
					🛡️ Rate limiting: <?php echo MAX_LOGIN_ATTEMPTS; ?> attempts per 15 minutes
				</div>
			</form>
		</div>
	</div>
</body>

</html>