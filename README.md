# IK Backup

A small WordPress backup plugin: full database dump plus `wp-content` archive, stored as a single zip on the server, with in-admin restore and a standalone emergency restore script for the case where wp-admin is no longer reachable.

## Features

- **One-click backup** of the full database (all tables) and the `wp-content` directory into one zip archive (`wp-content/uploads/ik-backups/`, web access to that directory is blocked via `.htaccess`, downloads go through an authenticated PHP endpoint).
- **In-admin restore** with an optional automatic safety backup taken right before the restore, and a database-only mode.
- **Emergency restore script**: deploys `ikb-emergency-restore.php` to the WordPress root with a secret token. If WordPress breaks but PHP still runs, restore any backup via browser (`?token=...`) or command line (`php ikb-emergency-restore.php backup.zip`).
- **Scheduled backups** via WP-Cron (daily or weekly) with retention (keeps the newest N backups).
- Backup manifest (`manifest.json` in every archive): creation date, site URL, WordPress/PHP/MySQL versions, table prefix, table/row counts, SHA-256 of the database dump.

## Honest limits

This plugin is **not** a disaster-recovery guarantee:

- Backups live on the same server as the site. Download copies to another location (or add off-site storage) — otherwise a hosting or disk failure takes the backups with it.
- The emergency restore script only helps while the web server and PHP still work. If the server or hosting account is unreachable, you need hosting-level access (file manager, SFTP, or the hoster's backup/restore tools).
- Restores overwrite the database, including orders and users created after the backup. Use the pre-restore safety backup and restore promptly after an incident.

## Install

1. Copy the `backup-plugin` folder to `wp-content/plugins/ik-backup`.
2. Activate **IK Backup** under Plugins.
3. Open **IK Backup** in the admin menu, create a backup, and deploy the emergency restore script. Store the shown token somewhere safe outside the site.

## Restore paths

| Situation | How to restore |
| --- | --- |
| wp-admin works | IK Backup → Restore (optionally with safety backup) |
| wp-admin broken, PHP/web still up | Browser: `https://site/ikb-emergency-restore.php?token=TOKEN` or CLI: `php ikb-emergency-restore.php <backup.zip>` |
| Server/PHP down or disk lost | Hoster backup, file manager/SFTP with a downloaded backup zip (extract `wp-content`, import `db.sql` with any MySQL client) |

## Compatibility

- WordPress 6.0+, PHP 7.4+ (tested on WordPress 7.1.5, PHP 8.3, MariaDB 10.6)
- Requires the PHP ZipArchive extension

## Development

GPL-2.0-or-later. Repository: https://github.com/IT-Kayali/backup-plugin
