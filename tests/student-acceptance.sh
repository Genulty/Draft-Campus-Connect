#!/usr/bin/env bash
# Uses a fresh, socket-only MySQL instance and two loopback PHP servers.
set -euo pipefail
cd "$(dirname "$0")/.."
dir=$(mktemp -d "${TMPDIR:-/tmp}/campus-student-acceptance.XXXXXX")
dir=$(cd "$dir" && pwd -P)
socket="$dir/mysql.sock"
mkdir -m 700 "$dir/sessions" "$dir/recovery-sessions"
db_pid= primary_pid= recovery_pid=
cleanup() {
  for pid in "$primary_pid" "$recovery_pid" "$db_pid"; do
    if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  done
  rm -rf -- "$dir"
}
trap cleanup EXIT
mysqld --no-defaults --initialize-insecure --datadir="$dir/data" --log-error="$dir/init.log"
mysqld --no-defaults --datadir="$dir/data" --socket="$socket" --pid-file="$dir/mysql.pid" --log-error="$dir/mysql.log" \
  --skip-networking --mysqlx=OFF --skip-log-bin --server-id=732129 --innodb-buffer-pool-size=32M &
db_pid=$!
sql() { mysql --no-defaults --protocol=socket --socket="$socket" -uroot "$@"; }
for i in {1..125}; do
  if sql -N -e "SELECT @@socket='$socket' AND @@skip_networking=1" 2>/dev/null | rg -qx 1; then break; fi
  sleep .1
done
test "$(sql -N -e "SELECT @@socket='$socket' AND @@skip_networking=1")" = 1
sql < db/Database_schema.sql
sql campus_connect < db/Project_data.sql
for file in database/migrations/2026-11-11-add-general-education.sql database/migrations/2026-11-12-add-{degree-credit-requirements,diversity-and-proficiency,elective-rules}.sql; do
  sql campus_connect < "$file"
done
# Use the draft configuration shipped with the current main baseline.
sql campus_connect < database/migrations/2026-11-17-degree-audit-rules.sql > "$dir/draft-audit-rules.log"
export DB_HOST=localhost DB_SOCKET="$socket" DB_NAME=campus_connect DB_USER=root DB_PASSWORD='' CAMPUS_CONFIG="$dir/unused.php"
one_port() { node -e 'const s=require("node:net").createServer();s.listen(0,"127.0.0.1",()=>{console.log(s.address().port);s.close();});'; }
port=$(one_port)
recovery=$(one_port)
APP_ENV=production CAMPUS_DEMO_RECOVERY=0 php -d session.save_path="$dir/sessions" -S "127.0.0.1:$port" -t public router.php > "$dir/web.log" 2>&1 &
primary_pid=$!
APP_ENV=development CAMPUS_DEMO_RECOVERY=1 php -d session.save_path="$dir/recovery-sessions" -S "127.0.0.1:$recovery" -t public router.php > "$dir/recovery.log" 2>&1 &
recovery_pid=$!
for i in {1..100}; do
  if rg -q 'Development Server.*started' "$dir/web.log" && rg -q 'Development Server.*started' "$dir/recovery.log"; then break; fi
  kill -0 "$primary_pid" "$recovery_pid"
  sleep .1
done
node tests/student-acceptance.cjs "http://127.0.0.1:$port" "http://127.0.0.1:$recovery" "$socket"
