#!/usr/bin/env bash
# Runs only against a fresh socket-only MySQL instance and loopback portal.
set -euo pipefail
cd "$(dirname "$0")/.."
d=$(mktemp -d /tmp/campus-audit-preview.XXXXXX)
d=$(cd "$d" && pwd -P)
socket="$d/mysql.sock"
session_dir="$d/sessions"
mkdir -m 700 "$session_dir"
db_pid='' web_pid=''
cleanup() {
    for pid in "$web_pid" "$db_pid"; do
        if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
    done
    rm -rf -- "$d"
}
trap cleanup EXIT
mysql_test() { mysql --no-defaults --protocol=SOCKET --socket="$socket" --user=root --batch --raw "$@"; }
mysqld --no-defaults --initialize-insecure --datadir="$d/data" --log-error="$d/init.log"
mysqld --no-defaults --datadir="$d/data" --socket="$socket" --pid-file="$d/mysql.pid" \
    --log-error="$d/server.log" --skip-networking --mysqlx=OFF --skip-log-bin --server-id=732122 &
db_pid=$!
for i in {1..100}; do
    if mysql_test -N -e "SELECT @@socket='$socket' AND @@skip_networking=1" 2>/dev/null | rg -qx 1; then break; fi
    sleep .1
done
test "$(mysql_test -N -e "SELECT @@socket='$socket' AND @@skip_networking=1")" = 1
export DB_HOST=localhost DB_SOCKET="$socket" DB_NAME=campus_connect DB_USER=root DB_PASSWORD='' CAMPUS_CONFIG="$d/unused.php"
mysql_test < db/Database_schema.sql
mysql_test campus_connect < db/Project_data.sql
mysql_test campus_connect < database/migrations/2026-11-11-add-general-education.sql
mysql_test campus_connect < database/migrations/2026-11-12-add-degree-credit-requirements.sql
supplemental=database/migrations/2026-11-12-add-diversity-and-proficiency.sql
electives=database/migrations/2026-11-12-add-elective-rules.sql
mysql_test campus_connect < "$supplemental"
mysql_test campus_connect < "$electives"
if [[ "${1:-}" == --serve ]]; then
    port="${2:-3003}"
    [[ "$port" =~ ^[0-9]{4,5}$ ]] && (( 10#$port >= 1024 && 10#$port <= 65535 ))
    mysql_test campus_connect < tests/fixtures/degree-audit-demo-rules.sql
else
    port=$(node -e 'const s=require("node:net").createServer();s.listen(0,"127.0.0.1",()=>{console.log(s.address().port);s.close();});')
fi
php -d session.save_path="$session_dir" -S "127.0.0.1:$port" -t public router.php > "$d/browser-server.log" 2>&1 &
web_pid=$!
for ((i=0; i<80; i++)); do
    if grep -q 'Development Server .* started' "$d/browser-server.log"; then break; fi
    kill -0 "$web_pid"; sleep .1
done
grep -q 'Development Server .* started' "$d/browser-server.log"
if [[ "${1:-}" == --serve ]]; then
    printf 'Disposable Degree Audit demo at http://127.0.0.1:%s/#degree-audit
' "$port"
    wait "$web_pid"
    exit
fi
php tests/degree-audit.php
mysql_test campus_connect < tests/fixtures/degree-audit-demo-rules.sql
php tests/degree-audit.php
node tests/degree-audit-major-preview.cjs "http://127.0.0.1:$port" "$socket"
