# Updates from the admin panel

Admin > Updates checks the latest stable release in `ManaMarkTea/ManaGallery`.
Review its release notes, then enter your current password and select Deploy.
No GitHub token or inbound SSH deployment key is required.

Maintainers publish a new release with Actions > Publish gallery update on main,
providing a new tag such as `v2026.11.15.1`. The workflow validates and builds a
code-only `managallery-update.zip`. Unreleased commits are never auto-installed.

The website rechecks the release ID, asset ID and GitHub SHA-256 digest before
installation. HTTPS downloads are limited to GitHub's release hosts. Packages
cannot contain runtime data, configuration, traversal paths or symbolic links.
The installer validates all files and PHP syntax before replacing code. It uses
the same site lock as ordinary requests and restores changed files on a failed
switch. Keep an independent backup: request termination or storage failure can
still require manual recovery from the saved `previous-code` directory.

PHP cURL and ZipArchive are required. The parent of the private application must
be writable: staged releases, state and recovery copies live in its private
`gallery-updates` sibling directory. For split hosting layouts, configure:

```php
'update_public_root' => '/absolute/path/to/public_html',
'update_backup_tools' => '/absolute/path/to/native-backup-code',
```

The public root defaults to the app's `public` folder. Backup tools are skipped
unless configured. Vendor dependencies are not replaced by these code-only
releases; dependency changes need a separate reviewed maintenance step.

The updater trusts maintainers who can publish GitHub releases. Checksums detect
corruption and changed assets; they are not an independent signing authority.
After confirming this flow on the host, revoke the old dedicated deployment key
in StackCP and remove `DEPLOY_SSH_KEY` and `DEPLOY_KNOWN_HOSTS` from GitHub.

## Storage visibility

The community overview includes cached archive size, live SQLite size (including
journals), and all measured file contents. Admin > Site health > Storage
breakdown starts or continues a bounded scan and lists categories and the 20
largest files. Configure `storage_account_root` with the absolute hosting account
home to include other sites; otherwise the scan covers the gallery directory and
configured archive and runtime storage. Symlinks are not followed.

Unique byte totals deduplicate hard links; logical sizes show every folder copy.
These are file contents, not filesystem allocation or the hosting provider's
billing total. Directory overhead, external databases, mail outside the scanned
roots and other hosting accounts are excluded. Scans are approximate snapshots
of a live filesystem and display their time and whether coverage is complete.
