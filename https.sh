#!/usr/bin/env bash
# Adds free HTTPS (Let's Encrypt) to Dentspace. Run as root:
#   bash https.sh                       (uses srv2008732.hstgr.cloud)
#   bash https.sh yourdomain.com        (a domain whose A record already points at this server)
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }
HOST="${1:-srv2008732.hstgr.cloud}"
CONF=/etc/nginx/sites-available/dentspace
WEBCONF=/var/www/dentspace/api/config.php
[ -f "$CONF" ] || { echo "Run install.sh first."; exit 1; }

echo "== Checking that $HOST points at this server"
MYIP="$(curl -s -m 8 https://api.ipify.org)"
DNSIP="$(getent ahostsv4 "$HOST" | awk '{print $1; exit}')"
echo "This server: $MYIP   $HOST -> ${DNSIP:-not found}"
[ -n "$MYIP" ] && [ "$MYIP" = "$DNSIP" ] || { echo "The name does not point here yet. Fix the DNS A record and wait a few minutes."; exit 1; }

read -r -p "Email for certificate notices [dentspacee@gmail.com]: " EMAIL
EMAIL="${EMAIL:-dentspacee@gmail.com}"
echo "This uses Let's Encrypt's free certificates. Terms: https://letsencrypt.org/repository/"
read -r -p "Type yes to accept the terms and continue: " OK
[ "$OK" = "yes" ] || { echo "Cancelled."; exit 1; }

echo "== Installing certbot"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y certbot python3-certbot-nginx

grep -q "server_name.*\b$HOST\b" "$CONF" || sed -i "0,/server_name /s//server_name $HOST /" "$CONF"
nginx -t
systemctl reload nginx

echo "== Requesting the certificate"
certbot --nginx -d "$HOST" -m "$EMAIL" --agree-tos --no-eff-email --redirect --non-interactive

# Links in alert emails should use https
if [ -f "$WEBCONF" ]; then
  HOST="$HOST" CONF="$WEBCONF" php -r '$c = require getenv("CONF"); $c["site_url"] = "https://" . getenv("HOST"); file_put_contents(getenv("CONF"), "<?php\nreturn " . var_export($c, true) . ";\n");'
  chown www-data:www-data "$WEBCONF"; chmod 640 "$WEBCONF"
fi
systemctl reload nginx
echo
echo "Done. Open:  https://$HOST/   and   https://$HOST/dashboard/"
