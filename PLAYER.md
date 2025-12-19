# Stream Player System Guide

## What It Does

The Stream Player System lets you watch IPTV streams directly in your browser from anywhere in OTT Stream Score. Click any preview button throughout the application and the video starts playing instantly in a modal window.

## How It Works

### The Preview Experience

When you click a preview button:

**Step 1:** The system secures exclusive access to that stream

**Step 2:** Video begins playing automatically with controls available

**Step 3:** Stream information displays: channel name, resolution, frame rate, codec, and reliability score

**Step 4:** You can watch as long as you want - the connection stays active

**Step 5:** Close the modal when finished and the system releases the stream

### Behind the Scenes

The system performs several tasks automatically to ensure smooth playback:

**Security Layer:** Your browser connects to OTT Stream Score over a secure HTTPS connection. However, most IPTV stream sources use regular HTTP connections. The system bridges this gap by acting as a secure intermediary, fetching the HTTP stream and delivering it to your browser over HTTPS. This satisfies browser security requirements while maintaining stream compatibility.

**Format Detection:** IPTV providers deliver streams in two common formats. The system automatically detects which format each stream uses and selects the appropriate player technology. You don't need to know or care about the technical format - it just works.

**Smart Playback:** For raw transport streams (the most common format), the system uses JavaScript to parse the video data and feed it to your browser's media engine. For playlist-based streams, it uses a different JavaScript library optimized for that format. Both approaches provide smooth, buffered playback without interruption.

**Connection Management:** The system maintains constant communication with the server while you watch. Every ten seconds, it sends a signal confirming you're still viewing. This prevents the connection from timing out and ensures uninterrupted playback.

## Why Exclusive Access Matters

### The Single Connection Limit

Your IPTV provider allows only one active connection at a time. If two connections attempt to use the same account simultaneously, both fail with authentication errors. This is a provider-imposed restriction, not a system limitation. 

### Coordination with Automated Checks

OTT Stream Score continuously monitors all your feeds in the background, checking each one periodically to verify it's still working and measuring its quality. These automated checks also require connecting to streams, which would conflict with your preview.

### The Lock System

To prevent conflicts, the system uses a coordination mechanism:

**When you start a preview:** The system creates a lock record indicating which stream is being watched and which user session owns it. Only that session can access the stream through the proxy.

**During your preview:** The automated checking system queries the lock status before processing any feeds. If a lock exists, the checker skips its cycle entirely and exits cleanly. This ensures your preview never gets interrupted.

**Every 10 seconds:** Your browser sends a heartbeat signal updating the lock timestamp. This proves you're still actively watching and prevents the lock from expiring.

**When you close the preview:** The system deletes the lock record immediately. The next time the automated checker runs, it sees no lock and proceeds normally with feed monitoring.

**If your browser crashes:** Without proper cleanup, the lock would remain forever and block all automated checking. To handle this, the system automatically expires any lock that hasn't received a heartbeat signal in 30 seconds. This provides automatic recovery without manual intervention.

### Lock Coordination Details

**Pre-processing check:** Before claiming any feeds from the queue, the checker queries for active locks. Finding one causes immediate clean exit. This prevents wasted work and ensures responsive preview.

**Mid-cycle check:** If you start a preview while the checker is already processing feeds, it detects this within approximately 12 seconds (the duration of a typical feed check). Upon detection, it releases all claimed feeds and exits gracefully.

**Stale lock cleanup:** Each checker run automatically deletes expired locks before performing the active lock check. This housekeeping ensures crashed sessions don't accumulate and cause problems.

## Stream Formats Explained

### Transport Stream Format

Most feeds use transport stream format, indicated by URLs ending in .ts extension. These are continuous streams of binary video data transmitted in small packets. Web browsers cannot play this format natively.

**How we handle it:** The system streams the binary data to your browser in real-time. JavaScript code parses the packets, extracts the video and audio, and feeds them to your browser's media playback engine. This happens continuously as data arrives, providing smooth live playback.

**Key characteristics:** 
- Plays live content without buffering the entire stream
- Supports seeking within the buffered portion
- Handles video indefinitely without memory issues
- Requires modern browser with Media Source Extensions support

### Playlist Format

Some feeds use HTTP Live Streaming, indicated by URLs ending in .m3u8 extension. These consist of a playlist file listing individual video segments, with each segment being a separate file.

**How we handle it:** The system fetches the playlist, rewrites all segment URLs to point back through the secure proxy, and delivers it to your browser. When the player requests segments, they also route through the proxy. This maintains the secure connection chain while preserving the streaming protocol.

**Key characteristics:**
- Naturally chunked for efficient delivery
- Supports multiple quality levels (if source provides them)
- Standard protocol with wide compatibility
- Easier to cache and optimize

## Security and Privacy

### Session-Based Access Control

When you acquire a lock for preview, the system records your session identifier. The proxy validates this session on every request. If someone else tries to access the same stream through the proxy URL, they receive an access denied error. Only your session can stream the feed you locked.

### Feed Validation

The lock specifies which feed you're previewing. If you somehow obtained a proxy URL for a different feed and tried to access it, the system checks whether the requested feed matches your lock. Mismatches trigger access denial. You must release your current lock and acquire a new one for each different feed.

### Timeout Protection

Locks don't persist forever. Without activity, they expire after 30 seconds. This prevents abandoned locks from blocking automated checks indefinitely. The heartbeat mechanism extends the timeout as long as you're actively watching.

## System Performance

### Resource Usage

**Database impact:** Each preview generates approximately 8 database queries per minute - one creation, six heartbeat updates, and one deletion. The automated checker adds about 51 queries per five-minute cycle. Combined load is negligible on modern database servers.

**Network bandwidth:** The proxy doesn't modify or transcode video data. It simply passes through the stream. Your bandwidth consumption equals the stream's bitrate - typically 3-8 megabits per second depending on resolution.

**Server processing:** Video decoding happens entirely in your browser via JavaScript. The server only streams raw bytes. CPU usage remains minimal, typically 1-2% during active streaming.

**Client processing:** Modern browsers handle video decoding efficiently. Expect 5-15% CPU usage on most devices, more on older hardware. JavaScript parsing adds minimal overhead.

## Browser Requirements

The system requires a modern browser with Media Source Extensions support. This includes:

- Chrome version 90 or later
- Firefox version 88 or later
- Edge version 90 or later  
- Safari version 14 or later
- Opera version 76 or later

Mobile browsers also work with the same version requirements. Older browsers from before 2021 may lack the necessary features.

You must have JavaScript enabled. The entire player system runs in JavaScript and cannot function with it disabled.

## Troubleshooting Common Issues

### "Another preview is active" Message

**What it means:** Someone else is currently previewing a stream, or a previous preview didn't clean up properly.

**What to do:** Wait 30 seconds for automatic cleanup.

### Video Spinner Never Stops

**Possible causes:**

**Stream is offline:** The source provider's server isn't responding or the specific feed is down. Try a different feed to confirm system functionality.

**Browser incompatibility:** Check that you're using a supported browser version. Media Source Extensions must be available.

**Network issues:** Slow or unstable internet connection may prevent buffering. Check your connection speed and stability.

**Proxy malfunction:** Server-side issues may prevent streaming. Check your server error logs.

## Monitoring and Diagnostics

### For Administrators

**Check active locks:** Query the lock table to see who's currently previewing and when their last heartbeat occurred.

**Monitor checker behavior:** Review system logs for messages about skipping cycles due to active previews or cleaning up stale locks.

**Track proxy activity:** Examine PHP error logs for proxy messages about stream detection and format handling.

**Verify timing:** Ensure heartbeats occur every 10 seconds, locks timeout at 30 seconds, and checker runs every 5 minutes.

## System Limitations

### Single Preview at a Time

Only one person can preview any stream at any given moment. This limitation stems from the single-connection restriction imposed by the IPTV provider, not the software design. Multiple concurrent previews would cause authentication failures.

### No Recording or DVR

The current system provides live viewing only. You cannot pause, rewind, or record streams. All functionality is real-time playback.

### No Quality Selection

If the source provides multiple quality levels (common with HLS), the player automatically selects one. You cannot manually choose quality or switch levels during playback.

### No Thumbnail Preview

The system doesn't generate thumbnail images or screenshots from streams. 

## Future Possibilities

### Preview Analytics

The system could log which feeds get previewed most often, how long users watch them, and which feeds never get previewed. This data would help identify good and bad feeds.

### Automatic Thumbnails

The system could periodically capture frames from active streams and use them as channel thumbnails. This would improve visual browsing and help identify channels quickly.
