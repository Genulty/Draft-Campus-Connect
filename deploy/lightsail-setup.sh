#!/usr/bin/env bash
# Sets up Campus Connect on an Amazon Lightsail Ubuntu instance.
# Run from inside the cloned repo:   sudo bash deploy/lightsail-setup.sh
# Safe to run again after a `git pull` to redeploy.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run with sudo: sudo bash deploy/lightsail-setup.sh" >&2
  exit 1
fi

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APP_USER="$(stat -c %U "$APP_DIR")"
ENV_FILE=/etc/campus-connect.env
SERVICE=campus-connect

# Small plans (512 MB) don't have enough memory for MySQL + Node; add 1 GB of swap.
MEM_MB="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
if [ "$MEM_MB" -lt 1500 ] && ! swapon --show | grep -q .; then
  echo "==> Adding 1 GB swap (only ${MEM_MB} MB RAM)"
  fallocate -l 1G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=1024
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "==> Installing system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y curl git nginx mysql-server ca-certificates

NODE_MAJOR="$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || true)"
if [ -z "$NODE_MAJOR" ] || [ "$NODE_MAJOR" -lt 18 ]; then
  echo "==> Installing Node.js 20"
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi
echo "Node $(node -v)"

echo "==> Installing app dependencies"
sudo -u "$APP_USER" bash -c "cd '$APP_DIR' && npm ci --omit=dev"

# Keep MySQL's memory use small enough for the 512 MB plan.
cat > /etc/mysql/mysql.conf.d/zz-campus-connect.cnf <<'CNF'
[mysqld]
performance_schema = OFF
innodb_buffer_pool_size = 64M
max_connections = 30
CNF
systemctl enable mysql
systemctl restart mysql

# Older installs of this script used SQLite; start the env file over for MySQL.
if [ -f "$ENV_FILE" ] && ! grep -q '^DB_PASSWORD=' "$ENV_FILE"; then
  rm -f "$ENV_FILE"
fi
if [ ! -f "$ENV_FILE" ]; then
  echo "==> Writing $ENV_FILE"
  cat > "$ENV_FILE" <<ENV
NODE_ENV=production
HOST=127.0.0.1
PORT=3000
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=campus
DB_PASSWORD=$(openssl rand -hex 16)
DB_NAME=campus_connect
SESSION_SECRET=$(openssl rand -hex 32)
ENV
  chmod 600 "$ENV_FILE"
fi
DB_PASSWORD="$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2)"

echo "==> Creating MySQL user 'campus'"
mysql <<SQL
CREATE USER IF NOT EXISTS 'campus'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'campus'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON campus_connect.* TO 'campus'@'localhost';
FLUSH PRIVILEGES;
SQL

if ! mysql -e 'USE campus_connect' 2>/dev/null; then
  echo "==> Creating the campus_connect database and loading the project data"
  (cd "$APP_DIR" && sudo -u "$APP_USER" env $(grep -v '^#' "$ENV_FILE" | xargs) node db/load.js)
else
  echo "==> Database campus_connect already exists (reload it with: sudo bash deploy/reset-db.sh)"
fi

echo "==> Creating systemd service"
cat > /etc/systemd/system/$SERVICE.service <<UNIT
[Unit]
Description=Campus Connect
After=network.target mysql.service
Requires=mysql.service

[Service]
User=$APP_USER
WorkingDirectory=$APP_DIR
EnvironmentFile=$ENV_FILE
ExecStart=$(command -v node) server/index.js
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable $SERVICE
systemctl restart $SERVICE

echo "==> Configuring nginx on port 80"
cat > /etc/nginx/sites-available/$SERVICE <<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/$SERVICE /etc/nginx/sites-enabled/$SERVICE
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable nginx
systemctl reload nginx || systemctl restart nginx

sleep 2
if curl -fsS http://127.0.0.1/ > /dev/null; then
  IP="$(curl -fsS --max-time 3 https://checkip.amazonaws.com 2>/dev/null || echo '<your-instance-public-ip>')"
  echo
  echo "Campus Connect is running: http://$IP"
  echo "Logs:    sudo journalctl -u $SERVICE -f"
else
  echo "The app did not answer. Check: sudo journalctl -u $SERVICE -n 50" >&2
  exit 1
fi
