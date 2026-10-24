# Source distribution

Original code is MIT-licensed; dependencies retain their own notices. Live artwork
and user records are not included or relicensed. Legacy application source and
proprietary compatibility patches are excluded. Migration utilities parse data
formats; they do not load or depend on a previous application's source code.

The default legacy SQL table prefix is legacy_. Set
RENDERGALLERY_LEGACY_TABLE_PREFIX privately when importing a different prefix.
Never publish a database dump or account export to demonstrate migration.

Public history starts with sanitized source. Server addresses, hosting account IDs,
credentials, logs and private historical commits are excluded. Use
ops/inventory.example.json as a template for a separately stored private inventory.
Keep passwords in a password manager and server-only configuration. Ignored files
are not backups, and ignoring a file does not remove it from Git history.
