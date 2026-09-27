#!/usr/bin/env bash
# TÃ¡i láº­p hai káº¿t quáº£ Claude bÃ¡o cÃ¡o trong IMPLEMENTATION-P0-CLAUDE.md:
#   (A) PHPUnit suite trÃªn MySQL 8
#   (B) ImpressionLog::create() khÃ´ng insert Ä‘Æ°á»£c
#
# PhiÃªn báº£n 2 â€” sá»­a theo review runner cá»§a Codex (5 Ä‘iá»ƒm):
#   1. TÃ i nguyÃªn Ä‘áº·t tÃªn riÃªng theo lÆ°á»£t cháº¡y, network --internal, khÃ´ng publish cá»•ng,
#      trap chá»‰ dá»n thá»© do chÃ­nh lÆ°á»£t nÃ y táº¡o ra.
#   2. Source mount read-only; .env tháº­t bá»‹ che báº±ng file rá»—ng; probe mount ngoÃ i /app;
#      khÃ´ng ghi gÃ¬ vÃ o repo. Evidence mount riÃªng, ghi Ä‘Æ°á»£c.
#   3. Cháº·n egress á»Ÿ táº§ng network (--internal), khÃ´ng dá»±a vÃ o QUEUE=sync hay MAIL=array.
#   4. KhÃ´ng dÃ¹ng `|| true` che lá»—i: ghi exit code tá»«ng bÆ°á»›c; háº¡ táº§ng/migration lá»—i thÃ¬ dá»«ng.
#   5. Ghi digest image, SHA, tráº¡ng thÃ¡i source; má»—i lÆ°á»£t má»™t thÆ° má»¥c evidence riÃªng.
set -Eeuo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="${REPO:-$(cd "$HERE/../.." && pwd)}"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
OUT="${OUT:-$HERE/evidence-claude/run-$RUN_ID}"

PHP_IMAGE="${PHP_IMAGE:-foodyman-local-backend}"   # PHP 8.x cÃ³ pdo_mysql
MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
SUITE_FILTER="${SUITE_FILTER:-}"                    # Ä‘á»ƒ trá»‘ng = cháº¡y full suite
READY_TIMEOUT_S="${READY_TIMEOUT_S:-120}"

NET="oohx-verify-net-$RUN_ID"
DB_CONTAINER="oohx-verify-db-$RUN_ID"
TEST_DB="oohx_verify"; TEST_USER="root"; TEST_PASS="verify-$RUN_ID"

NET_CREATED=0; DB_CREATED=0
cleanup() {
  local rc=$?
  [ "$DB_CREATED" = 1 ] && docker stop "$DB_CONTAINER" >/dev/null 2>&1 || true
  [ "$NET_CREATED" = 1 ] && docker network rm "$NET" >/dev/null 2>&1 || true
  exit "$rc"
}
trap cleanup EXIT INT TERM

step() { printf '\nâ”€â”€ %s\n' "$*"; }
record() { printf '%s=%s\n' "$1" "$2" >> "$OUT/environment.txt"; }
fail()  { printf 'Dá»ªNG: %s\n' "$*" >&2; exit 1; }

# â”€â”€â”€ Guard: cháº¡y TRÆ¯á»šC má»i thao tÃ¡c ghi â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
step "Kiá»ƒm tra an toÃ n"
[ -d "$REPO/app" ] && [ -f "$REPO/artisan" ] || fail "REPO khÃ´ng pháº£i thÆ° má»¥c Laravel: $REPO"
[ -d "$REPO/vendor/bin" ] || fail "Thiáº¿u vendor/ (cáº§n cáº£ dev deps Ä‘á»ƒ cÃ³ PHPUnit)."
if [ -f "$REPO/bootstrap/cache/config.php" ]; then
  fail "Tá»“n táº¡i bootstrap/cache/config.php â€” config cache ghi Ä‘Ã¨ biáº¿n mÃ´i trÆ°á»ng; xoÃ¡ rá»“i cháº¡y láº¡i."
fi
# TÃªn tÃ i nguyÃªn sinh theo RUN_ID nÃªn khÃ´ng thá»ƒ trÃ¹ng .env; váº«n kiá»ƒm cho cháº¯c.
for key in DB_HOST DB_DATABASE; do
  v="$(grep -E "^$key=" "$REPO/.env" 2>/dev/null | cut -d= -f2- || true)"
  [ -n "$v" ] && { [ "$v" = "$DB_CONTAINER" ] || [ "$v" = "$TEST_DB" ]; } && \
    fail "Cáº¥u hÃ¬nh test trÃ¹ng $key trong .env ($v)."
done
docker info >/dev/null 2>&1 || fail "Docker khÃ´ng cháº¡y."
echo "Guard OK â€” DB test '$TEST_DB' trÃªn container dÃ¹ng má»™t láº§n '$DB_CONTAINER'."

mkdir -p "$OUT"
: > "$OUT/environment.txt"
: > "$OUT/exit-codes.txt"

# â”€â”€â”€ Háº¡ táº§ng â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
step "Dá»±ng network ná»™i bá»™ + MySQL táº¡m"
# --internal: container khÃ´ng ra Ä‘Æ°á»£c Internet â‡’ khÃ´ng gá»i webhook/mail/API tháº­t
docker network create --internal "$NET" >/dev/null; NET_CREATED=1
docker run -d --rm --name "$DB_CONTAINER" --network "$NET" \
  -e MYSQL_ROOT_PASSWORD="$TEST_PASS" -e MYSQL_DATABASE="$TEST_DB" \
  "$MYSQL_IMAGE" >/dev/null; DB_CREATED=1

waited=0
until docker exec "$DB_CONTAINER" mysqladmin ping -u"$TEST_USER" -p"$TEST_PASS" --silent >/dev/null 2>&1; do
  sleep 2; waited=$((waited+2))
  [ "$waited" -ge "$READY_TIMEOUT_S" ] && fail "MySQL khÃ´ng sáºµn sÃ ng sau ${READY_TIMEOUT_S}s."
done
echo "MySQL sáºµn sÃ ng sau ${waited}s."

# php() cháº¡y trong container:
#   - source read-only
#   - /dev/null che .env tháº­t â‡’ chá»‰ biáº¿n mÃ´i trÆ°á»ng bÃªn dÆ°á»›i cÃ³ tÃ¡c dá»¥ng
#   - tmpfs cho cÃ¡c thÆ° má»¥c Laravel cáº§n ghi (khÃ´ng Ä‘á»¥ng repo)
#   - probe mount ngoÃ i /app
#   - evidence mount riÃªng, ghi Ä‘Æ°á»£c
# Prelude táº¡o láº¡i cÃ¢y thÆ° má»¥c ghi Ä‘Æ°á»£c bÃªn trong tmpfs (source mount read-only).
# Pháº£i cháº¡y cÃ¹ng container vá»›i lá»‡nh php, vÃ¬ tmpfs sá»‘ng theo tá»«ng `docker run`.
PRELUDE='mkdir -p /app/storage/framework/views /app/storage/framework/cache/data \
  /app/storage/framework/sessions /app/storage/framework/testing \
  /app/storage/app/public /app/storage/logs /app/bootstrap/cache; exec php "$@"'

dphp() {
  MSYS_NO_PATHCONV=1 docker run --rm --network "$NET" --entrypoint sh \
    -v "$REPO:/app:ro" \
    -v /dev/null:/app/.env:ro \
    -v "$OUT:/evidence" \
    -v "$HERE/probe-impression-insert.php:/probe/probe.php:ro" \
    --tmpfs /app/storage/framework --tmpfs /app/storage/app \
    --tmpfs /app/storage/logs --tmpfs /app/bootstrap/cache \
    -w /app \
    -e APP_ENV=testing -e APP_DEBUG=true \
    -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    `# Cáº¥u hÃ¬nh KHÃ”NG bÃ­ máº­t, pháº£i khá»›p .env vÃ¬ route nhÃ³m theo tÃªn miá»n:` \
    -e APP_URL=http://oohx.test -e FRONTPAGE_DOMAIN=oohx.test -e DASH_DOMAIN=dash.oohx.test \
    -e DB_CONNECTION=mysql -e DB_HOST="$DB_CONTAINER" -e DB_PORT=3306 \
    -e DB_DATABASE="$TEST_DB" -e DB_USERNAME="$TEST_USER" -e DB_PASSWORD="$TEST_PASS" \
    -e DB_URL= -e DB_SOCKET= \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
    -e MAIL_MAILER=array -e BROADCAST_CONNECTION=null \
    "$PHP_IMAGE" -c "$PRELUDE" php-runner "$@"
}

# â”€â”€â”€ Ghi mÃ´i trÆ°á»ng â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
step "Ghi thÃ´ng tin mÃ´i trÆ°á»ng"
record run_id        "$RUN_ID"
record date_utc      "$(date -u +%FT%TZ)"
record repo_sha      "$(git -C "$REPO" rev-parse HEAD)"
record repo_dirty_code "$(git -C "$REPO" status --porcelain -- app routes database tests config | tr '\n' ';')"
record repo_dirty_all  "$(git -C "$REPO" status --porcelain | tr '\n' ';')"
record php_image     "$PHP_IMAGE"
record php_image_id  "$(docker image inspect "$PHP_IMAGE" --format '{{.Id}}')"
record php_version   "$(dphp -r 'echo PHP_VERSION;')"
record mysql_image   "$MYSQL_IMAGE"
record mysql_image_digest "$(docker image inspect "$MYSQL_IMAGE" --format '{{if .RepoDigests}}{{index .RepoDigests 0}}{{end}}')"
# Láº¥y qua chÃ­nh káº¿t ná»‘i cá»§a á»©ng dá»¥ng: cháº¯c cháº¯n lÃ  server mÃ  test sáº½ dÃ¹ng.
record mysql_version "$(dphp artisan tinker --execute='echo DB::selectOne("select version() as v")->v;' 2>/dev/null | tr -d '\r\n')"
record suite_filter  "${SUITE_FILTER:-<full suite>}"
cat "$OUT/environment.txt"

# Káº¿t ná»‘i thá»±c táº¿ trÆ°á»›c khi migrate â€” chá»©ng minh khÃ´ng trá» vÃ o DB tháº­t
step "XÃ¡c nháº­n káº¿t ná»‘i thá»±c táº¿"
dphp artisan tinker --execute='
  $c = config("database.default");
  echo "connection=$c host=", config("database.connections.$c.host"),
       " database=", config("database.connections.$c.database"), PHP_EOL;
' > "$OUT/effective-connection.txt" 2>&1
cat "$OUT/effective-connection.txt"
grep -q "host=$DB_CONTAINER" "$OUT/effective-connection.txt" \
  || fail "Káº¿t ná»‘i thá»±c táº¿ khÃ´ng trá» tá»›i container test â€” dá»«ng trÆ°á»›c khi ghi."

# â”€â”€â”€ (A) Bá»™ test â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
step "(A) Cháº¡y bá»™ test"
set +e
if [ -n "$SUITE_FILTER" ]; then
  dphp vendor/bin/phpunit /app/docs/audit-5-vung-2026-09-23/T1ReviewTest.php --log-junit /evidence/phpunit-mysql.xml > "$OUT/phpunit-mysql.txt" 2>&1
else
  dphp artisan test --log-junit /evidence/phpunit-mysql.xml > "$OUT/phpunit-mysql.txt" 2>&1
fi
suite_rc=$?
set -e
echo "phpunit_exit=$suite_rc" >> "$OUT/exit-codes.txt"
tail -3 "$OUT/phpunit-mysql.txt"
# Suite cÃ³ lá»—i baseline Ä‘Ã£ biáº¿t â‡’ khÃ´ng dá»«ng á»Ÿ Ä‘Ã¢y, nhÆ°ng pháº£i cÃ³ file káº¿t quáº£.
[ -s "$OUT/phpunit-mysql.xml" ] || fail "KhÃ´ng sinh Ä‘Æ°á»£c JUnit â€” coi nhÆ° lá»—i háº¡ táº§ng."
{
  echo "junit_totals=$(grep -o 'tests="[0-9]*" assertions="[0-9]*" errors="[0-9]*" failures="[0-9]*"' "$OUT/phpunit-mysql.xml" | head -1)"
  echo "not_passed_names:"
  grep -oE '<testcase name="[^"]+"[^>]*>\s*<(failure|error)' -A0 "$OUT/phpunit-mysql.xml" 2>/dev/null | head -20
} >> "$OUT/summary.txt" 2>/dev/null || true

# â”€â”€â”€ (B) Probe â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
# DÃ¹ng CSDL riÃªng Ä‘á»ƒ tháº¥y migration cháº¡y tá»« Ä‘áº§u, khÃ´ng phá»¥ thuá»™c schema do suite Ä‘á»ƒ láº¡i.
step "(B) Probe ImpressionLog::create() trÃªn CSDL má»›i"
PROBE_DB="oohx_probe"
docker exec "$DB_CONTAINER" mysql -u"$TEST_USER" -p"$TEST_PASS" \
  -e "DROP DATABASE IF EXISTS $PROBE_DB; CREATE DATABASE $PROBE_DB;" >/dev/null 2>&1 \
  || fail "KhÃ´ng táº¡o Ä‘Æ°á»£c CSDL probe."

dphp_probe() {
  MSYS_NO_PATHCONV=1 docker run --rm --network "$NET" --entrypoint sh \
    -v "$REPO:/app:ro" -v /dev/null:/app/.env:ro -v "$OUT:/evidence" \
    -v "$HERE/probe-impression-insert.php:/probe/probe.php:ro" \
    --tmpfs /app/storage/framework --tmpfs /app/storage/app \
    --tmpfs /app/storage/logs --tmpfs /app/bootstrap/cache -w /app \
    -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    -e APP_URL=http://oohx.test -e FRONTPAGE_DOMAIN=oohx.test -e DASH_DOMAIN=dash.oohx.test \
    -e DB_CONNECTION=mysql -e DB_HOST="$DB_CONTAINER" -e DB_PORT=3306 \
    -e DB_DATABASE="$PROBE_DB" -e DB_USERNAME="$TEST_USER" -e DB_PASSWORD="$TEST_PASS" \
    -e DB_URL= -e DB_SOCKET= -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array \
    "$PHP_IMAGE" -c "$PRELUDE" php-runner "$@"
}

set +e
dphp_probe artisan migrate --force > "$OUT/migrate.txt" 2>&1
migrate_rc=$?
set -e
echo "migrate_exit=$migrate_rc" >> "$OUT/exit-codes.txt"
[ "$migrate_rc" -eq 0 ] || { tail -20 "$OUT/migrate.txt"; fail "Migration lá»—i â€” dá»«ng."; }
grep -qi "Nothing to migrate" "$OUT/migrate.txt" && fail "CSDL probe khÃ´ng tráº¯ng â€” káº¿t quáº£ khÃ´ng Ä‘Ã¡ng tin."
tail -2 "$OUT/migrate.txt"

set +e
dphp_probe artisan tinker --execute="require '/probe/probe.php';" > "$OUT/probe-impression.txt" 2>&1
probe_rc=$?
set -e
echo "probe_exit=$probe_rc" >> "$OUT/exit-codes.txt"
[ "$probe_rc" -eq 0 ] || { cat "$OUT/probe-impression.txt"; fail "Probe khÃ´ng cháº¡y Ä‘Æ°á»£c â€” lá»—i háº¡ táº§ng, khÃ´ng pháº£i káº¿t quáº£."; }
cat "$OUT/probe-impression.txt"

step "Xong"
echo "Evidence: $OUT"
cat "$OUT/exit-codes.txt"
