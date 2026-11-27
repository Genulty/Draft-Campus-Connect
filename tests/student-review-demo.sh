#!/usr/bin/env bash
# Starts a private MySQL fixture and a PHP server bound to loopback.
set -euo pipefail
cd "$(dirname "$0")/.."
port=3012
if (( $# )); then port="$1"; fi
if ! [[ "$port" =~ ^[0-9]{4,5}$ ]] || (( 10#$port < 1024 || 10#$port > 65535 )); then
    printf 'Usage: bash tests/student-review-demo.sh [port]\n' >&2
    exit 2
fi
port=$((10#$port))
socket_record="../integration-$port.socket"
d=$(mktemp -d "${TMPDIR:-/tmp}/campus-student-review-db.XXXXXX")
d=$(cd "$d" && pwd -P)
socket="$d/mysql.sock"
mkdir -m 700 "$d/sessions"
db_pid= php_pid=
cleanup() {
    for pid in "$php_pid" "$db_pid"; do
        if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
    done
    if [[ -f "$socket_record" && "$(cat "$socket_record")" == "$socket" ]]; then
        rm -f -- "$socket_record"
    fi
    rm -rf -- "$d"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mysqld --no-defaults --initialize-insecure --datadir="$d/data" --log-error="$d/init.log"
mysqld --no-defaults --datadir="$d/data" --socket="$socket" --pid-file="$d/mysql.pid" \
    --log-error="$d/server.log" --skip-networking --mysqlx=OFF --skip-log-bin --server-id=732156 &
db_pid=$!
fixture_sql() { mysql --no-defaults --protocol=SOCKET --socket="$socket" --user=root --batch --raw "$@"; }
for i in {1..100}; do
    if fixture_sql -N -e "SELECT @@socket='$socket' AND @@skip_networking=1" 2>/dev/null | grep -qx 1; then break; fi
    sleep .1
done
test "$(fixture_sql -N -e "SELECT @@socket='$socket' AND @@skip_networking=1")" = 1
fixture_sql < db/Database_schema.sql
fixture_sql campus_connect < db/Project_data.sql
for migration in 2026-11-11-add-general-education 2026-11-12-add-degree-credit-requirements 2026-11-12-add-diversity-and-proficiency 2026-11-12-add-elective-rules 2026-11-17-degree-audit-rules; do
    fixture_sql campus_connect < "database/migrations/$migration.sql" > "$d/$migration.log"
done
DB_PASSWORD=$(php -r 'echo bin2hex(random_bytes(24));')
fixture_sql <<SQL
CREATE USER 'section_fixture'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON campus_connect.* TO 'section_fixture'@'localhost';
SET GLOBAL time_zone = '+00:00';
SET GLOBAL init_connect = 'SET timestamp = 1797508800';
SQL
export DB_HOST=localhost DB_SOCKET="$socket" DB_NAME=campus_connect DB_USER=section_fixture DB_PASSWORD
export CAMPUS_CONFIG="$d/unused.php" APP_ENV=production CAMPUS_DEMO_RECOVERY=0
php -d session.save_path="$d/sessions" -S "127.0.0.1:$port" -t public router.php > "$d/http.log" 2>&1 &
php_pid=$!
for ((i=0; i<80; i++)); do
    if grep -q 'Development Server .* started' "$d/http.log"; then break; fi
    if ! kill -0 "$php_pid" 2>/dev/null; then
        cat "$d/http.log" >&2
        exit 1
    fi
    sleep .1
done
grep -q 'Development Server .* started' "$d/http.log"
printf '%s\n' "$socket" > "$socket_record"
printf 'Local Student review demo (draft Degree Audit rules) at http://127.0.0.1:%s/\n' "$port"
printf 'Private fixture socket: %s\n' "$socket"
printf 'Press Ctrl-C in this terminal to stop and discard this fixture.\n'
wait "$php_pid"
