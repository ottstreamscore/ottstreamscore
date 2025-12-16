<?php

/**
 * logout.php
 * Logout user and redirect to login
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

logout_user();

header('Location: login.php');
exit;
