#!/usr/bin/env bash
# Tái lập hai kết quả Claude báo cáo trong IMPLEMENTATION-P0-CLAUDE.md:
#   (A) PHPUnit suite trên MySQL 8
#   (B) ImpressionLog::create() không insert được
#
# Phiên bản 2 — sửa theo review runner của Codex (5 điểm):
#   1. Tài nguyên đặt tên riêng theo lượt chạy, network --internal, không publish cổng,
#      trap chỉ dọn thứ do chính lượt này tạo ra.
#   2. Source mount read-only; .env thật bị che bằng file rỗng; probe mount ngoài /app;
#      không ghi gì vào repo. Evidence mount riêng, ghi được.
#   3. Chặn egress ở tầng network (--internal), không dựa vào QUEUE=sync hay MAIL=array.
#   4. Không dùng `|| true` che lỗi: ghi exit code từng bước; hạ tầng/migration lỗi thì dừng.
#   5. Ghi digest image, SHA, trạng thái source; mỗi lượt một thư mục evidence riêng.
set -Eeuo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="${REPO:-$(cd "$HERE/../.." && pwd)}"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
OUT="${OUT:-$HERE/evidence-claude/run-$RUN_ID}"

PHP_IMAGE="${PHP_IMAGE:-foodyman-local-backend}"   # PHP 8.x có pdo_mysql
MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
SUITE_FILTER="${SUITE_FILTER:-}"                    # để trống = chạy full suite
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

step() { printf '\n── %s\n' "$*"; }
record() { printf '%s=%s\n' "$1" "$2" >> "$OUT/environment.txt"; }
fail()  { printf 'DỪNG: %s\n' "$*" >&2; exit 1; }

# ─── Guard: chạy TRƯỚC mọi thao tác ghi ───────────────────────────────────────
step "Kiểm tra an toàn"
[ -d "$REPO/app" ] && [ -f "$REPO/artisan" ] || fail "REPO không phải thư mục Laravel: $REPO"
[ -d "$REPO/vendor/bin" ] || fail "Thiếu vendor/ (cần cả dev deps để có PHPUnit)."
if [ -f "$REPO/bootstrap/cache/config.php" ]; then
  fail "Tồn tại bootstrap/cache/config.php — config cache ghi đè biến môi trường; xoá rồi chạy lại."
fi
# Tên tài nguyên sinh theo RUN_ID nên không thể trùng .env; vẫn kiểm cho chắc.
for key in DB_HOST DB_DATABASE; do
  v="$(grep -E "^$key=" "$REPO/.env" 2>/dev/null | cut -d= -f2- || true)"
  [ -n "$v" ] && { [ "$v" = "$DB_CONTAINER" ] || [ "$v" = "$TEST_DB" ]; } && \
    fail "Cấu hình test trùng $key trong .env ($v)."
done
docker info >/dev/null 2>&1 || fail "Docker không chạy."
echo "Guard OK — DB test '$TEST_DB' trên container dùng một lần '$DB_CONTAINER'."

mkdir -p "$OUT"
: > "$OUT/environment.txt"
: > "$OUT/exit-codes.txt"

# ─── Hạ tầng ──────────────────────────────────────────────────────────────────
step "Dựng network nội bộ + MySQL tạm"
# --internal: container không ra được Internet ⇒ không gọi webhook/mail/API thật
docker network create --internal "$NET" >/dev/null; NET_CREATED=1
docker run -d --rm --name "$DB_CONTAINER" --network "$NET" \
  -e MYSQL_ROOT_PASSWORD="$TEST_PASS" -e MYSQL_DATABASE="$TEST_DB" \
  "$MYSQL_IMAGE" >/dev/null; DB_CREATED=1

waited=0
until docker exec "$DB_CONTAINER" mysqladmin ping -u"$TEST_USER" -p"$TEST_PASS" --silent >/dev/null 2>&1; do
  sleep 2; waited=$((waited+2))
  [ "$waited" -ge "$READY_TIMEOUT_S" ] && fail "MySQL không sẵn sàng sau ${READY_TIMEOUT_S}s."
done
echo "MySQL sẵn sàng sau ${waited}s."

# php() chạy trong container:
#   - source read-only
#   - /dev/null che .env thật ⇒ chỉ biến môi trường bên dưới có tác dụng
#   - tmpfs cho các thư mục Laravel cần ghi (không đụng repo)
#   - probe mount ngoài /app
#   - evidence mount riêng, ghi được
# Prelude tạo lại cây thư mục ghi được bên trong tmpfs (source mount read-only).
# Phải chạy cùng container với lệnh php, vì tmpfs sống theo từng `docker run`.
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
    `# Cấu hình KHÔNG bí mật, phải khớp .env vì route nhóm theo tên miền:` \
    -e APP_URL=http://oohx.test -e FRONTPAGE_DOMAIN=oohx.test -e DASH_DOMAIN=dash.oohx.test \
    -e DB_CONNECTION=mysql -e DB_HOST="$DB_CONTAINER" -e DB_PORT=3306 \
    -e DB_DATABASE="$TEST_DB" -e DB_USERNAME="$TEST_USER" -e DB_PASSWORD="$TEST_PASS" \
    -e DB_URL= -e DB_SOCKET= \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
    -e MAIL_MAILER=array -e BROADCAST_CONNECTION=null \
    "$PHP_IMAGE" -c "$PRELUDE" php-runner "$@"
}

# ─── Ghi môi trường ───────────────────────────────────────────────────────────
step "Ghi thông tin môi trường"
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
# Lấy qua chính kết nối của ứng dụng: chắc chắn là server mà test sẽ dùng.
record mysql_version "$(dphp artisan tinker --execute='echo DB::selectOne("select version() as v")->v;' 2>/dev/null | tr -d '\r\n')"
record suite_filter  "${SUITE_FILTER:-<full suite>}"
cat "$OUT/environment.txt"

# Kết nối thực tế trước khi migrate — chứng minh không trỏ vào DB thật
step "Xác nhận kết nối thực tế"
dphp artisan tinker --execute='
  $c = config("database.default");
  echo "connection=$c host=", config("database.connections.$c.host"),
       " database=", config("database.connections.$c.database"), PHP_EOL;
' > "$OUT/effective-connection.txt" 2>&1
cat "$OUT/effective-connection.txt"
grep -q "host=$DB_CONTAINER" "$OUT/effective-connection.txt" \
  || fail "Kết nối thực tế không trỏ tới container test — dừng trước khi ghi."

# ─── (A) Bộ test ──────────────────────────────────────────────────────────────
step "(A) Chạy bộ test"
set +e
if [ -n "$SUITE_FILTER" ]; then
  dphp artisan test --filter="$SUITE_FILTER" --log-junit /evidence/phpunit-mysql.xml > "$OUT/phpunit-mysql.txt" 2>&1
else
  dphp artisan test --log-junit /evidence/phpunit-mysql.xml > "$OUT/phpunit-mysql.txt" 2>&1
fi
suite_rc=$?
set -e
echo "phpunit_exit=$suite_rc" >> "$OUT/exit-codes.txt"
tail -3 "$OUT/phpunit-mysql.txt"
# Suite có lỗi baseline đã biết ⇒ không dừng ở đây, nhưng phải có file kết quả.
[ -s "$OUT/phpunit-mysql.xml" ] || fail "Không sinh được JUnit — coi như lỗi hạ tầng."
{
  echo "junit_totals=$(grep -o 'tests="[0-9]*" assertions="[0-9]*" errors="[0-9]*" failures="[0-9]*"' "$OUT/phpunit-mysql.xml" | head -1)"
  echo "not_passed_names:"
  grep -oE '<testcase name="[^"]+"[^>]*>\s*<(failure|error)' -A0 "$OUT/phpunit-mysql.xml" 2>/dev/null | head -20
} >> "$OUT/summary.txt" 2>/dev/null || true

# ─── (B) Probe ────────────────────────────────────────────────────────────────
# Dùng CSDL riêng để thấy migration chạy từ đầu, không phụ thuộc schema do suite để lại.
step "(B) Probe ImpressionLog::create() trên CSDL mới"
PROBE_DB="oohx_probe"
docker exec "$DB_CONTAINER" mysql -u"$TEST_USER" -p"$TEST_PASS" \
  -e "DROP DATABASE IF EXISTS $PROBE_DB; CREATE DATABASE $PROBE_DB;" >/dev/null 2>&1 \
  || fail "Không tạo được CSDL probe."

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
[ "$migrate_rc" -eq 0 ] || { tail -20 "$OUT/migrate.txt"; fail "Migration lỗi — dừng."; }
grep -qi "Nothing to migrate" "$OUT/migrate.txt" && fail "CSDL probe không trắng — kết quả không đáng tin."
tail -2 "$OUT/migrate.txt"

set +e
dphp_probe artisan tinker --execute="require '/probe/probe.php';" > "$OUT/probe-impression.txt" 2>&1
probe_rc=$?
set -e
echo "probe_exit=$probe_rc" >> "$OUT/exit-codes.txt"
[ "$probe_rc" -eq 0 ] || { cat "$OUT/probe-impression.txt"; fail "Probe không chạy được — lỗi hạ tầng, không phải kết quả."; }
cat "$OUT/probe-impression.txt"

step "Xong"
echo "Evidence: $OUT"
cat "$OUT/exit-codes.txt"
