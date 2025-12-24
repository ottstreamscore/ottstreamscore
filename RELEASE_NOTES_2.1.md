# OTT Stream Score v2.1 - Release Notes

**Release Date:** December 2025  
**Compatibility:** PHP 8.1+, MySQL 5.7+/MariaDB 10.2+

---

## 🎯 Major Features

### 📺 Playlist Management Redesign
**URL-Based sync replaces file uploads**
- Enter playlist URL instead of uploading files
- Real-time download with MB progress tracking
- Preview entries and channel count before importing
- One-click credential rotation without re-downloading
- Visual progress indicators and detailed import results

### 📅 EPG Integration
**Electronic Program Guide with automated syncing**
- Configure EPG XML URL for scheduled synchronization
- Automatic format detection (gzip, zip, raw XML)
- View program schedules directly on channel pages
- Compare programming between similar channels
- Rolling 4-day data window (today + 3 days prior)
- Twice-daily cron updates with failure logging

### 🃏 Channel Page Redesign
**Modern card-based interface**
- Clean card layout replacing cluttered tables
- Sidebar with real-time search (group, channel, filename)
- Client-side sorting by score, reliability, resolution, FPS, codec
- Color-coded score badges: 🟢 Green (75+), 🟡 Yellow (50-74), 🔴 Red (<50)
- Toggle EPG display per feed with current program highlighting
- Best feed indicator with visual distinction

### 🔗 Group Associations
**Cross-regional feed discovery**
- Create named associations (e.g., "English Speaking", "North America")
- Link regional prefixes together (US|, UK|, CA|)
- Discover backup streams across regions automatically
- Find alternatives with similar tvg-ids when exact matches don't exist
- Results grouped by association for easy navigation

### 📋 Task Management
**Collaborative workflow system**
- Create tasks while analyzing feeds without context switching
- Task types: Feed Replacement, Feed Review, EPG Adjustment, Other
- Assign alternative feeds with full comparison data
- Filter by type, group, creator, or date range
- Complete audit trail tracking task lifecycle
- Real-time search across all task fields

### 🎯 Group Audit Enhancements
**Systematic playlist optimization**
- Analyze entire channel groups for better alternatives
- Date range filtering (7/30/90 days, all time, custom)
- Visual indicators show optimization opportunities
- Ignore system to dismiss irrelevant recommendations
- View and manage all ignored suggestions
- Side-by-side feed comparison with EPG verification

### 🔍 Enhanced DataTables Filtering
**Advanced search and filtering across the application**
- Multi-filter sidebar with instant search
- Searchable group dropdown with hundreds of options
- Prefix-based selection (e.g., "US| (156 groups)")
- Individual group selection alongside prefix filters
- Table state persistence across navigation
- Quick filter bar on Feeds page (Top Feeds, Dead Feeds, Unstable, Never Checked, Last 24h)

### 📊 Streamlined Reports
**Focused on optimization workflows**
- Group Audit as primary interface
- Removed redundant Feed Report tab
- Quick filters on Feeds page replace legacy reporting
- Cleaner, more focused user experience

---

## 🔧 Improvements

### 🎬 Stream Preview - AC-3 Audio Handling
Fixed AC-3 (Dolby Digital) audio codec compatibility in MPEG-TS streams.

**Before:** Streams with AC-3 audio failed completely  
**After:** Video plays without audio, warning banner explains browser limitation

### 📊 Feed Count Accuracy
Fixed channel feed counts to properly aggregate across channels sharing the same tvg-id instead of counting only individual channel associations.

### 🔤 Special Character Support
Resolved handling of special characters in group names (×, ⱽᴵᴾ, ᴿᴬᵂ, superscripts) by using index-based selection in dropdowns.

### Bug Fix (v2.1)
Table creation error in setup.php

---

## 💾 Database Changes

### New Tables
- `epg_data` - Electronic Program Guide storage
- `group_associations` - Named association groups
- `group_association_prefixes` - Prefix-to-association mappings
- `editor_todo_list` - Active task tracking
- `editor_todo_list_log` - Task completion history

### Modified Tables
All existing tables remain unchanged. v2.1 is fully backward compatible.

---

## 📁 New Files

### Core Functionality
- `tasks.php` - Task management interface
- `cron_epg.php` - EPG download and sync automation
- `get_epg_data.php` - AJAX endpoint for EPG program retrieval
- `playlist_api.php` - Playlist and EPG URL management API

### Task System
- `get_feed_details.php` - Feed detail AJAX endpoint
- `get_feed_alternatives.php` - Alternative feed discovery for tasks
- `create_task.php` - Task creation API

### Styling
- `style.css` - Application stylesheet
- `custom.css` - User-customizable styles

---

## 🔄 Modified Files

Key updates across the application:
- `channel.php` - Complete redesign with cards, sidebar, EPG integration, dynamic scoring
- `_bottom.php` - Task modal, EPG display, stream player integration
- `admin.php` - EPG configuration, cron settings, association management
- `feeds.php` - Quick filter bar, enhanced search, state persistence
- Plus updates to core infrastructure files for new features

---

## ⬆️ Upgrading to v2.1

### From v1.3+ (Recommended)

**Quick upgrade in 3 steps:**
1. Backup database and files
2. Upload new files (safe to overwrite)
3. Run migration: `https://yourdomain.com/migrate.php`

**No downtime required.** Migration completes in seconds.

### From Before v1.3

Must upgrade to v1.3 first, then to v2.1.  
**See INSTALL.md for complete instructions.**

---

## 📖 Documentation

- **[INSTALL.md](INSTALL.md)** - Installation and upgrade guide
- **[README.md](README.md)** - Feature overview and workflows
- **[SECURITY.md](SECURITY.md)** - Security best practices
- **[PLAYER.md](PLAYER.md)** - Stream player documentation

---

## ⚠️ Breaking Changes

**None.** Version 2.0 is fully backward compatible with v1.3+.

All existing data, configurations, and workflows remain unchanged.

---

## Version History

**Current Version:** 
**v2.1** (December 2025) - Bug fix.
**v2.0** (December 2025) - EPG integration, task management, group associations, feed comparison  

**Previous Versions:**
**v1.5** (December 2025) - Native video player for stream previews  
**v1.4** (December 2025) - User Management, migration interface  
**v1.3** (December 2025) - Authentication system, database-driven configuration, admin panel, setup interface  
**v1.2 and earlier** - File-based configuration (config.php)

For complete installation and upgrade instructions, see [INSTALL.md](INSTALL.md).