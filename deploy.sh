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
# ── Phép đo đã bấm đồng hồ, 09–10/10/2026, ba lần deploy ──
#
# Bản chú thích cũ dặn "ở lần deploy tới, bấm đồng hồ ngay lần gọi đầu tiên TỪ
# NGOÀI vào". Đã làm, ba lần. Số đo, theo đúng thứ tự gọi:
#
#   deploy #59+#60 (09/10 ~15:01)
#     1. dash.oohx.net/login            → TIMEOUT ở 25s
#     2. dash.oohx.net/login (gọi lại)  → 404 trong 1,71s   ← 404 là ĐÚNG, /login do Next phục vụ
#
#   deploy #61 (09/10 ~15:55)
#     1. oohx.net/                      → 200 trong 1,70s
#     2. oohx.net/my                    → 302 trong 1,31s
#     3. dash.oohx.net/admin/login      → 200 trong 2,18s   ← KHÔNG timeout
#     4. dash.oohx.net/publisher/login  → 200 trong 1,13s
#
#   deploy #62 (10/10 ~02:38)
#     1. oohx.net/                      → 200 trong 0,41s
#     2. oohx.net/my                    → 302 trong 1,12s
#     3. dash.oohx.net/publisher/login  → TIMEOUT ở 45s
#     4. dash.oohx.net/admin/login      → 200 trong 1,84s
#     5. publisher/login ×3             → 1,83s · 1,23s · 1,26s
#
#   deploy #63 (10/10 ~03:40) — lần đầu chạy ĐÚNG quy trình dò bên dưới
#     1. invitations/khong-ton-tai      → 410 trong 1,85s  ← đường dò, không Filament
#     2. dash.oohx.net/publisher/login  → 200 trong 1,52s  ← KHÔNG timeout
#     3. dash.oohx.net/admin/login      → 200 trong 1,14s
#     Không treo lần nào, nên lần này KHÔNG phân biệt được (a) với (b).
#
# ── Phép đo này LOẠI một giả thuyết ──
#
# "Cả ứng dụng lạnh sau `git reset --hard`" là **sai**. Ở deploy #62,
# `oohx.net/my` là **cùng một ứng dụng Laravel** (routes/web.php khai cả hai
# tên miền trong một app) và nó trả 302 trong 1,12s — 44 giây TRƯỚC khi
# dash.oohx.net timeout. Ứng dụng đã ấm, PHP đã nạp, mà dash vẫn treo.
#
# ── Và nó CHIA giả thuyết còn lại thành hai, chưa phân biệt được ──
#
#  (a) Lần khởi động panel Filament đầu tiên. `/my` là Blade thuần — Filament
#      không bao giờ boot ở đó. Mọi đường treo đều là đường của panel Filament,
#      nơi Filament phải quét và nạp toàn bộ resource / page / widget (~169 tệp
#      dưới app/Filament, cộng cây class của vendor).
#  (b) Vòng đời tiến trình theo TỪNG vhost. OpenLiteSpeed có thể phục vụ
#      oohx.net và dash.oohx.net bằng hai vhost với hai pool LSPHP riêng, nên
#      "ấm" ở vhost này không có nghĩa gì với vhost kia.
#
# Không phân biệt được từ ngoài, vì tôi không có quyền vào VPS. Nhưng phân biệt
# được bằng MỘT lời gọi, và đây là lời gọi đó — một đường có thật trên dash,
# chạy Laravel và render Blade, mà KHÔNG boot panel Filament nào:
#
#   curl -o /dev/null -w '%{http_code} %{time_total}\n' \
#     https://dash.oohx.net/invitations/khong-ton-tai/accept
#
# Nó phải trả **410** (đo 10/10: 410 trong 1,19s). Gọi nó TRƯỚC mọi trang panel:
#   - 410 nhanh, rồi trang panel treo → (a), lỗi ở lần boot Filament đầu
#   - 410 cũng treo                   → (b), lỗi ở vòng đời vhost của dash
#
# Đường nhẹ hơn nếu chỉ cần biết Laravel có sống: `dash.oohx.net/api/v2/health`
# không tồn tại và trả 404 theo đúng định dạng lỗi của dự án
# (`{"error":"not_found",…}`) — tức router Laravel đã nhận. Nhưng nó không chạy
# qua trình biên dịch Blade, nên để phân biệt (a)/(b) thì dùng đường trên.
#
# Lưu ý thứ tự gọi: ở cả hai lần timeout, lời gọi NGAY SAU đó trả nhanh. Nên
# khi đo, cái phải bấm đồng hồ là lời gọi ĐẦU TIÊN tới dash — gọi một trang
# panel khác trước là tự trả tiền hộ rồi đo ra số sai.
#
# Hiện tượng là **không đều**: hai trong bốn lần deploy có treo (#59+#60, #62),
# hai lần không (#61, #63). Nên một lần đo nhanh KHÔNG chứng minh đã hết — và
# đó cũng là lý do không được kết luận rằng `filament:optimize` dưới đây đã
# chữa được, chỉ vì lần deploy sau nó chạy nhanh.
#
# ── Đã thêm `filament:optimize`, 10/10/2026 — và nó là MỘT NƯỚC ĐI, chưa phải lời giải ──
#
# `filament:optimize` (bước [8]) ghi sẵn bảng resource/page/widget của từng
# panel, cộng bảng icon; `filament:optimize-clear` (bước [6]) dọn bảng cũ. Nó
# nhắm vào đúng phần việc giả thuyết (a) nói đang tốn: quét 169 tệp dưới
# `app/Filament` ở lần boot panel đầu tiên.
#
# Nhưng nó KHÔNG chứng minh (a) đúng:
#  - nếu nguyên nhân là (b) — vòng đời tiến trình theo vhost — thì bảng cache
#    này không giúp gì, và hiện tượng sẽ còn;
#  - mà vì hiện tượng không đều, vài lần deploy nhanh liên tiếp cũng không nói
#    được là nhờ nó.
#
# Cách duy nhất còn lại vẫn là quy trình dò ở trên, ở **lần treo tiếp theo**.
# Nếu sau khi có cache mà vẫn treo, thì (a) bị loại và chỉ còn (b).
#
# ── Đo end-to-end qua HTTP, 10/10/2026 ──
#
# PHPUnit KHÔNG canh được đường này: `hasCachedComponents()` trả false khi
# `runningInConsole()`, nên mọi test đều quét tươi và không bao giờ đọc bảng
# cache. Nên phép đo phải dựng server thật — `php -S` trong docker, có
# `route:cache`, gọi mỗi đường hai lần:
#
#   đường               không cache     có cache      giảm
#   /admin/login  (1)      15,44s         9,39s        −39%
#   /publisher/.. (1)       5,30s         3,46s        −35%
#   /admin/login  (2)       5,33s         2,36s        −56%
#   /publisher/.. (2)       5,45s         2,68s        −51%
#
# Điều quan trọng nằm ở hai dòng CUỐI, không phải hai dòng đầu: **mọi** request
# panel đều trả phí quét, không chỉ request đầu sau deploy. Không có bảng cache
# thì lần gọi thứ hai vẫn 5,3s; có bảng thì còn 2,4s. Nên việc này không phải
# chỉ để chữa cái timeout — nó bớt việc cho từng lần bấm trong panel.
#
# Số TUYỆT ĐỐI ở trên không mang sang production được: `php -S` không có
# opcache, và mọi `require` đi qua bind mount Windows. Chỉ TỶ LỆ là tín hiệu.
# Cũng không chạy `config:cache` khi đo, có chủ ý: nó sẽ đóng băng cấu hình CSDL
# vào `bootstrap/cache/config.php` trong cây làm việc, đúng cái bẫy CLAUDE.md §7
# cảnh báo.
#
# Chi phí ghi cache (cùng môi trường): `filament:optimize` 158ms cho ba panel +
# 4s cho bảng icon; `filament:optimize-clear` 45ms + 87ms. Ba tệp sinh ra:
# admin 19,7KB · publisher 7,0KB · buyer 1,6KB.

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
echo "[1/11] Enable maintenance mode"
$PHP_BIN artisan down || true

echo ""
echo "[2/11] Save current commit for rollback"
git rev-parse HEAD > .previous_deploy_commit || true
echo "Saved previous commit: $(cat .previous_deploy_commit || true)"

echo ""
echo "[3/11] Update source"
git fetch origin
git reset --hard origin/$BRANCH

echo ""
echo "[4/11] Install composer dependencies"
$COMPOSER_BIN install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo ""
echo "[5/11] Build frontend assets"
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
echo "[6/11] Clear old caches"
# TRƯỚC migrate, không phải sau.
#
# Bản cũ migrate trước rồi mới xoá cache, nên migration đọc config đã cache của
# lần deploy TRƯỚC. Một migration dựa vào config mới sẽ lặng lẽ dùng giá trị cũ.
$PHP_BIN artisan optimize:clear

# `optimize:clear` KHÔNG xoá cache của Filament — nó không biết về nó.
#
# Cache component của Filament nằm ở `bootstrap/cache/filament/panels/{id}.php`
# (ba tệp: admin, publisher, buyer) cộng `bootstrap/cache/blade-icons.php`. Cả
# `bootstrap/cache` bị .gitignore chặn, nên `git reset --hard` ở bước [3]
# KHÔNG dọn chúng: không xoá tay thì bản cũ sống qua deploy.
#
# Hệ quả nếu để sống: tệp đó là một mảng PHP ghi thẳng TÊN CLASS
# (`livewireComponents`, `resources`, `pages`, `widgets`), và `HasComponents`
# nạp nó bằng `require` rồi tin hẳn. Một resource vừa đổi tên hoặc vừa xoá sẽ
# được nạp từ bảng cũ → 500 trên mọi trang của panel đó, không phải một lỗi nhẹ.
$PHP_BIN artisan filament:optimize-clear

echo ""
echo "[7/11] Run migrations"
$PHP_BIN artisan migrate --force

echo ""
echo "[8/11] Rebuild caches"
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache || true

# Filament: ghi sẵn bảng resource/page/widget của từng panel, cộng bảng icon.
#
# Phải SAU `config:cache`, vì đường ghi cache đọc `config('filament.cache_path')`.
#
# KHÔNG `|| true`. Nếu lệnh này hỏng thì bảng component vừa bị xoá ở bước [6]
# không được dựng lại, và panel quay về quét 169 tệp mỗi request — tức đúng cái
# chi phí ta đang muốn bỏ, nhưng lặng lẽ. Thà deploy đỏ ngay: `trap … ERR` ở đầu
# file vẫn đưa ứng dụng trở lại online.
#
# An toàn với artisan: `hasCachedComponents()` trả false khi
# `app()->runningInConsole()`, nên migration và mọi lệnh artisan LUÔN quét tươi,
# không bao giờ đọc bảng này. Chỉ request HTTP đọc nó.
#
# Quyền đọc: bước [9] ngay dưới chmod 775 cho thư mục và 664 cho tệp dưới
# `bootstrap/cache`, nên `www` đọc được — cùng cơ chế `config.php` và
# `routes-v7.php` đang dùng. Thứ tự [8] rồi [9] là bắt buộc, không đổi được.
$PHP_BIN artisan filament:optimize

echo ""
echo "[9/11] Fix permissions and restart workers"
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
echo "[10/11] Build app Next.js — chỉ khi service đã được dựng"
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
echo "[11/11] Đồng bộ cấu hình proxy OpenLiteSpeed"
# Gọi script root `oohx-sync-proxy`, không tự chép file.
#
# ══ Vì sao đổi cách làm ══
#
# Bản trước tự `sudo -n cp` và tự chạy canary, và nó KHÔNG BAO GIỜ chạy được:
# đo ngày 06/10/2026 (lần chạy CI 37471490002), user deploy không ĐỌC nổi thư
# mục cấu hình OpenLiteSpeed —
#
#     grep: /www/server/panel/vhost/openlitespeed/detail/oohx.net.conf: Permission denied
#
# Mà bước này cần đọc để `cmp -s` bản đang chạy, để sao lưu, và để tìm xem có
# file nào khác khai trùng `extprocessor`. Cấp quyền đọc cả thư mục cấu hình
# web server cho user deploy thì rộng hơn hẳn việc cần làm.
#
# Nên toàn bộ phần đó chuyển vào `/usr/local/sbin/oohx-sync-proxy` chạy
# as-root, và sudoers chỉ cần MỘT dòng. Bước này còn ba việc: kiểm script đã
# cài chưa, cảnh báo nếu bản đã cài lệch bản trong repo, và gọi nó.
#
# Phần kiểm conf và canary KHÔNG lặp lại ở đây — nó nằm trong script root, và
# `tests/shell/thu-kiem-conf.sh` kiểm nó trong CI (job "Script deploy").
#
# Chú thích này từng ghi "41 trường hợp". Bộ test lớn lên mà con số ở lại, nên
# nó nói sai suốt một thời gian — và một con số sai trong chú thích tệ hơn
# không có con số, vì người đọc tin nó. Giờ chỉ tên job: chỗ đó luôn nói đúng
# số hiện tại.
SYNC_BIN="/usr/local/sbin/oohx-sync-proxy"
SYNC_SRC="docs/deploy/nextjs-proxy/oohx-sync-proxy.sh"

# `-x` nói "không chạy được từ user này", KHÔNG nói "không tồn tại".
#
# Phân biệt đó quan trọng, và bản trước làm sai đúng chỗ này: câu cũ khẳng định
# `$PROXY_DIR` "không tồn tại", trong khi thư mục ĐANG CÓ — user deploy chỉ
# không duyệt qua được thư mục cha. Hai lượt deploy in câu đó, và nó dẫn cả
# việc chẩn đoán đi sai hướng cho tới khi một lệnh chạy as root cho thấy file
# vẫn nằm nguyên ở đó.
#
# Nên thông báo dưới đây nói đúng cái quan sát được, và để ngỏ khả năng kia.
if [ ! -x "$SYNC_BIN" ]; then
    echo "Bỏ qua đồng bộ: $SYNC_BIN không chạy được từ user $(whoami)."
    echo "       (chưa cài, hoặc đã cài mà user này không thấy — hai thứ khác nhau)"
    echo "       Repo đang khai $(grep -cE '^context ' docs/deploy/nextjs-proxy/nextjs.conf) context."
    echo ""
    echo "       Cài một lần, chạy AS ROOT:"
    echo ""
    echo "         install -o root -g root -m 0755 \\"
    echo "             $(pwd)/$SYNC_SRC \\"
    echo "             $SYNC_BIN"
    echo "         echo '$(whoami) ALL=(root) NOPASSWD: $SYNC_BIN \"\"' \\"
    echo "             > /etc/sudoers.d/oohx-sync-proxy"
    echo "         chmod 440 /etc/sudoers.d/oohx-sync-proxy"
    echo "         visudo -c"
    echo ""
    echo "       Hai dấu nháy rỗng ở cuối KHÔNG phải lỗi gõ: trong sudoers, một"
    echo "       lệnh không kèm đối số nghĩa là CHO PHÉP MỌI ĐỐI SỐ. Dấu \"\" là"
    echo "       cách viết 'đúng không đối số nào'."
    echo "       Chi tiết và ranh giới quyền: docs/deploy/nextjs-proxy/README.md"
else
    # ══ Bản đã cài KHÔNG tự cập nhật theo repo ══
    #
    # `$SYNC_BIN` thuộc root; sửa file trong repo không đổi nó. Đó là chủ ý —
    # nếu nó tự cập nhật thì danh sách trắng bên trong vô nghĩa, vì ai sửa được
    # repo sẽ sửa luôn phần kiểm.
    #
    # Nhưng đó cũng là một cái bẫy đã sập ở dự án này theo kiểu khác (bash nạp
    # script trước khi bước 3 thay nó — "một lượt deploy chậm hơn một nhịp").
    # Nên so phiên bản và nói ra, thay vì để nó im lặng chạy bản cũ.
    ban_cai=$("$SYNC_BIN" --version 2>/dev/null || echo "?")
    ban_repo=$(grep -m1 '^VERSION=' "$SYNC_SRC" | cut -d= -f2)

    if [ "$ban_cai" != "$ban_repo" ]; then
        echo "CẢNH BÁO: $SYNC_BIN là v$ban_cai, repo có v$ban_repo."
        echo "          Bản ĐANG CHẠY là v$ban_cai. Cài lại as root nếu muốn bản mới:"
        echo "            install -o root -g root -m 0755 $(pwd)/$SYNC_SRC $SYNC_BIN"
    fi

    if sudo -n "$SYNC_BIN"; then
        echo "Proxy  : đã đồng bộ (script root v$ban_cai)."
    else
        ma_loi=$?
        echo ""

        if [ "$ma_loi" -eq 1 ] && ! sudo -n -l "$SYNC_BIN" >/dev/null 2>&1; then
            echo "LỖI: chưa có quyền sudo cho $SYNC_BIN."
            echo ""
            echo "     Cấp một lần, chạy AS ROOT:"
            echo "       echo '$(whoami) ALL=(root) NOPASSWD: $SYNC_BIN \"\"' \\"
            echo "           > /etc/sudoers.d/oohx-sync-proxy"
            echo "       chmod 440 /etc/sudoers.d/oohx-sync-proxy"
            echo "       visudo -c"
            echo ""
            echo "     Hai dấu nháy rỗng ở cuối là 'đúng không đối số nào' —"
            echo "     thiếu chúng thì sudoers cho phép MỌI đối số."
        else
            echo "LỖI: $SYNC_BIN thoát với mã $ma_loi — xem log của nó ở trên."
            echo "     Script tự lùi lại khi canary đỏ, nên định tuyến đang là bản"
            echo "     TRƯỚC khi đồng bộ, không phải một trạng thái nửa vời."
        fi

        echo ""
        echo "     Laravel đã deploy xong và đang online; chỉ phần định tuyến"
        echo "     proxy là chưa đổi."
        exit 1
    fi
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
