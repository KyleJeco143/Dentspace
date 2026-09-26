#!/usr/bin/env bash
# Connects the server to the Google Sheet. Run as root:  bash set-sheet.sh
set -euo pipefail
CONF=/var/www/dentspace/api/config.php
[ -f "$CONF" ] || { echo "config.php not found. Run install.sh first."; exit 1; }
read -r -p "Web app URL, or just the Deployment ID (the AKfycby... text): " URL
URL="${URL// /}"
case "$URL" in
  https://*) ;;
  AKfy*) URL="https://script.google.com/macros/s/$URL/exec" ;;
esac
read -r -s -p "The SECRET you put in the script (hidden as you type): " SEC
echo
[ -n "$URL" ] && [ -n "$SEC" ] || { echo "Both are required."; exit 1; }
URL="$URL" SEC="$SEC" CONF="$CONF" php -r '
$u = getenv("URL");
if (strpos($u, "https://script.google.com/") !== 0) { fwrite(STDERR, "That does not look like an Apps Script URL.\n"); exit(1); }
$c = require getenv("CONF");
$c["sheet_url"] = $u; $c["sheet_secret"] = getenv("SEC");
file_put_contents(getenv("CONF"), "<?php\nreturn " . var_export($c, true) . ";\n");
'
chown www-data:www-data "$CONF"; chmod 640 "$CONF"
echo "Saved. Make a test booking; a row should appear in the Bookings tab."
