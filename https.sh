#!/usr/bin/env bash
# Adds free HTTPS (Let's Encrypt) to Dentspace. Run as root:
#   bash https.sh                                      (uses srv2008732.hstgr.cloud)
#   bash https.sh dentspace.tech www.dentspace.tech    (names whose A records already point at this server)
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }
HOSTS=("$@")
[ ${#HOSTS[@]} -gt 0 ] || HOSTS=(srv2008732.hstgr.cloud)
HOST="${HOSTS[0]}"
CONF=/etc/nginx/sites-available/dentspace
WEBCONF=/var/www/dentspace/api/config.php
[ -f "$CONF" ] || { echo "Run install.sh first."; exit 1; }

MYIP="$(curl -s -m 8 https://api.ipify.org)"
echo "== Checking that every name points at this server ($MYIP)"
for H in "${HOSTS[@]}"; do
  DNSIP="$(getent ahostsv4 "$H" | awk '{print $1; exit}')"
  echo "  $H -> ${DNSIP:-not found}"
  if [ -z "$MYIP" ] || [ "$MYIP" != "$DNSIP" ]; then
    echo "$H does not point here yet. Fix its DNS A record and wait a few minutes, then run this again."
    exit 1
  fi
done

read -r -p "Email for certificate notices [dentspacee@gmail.com]: " EMAIL
EMAIL="${EMAIL:-dentspacee@gmail.com}"
echo "This uses Let's Encrypt's free certificates. Terms: https://letsencrypt.org/repository/"
read -r -p "Type yes to accept the terms and continue: " OK
[ "$OK" = "yes" ] || { echo "Cancelled."; exit 1; }

echo "== Installing certbot"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y certbot python3-certbot-nginx

# make sure nginx knows every name before certbot looks for it
for H in "${HOSTS[@]}"; do
  if ! grep -qE "server_name[^;]*[[:space:]]${H//./\\.}[[:space:];]" "$CONF"; then
    sed -i "0,/server_name /s//server_name $H /" "$CONF"
  fi
done
nginx -t
systemctl reload nginx

echo "== Requesting the certificate"
DOMARGS=()
for H in "${HOSTS[@]}"; do DOMARGS+=(-d "$H"); done
certbot --nginx "${DOMARGS[@]}" -m "$EMAIL" --agree-tos --no-eff-email --redirect --non-interactive

# Links in alert emails should use https and the main name
if [ -f "$WEBCONF" ]; then
  HOST="$HOST" CONF="$WEBCONF" php -r '$c = require getenv("CONF"); $c["site_url"] = "https://" . getenv("HOST"); file_put_contents(getenv("CONF"), "<?php\nreturn " . var_export($c, true) . ";\n");'
  chown www-data:www-data "$WEBCONF"; chmod 640 "$WEBCONF"
fi
systemctl reload nginx
echo
echo "Done. Open:  https://$HOST/   and   https://$HOST/dashboard/"
