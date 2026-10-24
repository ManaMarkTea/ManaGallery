# Production launch checklist

Use a new private application directory and keep the public document root limited
to the PHP launcher and static assets. Configure your own allowed hosts and HTTPS
origin before deployment. Never unpack a first-install database over an existing
installation. Keep code-only updates separate from runtime storage and credentials.

Verify sign-in, uploads, comments, private-path protection, TLS, backups and a full
restore before switching the production document root. Retain the previous source
and database snapshot for rollback. Installation-specific paths and accounts belong
in a private operations inventory, not this repository.
