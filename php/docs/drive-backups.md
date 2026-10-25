# Direct Google Drive backups

These CLI tools run on the PHP host. They do not need a VPS or a native binary.
PHP needs SQLite, cURL, OpenSSL and ZIP with AES-256 support.

The first backup is full. Later backups contain a consistent database snapshot
and only new or changed files. Deletions are recorded in the encrypted manifest.
A new full backup starts every 30 days. Files are hashed each run, so unchanged
images are read locally but are not uploaded again.

## Configure

Create a private backup directory outside the web root. Save a random recovery
password of at least 32 characters in a private file and in your password manager.
Losing this password means losing access to the backups.

Authorize a Google OAuth client with **only** the `drive.file` scope and offline
access. Keep the client secret and refresh token outside the web root and Git.
This permission covers files created or explicitly opened with that app, rather
than an absolute folder boundary. It does not grant access to your entire Drive.

Save a private JSON configuration with these fields:

```json
{
  "appDirectory": "/private/gallery",
  "backupDirectory": "/private/gallery-backups",
  "passwordFile": "/private/recovery-password",
  "clientId": "YOUR_OAUTH_CLIENT_ID",
  "clientSecret": "YOUR_OAUTH_CLIENT_SECRET",
  "refreshToken": "YOUR_REFRESH_TOKEN",
  "folderId": "APP_CREATED_BACKUP_FOLDER_ID"
}
```

Give the configuration and password files owner-only permissions (`chmod 600`).
Run:

```sh
php scripts/backup-drive.php /private/drive-settings.json
```

Schedule that command daily using your hosting panel or cron. Output includes
snapshot and upload progress. Interrupted uploads keep a private resumable
session. `--upload-only` retries pending uploads without making another snapshot.
Drive's returned file size and checksum must match before a copy is recorded.

## Test recovery

Download a full ZIP and every later incremental ZIP in that chain from Drive.
Pass them in creation order. Use a **new private directory**:

```sh
php scripts/restore-chain.php /private/recovery-test /private/recovery-password FULL.zip DELTA1.zip DELTA2.zip
```

The restore checks chain order, paths, every file checksum and SQLite integrity.
It never replaces a live installation. Keep a copy of the recovery password
outside the server, and test a downloaded chain after setup and periodically.

While the local archive inventory is available, this command downloads the latest
chain from Drive and runs the restore automatically:

```sh
php scripts/verify-drive-backup.php /private/drive-settings.json /private/new-recovery-check
```

It writes a separate restore-check result. An upload checksum by itself does not
count as a successful recovery test. Keep the check directory private: it holds
decrypted configuration, member data and artwork.

## Retention

The tools do not delete backup chains automatically. Keep the full ZIP and every
incremental ZIP needed for each retained restore point. After verifying a newer
full backup from Drive, you can remove an older **complete chain**. Removing a
single ZIP from the middle makes later incrementals in that chain unusable.

Keep enough local free space for a full archive, staging and pending uploads.
Full baselines intentionally remain local until an operator has tested recovery.
