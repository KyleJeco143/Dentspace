#!/usr/bin/env bash
# Dentspace backend installer for Ubuntu 22.04 (nginx already present).
# Run as root from inside the DENTSPACE folder that contains index.html, dashboard/ and api/:
#   bash install.sh
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }
SRC="$(cd "$(dirname "$0")" && pwd)"
WEB=/var/www/dentspace
[ -f "$SRC/api/lib.php" ] || { echo "api/lib.php not found next to install.sh"; exit 1; }

echo "== Installing PHP-FPM and MariaDB"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y php-fpm php-mysql php-mbstring mariadb-server
systemctl enable --now mariadb
PHPSOCK="$(ls /run/php/php*-fpm.sock | head -1)"

KEEP=0; [ -f "$WEB/api/config.php" ] && KEEP=1
echo "== Database"
DBPASS="$(openssl rand -hex 16)"
SETUPKEY="$(openssl rand -hex 24)"
if [ $KEEP = 0 ]; then
mysql -e "CREATE DATABASE IF NOT EXISTS dentspace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'dentspace'@'localhost' IDENTIFIED BY '$DBPASS';
ALTER USER 'dentspace'@'localhost' IDENTIFIED BY '$DBPASS';
GRANT ALL PRIVILEGES ON dentspace.* TO 'dentspace'@'localhost'; FLUSH PRIVILEGES;"
fi

echo "== Files"
mkdir -p "$WEB"
cp -r "$SRC/index.html" "$SRC/dashboard" "$WEB/"
mkdir -p "$WEB/api"; cp "$SRC"/api/*.php "$WEB/api/"; rm -f "$WEB/api/config.sample.php"
[ $KEEP = 1 ] && rm -f "$WEB/api/setup.php"
if [ $KEEP = 0 ]; then
cat > "$WEB/api/config.php" <<EOF
<?php
return ['db_host'=>'localhost','db_name'=>'dentspace','db_user'=>'dentspace','db_pass'=>'$DBPASS','setup_key'=>'$SETUPKEY'];
EOF
fi
chown -R www-data:www-data "$WEB/api"
chmod 640 "$WEB/api/config.php"

echo "== nginx"
NAMES="_"
[ -f /etc/nginx/sites-available/dentspace ] && NAMES="$(grep -m1 server_name /etc/nginx/sites-available/dentspace | sed 's/server_name//; s/;//')"
if ! grep -q "managed by Certbot" /etc/nginx/sites-available/dentspace 2>/dev/null; then
cat > /etc/nginx/sites-available/dentspace <<EOF
server {
    listen 80 default_server;
    server_name $NAMES _;
    root $WEB;
    index index.html;
    client_max_body_size 2m;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy same-origin always;

    location / { try_files \$uri \$uri/ =404; }

    # Only these three API endpoints (plus one-time setup) are public; lib/config are never served.
    location ~ ^/api/(availability|book|admin|setup)\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHPSOCK;
    }
    location ~ ^/api/ { return 404; }
}
EOF
fi
ln -sf /etc/nginx/sites-available/dentspace /etc/nginx/sites-enabled/dentspace
rm -f /etc/nginx/sites-enabled/default
if ! grep -q "manage" /etc/nginx/sites-available/dentspace; then
  sed -i 's/(availability|book|admin|setup)/(availability|book|admin|manage|setup)/' /etc/nginx/sites-available/dentspace
fi
nginx -t
systemctl reload nginx
systemctl restart "$(basename "$PHPSOCK" .sock)" 2>/dev/null || systemctl restart php*-fpm

IP="$(curl -s -m 5 https://api.ipify.org || echo 201.18.209.79)"
if [ $KEEP = 1 ]; then
  echo
  echo "Updated. Existing logins, data and settings were kept."
  echo "Booking page:  http://$IP/    Staff login:  http://$IP/dashboard/"
  exit 0
fi
echo
echo "=========================================================="
echo "Step 1 (once): open this link and create the 3 staff logins:"
echo "  http://$IP/api/setup.php?key=$SETUPKEY"
echo "Step 2: then run:  rm $WEB/api/setup.php"
echo "Booking page:  http://$IP/"
echo "Staff login:   http://$IP/dashboard/"
echo "=========================================================="
