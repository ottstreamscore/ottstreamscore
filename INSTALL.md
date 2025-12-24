# OTT Stream Score v2.1 - Installation & Upgrade Guide

## Overview

Version 2.1 adds EPG integration, task management, group associations for cross-regional discovery, feed comparison tools, and comprehensive playlist optimization workflows. Database changes include EPG tables, task tables, and group association tables. Choose your installation path below based on your situation.

---

## Table of Contents

1. [Fresh Installation](#fresh-installation) - New installations
2. [Upgrading from v1.3+](#upgrading-from-v13) - Use migrate.php
3. [Upgrading from before v1.3](#upgrading-from-before-v13) - Use setup.php first
4. [Post-Installation](#post-installation) - All users
5. [Technical Details](#technical-details) - Database schema and logic
6. [Troubleshooting](#troubleshooting) - Common issues

---

## Fresh Installation

For brand new installations of OTT Stream Score.

### Requirements
- PHP 8.1+ (PDO, cURL, mbstring, zip extensions)
- MySQL 5.7+ or MariaDB 10.2+
- FFmpeg/FFprobe
- Web server (Apache/Nginx)
- Cron access
- Write permissions for `/playlists/` directory and log files

### Installation Steps

1. **Upload files to your web server**
```bash
   # Clone to your web directory
   git clone https://github.com/ottstreamscore/ottstreamscore /path/to/your/webroot
   cd /path/to/your/webroot
```
   
   Or download and extract the ZIP file to your web directory.

2. **Set file permissions**
```bash
   # Make the application directory writable
   chmod 755 .
```

3. **Run setup**
   
   **Via browser:** Navigate to `https://yourdomain.com/path/to/setup.php`
   
   **Via CLI:**
```bash
   php setup.php
```

4. **Follow the setup wizard**
   - Enter database credentials (host, port, name, user, password)
   - Configure stream host URL
   - Set timezone
   - Configure monitoring settings (batch size, lock duration, recheck intervals)
   - Create admin user account (username, password, email)
   - Setup automatically creates `/playlists` directory (0700 permissions) with access protection

5. **Login**
   
   Navigate to `https://yourdomain.com/path/to/login.php` and login with your admin credentials.

6. **Import your data**
   
   Go to Admin → Setup Playlist & EPG tab:
   - Enter your M3U playlist URL
   - Enter your EPG URL
   - Click Fetch to import
   - Review results, click Sync to import

7. **Setup cron job 1** (required for feed monitoring)
```bash
   */5 * * * * cd /path/to/installation && php cron_check_feeds.php >> /dev/null 2>&1
```

8. **Setup cron job 2** (optional, but required for inclusion of EPG data)
```bash
   0 0,12 * * * cd /path/to/installation && php cron_epg.php >> /dev/null 2>&1
```

9. **Secure your installation**
```bash
   # Delete or move setup.php outside webroot
   # Delete or move migrate.php outside webroot
   rm setup.php
   rm migrate.php
   
   # Or move it
   mv setup.php ../setup.php.bak
   mv migrate.php ../migrate.php.bak
```

**✅ Installation complete!** Access your dashboard at `https://yourdomain.com/path/to/`

---

## Upgrading from v1.3+

**For users currently running v1.3, v1.4, or v1.5.**

This is the simplest upgrade path using the `migrate.php` script.

### Before You Begin

**Backup your database:**
```bash
mysqldump -u username -p database_name > backup_$(date +%F).sql
```

**Backup your application directory:**
```bash
tar -czf ottstreamscore_backup_$(date +%F).tar.gz /path/to/your/installation
```

### Upgrade Steps

1. **Pull latest code**
```bash
   cd /path/to/your/installation
   git pull origin main
```
   
   Or download the latest release and extract files over your existing installation.

2. **Run migration**
   
   **Via browser:** Navigate to `https://yourdomain.com/path/to/migrate.php`
   
   **Via CLI:**
```bash
   php migrate.php
```

3. **Verify migration**
   
   The migration script will:
   - Verify your installation is v1.3 or later
   - Create new v2.1 tables (epg_data, group_associations, group_association_prefixes, editor_todo_list, editor_todo_list_log)
   - Create `/playlists` directory if missing (0700 permissions with protection)
   - Report success or skip if table/directory already exists

4. **Configure EPG** (new feature)
   
   Navigate to Admin → Playlist tab:
   - Enter your EPG (XMLTV) URL
   - Click "Save EPG URL"
   - EPG will sync automatically via cron

5. **Setup EPG cron** (if not already running)
```bash
   0 0,12 * * * cd /path/to/installation && php cron_epg.php >> /dev/null 2>&1
```

6. **Clean up** (optional)
```bash
   # Remove migration script
   rm migrate.php
```

**✅ Upgrade complete!** Your installation now supports EPG integration, task management, and group associations.

### What Gets Migrated

The migration script adds:
- ✅ `epg_data` - Electronic Program Guide storage
- ✅ `group_associations` - Named association groups
- ✅ `group_association_prefixes` - Prefix-to-association mappings
- ✅ `editor_todo_list` - Active tasks
- ✅ `editor_todo_list_log` - Task history
- ✅ Safe to run multiple times (idempotent)

### Expected Output

**CLI mode:**
```
======================================================================
OTT Stream Score - Database Migration (v1.3+ to v2.1)
======================================================================

✅ Created epg_data table
✅ Created group_associations table
✅ Created group_association_prefixes table
✅ Created editor_todo_list table
✅ Created editor_todo_list_log table

======================================================================
✅ Migration completed successfully!
======================================================================
```

**Web mode:**
- Visual interface with migration status
- Links to new features when complete
- Error handling with clear messaging

---

## Upgrading from before v1.3

**For users running versions 1.2 or earlier.**

If you're upgrading from before v1.3, you must first upgrade to v1.3, then upgrade to v2.1.

### Why This Matters

Version 1.3 introduced major architectural changes:
- Authentication system
- Database-driven configuration (replaced config.php)
- New database tables (users, settings, login_attempts)
- Security features

**You cannot skip v1.3** when upgrading from earlier versions.

### Upgrade Path

**Step 1: Upgrade to v1.3 first**

1. **Backup everything:**
```bash
   tar -czf ott-backup-$(date +%Y%m%d).tar.gz /path/to/installation
   mysqldump -u user -p database > backup.sql
```

2. **Get v1.3 files:**
```bash
   cd /path/to/installation
   git checkout v1.3
```
   Or download the v1.3 release from GitHub.

3. **Run setup.php:**
   
   Navigate to `https://yourdomain.com/path/to/setup.php`
   
   - Setup will detect your existing installation
   - Automatically import settings from `config.php`
   - Create new database tables (users, settings, login_attempts)
   - Preserve all existing feed data

4. **Create admin account** when prompted

5. **Login and verify** settings in Admin Dashboard

**Step 2: Upgrade to v2.1**

Once you're successfully running v1.3+:

1. **Get v2.1 files:**
```bash
   cd /path/to/installation
   git checkout main
   # or: git checkout v2.1
```

2. **Run migration:**
```bash
   php migrate.php
```

3. **Configure new features:**
   - Add EPG URL in Admin → Playlist tab
   - Setup EPG cron job
   - Create group associations (optional)

**✅ Upgrade complete!**

### Key Migration Notes

**From pre-v1.3 to v1.3:**
- config.php is deprecated and replaced by database settings
- Authentication is now required (create admin account)
- Database credentials move to `.db_bootstrap` file

**From v1.3-v1.5 to v2.1:**
- Adds EPG integration with automated syncing
- Adds task management system for collaborative workflows
- Adds group associations for cross-regional discovery
- Adds feed comparison and EPG schedule comparison
- All previous features remain unchanged
- No breaking changes

---

## Post-Installation

Applies to all installation types.

### Accessing the Application

Once installed, access your application at:
- **Dashboard:** `https://yourdomain.com/path/to/index.php`
- **Login:** `https://yourdomain.com/path/to/login.php`
- **Admin Panel:** `https://yourdomain.com/path/to/admin.php`

### Admin Panel Features

**Application Settings:**
- Stream host configuration
- Timezone settings
- Monitoring intervals (batch size, lock duration)
- Retry logic configuration

**Playlist & EPG:**
- URL-based playlist fetching with real-time download progress
- Sync mode updates existing data while preserving quality scores and history
- EPG URL configuration for automated program guide syncing
- One-click credential rotation without re-downloading
- Last sync timestamps and status monitoring

**Group Associations:**
- Create named associations (e.g., "English Speaking", "North America")
- Link group prefixes together (US|, UK|, CA|)
- Discover similar channels across regions automatically
- Find backup streams when primary feeds fail
- Alternative to exact tvg-id matching for discovering regional variants

**Update Stream Credentials:**
- Bulk update /live/ URLs with new credentials
- Force immediate recheck of affected feeds

**User Management:** 
- View all user accounts
- Create new users for team collaboration
- Monitor failed login attempts
- Reset account lockouts
- Delete users (with safeguards)

**Database:**
- Update database credentials
- Test connection before saving

**Change Password:**
- Update your user account password

### Setting Up Cron Jobs

**Feed Monitoring** (required):
```bash
*/5 * * * * cd /path/to/installation && php cron_check_feeds.php >> /dev/null 2>&1
```

**Frequency recommendations:**
- `*/5 * * * *` - Every 5 minutes (standard, recommended)
- `*/10 * * * *` - Every 10 minutes (lighter load)
- `*/15 * * * *` - Every 15 minutes (minimal load)

Adjust based on your feed count and server capacity.

**EPG Sync** (optional, but required for EPG data inclusion):
```bash
0 0,12 * * * cd /path/to/installation && php cron_epg.php >> /dev/null 2>&1
```

**Frequency recommendations:**
- `0 0,12 * * *` - Twice daily at midnight and noon (standard, recommended)
- `0 0 * * *` - Once daily at midnight (lighter load)

### New v2.1 Features to Explore

**EPG Integration:**
- View program schedules on channel pages (toggle EPG display)
- Compare schedules between similar channels
- Verify content matches before feed replacement
- EPG data automatically syncs via cron

**Task Management:**
- Create tasks for feed replacements, reviews, or EPG adjustments
- Assign alternative feeds with full context
- Add notes explaining changes
- Track task completion history
- Collaborate with team members on playlist optimization

**Group Associations:**
- Define associations by language, region, or content type
- Link prefixes (e.g., US| + UK| + CA| = "English Speaking")
- Discover backup streams across regions automatically
- Find alternatives when primary feeds fail

**Feed Comparison:**
- Side-by-side metrics comparison
- EPG schedule comparison
- Historical reliability charts
- Quality score breakdowns

**Group Audit:**
- Analyze entire categories for optimization opportunities
- Get recommendations for better alternatives
- Filter by date range (7/30/90 days, all time, custom)
- Dismiss irrelevant suggestions
- Bulk workflow for systematic improvement

---

## Security Notes

### File Permissions

Setup automatically secures sensitive files:
- `.db_bootstrap` (chmod 0600) - Database credentials
- `.installed` (chmod 0600) - Installation marker

### User Management Best Practices

- Use strong passwords (minimum 8 characters, longer is better)
- Don't share user credentials between people
- Monitor failed login attempts regularly
- Remove user accounts when team members leave
- Keep the first admin account as a recovery account

### Rate Limiting

- 5 failed login attempts = 15 minute lockout
- Applies per username AND per IP address
- Admin can manually reset via User Management tab
- Automatic cleanup of old login attempt records

### Optional Hardening

See `SECURITY.md` for additional security measures:
- `.htaccess` rules to protect dotfiles
- Moving `.db_bootstrap` outside web root
- SSL/TLS configuration
- Firewall rules

### Playlist Security

Browser upload system stores playlists temporarily in `/playlists` directory (0700 permissions, protected by `index.php`). Files are automatically deleted after successful import.

⚠️ **Important:** If manually uploading playlists via SFTP/SSH, never store them in web-accessible directories. Playlist files contain sensitive stream URLs and credentials.

---

## Technical Details

### Database Schema
- **channels** - Channel metadata (tvg-id, name, logo, group)
- **feeds** - Feed URLs, current status, and quality metrics
- **channel_feeds** - Many-to-many relationship (supports duplicate feeds per channel)
- **feed_checks** - Historical check data with timestamps and results
- **feed_check_queue** - Smart scheduling queue for automated monitoring
- **feed_id_mapping** - URL hash mapping for feed ID persistence across imports
- **stream_preview_lock** - Mutex coordination for single-connection stream preview
- **epg_data** - Electronic Program Guide data with schedule information
- **group_associations** - Named association groups for cross-regional discovery
- **group_association_prefixes** - Links prefixes to associations (many-to-many)
- **editor_todo_list** - Active tasks awaiting completion
- **editor_todo_list_log** - Historical record of completed/deleted tasks
- **group_audit_ignores** - User-dismissed feed recommendations
- **settings** - Application configuration (playlist URL, EPG URL, timezone, etc.)
- **users** - Authentication and user management
- **login_attempts** - Security logging and rate limiting

### Feed Checking Logic
1. Queue selects next batch of due feeds based on smart scheduling
2. FFprobe analyzes stream metadata (resolution, FPS, codec)
3. HTTP response codes and reachability recorded
4. Results stored in feed_checks table
5. Reliability score recalculated over 7-day rolling window
6. Next check scheduled: 72 hours (healthy) or progressive backoff (failed)

### Reliability Calculation
- Rolling 7-day (168-hour) window
- Percentage of successful checks vs total checks in window
- Recent checks weighted slightly higher
- Displayed as percentage (0-100%)
- Updates automatically with each new check

### EPG Processing
- Downloads from remote URL (supports gzip/zip compression)
- Auto-detects format via magic bytes inspection
- Filters to current + 3 days prior (rolling window)
- Converts timestamps to configured timezone
- Batch inserts with connection keep-alive for large files
- Cleans stale data older than 4 days

---

## Troubleshooting

### "Database connection failed"

**Check credentials:**
```bash
cd /path/to/installation
cat .db_bootstrap
```

**Test connection:**
```bash
mysql -h hostname -u username -p database_name
```

**Verify database is running:**
```bash
systemctl status mysql
# or
systemctl status mariadb
```

**Check permissions:**
```sql
SHOW GRANTS FOR 'username'@'hostname';
```

User needs: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER

### "Setup already completed"

To re-run setup:
```bash
cd /path/to/installation
rm .installed
```

**Warning:** This will prompt you to recreate admin user. Existing users remain in database.

### "This migration script is for v1.3+ installations only"

This error from `migrate.php` means you're running a version before v1.3.

**Solution:** Follow the [Upgrading from before v1.3](#upgrading-from-before-v13) section above.

### Blank page after login

**Check error log:**
```bash
cd /path/to/installation
tail -f php_errors.log
```

**Common causes:**
- Missing database connection
- Missing required PHP extensions
- File permission issues

**Verify PHP extensions:**
```bash
php -m | grep -E "pdo|mysql|session|curl|mbstring|zip"
```

### Account locked out

**After 5 failed login attempts, accounts lock for 15 minutes.**

**Option 1:** Wait 15 minutes for automatic unlock

**Option 2:** Admin resets via User Management tab

**Option 3:** Manual database reset:
```sql
DELETE FROM login_attempts WHERE username = 'locked_username';
```

### Permission denied errors

**Fix file permissions:**
```bash
cd /path/to/installation
chmod 755 .
chown -R www-data:www-data .  # Or your web server user
```

**Find your web server user:**
```bash
# Apache
ps aux | grep apache | head -1

# Nginx
ps aux | grep nginx | head -1
```

### FFmpeg/FFprobe not found

**Install on Ubuntu/Debian:**
```bash
sudo apt-get update
sudo apt-get install ffmpeg
```

**Install on CentOS/RHEL:**
```bash
sudo yum install epel-release
sudo yum install ffmpeg
```

**Verify installation:**
```bash
which ffmpeg
which ffprobe
ffprobe -version
```

### Session timeout issues

Sessions timeout after 30 minutes of inactivity by default.

**To adjust, edit `auth.php`:**
```php
const SESSION_TIMEOUT = 1800; // 30 minutes in seconds
```

Change value to desired timeout in seconds.

---

## Configuration Files

### `.db_bootstrap` (JSON)
Contains database connection credentials.

**Location:** Application root  
**Permissions:** 0600 (read/write owner only)  
**Protected:** Yes (automatic chmod by setup)

**Example structure:**
```json
{
    "host": "localhost",
    "port": 3306,
    "name": "dbname",
    "user": "dbuser",
    "pass": "password",
    "charset": "utf8mb4"
}
```

### `.installed` (Marker file)
Prevents setup from running multiple times.

**Location:** Application root  
**Permissions:** 0600  
**To re-run setup:** Delete this file

---

## Database Schema

### Version 2.1 Tables

**Core tables:**
- `channels` - Channel metadata
- `feeds` - Feed URLs and current status
- `feed_id_mapping` - URL hash mapping for feed ID persistence
- `channel_feeds` - Many-to-many relationship (supports duplicates)
- `feed_checks` - Historical check data
- `feed_check_queue` - Monitoring schedule
- `stream_preview_lock` - Mutex coordination for stream preview

**EPG tables:**
- `epg_data` - Electronic Program Guide storage

**Association tables:**
- `group_associations` - Named association groups
- `group_association_prefixes` - Prefix-to-association mappings

**Task tables:**
- `editor_todo_list` - Active tasks
- `editor_todo_list_log` - Task history

**Management tables:**
- `settings` - Application configuration
- `users` - User accounts and authentication
- `login_attempts` - Security logging and rate limiting 
- `group_audit_ignores` - User-dismissed feed recommendations

## Getting Help

### Before Asking for Help

1. Check your PHP error log
2. Verify database connection works
3. Ensure all PHP extensions are installed
4. Check web server error logs
5. Verify cron jobs are running
6. Try the troubleshooting steps above

### Reporting Issues

**GitHub Issues:** https://github.com/ottstreamscore/ottstreamscore/issues

**Include in your report:**
- PHP version: `php -v`
- Database version: `mysql --version`
- Error messages from `php_errors.log` and `cron_epg_errors.log`
- Steps to reproduce the issue
- Installation type (fresh vs upgrade)
- Previous version (if upgrading)
---

## Version History

**v2.1** (December 2025) - EPG integration, task management, group associations, feed comparison  
**v1.5** (December 2025) - Native video player for stream previews  
**v1.4** (December 2025) - User Management, migration interface  
**v1.3** (December 2025) - Authentication system, database-driven configuration, admin panel, setup interface  
**v1.2 and earlier** - File-based configuration (config.php)

---

## Additional Documentation

- **[README.md](README.md)** - Feature overview and quick start
- **[SECURITY.md](SECURITY.md)** - Security best practices
- **[RELEASE_NOTES_2.1.md](RELEASE_NOTES_2.1.md)** - Version 2.1 changelog

---

**Current Version:** 2.1  
**Release Date:** December 2025  
**Support:** GitHub Issues and Documentation