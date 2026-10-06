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

echo "==> Installing system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y curl git nginx build-essential python3 ca-certificates

NODE_MAJOR="$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || true)"
if [ -z "$NODE_MAJOR" ] || [ "$NODE_MAJOR" -lt 18 ]; then
  echo "==> Installing Node.js 20"
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi
echo "Node $(node -v)"

echo "==> Installing app dependencies"
sudo -u "$APP_USER" bash -c "cd '$APP_DIR' && npm ci --omit=dev"

if [ ! -f "$ENV_FILE" ]; then
  echo "==> Writing $ENV_FILE"
  cat > "$ENV_FILE" <<ENV
NODE_ENV=production
HOST=127.0.0.1
PORT=3000
DB_PATH=$APP_DIR/campus.db
SESSION_SECRET=$(openssl rand -hex 32)
ENV
  chmod 600 "$ENV_FILE"
fi

echo "==> Creating systemd service"
cat > /etc/systemd/system/$SERVICE.service <<UNIT
[Unit]
Description=Campus Connect
After=network.target

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
