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
echo "[1/10] Enable maintenance mode"
$PHP_BIN artisan down || true

echo ""
echo "[2/10] Save current commit for rollback"
git rev-parse HEAD > .previous_deploy_commit || true
echo "Saved previous commit: $(cat .previous_deploy_commit || true)"

echo ""
echo "[3/10] Update source"
git fetch origin
git reset --hard origin/$BRANCH

echo ""
echo "[4/10] Install composer dependencies"
$COMPOSER_BIN install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo ""
echo "[5/10] Build frontend assets"
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
echo "[6/10] Clear old caches"
# TRƯỚC migrate, không phải sau.
#
# Bản cũ migrate trước rồi mới xoá cache, nên migration đọc config đã cache của
# lần deploy TRƯỚC. Một migration dựa vào config mới sẽ lặng lẽ dùng giá trị cũ.
$PHP_BIN artisan optimize:clear

echo ""
echo "[7/10] Run migrations"
$PHP_BIN artisan migrate --force

echo ""
echo "[8/10] Rebuild caches"
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache || true

echo ""
echo "[9/10] Fix permissions and restart workers"
# Thư mục và file đặt RIÊNG, không `chmod -R` cả cây.
#
# Bản cũ chạy `chmod -R 775 storage bootstrap/cache`. 775 lật bit thực thi lên
# 11 file `.gitignore` được git theo dõi dưới hai thư mục đó, và git theo dõi
# bit thực thi — nên chúng thành "modified" vĩnh viễn: bước 3 trả mode về 644,
# bước 9 lật lại 755, vòng lặp không bao giờ dừng.
#
# Hệ quả không phải trang hỏng, mà là `git status` trên production KHÔNG BAO
# GIỜ sạch — nên không còn cách nào phát hiện có ai sửa tay file nào trên máy
# chủ. Đo ngày 02/10/2026: đúng 11 file, đúng hai thư mục bị chmod.
#
# 664 cho file là đủ, và git không thấy nó khác 644: git chỉ lưu 100644 hoặc
# 100755, không lưu bit group.
#
# `|| true` vì nhiều file trong storage thuộc user `www` chứ không phải
# `deploy`, nên chmod báo "Operation not permitted" và đó là bình thường.
find storage bootstrap/cache -type d -exec chmod 775 {} + 2>/dev/null || true
find storage bootstrap/cache -type f -exec chmod 664 {} + 2>/dev/null || true
$PHP_BIN artisan queue:restart || true

echo ""
echo "Bring app back online"
$PHP_BIN artisan up || true

echo ""
echo "[10/10] Build app Next.js — chỉ khi service đã được dựng"
# Đặt ở CUỐI, sau khi Laravel đã online. Và có điều kiện.
#
# ══ Vì sao không nằm giữa, ở vị trí [5b] như bản đầu ══
#
# Bản đầu đặt nó giữa bước 5 và bước 6 với nhãn `[5b/9]`, rồi cho `exit 1` khi
# restart hỏng. Hai thứ đó cộng lại cho một trạng thái nửa vời TỆ HƠN lỗi nó
# định chặn: bước 3 đã thay TOÀN BỘ mã nguồn, nhưng bước xoá cache, migration
# và dựng lại cache chưa chạy. Code mới trên route cache cũ, và migration
# không chạy. Trang vẫn online nhờ `trap`, nên không ai thấy gì bất thường.
#
# Bản build Next không phụ thuộc bước nào của Laravel ngoài mã nguồn, nên chỗ
# đúng của nó là sau cùng. Hỏng ở đây thì Laravel đã deploy xong trọn vẹn và
# chỉ bản Next là cũ — vẫn đỏ để buộc sửa, nhưng không để lại nửa vời.
#
# `webapp/` là trang công khai trên Next.js (giai đoạn 6), chuyển từng đường
# dẫn một. Bỏ bước này khi service đã chạy là lỗi âm thầm: `git reset --hard` ở
# bước 3 thay mã nguồn Next.js, còn `.next` vẫn là bản build cũ — tức production
# phục vụ trang Next.js của commit TRƯỚC, im lặng. Đúng loại lỗi mà bước 5 vừa
# được thêm để chữa cho asset của Laravel.
#
# Điều kiện là sự tồn tại của unit systemd, không phải sự tồn tại của thư mục:
# thư mục luôn có (nó nằm trong repo), còn unit chỉ có khi ai đó đã cố ý bật.
if [ -f /etc/systemd/system/oohx-webapp.service ]; then
    if ! command -v npm >/dev/null 2>&1; then
        echo "LỖI: service oohx-webapp đã cài nhưng không có npm để build."
        exit 1
    fi

    ( cd webapp && npm ci && npm run build )

    # Restart SAU khi build xong, không trước: `next start` nạp `.next` lúc khởi
    # động, nên restart trước khi build là khởi động lại trên bản cũ rồi để bản
    # mới nằm đó không ai dùng.
    #
    # `sudo -n`: KHÔNG hỏi mật khẩu, thất bại ngay.
    #
    # Bản đầu viết `sudo systemctl restart ... || systemctl restart ... || { echo
    # cảnh báo; }` rồi in "đã build và restart" ở dòng sau. Ba chỗ sai:
    #
    # 1. `sudo` không có `-n` nên nó CHỜ mật khẩu trên một phiên không có TTY,
    #    và trả "Interactive authentication required".
    # 2. Nhánh dự phòng không sudo cũng hỏng, vì user `deploy` không đủ quyền.
    # 3. Dòng "đã build và restart" chạy VÔ ĐIỀU KIỆN sau đó, nên log in cả
    #    cảnh báo lẫn lời khẳng định ngược lại nó. Đo ngày 03/10/2026: deploy
    #    xanh, log nói đã restart, và service vẫn chạy bản build cũ.
    #
    # Nay thất bại thì DỪNG deploy. Lý do: một bản Next cũ phục vụ sau khi code
    # đã đổi là đúng loại lỗi âm thầm mà bước [5] vừa được thêm để chặn cho
    # asset của Laravel. `trap ... ERR` ở đầu file vẫn đưa ứng dụng trở lại
    # online, nên trang không bị tắt.
    if ! sudo -n systemctl restart oohx-webapp 2>/dev/null; then
        echo "LỖI: build xong nhưng KHÔNG restart được oohx-webapp."
        echo "     Service vẫn đang chạy bản build CŨ."
        echo ""
        echo "     Nguyên nhân thường gặp: user $(whoami) không được phép"
        echo "     restart unit đó mà không cần mật khẩu. Cấp đúng một quyền đó:"
        echo ""
        echo "       echo '$(whoami) ALL=(root) NOPASSWD: /usr/bin/systemctl restart oohx-webapp' \\"
        echo "         > /etc/sudoers.d/oohx-webapp-restart"
        echo "       chmod 440 /etc/sudoers.d/oohx-webapp-restart"
        echo "       visudo -c"
        echo ""
        echo "     Phạm vi hẹp có chủ ý: đúng MỘT lệnh, đúng MỘT unit."
        exit 1
    fi

    echo "Next.js : đã build và restart"
else
    echo "Bỏ qua: /etc/systemd/system/oohx-webapp.service chưa tồn tại."
    echo "        Trang công khai vẫn do Laravel phục vụ toàn bộ."
fi


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
