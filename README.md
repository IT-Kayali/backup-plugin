# IK Backup

A small WordPress backup plugin: full database dump plus `wp-content` archive, stored as a single zip on the server, with in-admin restore and a standalone emergency restore script for the case where wp-admin is no longer reachable.

## Features

- **One-click backup** of the full database (all tables) and the `wp-content` directory into one zip archive. Backups are stored **outside the web root** by default (a hidden directory next to the WordPress root). A location can be forced with `IKB_BACKUP_DIR` in `wp-config.php`. If no directory outside the web root is writable, the plugin falls back to a randomized directory under uploads - and **refuses to create backups unless a live probe proves the directory cannot be fetched over HTTP** (fail-closed). Downloads go through an authenticated, nonce-protected PHP endpoint.
- **In-admin restore** with an optional automatic safety backup taken right before the restore, and a database-only mode.
- **Emergency restore script**: deploys `ikb-emergency-restore.php` to the WordPress root with a secret token. If WordPress breaks but PHP still runs, restore any backup via browser (token + typed `RESTORE` confirmation) or command line (`php ikb-emergency-restore.php backup.zip`, interactive confirmation). Archives are validated for path traversal before extraction. Redeploy from the admin page at any time to rotate the token.
- **Scheduled backups** via WP-Cron (daily or weekly) with retention (keeps the newest N backups).
- Backup manifest (`manifest.json` in every archive): creation date, site URL, WordPress/PHP/MySQL versions, table prefix, table/row counts, SHA-256 of the database dump.

## What is (not) in a backup

A backup archive contains:

- `db.sql` - a full dump of every table in the WordPress database
- `wp-content/` - plugins, themes, uploads, and everything else under `wp-content`
- `manifest.json` - metadata plus the SHA-256 checksum of `db.sql`

A backup does **not** contain `wp-config.php`, WordPress core files (`wp-admin`, `wp-includes`, root PHP files), `.htaccess`, or anything else outside `wp-content`. Restores therefore never touch core files or your database credentials; on a broken install you restore core from a fresh WordPress download and this plugin restores content and data.

Before any destructive step, every restore verifies the archive: supported manifest format, no path-traversal entries, and the `db.sql` checksum matching the manifest. A corrupt or tampered archive is rejected before anything is overwritten.

## Honest limits

This plugin is **not** a disaster-recovery guarantee:

- Backups live on the same server as the site (outside the web root). Download copies to another location — otherwise a hosting or disk failure takes the backups with it.
- The emergency restore script only helps while the web server and PHP still work. If the server or hosting account is unreachable, you need hosting-level access (file manager, SFTP, or the hoster's backup/restore tools). The deployed script holds a secret token: treat the token like a password and rotate (redeploy) it after use.
- Restores overwrite the whole database, including orders and users created after the backup. Use the pre-restore safety backup and restore promptly after an incident.

## Install

1. Copy the `backup-plugin` folder to `wp-content/plugins/ik-backup`.
2. Activate **IK Backup** under Plugins.
3. Open **IK Backup** in the admin menu, create a backup, and deploy the emergency restore script. Store the shown token somewhere safe outside the site.

## Restore paths

| Situation | How to restore |
| --- | --- |
| wp-admin works | IK Backup → Restore (optionally with safety backup) |
| wp-admin broken, PHP/web still up | Browser: `https://site/ikb-emergency-restore.php?token=TOKEN` (type RESTORE) or CLI: `php ikb-emergency-restore.php <backup.zip>` |
| Server/PHP down or disk lost | Hoster backup, file manager/SFTP with a downloaded backup zip (extract `wp-content`, import `db.sql` with any MySQL client) |

## Compatibility

- WordPress 6.0+, PHP 7.4+ (tested on WordPress 7.1.5, PHP 8.3, MariaDB 10.6)
- Requires the PHP ZipArchive extension
- A writable directory outside the web root (available on most hosting) or a server that blocks direct access to the fallback uploads directory

## Development

GPL-2.0-or-later. Repository: https://github.com/IT-Kayali/backup-plugin
