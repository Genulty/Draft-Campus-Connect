#!/usr/bin/env bash
# Drops and reloads the campus_connect database from db/Database_schema.sql and db/Project_data.sql.
#   sudo bash deploy/reset-db.sh
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
echo "Reloading the database (this takes a few seconds)..."
mysql < "$APP_DIR/db/Database_schema.sql"
mysql campus_connect < "$APP_DIR/db/Project_data.sql"
rm -f /tmp/campus-connect-reset-failures.json
echo "Database reloaded: $(mysql -N campus_connect -e 'SELECT COUNT(*) FROM User') users."
