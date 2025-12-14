<?php

declare(strict_types=1);

/**
 * OTT Admin bootstrap
 * Uses existing db.php in SAME directory
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!function_exists('h')) {
	function h(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('q')) {
	function q(string $key, string $default = ''): string
	{
		return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
	}
}

if (!function_exists('fmt_dt')) {
	function fmt_dt(?string $dt): string
	{
		return $dt ? date('Y-m-d H:i', strtotime($dt)) : '—';
	}
}

if (!function_exists('redact_live_url')) {
	function redact_live_url(string $url): string
	{
		return preg_replace('~(/live/)([^/]+)/([^/]+)/~i', '$1***/***/', $url) ?? $url;
	}
}
