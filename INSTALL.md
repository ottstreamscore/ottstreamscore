# OTT Stream Score v1.3 - Installation & Upgrade Guide

## Overview

Version 1.3 introduces a secure authentication system and streamlined configuration. All users (new installations and upgrades) will run `setup.php` once.

---

## Fresh Installation

### Requirements
- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.2+
- FFmpeg and FFprobe installed on server
- Web server (Apache/Nginx)

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
   - Configure stream host URL (the custom domain you configured in your panel specifically for serving streams, or the provided stream domain with http://)
   - Set timezone
   - Configure monitoring settings (batch size, lock duration, recheck intervals)
   - Create admin user account (username, password, email)

5. **Login**
   
   Navigate to `https://yourdomain.com/path/to/login.php` and login with your admin credentials.

6. **Import your playlist**
   
   Go to Admin → Playlist tab to import your M3U playlist file.


**Note:** You can safely remove setup.php from your server after the installation is complete. 

---

## Upgrading from Previous Versions

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

2. **Run setup**
   
   **Via browser:** Navigate to `https://yourdomain.com/path/to/setup.php`
   
   **Via CLI:**
   ```bash
   php setup.php
   ```

3. **Setup detects existing installation**
   - Automatically reads your current `config.php` settings
   - Shows detected configuration for confirmation
   - Migrates all settings to database
   - Creates new authentication tables
   - Prompts you to create admin user account
   - Deletes `config.php` after successful migration

4. **Login with new admin account**
   
   Navigate to `https://yourdomain.com/path/to/login.php`

5. **Verify settings**
   
   Go to Admin → Application Settings and verify all settings migrated correctly.


**Note:** You can safely remove setup.php from your server after the installation is complete. 

### What Gets Migrated

Setup automatically migrates these settings from `config.php`:
- Database credentials (host, port, name, user, password)
- Stream host URL
- Timezone
- Batch size
- Lock duration (minutes)
- OK recheck hours (how long to wait before rechecking working streams)
- Failed feed retry intervals (min and max)

### Files Removed by Setup

The following file is automatically deleted during upgrade:
- `config.php` (replaced by database-driven settings + `.db_bootstrap`)

### Deprecated Files (Safe to Delete)

These files are no longer used and can be manually removed:
- `install_db.php` (replaced by `setup.php`)
- `process_playlist.php` (incorporated into Admin panel)
- `rotate_creds.php` (incorporated into Admin panel)
- `process_playlist_run.php` (replaced by import_handler.php)

**To remove:**
```bash
cd /path/to/your/installation
rm -f install_db.php process_playlist.php rotate_creds.php
```

---

## Post-Installation

### Accessing the Application

Once installed, access your application at:
- **Dashboard:** `https://yourdomain.com/path/to/index.php` (or your configured web path)
- **Login:** `https://yourdomain.com/path/to/login.php`
- **Admin Panel:** `https://yourdomain.com/path/to/admin.php`

### Admin Panel Features

**Application Settings:**
- Stream host configuration (base URL for authenticated streams)
- Timezone settings
- Monitoring intervals (batch size, lock duration)
- Retry logic configuration (healthy feeds recheck, failed feed retry intervals)

**Sync Playlist:**
- Import M3U playlist files
- Choose directory (defaults to application root)
- Sync mode (update existing) or Insert-only mode
- Supports both fresh imports and playlist updates

**Update Stream Credentials:**
- Update stream credentials (bulk update /live/ URLs)
- Force immediate recheck of affected feeds
- Automatically regenerates URL hashes

**Database:**
- Update database credentials
- Test connection before saving
- Changes are written to `.db_bootstrap` file

**Change Password:**
- Change your user account password

---

### Setting Up Cron Job

Feed checking is handled by a cron-driven worker script. This is required — feed checking does not happen via the web UI.

Add this to your crontab to process feed checks:
```bash
*/5 * * * * cd /path/to/your/installation && php cron_check_feeds.php >> /dev/null 2>&1
```

**Requirements:**
- Must run via PHP CLI, not HTTP

**Recommended schedule:**
- `*/5 * * * *` - Every 5 minutes (standard)
- `*/10 * * * *` - Every 10 minutes (lighter load)
- `*/15 * * * *` - Every 15 minutes (minimal load)

Adjust based on your feed count and server capacity.

---

## Security Notes

### File Permissions

Setup automatically secures sensitive files with `chmod 0600`:
- `.db_bootstrap` (database credentials)
- `.installed` (installation marker)

### Optional Hardening

See `SECURITY.md` for additional security measures:
- `.htaccess` rules to protect dotfiles
- Moving `.db_bootstrap` outside web root
- SSL/TLS configuration
- Firewall rules

### Playlist Security

⚠️ **Important:** Store M3U playlist files in non-public directories that cannot be accessed via browser. Playlist files contain sensitive stream URLs and credentials.

---

## Troubleshooting

### "Database connection failed"
- Verify database credentials in `.db_bootstrap` (JSON file in application root)
- Check database server is running: `systemctl status mysql` or `systemctl status mariadb`
- Ensure database user has proper permissions (SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER)
- Test connection manually: `mysql -h hostname -u username -p database_name`

### "Setup already completed"
If you need to re-run setup:
```bash
cd /path/to/your/installation
rm .installed
```
**Warning:** This will prompt you to recreate admin user. Existing users will remain in database.

### Blank page after login
Check `php_errors.log` in the application directory:
```bash
cd /path/to/your/installation
tail -f php_errors.log
```

Common causes:
- Missing `db()` function - ensure `db.php` has the helper function
- Database connection issues
- Missing required PHP extensions

### "Call to undefined function db()"
Add this to `db.php` at the bottom (after the constants):
```php
/**
 * Legacy helper - alias for get_db_connection()
 */
function db(): PDO
{
    return get_db_connection();
}
```

### Session timeout issues
Sessions timeout after 30 minutes of inactivity by default. To adjust, edit `auth.php`:
```php
const SESSION_TIMEOUT = 1800; // 30 minutes in seconds
```

### Rate limiting lockout
After 5 failed login attempts, accounts lock for 15 minutes.
- **Wait:** 15 minutes for automatic unlock
- **Or manually clear:** 
```sql
DELETE FROM login_attempts WHERE username = 'your_username';
```

### Permission denied errors
Ensure web server can write to application directory:
```bash
cd /path/to/your/installation
chmod 755 .
chown -R www-data:www-data .  # Or your web server user
```

### FFmpeg/FFprobe not found
Install on Ubuntu/Debian:
```bash
sudo apt-get update
sudo apt-get install ffmpeg
```

Verify installation:
```bash
which ffmpeg
which ffprobe
```

---

## Configuration Files

### `.db_bootstrap` (JSON)
Contains database connection credentials. Created by setup.php.

**Location:** Application root  
**Permissions:** 0600 (read/write owner only)  
**Protected:** Yes (automatic chmod)

### `.installed` (Marker file)
Prevents setup from running multiple times.

**Location:** Application root  
**Permissions:** 0600  
**To re-run setup:** Delete this file

---

## Database Schema Changes

Version 1.3 adds these tables:

**`settings`** - Application configuration (replaces config.php)  
**`users`** - Authentication and user management  
**`login_attempts`** - Rate limiting and security logging  

Existing tables are unchanged. No data loss during migration.

---

## Getting Help

**Common issues:**
- Review `SECURITY.md` for security best practices
- Verify database permissions (GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER)
- Ensure all PHP extensions are installed
- Check web server error logs: `/var/log/apache2/error.log` or `/var/log/nginx/error.log`

**Report issues:**
- GitHub: https://github.com/ottstreamscore/ottstreamscore/issues
- Include error messages from `php_errors.log`
- Include PHP version and database version
- Describe steps to reproduce the issue

---

## Version History

**v1.3** - Authentication system, database-driven configuration, admin panel
**v1.2 and earlier** - File-based configuration (config.php)