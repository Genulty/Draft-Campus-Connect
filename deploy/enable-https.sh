#!/usr/bin/env bash
# Turns on HTTPS with a free Let's Encrypt certificate.
# Without your own domain it uses <ip-with-dashes>.sslip.io, a free hostname that points to this server.
#   sudo bash deploy/enable-https.sh                 # e.g. https://3-239-161-185.sslip.io
#   sudo bash deploy/enable-https.sh mydomain.com    # if you own a domain pointing at this server
# Port 443 (HTTPS) must be open in the Lightsail firewall first.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run with sudo: sudo bash deploy/enable-https.sh" >&2
  exit 1
fi

DOMAIN="${1:-}"
if [ -z "$DOMAIN" ]; then
  IP="$(curl -fsS --max-time 5 https://checkip.amazonaws.com | tr -d '[:space:]')"
  DOMAIN="${IP//./-}.sslip.io"
fi
echo "==> Setting up HTTPS for $DOMAIN"

# Let nginx answer to the new name (certbot needs a matching server_name).
CONF=/etc/nginx/sites-available/campus-connect
sed -i -E "s/^(\s*server_name).*/\1 $DOMAIN _;/" "$CONF"
nginx -t
systemctl reload nginx

apt-get update -y
apt-get install -y certbot python3-certbot-nginx

# Gets the certificate, switches nginx to HTTPS, redirects http:// to https://, and sets up automatic renewal.
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect

# Session cookies only travel over HTTPS from now on.
ENV_FILE=/etc/campus-connect.env
grep -q '^COOKIE_SECURE=' "$ENV_FILE" || echo 'COOKIE_SECURE=1' >> "$ENV_FILE"
systemctl restart campus-connect

echo
echo "Done. Open: https://$DOMAIN"
