#!/usr/bin/env bash
# Adds the email settings to /var/www/dentspace/api/config.php.
# Run as root. It asks for the Gmail app password with hidden typing; nothing is echoed or stored elsewhere.
set -euo pipefail
CONF=/var/www/dentspace/api/config.php
[ -f "$CONF" ] || { echo "config.php not found. Run install.sh first."; exit 1; }
read -r -p "Gmail address that sends and receives alerts [dentspacedmd@gmail.com]: " GM
GM="${GM:-dentspacedmd@gmail.com}"
read -r -s -p "Gmail app password (16 letters, hidden as you type): " PW
echo
PW="${PW// /}"
[ -n "$PW" ] || { echo "No password entered."; exit 1; }
IP="$(curl -s -m 5 https://api.ipify.org || echo 201.18.209.79)"
GM="$GM" PW="$PW" SITE="http://$IP" CONF="$CONF" php -r '
$c = require getenv("CONF");
$c["smtp_host"] = "smtp.gmail.com"; $c["smtp_port"] = 465;
$c["smtp_user"] = getenv("GM"); $c["smtp_pass"] = getenv("PW");
$c["notify_to"] = getenv("GM"); $c["from_name"] = "Dentspace";
if (empty($c["site_url"])) $c["site_url"] = getenv("SITE");
file_put_contents(getenv("CONF"), "<?php\nreturn " . var_export($c, true) . ";\n");
'
chown www-data:www-data "$CONF"; chmod 640 "$CONF"
echo "Saved. Now make a test booking with your own email to check it."
