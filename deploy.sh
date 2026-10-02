#!/bin/bash
#
# Script deploy chạy trên VPS, do .github/workflows/deploy.yml gọi qua SSH.
#
# ══ Vì sao file này nằm trong repo ══
#
# Trước 02/10/2026 nó chỉ sống trên máy chủ: không ai review được qua pull
# request, và sửa nó không để lại dấu vết trong git. Một script có quyền chạy
# migration trên production thì phải được đọc và có lịch sử như mọi file khác.
#
# Từ nay `git reset --hard` ở bước [3] sẽ giữ bản trên máy chủ khớp với repo.
# Lần ĐẦU phải sao chép tay (xem README của repo), vì thay nội dung một script
# bash đang chạy giữa chừng là chuyện không nên làm.
#
# ══ Ba lỗi đã sửa so với bản cũ ══
#
# 1. KHÔNG build asset. Bản cũ chỉ `composer install` rồi `view:cache`, trong
#    khi layout trang công khai dùng `@vite` và `public/build` bị .gitignore
#    chặn. Hệ quả đo được ngày 02/10: production phục vụ CSS/JS từ 22/09 12:01,
#    và 4 dòng CSS của commit 889c8e2 (22/09 17:55) chưa bao giờ lên. Trang vẫn
#    200, vẫn có CSS — chỉ là CSS của mười ngày trước. Lỗi âm thầm theo đúng
#    kiểu tệ nhất.
#
# 2. `migrate` chạy TRƯỚC `optimize:clear`, nên migration đọc config đã cache
#    của lần deploy TRƯỚC. Nay xoá cache trước khi migrate.
#
# ══ Một lỗi CHƯA tìm ra nguyên nhân, ghi lại để không ai kết luận sớm ══
#
# Ngày 02/10 lúc 06:24, yêu cầu ĐẦU TIÊN tới oohx.net sau deploy timeout ở 100s
# và Cloudflare trả 524; yêu cầu thứ hai trả 200 trong 1,26s.
#
# Tôi đoán nguyên nhân là cache tổng hợp bị xoá rồi phải dựng lại trong request
# đầu, và đã thêm một lệnh `oohx:warm-cache` để chữa. **Đo ra thì sai**:
# `cache:clear` rồi gọi ngay cho 1,14s, gọi lần hai 1,05s — dựng lại toàn bộ
# cache tốn khoảng 90ms, không phải 100 giây. Lệnh đó đã được gỡ.
#
# Cũng đã loại: không có lời gọi HTTP/SSH nào trên đường trang chủ, và
# `storage/framework/cache/data` thuộc `www` và ghi được bình thường.
#
# Nguyên nhân vẫn chưa biết. Khả năng còn lại chưa loại được: opcache lạnh sau
# khi `git reset --hard` thay toàn bộ file PHP (vendor có Filament, rất lớn),
# PHP-FPM đang nạp lại, hoặc một sự cố nhất thời của Cloudflare. Cách đo: ở lần
# deploy tới, bấm đồng hồ ngay lần gọi đầu tiên TỪ NGOÀI vào.

set -e
trap 'php artisan up || true' ERR

APP_DIR="/www/wwwroot/dash.oohx.net"
BRANCH="main"
PHP_BIN="php"
COMPOSER_BIN="composer"

cd "$APP_DIR"

echo "=============================="
echo " Laravel Deploy Script Start"
echo "=============================="
echo "User   : $(whoami)"
echo "Path   : $(pwd)"
echo "Branch : $BRANCH"

echo ""
echo "[1/9] Enable maintenance mode"
$PHP_BIN artisan down || true

echo ""
echo "[2/9] Save current commit for rollback"
git rev-parse HEAD > .previous_deploy_commit || true
echo "Saved previous commit: $(cat .previous_deploy_commit || true)"

echo ""
echo "[3/9] Update source"
git fetch origin
git reset --hard origin/$BRANCH

echo ""
echo "[4/9] Install composer dependencies"
$COMPOSER_BIN install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo ""
echo "[5/9] Build frontend assets"
# KHÔNG có `|| true` ở đây, có chủ ý.
#
# Bản cũ không build gì cả và không ai biết, vì trang vẫn chạy với asset cũ.
# Thà deploy đỏ ngay và nhìn thấy, còn hơn mười ngày phục vụ CSS sai mà không
# ai phát hiện. `trap ... ERR` ở đầu file vẫn đưa ứng dụng trở lại online.
if ! command -v npm >/dev/null 2>&1; then
    echo "LỖI: không có npm trên máy chủ."
    echo "      Layout trang công khai dùng @vite và public/build bị .gitignore"
    echo "      chặn, nên không build là phục vụ asset cũ vô thời hạn."
    echo "      Cài Node rồi deploy lại."
    exit 1
fi
npm ci
npm run build

echo ""
echo "[6/9] Clear old caches"
# TRƯỚC migrate, không phải sau.
#
# Bản cũ migrate trước rồi mới xoá cache, nên migration đọc config đã cache của
# lần deploy TRƯỚC. Một migration dựa vào config mới sẽ lặng lẽ dùng giá trị cũ.
$PHP_BIN artisan optimize:clear

echo ""
echo "[7/9] Run migrations"
$PHP_BIN artisan migrate --force

echo ""
echo "[8/9] Rebuild caches"
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache || true

echo ""
echo "[9/9] Fix permissions and restart workers"
# `|| true` vì nhiều file trong storage thuộc user `www` chứ không phải
# `deploy`, nên chmod báo "Operation not permitted" và đó là bình thường.
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
$PHP_BIN artisan queue:restart || true

echo ""
echo "Bring app back online"
$PHP_BIN artisan up || true

echo ""
echo "=============================="
echo " Laravel Deploy Success"
echo "=============================="
# In CHUỖI BĂM, không đếm số file.
#
# Bản đầu tôi in `ls | wc -l` và nó vô dụng: nó đếm cả file cũ, nên "4 file"
# xuất hiện y nhau dù build có ghi hay không. Ngày 02/10 tôi đã dùng chính dòng
# đó làm bằng chứng build thành công, và nó không chứng minh được gì — phải đi
# `grep` vào file build mới biết sự thật.
#
# Tên file mang băm nội dung, nên hai lần build cho cùng băm nghĩa là cùng nội
# dung. Đó là con số nói được điều cần biết.
echo "Assets :"
ls -1 public/build/assets 2>/dev/null | sed 's/^/           /' || echo "           (không có)"
echo "Rollback: git reset --hard \$(cat .previous_deploy_commit) && bash deploy.sh"
