#!/usr/bin/env bash
# Sets up Campus Connect on an Amazon Lightsail Ubuntu instance:
# nginx serves the website (public/) and passes /api/ requests to PHP-FPM (api/index.php),
# which talks to MySQL.
# Run from inside the cloned repo:   sudo bash deploy/lightsail-setup.sh
# Safe to run again after a `git pull` to redeploy.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run with sudo: sudo bash deploy/lightsail-setup.sh" >&2
  exit 1
fi

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SITE=campus-connect
CONFIG_DIR=/etc/campus-connect
CONFIG_FILE=$CONFIG_DIR/config.php
OLD_ENV_FILE=/etc/campus-connect.env   # used by the earlier Node.js version

# Small plans (512 MB) don't have much memory for MySQL; add 1 GB of swap.
MEM_MB="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
if [ "$MEM_MB" -lt 1500 ] && ! swapon --show | grep -q .; then
  echo "==> Adding 1 GB swap (only ${MEM_MB} MB RAM)"
  fallocate -l 1G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=1024
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "==> Installing nginx, MySQL and PHP"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y curl git nginx mysql-server php-fpm php-mysql ca-certificates
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
echo "PHP $PHP_VERSION"

# The earlier version of this site ran on Node.js; stop and remove that service if it is still there.
if [ -f /etc/systemd/system/$SITE.service ]; then
  echo "==> Removing the old Node.js service"
  systemctl disable --now $SITE || true
  rm -f /etc/systemd/system/$SITE.service
  systemctl daemon-reload
fi

# Keep MySQL's memory use small enough for the 512 MB plan.
cat > /etc/mysql/mysql.conf.d/zz-campus-connect.cnf <<'CNF'
[mysqld]
performance_schema = OFF
innodb_buffer_pool_size = 64M
max_connections = 30
CNF
systemctl enable mysql
systemctl restart mysql

# The MySQL password for the website's 'campus' user: keep the existing one, or make a new random one.
DB_PASSWORD=""
if [ -f "$CONFIG_FILE" ]; then
  DB_PASSWORD="$(php -r "echo (require '$CONFIG_FILE')['password'] ?? '';")"
elif [ -f "$OLD_ENV_FILE" ]; then
  DB_PASSWORD="$(grep '^DB_PASSWORD=' "$OLD_ENV_FILE" | cut -d= -f2 || true)"
fi
[ -n "$DB_PASSWORD" ] || DB_PASSWORD="$(openssl rand -hex 16)"

echo "==> Writing $CONFIG_FILE (readable only by root and PHP)"
mkdir -p "$CONFIG_DIR"
cat > "$CONFIG_FILE" <<PHP
<?php
// Database settings for Campus Connect (written by deploy/lightsail-setup.sh).
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'user' => 'campus',
    'password' => '$DB_PASSWORD',
    'database' => 'campus_connect',
];
PHP
chown root:www-data "$CONFIG_FILE"
chmod 640 "$CONFIG_FILE"
rm -f "$OLD_ENV_FILE"

echo "==> Creating MySQL user 'campus'"
mysql <<SQL
CREATE USER IF NOT EXISTS 'campus'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'campus'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON campus_connect.* TO 'campus'@'localhost';
FLUSH PRIVILEGES;
SQL

if ! mysql -e 'USE campus_connect' 2>/dev/null; then
  echo "==> Creating the campus_connect database and loading the project data"
  mysql < "$APP_DIR/db/Database_schema.sql"
  mysql campus_connect < "$APP_DIR/db/Project_data.sql"
else
  echo "==> Database campus_connect already exists (reload it with: sudo bash deploy/reset-db.sh)"
fi

# nginx and PHP run as www-data and need to reach the project folder inside the home directory.
dir="$APP_DIR"
while [ "$dir" != "/" ]; do chmod o+x "$dir"; dir="$(dirname "$dir")"; done
chmod -R o+rX "$APP_DIR/public" "$APP_DIR/api"

# HTTPS is used if deploy/enable-https.sh has already obtained a certificate.
DOMAIN="$(ls /etc/letsencrypt/live 2>/dev/null | grep -v README | head -1 || true)"

APP_BLOCK="    root $APP_DIR/public;
    index index.html;

    # The website: HTML, CSS and JavaScript files.
    location / {
        try_files \$uri /index.html;
    }

    # The API: every /api/... request is handled by api/index.php in PHP-FPM.
    location /api/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $APP_DIR/api/index.php;
        fastcgi_param SCRIPT_NAME /api/index.php;
        fastcgi_pass unix:$FPM_SOCKET;
    }"

# phpMyAdmin at /phpmyadmin/, if deploy/enable-phpmyadmin.sh has installed it.
if [ -d /usr/share/phpmyadmin ]; then
  APP_BLOCK="$APP_BLOCK

    # phpMyAdmin: a web page for browsing the MySQL database.
    location = /phpmyadmin { return 301 /phpmyadmin/; }
    location ^~ /phpmyadmin/ {
        root /usr/share/;
        index index.php;
        try_files \$uri \$uri/ =404;
        location ~ \\.php\$ {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$request_filename;
            fastcgi_pass unix:$FPM_SOCKET;
        }
    }"
fi

# Listen on IPv6 too when the server has it.
V6_80=""; V6_443=""
if [ -f /proc/net/if_inet6 ]; then V6_80="listen [::]:80 default_server;"; V6_443="listen [::]:443 ssl;"; fi

echo "==> Configuring nginx"
if [ -n "$DOMAIN" ] && [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
  cat > /etc/nginx/sites-available/$SITE <<NGINX
server {
    listen 80 default_server;
    $V6_80
    server_name $DOMAIN _;
    return 301 https://$DOMAIN\$request_uri;
}

server {
    listen 443 ssl;
    $V6_443
    server_name $DOMAIN;
    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

$APP_BLOCK
}
NGINX
  URL="https://$DOMAIN"
  CHECK=(--resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/api/me")
else
  cat > /etc/nginx/sites-available/$SITE <<NGINX
server {
    listen 80 default_server;
    $V6_80
    server_name _;

$APP_BLOCK
}
NGINX
  URL="http://$(curl -fsS --max-time 3 https://checkip.amazonaws.com 2>/dev/null || echo '<your-instance-public-ip>')"
  CHECK=("http://127.0.0.1/api/me")
fi
ln -sf /etc/nginx/sites-available/$SITE /etc/nginx/sites-enabled/$SITE
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable "php${PHP_VERSION}-fpm" nginx
systemctl restart "php${PHP_VERSION}-fpm"
systemctl reload nginx || systemctl restart nginx

sleep 1
# The API answers "Not logged in." (HTTP 401) when it is working.
if curl -sk -o /dev/null -w '%{http_code}' "${CHECK[@]}" | grep -q 401; then
  echo
  echo "Campus Connect is running: $URL"
  echo "PHP errors:  sudo tail -n 50 /var/log/nginx/error.log"
else
  echo "The site did not answer. Check: sudo tail -n 50 /var/log/nginx/error.log" >&2
  exit 1
fi
