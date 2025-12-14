<?php

/**
 * config.php
 * Central config for OTT stream testing tools (NO output from this file)
 *
 * Put this file in /ott/ alongside db.php, _boot.php, etc.
 */

declare(strict_types=1);

/* ------------------------------
   STREAM / XTREAM HOST SETTINGS
--------------------------------- */

// Base host used to build authenticated live URLs (no trailing slash). This is your panel's custom domain.
const STREAM_HOST = 'http://YOUR_CUSTOM_DOMAIN';

/* ------------------------------
   DB SETTINGS
--------------------------------- */

const DB_HOST = 'LOCALHOST';
const DB_PORT = 3306; // change if necessary
const DB_NAME = 'DB_NAME';
const DB_USER = 'DB_USER';
const DB_PASS = 'DB_PASS';

// Charset (MariaDB safe)
const DB_CHARSET = 'utf8mb4';

/* ------------------------------
   APP SETTINGS
--------------------------------- */

// Default timezone for app output (matches your other scripts)
const APP_TZ = 'America/New_York';

// How many feeds to we process in each batch run?
const BATCH_SIZE = 25;

// Lock duration in minutes to prevent concurrent batch processing
const LOCK_MINUTES = 10;

// Hours to wait before rechecking streams that previously tested OK
const OK_RECHECK_HOURS = 72;

// initial retry after fail
const FAIL_RETRY_MINUTES_MIN = 30;

// cap max retries
const FAIL_RETRY_MINUTES_MAX = 360;
