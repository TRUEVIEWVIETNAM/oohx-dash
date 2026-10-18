#!/bin/bash
# Thử khối "tự nạp lại" của deploy.sh trong một repo git thật dựng tạm.
#
# Vì sao nó đáng một bộ thử: bash đọc script đang chạy TỪ ĐĨA, và bước 3 của
# deploy.sh ghi đè chính tệp đó. Ngày 18/10/2026 chuyện này làm một lần deploy
# kết thúc "success", in đủ mười một bước, mà bước mới thêm thì không chạy —
# khóa MapTiler không tới production và không lỗi nào được ghi.
#
# Khối sửa nó dùng `exec`, nên hai chiều đều phải đúng:
#   - deploy.sh vừa đổi   → PHẢI nạp lại, nếu không thì lỗi cũ quay lại
#   - deploy.sh không đổi → PHẢI KHÔNG nạp lại, nếu không là một vòng lặp
#     vô hạn trên production
#
# Lấy đúng khối ra khỏi script thật chứ không chép lại.

set -u

SRC="deploy.sh"
D="${TMPDIR:-/tmp}/thu-nap-lai-$$"
KHOI="$D/khoi.sh"

so_dung=0
so_sai=0

khang_dinh() {
    if [ "$2" = "$3" ]; then
        printf '  OK    %s\n' "$1"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %s\n        mong đợi [%s]\n        thực tế  [%s]\n' "$1" "$3" "$2"
        so_sai=$((so_sai + 1))
    fi
}

rm -rf "$D" && mkdir -p "$D"

# Khối: từ dòng ghi bản băm tới `fi` ở cột 0 đầu tiên sau nó.
sed -n '/^SHA_TRUOC_KHI_KEO=/,/^fi$/p' "$SRC" > "$KHOI"

[ -s "$KHOI" ] || { echo "KHONG TRICH DUOC KHOI TU $SRC"; exit 1; }
bash -n "$KHOI" || { echo "KHOI TRICH RA KHONG HOP LE"; exit 1; }

git init -q --bare "$D/goc.git"
git init -q -b main "$D/may-chu"
cd "$D/may-chu" || exit 1
git remote add origin "$D/goc.git"
git config user.email thu@oohx.test
git config user.name thu
git config core.autocrlf false

# Script thử: phần đầu tối thiểu + KHỐI THẬT + một dòng cho biết bản nào chạy.
dung() {
    {
        echo 'set -e'
        echo 'BRANCH=main'
        cat "$KHOI"
        echo "echo \"CHAY-TIEP: $1\""
    } > deploy.sh
    git add -A
    git commit -qm "$1"
}

dung v1
git push -q origin main
dung v2
git push -q origin main

# Máy chủ lùi về v1; origin vẫn ở v2 — đúng tình huống trước mỗi lần deploy.
git reset -q --hard HEAD~1

echo "Thử khối tự-nạp-lại trích từ $SRC"
echo

khang_dinh 'dựng sandbox: máy chủ đang ở bản cũ' \
    "$(grep -c 'CHAY-TIEP: v1' deploy.sh)" '1'

# ── 1. deploy.sh vừa đổi → phải nạp lại và chạy BẢN MỚI ─────────────────
RA=$(bash deploy.sh 2>&1)

khang_dinh 'có báo nạp lại' \
    "$(echo "$RA" | grep -c 'nạp lại bản mới')" '1'
khang_dinh 'chạy tiếp bằng BẢN MỚI, không phải bản đang chạy' \
    "$(echo "$RA" | grep -o 'CHAY-TIEP: v[0-9]' | head -1)" 'CHAY-TIEP: v2'
khang_dinh 'chỉ chạy tiếp MỘT lần' \
    "$(echo "$RA" | grep -c 'CHAY-TIEP:')" '1'

# ── 2. không đổi gì nữa → KHÔNG được nạp lại (vòng lặp vô hạn) ──────────
RA2=$(bash deploy.sh 2>&1)

khang_dinh 'lần sau không nạp lại' \
    "$(echo "$RA2" | grep -c 'nạp lại bản mới')" '0'
khang_dinh 'vẫn chạy tới cuối' \
    "$(echo "$RA2" | grep -c 'CHAY-TIEP: v2')" '1'

# ── 3. gọi thẳng với cờ → không nạp lại, kể cả khi remote có bản mới ────
dung v3
git push -q origin main
git reset -q --hard HEAD~1

RA3=$(bash deploy.sh --da-nap-lai 2>&1)
khang_dinh 'có cờ --da-nap-lai thì không nạp lại nữa' \
    "$(echo "$RA3" | grep -c 'nạp lại bản mới')" '0'

cd / || exit 1
rm -rf "$D"

echo
echo "  $so_dung đúng, $so_sai sai"
[ "$so_sai" -eq 0 ] || exit 1
