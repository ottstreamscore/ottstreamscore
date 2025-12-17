# OTT Stream Score v1.4 - Release Notes

**Release Date:** December 2025  
**Compatibility:** PHP 8.0+, MySQL 5.7+/MariaDB 10.2+

---

## 🚀 Major Features

### 👥 User Management System
Complete multi-user administration interface with comprehensive user account management.

**Key Features:**
- **User List View:** 
  - Display all users with username, last login timestamp, and failed login attempts
  - Visual badges identify current user and the original admin account
  - Real-time display of invalid login attempts from last 15 minutes
  - Sort and filter user accounts
  
- **Create New Users:**
  - Simple user creation form with username and password
  - Password confirmation with client-side validation
  - Server-side validation ensures username uniqueness
  - Username format validation (3-50 characters, alphanumeric, underscore, dash)
  - Minimum 8-character password requirement
  
- **Security Management:**
  - **Reset Lockout:** Clear failed login attempts with one click
  - **View Login Logs:** Modal displays failed attempt details including IP addresses and timestamps
  - Rate limiting protection (5 failed attempts locks account for 15 minutes)
  - Automatic cleanup of login attempt history (older than 1 hour)
  
- **User Deletion:**
  - Protected deletion rules:
    - Cannot delete your own account
    - Cannot delete the first user created (original admin)
  - Safe deletion removes user and preserves system integrity

**Why This Matters:**
- **Multi-user Support:** Share access with team members without sharing passwords
- **Audit Trail:** Track login activity and security events per user
- **Security Controls:** Manage lockouts and monitor suspicious login attempts
- **Account Protection:** Built-in safeguards prevent accidentally locking yourself out

Access User Management from **Admin Panel → User Management tab**.

### 🔄 Manual Feed Checks
Initiate on-demand feed checks from the Feed History page without waiting for automated cron runs.

### 📊 Export and Copy
Export or copy table data to clipboard from all DataTables throughout the application.

---

## 🐛 Bug Fixes

### Database Schema
- **Fixed missing login_attempts table:** Added table creation to setup.php (was referenced in auth.php but not created during installation)

---

## 🔄 Migration Tools

### New: migrate.php Script
Dedicated migration script for users upgrading from v1.3 or later.

**Key Features:**
- **Version Detection:** Automatically verifies v1.3+ installation before proceeding
- **Safe Migration:** Creates login_attempts table if missing
- **Idempotent:** Safe to run multiple times (skips if table exists)
- **Dual Interface:** Works via web browser or command line

**Usage:**
```bash
# Via browser
https://yourdomain.com/path/to/migrate.php

# Via CLI
php migrate.php
```

**What It Does:**
1. Validates database configuration
2. Checks for v1.3+ required tables
3. Creates `login_attempts` table if missing
4. Reports migration status with clear visual feedback

---

## 📋 Technical Changes

### Database Schema Updates
- **New Table: `login_attempts`**
  - Tracks all login attempts (successful and failed)
  - Stores: IP address, username, timestamp, success status
  - Indexed for fast querying by IP, username, and timestamp
  - Automatic cleanup of records older than 1 hour
  - Enables rate limiting and security monitoring

### Security Enhancements
- Enhanced user management with granular controls
- Failed login attempt tracking per user account
- Visual security indicators in admin interface
- IP address logging for security auditing

### Code Improvements
- Enhanced username validation with regex patterns

### Admin Interface Updates
- New "User Management" tab in admin panel
- Real-time failed attempt counters

---

## ⬆️ Upgrading to v1.4

### From v1.3 (Recommended Path)

**Quick Upgrade:**
1. Backup your installation and database
2. Upload all new files (safe to overwrite)
3. Run migration: `https://yourdomain.com/migrate.php`
4. Verify User Management tab appears in Admin panel

**No downtime required.** Migration takes seconds.

### From Before v1.3

Users upgrading from versions prior to v1.3 should follow the upgrade instructions in the v1.3 release notes first, then upgrade to v1.4.

**See INSTALL.md for complete upgrade instructions.**

---

## 🔐 Security Notes

### User Management Best Practices
- Use strong passwords (minimum 8 characters recommended, longer is better)
- Monitor failed login attempts regularly
- Remove user accounts when team members leave
- Don't share user credentials between people
- Keep the first admin account as a recovery account

### Rate Limiting
- 5 failed login attempts = 15 minute lockout
- Lockout applies per username AND per IP address
- Admin can manually reset lockouts via User Management tab
- Automatic cleanup prevents database bloat

### Protected Operations
All user management operations are:
- CSRF protected
- Authentication required
- Input validated and sanitized

---

## 📖 Documentation Updates

- **INSTALL.md** - Added migration instructions for v1.3+ users
- **README.md** - Updated with User Management features
- **migrate.php** - Inline documentation and clear error messages

---

## ⚠️ Breaking Changes

**None.** Version 1.4 is fully backward compatible with v1.3.

All existing installations will continue to work. The User Management tab will appear automatically after running `migrate.php`.

---

## 🎯 Coming Soon

Future enhancements being considered:
- Role-based permissions (admin vs viewer)
- Email notifications for security events
- Two-factor authentication
- Turnstile integration for login
- Webhooks

---

## 🙏 Acknowledgments

Thank you to the community for feedback!

---

**Previous Version:** 1.3 (December 2025)  
**Current Version:** 1.4 (December 2025)  
**Next Version:** TBD 

For complete installation and upgrade instructions, see [INSTALL.md](INSTALL.md).