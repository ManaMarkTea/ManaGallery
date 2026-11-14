# Deploying from GitHub

The **Deploy to shared hosting** workflow copies the code on `main` to the
host over SSH and installs it with `scripts/deploy-shared-host.php`. Start it
from the repository's **Actions** tab (or the GitHub mobile app): choose the
workflow, then **Run workflow** on `main`.

What it changes:

- `src/`, `templates/`, `public/index.php` and `public/assets/` in the
  private app directory, plus the copies of `public/assets/` in the public root.
- When `DEPLOY_TOOLS_DIR` is set, `scripts/` and `src/` in the private
  backup-tools directory used by the scheduled backup.

It never changes the database, uploads, archive images, `storage/`,
`config.local.php` or `vendor/`. Each replaced file is saved in the release's
`previous-code/` folder before the switch, and a failed switch rolls back.
Composer dependency changes still need a manual `composer install`.

## One-time setup (at a computer)

1. **Create a key only for deploys** (no passphrase), on your own computer:

   ```sh
   ssh-keygen -t ed25519 -N '' -C 'github-deploy' -f managallery-deploy
   ```

2. **Allow it on the host.** In the hosting panel's SSH access page, add the
   contents of `managallery-deploy.pub`, or append it to `~/.ssh/authorized_keys`.
   Keep using your personal key for your own logins.

3. **Record the host key** from your computer, after you have connected once
   and confirmed the fingerprint the hosting panel shows:

   ```sh
   ssh-keyscan -p 22 ssh.example-host.com
   ```

4. **Create the release folder** on the host, outside the public web root, for
   example `~/Apps/ManaGallery/releases` (it already exists on our host).

5. In GitHub: **Settings → Environments → New environment** named `production`.
   Under **Deployment branches**, allow only `main`. Optionally add yourself as
   a required reviewer so every deploy waits for your approval.

6. Add **environment secrets**:

   | Secret | Value |
   |---|---|
   | `DEPLOY_SSH_KEY` | the full contents of the private `managallery-deploy` file |
   | `DEPLOY_KNOWN_HOSTS` | the `ssh-keyscan` output from step 3 |

7. Add **environment variables** (absolute paths on the host):

   | Variable | Example |
   |---|---|
   | `DEPLOY_SSH_TARGET` | `user@ssh.example-host.com` |
   | `DEPLOY_SSH_PORT` | `22` (optional) |
   | `DEPLOY_APP_DIR` | `/home/.../Apps/ManaGallery/app` |
   | `DEPLOY_PUBLIC_DIR` | the site's public web root |
   | `DEPLOY_TOOLS_DIR` | `/home/.../Apps/ManaGallery/native-backup-code` (optional) |
   | `DEPLOY_RELEASES_DIR` | `/home/.../Apps/ManaGallery/releases` |
   | `DEPLOY_PHP` | the host's PHP 8.3 command, if not `php` (optional) |

8. Delete the private key file from your computer once it is saved in GitHub,
   then run the workflow and check the site and Admin › Health.

## Rolling back

Run the workflow again from an earlier commit, or copy files back from the
newest release's `previous-code/` folder (`targets.json` lists where each one
belongs). The five newest GitHub releases are kept on the host.

## Security

Anyone who can push to `main` and run this workflow can change the live site.
Keep two-factor authentication on the GitHub account, and add a required
reviewer to the `production` environment if others have write access. To
revoke the deploy key, remove it from the host's authorized keys.
