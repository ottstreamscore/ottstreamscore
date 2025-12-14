<?php

declare(strict_types=1);

/**
 * install_db.php
 * One-click schema installer/upgrader.
 *
 * - Creates required tables if missing
 * - Adds columns/indexes if missing
 * - Safe to run multiple times
 *
 * After successful run: DELETE this file (or keep directory behind auth).
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/_boot.php';
$pdo = db();

header('Content-Type: text/plain; charset=utf-8');

function out(string $s): void
{
	echo $s . "\n";
}

function table_exists(PDO $pdo, string $table): bool
{
	$st = $pdo->prepare("
		SELECT COUNT(*)
		FROM information_schema.tables
		WHERE table_schema = DATABASE() AND table_name = :t
	");
	$st->execute([':t' => $table]);
	return (int)$st->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $col): bool
{
	$st = $pdo->prepare("
		SELECT COUNT(*)
		FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c
	");
	$st->execute([':t' => $table, ':c' => $col]);
	return (int)$st->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $table, string $idx): bool
{
	$st = $pdo->prepare("
		SELECT COUNT(*)
		FROM information_schema.statistics
		WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i
	");
	$st->execute([':t' => $table, ':i' => $idx]);
	return (int)$st->fetchColumn() > 0;
}

function run(PDO $pdo, string $sql, string $label): void
{
	out("==> $label");
	$pdo->exec($sql);
	out("OK\n");
}

out("IPTV Feed Monitor - DB Install/Upgrade");
out("DB: " . ($pdo->query("SELECT DATABASE()")->fetchColumn() ?: '(unknown)'));
out("Server: " . ($pdo->query("SELECT VERSION()")->fetchColumn() ?: '(unknown)'));
out("Time: " . date('Y-m-d H:i:s'));
out(str_repeat('-', 60));

/**
 * TABLES
 * Notes:
 * - channels represents a channel instance in a group (tvg-id is the duplicate key)
 * - feeds are unique by url_hash (sha1(url))
 * - feed_checks stores historical check results
 * - feed_check_queue schedules future checks
 *
 * Unique keys:
 * - channels unique key uses (tvg_id, tvg_name, group_title) so the same tvg-id can exist in multiple groups safely.
 */

if (!table_exists($pdo, 'channels')) {
	run($pdo, "
		CREATE TABLE channels (
		  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  tvg_id VARCHAR(191) NOT NULL,
		  tvg_name VARCHAR(255) NOT NULL,
		  tvg_logo TEXT NULL,
		  group_title VARCHAR(255) NOT NULL,
		  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		  PRIMARY KEY (id),
		  UNIQUE KEY uniq_channel (tvg_id, tvg_name, group_title),
		  KEY idx_name (tvg_name),
		  KEY idx_group (group_title),
		  KEY idx_tvgid (tvg_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
	", "Create table: channels");
} else {
	out("channels exists ✓");
	// Ensure indexes exist
	if (!index_exists($pdo, 'channels', 'idx_tvgid')) {
		run($pdo, "ALTER TABLE channels ADD INDEX idx_tvgid (tvg_id);", "Add idx_tvgid on channels(tvg_id)");
	}
}

if (!table_exists($pdo, 'feeds')) {
	run($pdo, "
		CREATE TABLE feeds (
		  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  channel_id BIGINT UNSIGNED NOT NULL,
		  url TEXT NOT NULL,
		  url_display TEXT NULL,
		  url_hash CHAR(40) NOT NULL,
		  is_live TINYINT(1) NOT NULL DEFAULT 1,
		  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

		  -- latest snapshot
		  last_checked_at DATETIME NULL,
		  last_ok TINYINT(1) NULL,
		  last_codec VARCHAR(32) NULL,
		  last_w INT NULL,
		  last_h INT NULL,
		  last_fps DECIMAL(6,2) NULL,
		  last_error VARCHAR(255) NULL,

		  -- ranking
		  reliability_score DECIMAL(6,2) NOT NULL DEFAULT 0.00, -- 0..100
		  quality_score DECIMAL(10,2) NOT NULL DEFAULT 0.00,

		  PRIMARY KEY (id),
		  UNIQUE KEY uniq_url_hash (url_hash),
		  KEY idx_channel (channel_id),
		  KEY idx_last_ok (last_ok),
		  KEY idx_quality (quality_score),
		  CONSTRAINT fk_feeds_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
	", "Create table: feeds");
} else {
	out("feeds exists ✓");

	// Add url_display if missing
	if (!column_exists($pdo, 'feeds', 'url_display')) {
		run($pdo, "ALTER TABLE feeds ADD COLUMN url_display TEXT NULL AFTER url;", "Add feeds.url_display");
	}

	// Add reliability_score if missing
	if (!column_exists($pdo, 'feeds', 'reliability_score')) {
		run($pdo, "ALTER TABLE feeds ADD COLUMN reliability_score DECIMAL(6,2) NOT NULL DEFAULT 0.00;", "Add feeds.reliability_score");
	}
	if (!column_exists($pdo, 'feeds', 'quality_score')) {
		run($pdo, "ALTER TABLE feeds ADD COLUMN quality_score DECIMAL(10,2) NOT NULL DEFAULT 0.00;", "Add feeds.quality_score");
	}

	// Ensure indexes exist
	if (!index_exists($pdo, 'feeds', 'idx_channel')) {
		run($pdo, "ALTER TABLE feeds ADD INDEX idx_channel (channel_id);", "Add idx_channel on feeds(channel_id)");
	}
	if (!index_exists($pdo, 'feeds', 'idx_last_ok')) {
		run($pdo, "ALTER TABLE feeds ADD INDEX idx_last_ok (last_ok);", "Add idx_last_ok on feeds(last_ok)");
	}
	if (!index_exists($pdo, 'feeds', 'idx_quality')) {
		run($pdo, "ALTER TABLE feeds ADD INDEX idx_quality (quality_score);", "Add idx_quality on feeds(quality_score)");
	}
}

if (!table_exists($pdo, 'feed_checks')) {
	run($pdo, "
		CREATE TABLE feed_checks (
		  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  feed_id BIGINT UNSIGNED NOT NULL,
		  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

		  ok TINYINT(1) NOT NULL DEFAULT 0,
		  http_code INT NULL,
		  bytes INT NULL,
		  content_type VARCHAR(128) NULL,

		  codec VARCHAR(32) NULL,
		  w INT NULL,
		  h INT NULL,
		  fps DECIMAL(6,2) NULL,

		  error VARCHAR(255) NULL,
		  raw_json MEDIUMTEXT NULL,

		  PRIMARY KEY (id),
		  KEY idx_feed_time (feed_id, checked_at),
		  KEY idx_time (checked_at),
		  CONSTRAINT fk_checks_feed FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
	", "Create table: feed_checks");
} else {
	out("feed_checks exists ✓");
	if (!index_exists($pdo, 'feed_checks', 'idx_feed_time')) {
		run($pdo, "ALTER TABLE feed_checks ADD INDEX idx_feed_time (feed_id, checked_at);", "Add idx_feed_time on feed_checks(feed_id, checked_at)");
	}
}

if (!table_exists($pdo, 'feed_check_queue')) {
	run($pdo, "
		CREATE TABLE feed_check_queue (
		  feed_id BIGINT UNSIGNED NOT NULL,
		  next_run_at DATETIME NOT NULL,
		  locked_at DATETIME NULL,
		  lock_token CHAR(36) NULL,
		  attempts INT NOT NULL DEFAULT 0,
		  last_result_ok TINYINT(1) NULL,
		  last_error VARCHAR(255) NULL,
		  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		  PRIMARY KEY (feed_id),
		  KEY idx_next (next_run_at),
		  KEY idx_lock (locked_at),
		  CONSTRAINT fk_queue_feed FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
	", "Create table: feed_check_queue");
} else {
	out("feed_check_queue exists ✓");
	if (!index_exists($pdo, 'feed_check_queue', 'idx_next')) {
		run($pdo, "ALTER TABLE feed_check_queue ADD INDEX idx_next (next_run_at);", "Add idx_next on feed_check_queue(next_run_at)");
	}
	if (!index_exists($pdo, 'feed_check_queue', 'idx_lock')) {
		run($pdo, "ALTER TABLE feed_check_queue ADD INDEX idx_lock (locked_at);", "Add idx_lock on feed_check_queue(locked_at)");
	}
}

out(str_repeat('-', 60));
out("DONE ✅");
out("Next steps:");
out("1) Delete install_db.php (or keep behind auth).");
out("2) Upload your .m3u into the installation directory and run process_playlist.php");
out("3) Confirm cron is running and feeds are updating.");
