#!/bin/bash
# Thử `dat_env()` của deploy.sh — hàm ghi khóa MapTiler vào `.env` máy chủ.
#
# Vì sao hàm này đáng một bộ thử riêng: nó là chỗ duy nhất trong toàn dự án
# mà một script **ghi đè `.env` của production**. Cùng tệp đó giữ `APP_KEY`
# và `DB_PASSWORD`. Một `grep -v` viết lệch hay một chuyển hướng đặt sai và
# deploy xóa mất thứ không lấy lại được từ repo.
#
# Lấy đúng hàm ra khỏi script thật (từ `dat_env() {` tới `}` ở cột 0 đầu
# tiên) chứ không chép lại — chép lại thì thử một bản khác.

set -u

SRC="deploy.sh"
TMP="${TMPDIR:-/tmp}/dat-env-$$.sh"

sed -n '/^dat_env() {/,/^}/p' "$SRC" > "$TMP"

bash -n "$TMP" || { echo "HAM TRICH RA KHONG HOP LE"; exit 1; }
# shellcheck disable=SC1090
. "$TMP"

D="${TMPDIR:-/tmp}/dat-env-thu-$$"
rm -rf "$D" && mkdir -p "$D"
cd "$D" || exit 1

so_dung=0
so_sai=0

khang_dinh() {
    local ten="$1"
    local thuc_te="$2"
    local mong_doi="$3"

    if [ "$thuc_te" = "$mong_doi" ]; then
        printf '  OK    %s\n' "$ten"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %s\n        mong đợi [%s]\n        thực tế  [%s]\n' \
            "$ten" "$mong_doi" "$thuc_te"
        so_sai=$((so_sai + 1))
    fi
}

doc() {
    sed -n "s/^$1=//p" .env 2>/dev/null | head -1
}

echo "Thử dat_env() trích từ $SRC"
echo

printf 'APP_NAME=oohx\nDB_PASSWORD=bi-mat\n' > .env
dat_env MAPTILER_KEY abc123 >/dev/null
khang_dinh 'thêm khóa vào .env chưa có dòng nào' "$(doc MAPTILER_KEY)" 'abc123'

dat_env MAPTILER_KEY xyz789 >/dev/null
khang_dinh 'xoay sang khóa mới' "$(doc MAPTILER_KEY)" 'xyz789'
khang_dinh 'không để sót dòng khóa cũ' "$(grep -c '^MAPTILER_KEY=' .env)" '1'

printf 'APP_NAME=oohx\nMAPTILER_KEY="cu-co-nhay"\n' > .env
dat_env MAPTILER_KEY moi456 >/dev/null
khang_dinh 'thay được khóa cũ viết trong nháy kép' "$(doc MAPTILER_KEY)" 'moi456'

# Nhánh quan trọng nhất: deploy chạy khi secret chưa đặt thì biến rỗng, và
# một `.env` bị xóa khóa vì chuyện đó là một sự cố production.
printf 'APP_NAME=oohx\nMAPTILER_KEY=dang-chay\n' > .env
dat_env MAPTILER_KEY '' >/dev/null
khang_dinh 'giá trị rỗng KHÔNG được xóa khóa đang chạy' "$(doc MAPTILER_KEY)" 'dang-chay'

# Và nhánh giữ mọi thứ khác nguyên vẹn.
printf 'APP_NAME=oohx\nDB_PASSWORD=khong-duoc-mat\nMAPTILER_KEY=cu\nAPP_KEY=base64:xxx\n' > .env
dat_env MAPTILER_KEY moi >/dev/null
khang_dinh 'DB_PASSWORD còn nguyên' "$(doc DB_PASSWORD)" 'khong-duoc-mat'
khang_dinh 'APP_KEY còn nguyên' "$(doc APP_KEY)" 'base64:xxx'
khang_dinh 'APP_NAME còn nguyên' "$(doc APP_NAME)" 'oohx'
khang_dinh 'không bỏ sót tệp tạm' "$(ls .env.dang-ghi 2>/dev/null | wc -l | tr -d ' ')" '0'

rm -f .env
dat_env MAPTILER_KEY abc >/dev/null
khang_dinh 'không có .env thì KHÔNG tạo mới' "$(ls .env 2>/dev/null | wc -l | tr -d ' ')" '0'

cd / || exit 1
rm -rf "$D" "$TMP"

echo
echo "  $so_dung đúng, $so_sai sai"
[ "$so_sai" -eq 0 ] || exit 1
