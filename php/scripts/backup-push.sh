#!/bin/bash
set -euo pipefail
umask 077
base="$HOME/rendergallery-php"
ssh_options=(-4 -i "$base/backup-key" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$base/backup-known-hosts")
remote=${RENDERGALLERY_BACKUP_TARGET:?Set RENDERGALLERY_BACKUP_TARGET to your backup worker user@host}
mode=${1:-backup}
if [[ "$mode" = status ]]; then
  /usr/bin/ssh "${ssh_options[@]}" "$remote" status | /usr/php85/usr/bin/php "$base/backup-status.php"
  exit
fi
[[ "$mode" = backup ]] || { echo 'Expected backup or status'; exit 1; }
exec 9>"$base/push.lock"
/usr/bin/flock -n 9 || exit 0
echo "[$(date -u +%FT%TZ)] Preparing consistent hosting snapshot…"
/usr/php85/usr/bin/php "$base/backup-stage.php"
printf -v transport '%q ' /usr/bin/ssh "${ssh_options[@]}"
echo 'Transferring changed files over SSH to the write-only backup receiver…'
/usr/bin/rsync -rlt --delete --safe-links --delay-updates --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= -e "$transport" "$base/backup-export/current/" "$remote:."
echo 'Verifying, encrypting and copying the snapshot off-site…'
/usr/bin/ssh "${ssh_options[@]}" "$remote" run-backup
/usr/bin/ssh "${ssh_options[@]}" "$remote" status | /usr/php85/usr/bin/php "$base/backup-status.php"
echo "[$(date -u +%FT%TZ)] Backup completed."
