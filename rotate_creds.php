<?php

declare(strict_types=1);

$title = 'Rotate Credentials';
require_once __DIR__ . '/_top.php';

$pdo = db();

$host = STREAM_HOST;

$did = false;
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$newUser = trim((string)($_POST['username'] ?? ''));
	$newPass = trim((string)($_POST['password'] ?? ''));
	$forceDue = isset($_POST['force_due']) && $_POST['force_due'] === '1';

	if ($newUser === '' || $newPass === '') {
		$err = 'Username and password are required.';
	} elseif (!preg_match('/^[A-Za-z0-9]+$/', $newUser) || !preg_match('/^[A-Za-z0-9]+$/', $newPass)) {
		$err = 'Username/password must be alphanumeric (no slashes/spaces).';
	} else {
		$pattern = '(/live/)[^/]+/[^/]+/';

		$pdo->beginTransaction();
		try {
			// Update URLs + url_display + url_hash (computed from the NEW url expression)
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

			// Force everything to be due now (recheck quickly) - only if checkbox is checked
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
			$did = true;
			$msg = "Updated {$affectedFeeds} feeds. " . ($forceDue ? "Forced {$affectedQueue} queued items due now." : "Queue items unchanged.");
		} catch (Throwable $e) {
			$pdo->rollBack();
			$err = $e->getMessage();
		}
	}
}

?>

<div class="card shadow-sm">
	<div class="card-header fw-semibold">Rotate LIVE credentials</div>
	<div class="card-body">

		<?php if ($did): ?>
			<div class="alert alert-success"><?= h($msg) ?></div>
		<?php elseif ($err): ?>
			<div class="alert alert-danger"><strong>Error:</strong> <?= h($err) ?></div>
		<?php endif; ?>

		<div class="text-muted small mb-3">
			This updates all <code>/live/{user}/{pass}/</code> URLs that start with <code><?= h($host) ?></code>,
			regenerates <code>url_hash</code>, and keeps <code>url_display</code> masked.
		</div>

		<form method="post">
			<div class="row g-2">
				<div class="col-md-4">
					<label class="form-label">New username</label>
					<input class="form-control" name="username" autocomplete="off" required>
				</div>
				<div class="col-md-4">
					<label class="form-label">New password</label>
					<input class="form-control" name="password" autocomplete="off" required>
				</div>
				<div class="col-md-4 d-flex align-items-end">
					<div class="form-check">
						<input class="form-check-input" type="checkbox" name="force_due" value="1" id="force_due" checked>
						<label class="form-check-label" for="force_due">
							Force recheck now
						</label>
					</div>
				</div>
			</div>

			<div class="mt-3 d-grid d-md-block">
				<button class="btn btn-dark" type="submit">Rotate Credentials</button>
			</div>
		</form>
	</div>
</div>

<?php require_once __DIR__ . '/_bottom.php'; ?>
