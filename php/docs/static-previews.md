# Static thumbnails

Published thumbnails can be served directly by the web server. Pending and
hidden artwork uses the protected PHP route. Full-size originals stay private.
Docker enables this automatically and rebuilds copies when the container starts.

For other installs, create a dedicated, writable directory under the public web
root. Add these settings to private `config.local.php`:

```php
'public_preview_directory' => '/home/account/public_html/previews',
'public_preview_url' => '/previews',
```

From the private PHP application directory, run:

```sh
php scripts/publish-previews.php
```

The command copies eligible thumbnails, checks their bytes and records their
URLs. Approval publishes new copies automatically. Hiding or deleting artwork
removes every public version before changing its visibility. A failed removal
stops that moderation action. Originals are never moved or linked into public.

Filenames include the artwork number and content hash, such as
`art-42-preview-HASH.jpg`. Normal image requests need no PHP, session or database
lookup. Unpublished thumbnails fall back to the existing protected route.

Apache must allow the generated `.htaccess` and have `mod_headers` enabled.
The directory disables script handling and directory listings, and sets
`Cache-Control: private, max-age=300, must-revalidate`. Already-viewed copies can
remain in a browser's cache for five minutes after removal. Shared caches must
honor the private directive. Other web servers need equivalent static-file,
execution and cache rules.

Check a published image in DevTools: it should have an image extension,
`X-Gallery-Media: static-preview` and no PHP request ID or PHP Server-Timing.
On the PHP development server the router simulates these headers; production
Apache serves the files itself.

After restoring a database or changing archive files, rebuild the exports before
reopening the site. The command rechecks current visibility and removes stale
copies, including files left by an interrupted publication. If disabling static
publication, take the site offline and remove its managed public copies before
clearing the configuration. Never leave an unmanaged export directory behind.

Generated copies are runtime data and must stay out of Git. Keep the private
originals in your backups; public copies can be recreated from them.
