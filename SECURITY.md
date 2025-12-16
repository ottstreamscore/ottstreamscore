# Security Guide

## Automatic Protections

OTT Stream Score includes built-in security features that are automatically applied:

### File Permissions
Setup automatically sets secure permissions:
- `.db_bootstrap` - `chmod 0600` (database credentials)
- `.installed` - `chmod 0600` (installation marker)

### Authentication
- Session-based authentication with 30-minute timeout
- Password hashing with bcrypt
- CSRF protection on all forms
- Rate limiting: 5 failed attempts = 15 minute lockout
- Session regeneration on login (prevents session fixation)
- Secure session cookies (httpOnly, sameSite)

### Input Validation
- All user inputs sanitized
- Prepared statements for database queries (prevents SQL injection)
- XSS protection via `htmlspecialchars()` on all output

### Login Security
- Failed login attempts logged to database
- Rate limiting by IP address and username
- Account lockout after repeated failures
- Last login timestamp tracking

---

## Optional Hardening

### Protect Dotfiles (.htaccess)

Add to your `.htaccess` file:

```apache
# Deny access to dotfiles
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>

# Specifically protect sensitive files
<Files ".db_bootstrap">
    Order allow,deny
    Deny from all
</Files>

<Files ".installed">
    Order allow,deny
    Deny from all
</Files>

<Files "php_errors.log">
    Order allow,deny
    Deny from all
</Files>
```

For Nginx, add to your site configuration:

```nginx
location ~ /\. {
    deny all;
    access_log off;
    log_not_found off;
}

location ~ ^/(\.db_bootstrap|\.installed|php_errors\.log) {
    deny all;
}
```

### Move Sensitive Files Outside Web Root

**Best practice:** Move `.db_bootstrap` outside your web-accessible directory.

```bash
# Move file
mv .db_bootstrap /home/user/private/.db_bootstrap

# Update db.php (line 31)
$bootstrap_file = '/home/user/private/.db_bootstrap';
```

### Use Environment Variables

For maximum security, use environment variables instead of `.db_bootstrap`:

1. Set environment variables in your web server config
2. Modify `db.php` to read from `$_ENV` or `getenv()`

Example (Apache):
```apache
SetEnv DB_HOST "localhost"
SetEnv DB_NAME "your_database"
SetEnv DB_USER "your_user"
SetEnv DB_PASS "your_password"
```

---

## Best Practices

### Playlist Files
⚠️ **Critical:** Never store M3U playlist files in web-accessible directories. They contain sensitive stream credentials.

**Recommended locations:**
- `/home/user/playlists/` (outside web root)
- `/var/private/playlists/`
- Any directory not accessible via HTTP

### HTTPS/SSL
Always use HTTPS in production:
- Prevents credential interception
- Protects session cookies
- Required for `secure` cookie flag

### Database Security
- Use strong, unique database passwords
- Limit database user permissions (don't use root)
- Grant only required permissions: `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER`
- Use separate database user for this application

### File Permissions
```bash
# Application files
chmod 644 *.php
chmod 755 .

# Writable directory (if needed)
chmod 775 uploads/

# Secure sensitive files
chmod 600 .db_bootstrap
chmod 600 .installed
```

### Regular Updates
- Keep PHP updated
- Update MySQL/MariaDB
- Pull latest application updates: `git pull origin main`
- Monitor security advisories

---

## Security Configuration

### Adjust Session Timeout

Edit `auth.php`:
```php
const SESSION_TIMEOUT = 1800; // 30 minutes (default)
const SESSION_TIMEOUT = 3600; // 60 minutes
const SESSION_TIMEOUT = 7200; // 2 hours
```

### Adjust Rate Limiting

Edit `auth.php`:
```php
const MAX_LOGIN_ATTEMPTS = 5;   // Max failed attempts
const LOCKOUT_TIME = 900;       // 15 minutes lockout
const ATTEMPT_WINDOW = 900;     // Window to count attempts
```

### Password Requirements

Current: Minimum 8 characters

To enforce stronger passwords, edit validation in `admin.php` and `setup.php`:
```php
if (strlen($password) < 12) {
    $error = 'Password must be at least 12 characters';
}

// Add complexity requirements
if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $error = 'Password must contain uppercase and numbers';
}
```

---

## Monitoring

### Check Login Attempts
```sql
-- Recent failed logins
SELECT username, ip_address, attempted_at, COUNT(*) as attempts
FROM login_attempts
WHERE success = 0
AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY username, ip_address
ORDER BY attempts DESC;

-- Currently locked out
SELECT ip_address, username, COUNT(*) as attempts, MAX(attempted_at) as last_attempt
FROM login_attempts
WHERE success = 0
AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
GROUP BY ip_address, username
HAVING attempts >= 5;
```

### Monitor Error Logs
```bash
tail -f php_errors.log
```

### Check for Suspicious Activity
- Multiple failed logins from same IP
- Login attempts for non-existent users
- Unusual access times
- Database connection failures

---

## Incident Response

### Account Compromised

1. **Change password immediately** (Admin → Account)
2. **Review login attempts:**
   ```sql
   SELECT * FROM login_attempts 
   WHERE username = 'your_username' 
   ORDER BY attempted_at DESC 
   LIMIT 50;
   ```
3. **Clear all sessions** (logout and delete session files)
4. **Check for unauthorized changes** in database

### Database Credentials Exposed

1. **Create new database user** with different password
2. **Update credentials** (Admin → Database)
3. **Revoke old user permissions:**
   ```sql
   DROP USER 'old_user'@'localhost';
   ```
4. **Review database audit logs** for unauthorized access

### Server Compromise

1. **Restore from backup**
2. **Scan for malware:**
   ```bash
   grep -r "eval(base64" .
   grep -r "system(" .
   ```
3. **Check file modification times:**
   ```bash
   find . -name "*.php" -mtime -7
   ```
4. **Review web server access logs**
5. **Change all passwords**

---

## Security Checklist

### Production Deployment

- [ ] HTTPS enabled with valid SSL certificate
- [ ] `.db_bootstrap` protected (chmod 0600 or moved outside web root)
- [ ] Strong database password (16+ characters)
- [ ] Database user has minimal permissions
- [ ] `.htaccess` or nginx rules protecting dotfiles
- [ ] `php_errors.log` not web-accessible
- [ ] Playlist files in private directories
- [ ] Error display disabled in production (`display_errors = 0`)
- [ ] Regular backups configured
- [ ] Monitoring enabled for failed login attempts
- [ ] Firewall configured (allow only HTTP/HTTPS/SSH)
- [ ] SSH key authentication (disable password login)
- [ ] Server timezone matches application timezone

### Ongoing Maintenance

- [ ] Review login attempts weekly
- [ ] Update application monthly
- [ ] Update server packages monthly
- [ ] Test backups quarterly
- [ ] Review user accounts quarterly
- [ ] Audit file permissions quarterly

---

## Reporting Security Issues

If you discover a security vulnerability:

1. **Do NOT open a public GitHub issue**
2. Email security details to repository maintainers
3. Include:
   - Description of vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if available)

Allow 48-72 hours for initial response.