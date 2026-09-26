#!/usr/bin/env bash
# Nightly Dentspace backup: the database (compressed) and the config file. Keeps 30 days.
# Installed by install-backup.sh as /usr/local/bin/dentspace-backup and run from cron.
set -euo pipefail
umask 077
DIR=/var/backups/dentspace
KEEP_DAYS=30
mkdir -p "$DIR"
chmod 700 "$DIR"
STAMP="$(date +%F-%H%M)"

mysqldump --single-transaction --routines --triggers dentspace | gzip -9 > "$DIR/db-$STAMP.sql.gz.tmp"
gzip -t "$DIR/db-$STAMP.sql.gz.tmp"                       # the archive must be readable
SIZE="$(stat -c %s "$DIR/db-$STAMP.sql.gz.tmp")"
if [ "$SIZE" -lt 500 ]; then rm -f "$DIR/db-$STAMP.sql.gz.tmp"; echo "$(date -Is) FAILED: dump suspiciously small ($SIZE bytes)"; exit 1; fi
mv "$DIR/db-$STAMP.sql.gz.tmp" "$DIR/db-$STAMP.sql.gz"

tar -czf "$DIR/config-$STAMP.tar.gz" -C /var/www/dentspace api/config.php   # holds the DB password and secrets

find "$DIR" -type f \( -name 'db-*.sql.gz' -o -name 'config-*.tar.gz' \) -mtime +"$KEEP_DAYS" -delete
echo "$(date -Is) ok  db-$STAMP.sql.gz ($SIZE bytes), $(ls "$DIR"/db-*.sql.gz | wc -l) database backups kept"
