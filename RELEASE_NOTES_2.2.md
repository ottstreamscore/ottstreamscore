# OTT Stream Score v2.2 - Release Notes

**Release Date:** December 2025  
**Compatibility:** PHP 8.1+, MySQL 5.7+/MariaDB 10.2+

---

## 🎯 Major Features

### ⏸️ System-Wide Pause Control
Take full control of your feed monitoring with the new pause system. Administrators can now instantly pause all automated feed checks directly from the admin panel, giving you the flexibility to manage system resources during maintenance windows or high-traffic periods without stopping the application.

**Benefits:**
- 🛑 Instant control over automated checks
- 🔧 Perfect for maintenance windows
- ⚡ No service interruption required

---

### 📺 Catch-Up Support Detection
Enhance your feed replacement strategy with catch-up capability tracking. The platform now identifies and tracks which feeds support catch-up/timeshift functionality, making it easier to find equivalent replacements when substituting failing feeds.

**New Capabilities:**
- 🔍 Filter feeds by catch-up availability during replacement searches
- 📅 Display catch-up window duration (in days) for accurate matching
- 🎯 Ensure feature parity when replacing feeds

---

### 🗑️ Selective Data Deletion
Administrators now have the ability to selectively wipe playlist or EPG data without reinstalling the entire application. This powerful new feature allows you to start fresh with a new provider, clear corrupted data, or reset test environments—all while maintaining user accounts and system configuration. For security, all deletion operations require primary user (User #1) authentication and dual confirmation to prevent accidental data loss.

**Benefits:**
- 🔄 Start fresh with new providers without reinstalling
- 🎯 Independently delete playlist data, EPG data, or both
- 🔒 Secure deletion with primary user authentication required
- 🛡️ Dual confirmation prevents accidental deletions

---

### ✏️ Enhanced Task Management
Streamline your workflow with improved task editing capabilities. The new inline note editing feature allows team members to update task context without recreating tasks.

**Features:**
- 📝 Edit task notes on existing tasks
- 💬 Support for multi-line notes with formatting

---

### 🏢 Managed Hosting Preparation
The codebase has been prepared for our upcoming managed hosting service.

**What's Coming:**
- ☁️ Fully managed infrastructure
- 🚀 Zero-maintenance deployments
- ⚡ Instant deployment on our EU servers
- 💳 No recurring billing
- ₿ Crypto accepted

---

## 🔧 Improvements

- **Playlist Import**: Implemented batch processing (5,000 entries per batch) to handle large playlists (50k+ channels) without gateway timeouts
- **Import Progress**: Added real-time progress tracking with percentage indicators during playlist sync operations

---

## ⬆️ Upgrading to v2.2

### From v1.3+ (Recommended)

**Quick upgrade in 3 steps:**
1. Backup database and files
2. Upload new files (safe to overwrite)
3. Run migration: `https://yourdomain.com/migrate.php`

**No downtime required.** Migration completes in seconds.

### From Before v1.3

Must upgrade to v1.3 first, then to current release version.  
**See INSTALL.md for complete instructions.**

---

## 📖 Documentation

- **[INSTALL.md](INSTALL.md)** - Installation and upgrade guide
- **[README.md](README.md)** - Feature overview and workflows
- **[SECURITY.md](SECURITY.md)** - Security best practices
- **[PLAYER.md](PLAYER.md)** - Stream player documentation

---

## ⚠️ Breaking Changes

**None.** Version 2.2 is fully backward compatible with v1.3+.

All existing data, configurations, and workflows remain unchanged.

---

## Version History

**Current Version:** 
**v2.2** (December 2025) - Ability to pause feed checks, delete data, task note editing, catch-up support, managed hosting option prep

**Previous Versions:**
**v2.1** (December 2025) - Bug fix.
**v2.0** (December 2025) - EPG integration, task management, group associations, feed comparison  
**v1.5** (December 2025) - Native video player for stream previews  
**v1.4** (December 2025) - User Management, migration interface  
**v1.3** (December 2025) - Authentication system, database-driven configuration, admin panel, setup interface  
**v1.2 and earlier** - File-based configuration (config.php)

For complete installation and upgrade instructions, see [INSTALL.md](INSTALL.md).