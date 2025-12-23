<?php

declare(strict_types=1);

/**
 * OTT Stream Score Admin bootstrap
 * Uses existing db.php in SAME directory
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';


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
		if (!$dt || $dt === '0000-00-00 00:00:00') {
			return '—';
		}

		$timezone = get_setting('app_timezone', 'America/New_York');

		try {
			// Parse as UTC (database stores UTC)
			$utc = new DateTime($dt, new DateTimeZone('UTC'));
			// Convert to local timezone
			$utc->setTimezone(new DateTimeZone($timezone));
			// Format and return
			return $utc->format('Y-m-d H:i');
		} catch (Exception $e) {
			// Fallback if conversion fails
			return $dt;
		}
	}
}
if (!function_exists('redact_live_url')) {
	function redact_live_url(string $url): string
	{
		return preg_replace('~(/live/)([^/]+)/([^/]+)/~i', '$1***/***/', $url) ?? $url;
	}
}
