#!/usr/bin/env bash
# Installs phpMyAdmin at http(s)://<your site>/phpmyadmin/ for browsing the campus_connect database.
# It gets its own MySQL login, 'dbadmin', limited to the campus_connect database.
#   sudo bash deploy/enable-phpmyadmin.sh
# Show the login again later:   sudo cat /etc/campus-connect/phpmyadmin-login.txt
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run with sudo: sudo bash deploy/enable-phpmyadmin.sh" >&2
  exit 1
fi
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOGIN_FILE=/etc/campus-connect/phpmyadmin-login.txt

echo "==> Installing phpMyAdmin (without Apache; nginx serves it)"
export DEBIAN_FRONTEND=noninteractive
echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect" | debconf-set-selections
echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
apt-get update -y
apt-get install -y --no-install-recommends phpmyadmin php-mbstring

# Connect over TCP to 127.0.0.1 (like the website does) rather than MySQL's socket file.
mkdir -p /etc/phpmyadmin/conf.d
cat > /etc/phpmyadmin/conf.d/campus-connect.php <<'PHP'
<?php
// Campus Connect: reach MySQL over TCP (written by deploy/enable-phpmyadmin.sh).
$cfg['Servers'][1]['host'] = '127.0.0.1';
$cfg['Servers'][1]['connect_type'] = 'tcp';
PHP

# Keep the existing password if this script already ran, otherwise make a random one.
mkdir -p /etc/campus-connect
PASSWORD="$(sed -n 's/^Password: //p' "$LOGIN_FILE" 2>/dev/null || true)"
[ -n "$PASSWORD" ] || PASSWORD="$(openssl rand -hex 12)"

echo "==> Creating the MySQL login 'dbadmin' (campus_connect database only)"
mysql <<SQL
CREATE USER IF NOT EXISTS 'dbadmin'@'localhost' IDENTIFIED BY '$PASSWORD';
ALTER USER 'dbadmin'@'localhost' IDENTIFIED BY '$PASSWORD';
GRANT ALL PRIVILEGES ON campus_connect.* TO 'dbadmin'@'localhost';
FLUSH PRIVILEGES;
SQL
printf 'Username: dbadmin\nPassword: %s\n' "$PASSWORD" > "$LOGIN_FILE"
chmod 600 "$LOGIN_FILE"

# Rebuild the nginx site so it includes /phpmyadmin/.
bash "$APP_DIR/deploy/lightsail-setup.sh" | grep "Campus Connect is running" || true

echo
echo "phpMyAdmin:  <your site address>/phpmyadmin/"
echo "Username:    dbadmin"
echo "Password:    $PASSWORD"
echo "(Shown again any time with: sudo cat $LOGIN_FILE)"
