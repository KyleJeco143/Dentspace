#!/usr/bin/env bash
# Installs the nightly backup (02:30 Manila time = 18:30 UTC). Run as root:  bash install-backup.sh
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }
SRC="$(cd "$(dirname "$0")" && pwd)"
install -m 750 "$SRC/backup.sh" /usr/local/bin/dentspace-backup
cat > /etc/cron.d/dentspace-backup <<'EOF'
# Dentspace nightly backup, 02:30 Philippine time (18:30 UTC)
30 18 * * * root /usr/local/bin/dentspace-backup >> /var/log/dentspace-backup.log 2>&1
EOF
chmod 644 /etc/cron.d/dentspace-backup
echo "== Running one backup now to test it"
/usr/local/bin/dentspace-backup | tee -a /var/log/dentspace-backup.log
echo
ls -lh /var/backups/dentspace
echo
echo "Installed. A backup runs every night at 02:30 and 30 days are kept in /var/backups/dentspace."
echo "Restore (only in an emergency):  gunzip -c /var/backups/dentspace/db-DATE.sql.gz | mysql dentspace"
