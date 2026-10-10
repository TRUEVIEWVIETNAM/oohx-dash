#!/bin/bash
#
# Đưa production về commit của lần deploy trước. Chạy TAY trên VPS, không có
# workflow nào gọi nó.
#
#     bash rollback.sh
#
# ══ Vì sao file này nằm trong repo ══
#
# Trước 02/10/2026 nó chỉ sống trên máy chủ, giống `deploy.sh`. Một script có
# quyền thay toàn bộ mã nguồn production thì phải đọc được qua pull request và
# có lịch sử như mọi file khác. Lần ĐẦU phải chép tay sang máy chủ; từ đó bước
# 3 của `deploy.sh` (`git reset --hard`) giữ nó khớp với repo.
#
# ══ Hai lỗi đã sửa so với bản trên máy chủ ══
#
# 1. KHÔNG có `trap ... ERR`. Bản cũ có `set -e` nhưng không có trap, nên nếu
#    `composer install` hỏng thì script thoát giữa đường và để ứng dụng NGUYÊN
#    trong maintenance mode. Trang tắt vô thời hạn, đúng vào lúc đang chữa
#    cháy. `deploy.sh` đã có trap từ đầu; file này thì không.
#
# 2. KHÔNG build asset. Đúng lỗi mà `deploy.sh` vừa sửa ngày 02/10: layout
#    trang công khai dùng `@vite` và `public/build` bị .gitignore chặn, nên
#    rollback trả code về bản cũ nhưng để lại asset của bản MỚI. Trang vẫn 200,
#    vẫn có CSS — CSS của bản vừa bị gỡ.
#
# ══ Một điều CÓ CHỦ Ý, không phải thiếu sót ══
#
# Script này KHÔNG lùi migration. CLAUDE.md mục 6: "Không sửa dữ liệu lịch sử."
# `migrate:rollback` trên production là cách xoá cột và mất dữ liệu trong đó —
# không lấy lại được, và thường tệ hơn chính lỗi đang chữa.
#
# Hệ quả: mã nguồn cũ chạy trên lược đồ mới. Migration chỉ THÊM cột hoặc THÊM
# bảng thì không sao, vì mã cũ không biết tới chúng. Migration ĐỔI TÊN hoặc XOÁ
# cột thì mã cũ sẽ hỏng — nên bước 0 in ra đúng những migration đang đi trước
# mã nguồn, để người chạy quyết định thay vì đoán.

set -e
trap 'echo ""; echo "LỖI giữa rollback — đưa ứng dụng trở lại online."; php artisan up || true' ERR

APP_DIR="/www/wwwroot/dash.oohx.net"
PHP_BIN="php"
COMPOSER_BIN="composer"

cd "$APP_DIR"

if [ ! -f .previous_deploy_commit ]; then
  echo "Không có .previous_deploy_commit — chưa lần deploy nào ghi mốc để lùi về."
  echo "Muốn lùi về một commit cụ thể thì ghi nó vào file đó rồi chạy lại:"
  echo "    echo <sha> > .previous_deploy_commit"
  exit 1
fi

ROLLBACK_COMMIT=$(cat .previous_deploy_commit)
CURRENT_COMMIT=$(git rev-parse HEAD)

echo "=============================="
echo " Laravel Rollback Script Start"
echo "=============================="
echo "Đang ở  : $CURRENT_COMMIT"
echo "Lùi về  : $ROLLBACK_COMMIT"
echo ""
echo "[1/9] Fetch source và kiểm an toàn"
# Fetch TRƯỚC mọi phép kiểm, vì commit đích có thể chưa có trên máy chủ — và
# `git cat-file` trên một commit chưa fetch sẽ báo "thiếu file" dù file có.
#
# Cả khối này chạy TRƯỚC `artisan down`, có chủ ý: phép kiểm nào hỏng thì trang
# vẫn đang chạy bình thường, không ai thấy gì. Đưa trang vào maintenance rồi
# mới phát hiện đích không dùng được là tự tạo thêm một sự cố.
git fetch origin

if ! git rev-parse -q --verify "$ROLLBACK_COMMIT^{commit}" >/dev/null; then
  echo "DỪNG: không tìm thấy commit $ROLLBACK_COMMIT sau khi fetch."
  echo "       Nội dung .previous_deploy_commit có thể hỏng, hoặc commit đó"
  echo "       đã bị xoá khỏi origin (nhánh bị force-push hoặc bị gỡ)."
  exit 1
fi

# ══ Commit đích có còn chứa chính đường deploy không ══
#
# Đây là cái bẫy đã thật sự chực chờ ngày 03/10/2026, một ngày sau khi hai
# script này vào repo: `.previous_deploy_commit` đang trỏ vào `2f41dce`, tức
# `main` TRƯỚC lần merge đưa `deploy.sh` và `rollback.sh` vào git.
#
# `git reset --hard` xoá file được git theo dõi mà commit đích không có. Nên
# lùi về đó sẽ XOÁ cả hai script khỏi máy chủ, và lần deploy tự động kế tiếp
# chạy `bash deploy.sh` trên một file không tồn tại — deploy hỏng hoàn toàn,
# và `rollback.sh` cũng mất nên không chạy lại được để chữa. Lối ra duy nhất
# là vào tay gõ `git reset --hard origin/main`.
#
# Người chạy script này đang có sự cố. Họ sẽ không đọc commit đích trước khi
# gõ. Nên script phải tự biết.
MISSING=""
for f in deploy.sh rollback.sh; do
  git cat-file -e "$ROLLBACK_COMMIT:$f" 2>/dev/null || MISSING="$MISSING $f"
done

if [ -n "$MISSING" ]; then
  echo ""
  echo "DỪNG: commit $ROLLBACK_COMMIT không chứa:$MISSING"
  echo ""
  echo "       Lệnh git reset --hard xoá file git theo dõi mà commit đích không"
  echo "       có, nên lùi về đó sẽ XOÁ chính đường deploy khỏi máy chủ."
  echo "       Lần deploy tự động sau sẽ chạy 'bash deploy.sh' trên một file"
  echo "       không tồn tại và hỏng hoàn toàn."
  echo ""
  echo "       Muốn lùi về một bản CÒN hai script thì đặt lại mốc:"
  echo "           echo <sha> > .previous_deploy_commit"
  echo ""
  echo "       Thật sự cần lùi về đúng commit này thì cố ý vượt qua:"
  echo "           ROLLBACK_ALLOW_MISSING_SCRIPTS=1 bash rollback.sh"
  echo "       và chuẩn bị sẵn lệnh chữa:"
  echo "           git reset --hard origin/main"
  echo ""
  if [ "$ROLLBACK_ALLOW_MISSING_SCRIPTS" != "1" ]; then
    exit 1
  fi
  echo "       ROLLBACK_ALLOW_MISSING_SCRIPTS=1 — đi tiếp theo yêu cầu."
fi

# ══ Migration đang đi trước mã nguồn ══
#
# Liệt kê file migration mà bản đang chạy CÓ nhưng bản sắp lùi về KHÔNG có.
# Chúng vẫn ở trong CSDL sau rollback — script này không lùi migration, có chủ
# ý (xem đầu file). Danh sách này để người chạy biết mình đang để lại gì.
echo ""
echo "Migration đang đi trước mã nguồn:"
AHEAD=$(git diff --name-only --diff-filter=A "$ROLLBACK_COMMIT" "$CURRENT_COMMIT" -- database/migrations 2>/dev/null || true)
if [ -z "$AHEAD" ]; then
  echo "           Không có — lược đồ CSDL khớp với mã nguồn sắp lùi về."
else
  echo "$AHEAD" | sed 's/^/           /'
  echo ""
  echo "           Những migration trên ĐÃ CHẠY và sẽ Ở LẠI trong CSDL."
  echo "           Chỉ THÊM cột/bảng thì mã cũ chạy bình thường."
  echo "           Có ĐỔI TÊN hoặc XOÁ cột thì mã cũ sẽ hỏng — đọc chúng trước"
  echo "           khi tiếp tục, và cân nhắc sửa xuôi thay vì lùi."
fi

echo ""
echo "[2/9] Enable maintenance mode"
$PHP_BIN artisan down || true

echo ""
echo "[3/9] Reset to previous commit"
git reset --hard "$ROLLBACK_COMMIT"

echo ""
echo "[4/9] Install composer dependencies"
$COMPOSER_BIN install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo ""
echo "[5/9] Build frontend assets"
# KHÔNG có `|| true`, cùng lý do như trong `deploy.sh`: thà đỏ ngay và nhìn
# thấy, còn hơn phục vụ asset của bản vừa bị gỡ mà không ai phát hiện. `trap`
# ở đầu file vẫn đưa ứng dụng trở lại online.
if ! command -v npm >/dev/null 2>&1; then
    echo "LỖI: không có npm trên máy chủ."
    echo "      Không build thì public/build giữ asset của bản vừa bị gỡ."
    exit 1
fi
npm ci
npm run build

echo ""
echo "[6/9] Clear old caches"
$PHP_BIN artisan optimize:clear

# Cache component của Filament — ở đây nó CẦN hơn cả trong `deploy.sh`.
#
# Rollback lùi mã về commit trước, nhưng `bootstrap/cache` bị .gitignore chặn
# nên bảng component của bản MỚI sống nguyên. Bảng đó ghi thẳng tên class, và
# `HasComponents` nạp nó bằng `require` rồi tin hẳn — nên lùi mã mà giữ bảng cũ
# là 500 trên mọi trang panel, đúng thứ người ta vừa rollback để tránh.
#
# Không `|| true`: nếu không xoá được thì rollback xong vẫn vỡ, và biết ngay thì
# hơn.
$PHP_BIN artisan filament:optimize-clear

echo ""
echo "[7/9] Rebuild caches"
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache || true

# CÓ `|| true` ở đây, khác `deploy.sh` — và khác có chủ ý.
#
# Trong `deploy.sh` lệnh này hỏng thì deploy phải đỏ: ta đang thêm tính năng,
# dừng lại được. Trong rollback thì mục tiêu duy nhất là đưa mã CŨ lên sống;
# thiếu một bảng cache tăng tốc là chấp nhận được, còn bỏ dở rollback thì không.
$PHP_BIN artisan filament:optimize || true

echo ""
echo "[8/9] Fix permissions and restart workers"
# Thư mục và file đặt RIÊNG, không `chmod -R`. Lý do đầy đủ trong `deploy.sh`:
# 775 lật bit thực thi lên file git theo dõi và làm `git status` không bao giờ
# sạch.
find storage bootstrap/cache -type d -exec chmod 775 {} + 2>/dev/null || true
find storage bootstrap/cache -type f -exec chmod 664 {} + 2>/dev/null || true
$PHP_BIN artisan queue:restart || true

echo ""
echo "[9/9] Bring app back online"
$PHP_BIN artisan up || true

echo ""
echo "=============================="
echo " Laravel Rollback Success"
echo "=============================="
echo "HEAD   : $(git rev-parse HEAD)"
echo "Assets :"
ls -1 public/build/assets 2>/dev/null | sed 's/^/           /' || echo "           (không có)"
echo ""
echo "File .previous_deploy_commit KHÔNG bị ghi lại, nên chạy lại script này sẽ"
echo "lùi về đúng commit đó. Muốn tiến lên thì deploy bình thường."
