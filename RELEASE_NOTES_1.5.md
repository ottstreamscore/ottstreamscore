# OTT Stream Score v1.5 - Release Notes

**Release Date:** December 2025  
**Compatibility:** PHP 8.0+, MySQL 5.7+/MariaDB 10.2+

---

## 🚀 Major Features

### 📺 Built-in Stream Player

**Key Features:**
- **In-Browser Video Player**: Preview any stream directly within OTT Stream Score - no external tools required
- **Universal Format Support**: Automatically detects and plays both MPEG-TS (.ts) and HLS (.m3u8) streams
- **Smart Lock Coordination**: Prevents conflicts between user previews and automated feed checking (single-connection authentication)
- **Real-Time Stream Metadata**: View resolution, frame rate, codec, reliability score, and quality badges (4K/FHD/HD/SD) while watching
- **Secure Streaming**: HTTPS proxy handles mixed content issues transparently
- **System-Wide Availability**: Preview buttons available throughout the application - reports, feed history, channel pages
- **Rapid Switching**: Optimized for quick preview workflows when evaluating multiple streams
  
**Why This Matters:**
Stream validation is now effortless. Instead of copying URLs to external players, or using your fucking remote (like me), click any preview button and watch instantly. The lock system ensures your preview never conflicts with automated checks, while the heartbeat mechanism maintains your connection as long as you're watching. Quality badges let you instantly identify 4K, FHD, HD, or SD streams at a glance. This transforms stream management from a tedious copy-paste workflow into a seamless, integrated experience - preview, evaluate, and move on in seconds. Note: Just like any web player I've used, there are certain streams that just will not fucking load. If you get a blank screen but see stream activity in the modal, double-check the channel/feed with your usual player.

### 📤 Browser-Based Playlist Upload

**No more SFTP/SSH required.** Upload playlists directly through the admin interface with automatic cleanup.

**Key Features:**
- **Chunked Upload**: Handles large playlists (100MB+) without timeout issues
- **Real-Time Progress**: Upload and import progress bars show exactly where you are
- **Auto-Cleanup**: Playlists deleted from server immediately after successful import
- **Protected Storage**: Protected `/playlists` directory (0700 permissions, not web-accessible)

**What Changed:**
- ✅ Upload directly from browser
- ✅ Built-in progress tracking
- ✅ Automatic file cleanup
- ✅ Temporary storage only (no manual deletion required)
- ❌ No more manual SFTP/SSH uploads

**Security:**
- Directory permissions: `0700` (owner only)
- `index.php` blocks directory browsing
- Files exist only during upload → import workflow
- Auto-deleted on successful import

### 🔄 Manual Feed Verification

Feeds that exceed the 12-second check timeout during automatically scheduled checks, are flagged as failed. To verify these feeds, you can trigger an on-demand check directly from the feed history page and preview the stream using the built-in player.

**What changed:**
- Real-time manual feed checks available on the feed history page
- Useful for investigating timeout issues and validating feed status

---

## 🐛 Bug Fixes

### Playlist Import - Credential Change Handling

**Improved:** Import handler now handles playlist credential changes without creating duplicate feeds. 

**Scenario:** 
- Playlist URLs contain embedded credentials: `http://server.com/live/username123/password456/stream.ts`
- Provider updates credentials → Exports playlist from editor → Imports playlist into OTT Stream Score → URLs become: `http://server.com/live/username789/password999/stream.ts`
- Old behavior: Different `url_hash` → creates duplicate feeds
- New behavior: Finds feed by channel association → updates existing feed with new URL

**How it works:**
- Sync mode first checks if channel already has a feed via `channel_feeds` junction table
- If found, updates existing `feeds(url, url_hash)` with new credentials
- Only creates new feed if channel has no existing feed
- Preserves feed check history across credential changes

**Note:** This prevents user error when re-importing playlists with refreshed credentials. Note: If you need to change existing credentials, you can do so through the `Admin Panel`.  This will regenerate all of the saved urls and their associated hash without creating any duplicates.

---

## 📋 Technical Changes

### Database Schema Updates

**New Tables:**
- `stream_preview_lock`: Mutex coordination table for stream preview system. Ensures only one preview or automated check runs at a time. Tracks session ownership, heartbeat timestamps, and auto-expires stale locks after 30 seconds.
- `feed_id_mapping`: URL hash mapping table for feed ID persistence across playlist imports. Maps old feed IDs to URL hashes, preventing feed ID changes when playlist order changes.

**Migration:** Run `migrate.php` to add these tables to existing installations. Fresh installations via `setup.php` include them automatically.

---

## ⬆️ Upgrading to v1.5

### From v1.3+ (Recommended Path)

**Quick Upgrade:**
1. Backup your installation and database
2. Upload all new files (safe to overwrite)
3. Run migration: `https://yourdomain.com/migrate.php`
4. Verify User Management tab appears in Admin panel

**No downtime required.** Migration takes seconds.

### From Before v1.3

Users upgrading from versions prior to v1.3 should follow the upgrade instructions in the v1.3 release notes first, then upgrade to v1.5.

**See INSTALL.md for complete upgrade instructions.**

---

## 📖 Version 1.5 Documentation

- **INSTALL.md** - Fresh installations and upgrades from previous versions
- **README.md** - Software documentation
- **PLAYER.md** - Internal stream player documentation
- **SECURITY.md** - Security best practices

---

## ⚠️ Breaking Changes

**None.** Version 1.5 is fully backward compatible with v1.3+.

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

**Previous Version:** 1.4 (December 2025)  
**Current Version:** 1.5 (December 2025)  
**Next Version:** TBD 

For complete installation and upgrade instructions, see [INSTALL.md](INSTALL.md).