#!/usr/bin/env bash
# Drops and reloads the campus_connect database from db/Database_schema.sql and db/Project_data.sql.
#   sudo bash deploy/reset-db.sh
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APP_USER="$(stat -c %U "$APP_DIR")"
(cd "$APP_DIR" && sudo -u "$APP_USER" env $(grep -v '^#' /etc/campus-connect.env | xargs) node db/load.js)
systemctl restart campus-connect
echo "Database reloaded."
