#!/usr/bin/env bash
# Starts a private MySQL fixture and a PHP server bound to loopback.
set -euo pipefail
cd "$(dirname "$0")/.."
d=$(mktemp -d "${TMPDIR:-/tmp}/campus-section-db.XXXXXX")
d=$(cd "$d" && pwd -P)
socket="$d/mysql.sock"
mkdir -m 700 "$d/sessions"
db_pid= php_pid=
cleanup() {
    for pid in "$php_pid" "$db_pid"; do
        if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
    done
    rm -rf -- "$d"
}
trap cleanup EXIT
mysqld --no-defaults --initialize-insecure --datadir="$d/data" --log-error="$d/init.log"
mysqld --no-defaults --datadir="$d/data" --socket="$socket" --pid-file="$d/mysql.pid" \
    --log-error="$d/server.log" --skip-networking --mysqlx=OFF --skip-log-bin --server-id=732142 &
db_pid=$!
fixture_sql() { mysql --no-defaults --protocol=SOCKET --socket="$socket" --user=root --batch --raw "$@"; }
for i in {1..100}; do
    if fixture_sql -N -e "SELECT @@socket='$socket' AND @@skip_networking=1" 2>/dev/null | grep -qx 1; then break; fi
    sleep .1
done
test "$(fixture_sql -N -e "SELECT @@socket='$socket' AND @@skip_networking=1")" = 1
fixture_sql < db/Database_schema.sql
fixture_sql campus_connect < db/Project_data.sql
DB_PASSWORD=$(php -r 'echo bin2hex(random_bytes(24));')
fixture_sql <<SQL
CREATE USER 'section_fixture'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON campus_connect.* TO 'section_fixture'@'localhost';
SET GLOBAL time_zone = '+00:00';
SET GLOBAL init_connect = 'SET timestamp = 1794830400';
SQL
export DB_HOST=localhost DB_SOCKET="$socket" DB_NAME=campus_connect DB_USER=section_fixture DB_PASSWORD
export CAMPUS_CONFIG="$d/unused.php" APP_ENV=production CAMPUS_DEMO_RECOVERY=0
if [[ "${1:-}" == --serve ]]; then
    port="${2:-3006}"
    [[ "$port" =~ ^[0-9]{4,5}$ ]] && (( 10#$port >= 1024 && 10#$port <= 65535 ))
else
    # Prepare one-time credentials for the seeded accounts used by Chromium.
    SECTION_TEST_PASSWORD=$(php -r 'echo bin2hex(random_bytes(24));')
    export SECTION_TEST_PASSWORD
    fixture_hash=$(php -r 'echo password_hash(getenv("SECTION_TEST_PASSWORD"), PASSWORD_DEFAULT);')
    fixture_sql campus_connect <<SQL
UPDATE Login SET user_Password = '$fixture_hash' WHERE user_ID IN (300003, 300001, 100001);
SQL
    port=$(node -e 'const s=require("node:net").createServer();s.listen(0,"127.0.0.1",()=>{console.log(s.address().port);s.close();});')
fi
php -d session.save_path="$d/sessions" -S "127.0.0.1:$port" -t public router.php > "$d/http.log" 2>&1 &
php_pid=$!
for ((i=0; i<80; i++)); do
    if grep -q 'Development Server .* started' "$d/http.log"; then break; fi
    kill -0 "$php_pid"; sleep .1
done
grep -q 'Development Server .* started' "$d/http.log"
if [[ "${1:-}" == --serve ]]; then
    printf 'Disposable Create Section demo at http://127.0.0.1:%s/\n' "$port"
    wait "$php_pid"
    exit
fi
node tests/create-section-live.cjs "http://127.0.0.1:$port" "$socket"
