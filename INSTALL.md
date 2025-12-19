# OTT Stream Score v1.5 - Installation & Upgrade Guide

## Overview

Version 1.5 adds native stream preview functionality, browser-based playlist upload, and improved playlist import handling. Database changes include the `stream_preview_lock` table (for preview coordination) and `feed_id_mapping` table (for playlist import deduplication). Choose your installation path below based on your situation.

---

## Table of Contents

1. [Fresh Installation](#fresh-installation) - New installations
2. [Upgrading from v1.3+](#upgrading-from-v13) - Use migrate.php
3. [Upgrading from before v1.3](#upgrading-from-before-v13) - Use setup.php first
4. [Post-Installation](#post-installation) - All users
5. [Troubleshooting](#troubleshooting) - Common issues

---

## Fresh Installation

For brand new installations of OTT Stream Score.

### Requirements
- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.2+
- FFmpeg and FFprobe installed on server
- Web server (Apache/Nginx)
- Session support enabled in PHP

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

6. **Import your playlist**
   
   Go to Admin → Sync Playlist tab to import your M3U playlist file.

7. **Setup cron job** (required for feed monitoring)
   ```bash
   */5 * * * * cd /path/to/installation && php cron_check_feeds.php >> /dev/null 2>&1
   ```

8. **Secure your installation**
   ```bash
   # Delete or move setup.php outside webroot
   rm setup.php
   
   # Or move it
   mv setup.php ../setup.php.bak
   ```

**✅ Installation complete!** Access your dashboard at `https://yourdomain.com/path/to/`

---

## Upgrading from v1.3+

**For users currently running v1.3 or later.**

This is the simplest upgrade path using the new `migrate.php` script.

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
   - Create tables added post v1.3
   - Create `/playlists` directory if missing (0700 permissions with protection)
   - Report success or skip if table/directory already exists

4. **Clean up** (optional)
   ```bash
   # Remove migration script
   rm migrate.php
   ```

**✅ Upgrade complete!** Your installation now supports User Management.

### What Gets Migrated

The migration script:
- ✅ Adds new tables created post v1.3
- ✅ Safe to run multiple times (idempotent)

### Expected Output

**CLI mode:**
```
======================================================================
OTT Stream Score - Database Migration (v1.3+)
======================================================================

✅ Created <<Table Name>>

======================================================================
✅ Migration completed successfully!
======================================================================
```

**Web mode:**
- Visual interface with migration status
- Link to User Management when complete
- Error handling with clear messaging

---

## Upgrading from before v1.3

**For users running versions 1.2 or earlier.**

If you're upgrading from before v1.3, you must first read and follow the upgrade instructions from the v1.3 release, then upgrade to v1.5.

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

**Step 2: Upgrade to v1.5**

Once you're successfully running v1.3:

1. **Get v1.5 files:**
   ```bash
   cd /path/to/installation
   git checkout main
   # or: git checkout v1.5
   ```

2. **Run migration:**
   ```bash
   php migrate.php
   ```

3. **Access User Management** in Admin panel

**✅ Upgrade complete!**

### Key Migration Notes

**From pre-v1.3 to v1.3:**
- config.php is deprecated and replaced by database settings
- Authentication is now required (create admin account)
- Database credentials move to `.db_bootstrap` file

**From v1.3 to v1.4:**
- Adds login_attempts table for User Management
- No breaking changes
- All v1.3 features remain unchanged

**From v1.4 to v1.5:**
- Adds native video player for stream previews
- Adds browser-based playlist upload with automatic cleanup
- Adds stream_preview_lock table for preview system
- Adds feed_id_mapping table to improve playlist import deduplication
- Creates protected /playlists directory for temporary upload storage
- No breaking changes
- All v1.4 features remain unchanged

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

**Sync Playlist:**
- Browser-based playlist upload (supports 80MB+ files with progress tracking)
- Import M3U playlist files with automatic cleanup
- Sync mode (update existing) or Insert-only mode

**Update Stream Credentials:**
- Bulk update /live/ URLs with new credentials
- Force immediate recheck of affected feeds

**User Management:** 
- View all user accounts
- Create new users
- Monitor failed login attempts
- Reset account lockouts
- Delete users (with safeguards)

**Database:**
- Update database credentials
- Test connection before saving

**Change Password:**
- Update your user account password

### Setting Up Cron Job

**Required** for feed monitoring:
```bash
*/5 * * * * cd /path/to/installation && php cron_check_feeds.php >> /dev/null 2>&1
```

**Frequency recommendations:**
- `*/5 * * * *` - Every 5 minutes (standard)
- `*/10 * * * *` - Every 10 minutes (lighter load)
- `*/15 * * * *` - Every 15 minutes (minimal load)

Adjust based on your feed count and server capacity.

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

**Browser-based upload:** v1.5+ includes a browser upload system that stores playlists temporarily in `/playlists` directory (0700 permissions, protected by `index.php`). Files are automatically deleted after successful import.

⚠️ **Important:** If manually uploading playlists via SFTP/SSH, never store them in web-accessible directories. Playlist files contain sensitive stream URLs and credentials.

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
php -m | grep -E "pdo|mysql|session"
```

### User Management tab not showing

**Verify login_attempts table exists:**
```sql
SHOW TABLES LIKE 'login_attempts';
```

**If missing, run migration:**
```bash
php migrate.php
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

### Version 1.5 Tables

**Core tables:**
- `channels` - Channel metadata
- `feeds` - Feed URLs and current status
- `feed_id_mapping` - URL hash mapping table for feed ID persistence across playlist imports *(added in v1.5)*
- `channel_feeds` - Many-to-many relationship (supports duplicates)
- `feed_checks` - Historical check data
- `feed_check_queue` - Monitoring schedule
- `stream_preview_lock` - Mutex coordination table for stream preview system *(added in v1.5)*

**Management tables:**
- `settings` - Application configuration
- `users` - User accounts and authentication
- `login_attempts` - Security logging and rate limiting 
- `group_audit_ignores` - User-dismissed feed recommendations

### Schema Verification

**Check all tables exist:**
```sql
SHOW TABLES;
```

## Getting Help

### Before Asking for Help

1. Check `php_errors.log` in application directory
2. Verify database connection works
3. Ensure all PHP extensions are installed
4. Check web server error logs
5. Try the troubleshooting steps above

### Reporting Issues

**GitHub Issues:** https://github.com/ottstreamscore/ottstreamscore/issues

**Include in your report:**
- PHP version: `php -v`
- Database version: `mysql --version`
- Error messages from `php_errors.log`
- Steps to reproduce the issue
- Installation type (fresh vs upgrade)
- Previous version (if upgrading)

### Useful Commands

**Check PHP version:**
```bash
php -v
```

**Check database version:**
```bash
mysql --version
```

**Test database connection:**
```bash
mysql -h host -u user -p database
```

**View PHP extensions:**
```bash
php -m
```

**Check cron execution:**
```bash
grep cron_check_feeds /var/log/syslog | tail -20
```

**View recent feed checks:**
```sql
SELECT * FROM feed_checks ORDER BY checked_at DESC LIMIT 10;
```

---

## Version History

**v1.5** (December 2025) - Native video player for stream previews
**v1.4** (December 2025) - User Management, migration interface
**v1.3** (December 2025) - Authentication system, database-driven configuration, admin panel, setup interface
**v1.2 and earlier** - File-based configuration (config.php)

---

## Additional Documentation

- **[README.md](README.md)** - Feature overview and quick start
- **[SECURITY.md](SECURITY.md)** - Security best practices
- **[RELEASE_NOTES_1.5.md](RELEASE_NOTES_1.5.md)** - Version 1.5 changelog

---

**Current Version:** 1.5  
**Release Date:** December 2025  
**Support:** GitHub Issues and Documentation