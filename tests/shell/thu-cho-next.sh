#!/bin/bash
# Thử `cho_next_het_qua_do()` của oohx-sync-proxy.sh.
#
# ══ Vì sao hàm này đáng một bộ thử ══
#
# Nó đứng chắn trước canary ở MỌI lượt deploy, và hỏng theo hai chiều đều tệ:
#
#   - chờ quá ít  → quay lại đúng lỗi 06/11/2026: canary gõ cửa Next 400ms sau
#     `systemctl restart`, bốn đường báo 503, deploy đỏ trong khi production
#     lên bình thường ngay sau đó;
#   - chờ quá nhiều → một conf chưa dán (`/login` ra Laravel 404) ngốn trọn
#     hạn chờ rồi báo một nguyên nhân sai, và mỗi lượt deploy chậm thêm một
#     phút.
#
# Nên phép thử phải đo cả hai chiều, và đo bằng chính hàm trong script thật —
# không chép lại, vì chép lại là thử một bản khác.
#
# `ma()` bị thay bằng một bản giả đọc kịch bản từ biến môi trường: hàm thật
# gọi curl tới production, thứ không có trong CI.

set -u

SRC="docs/deploy/nextjs-proxy/oohx-sync-proxy.sh"
D="${TMPDIR:-/tmp}/thu-cho-next-$$"
HAM="$D/ham.sh"

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

# Trích từ dòng khai hàm tới `}` ở cột 0 đầu tiên sau nó.
sed -n '/^cho_next_het_qua_do() {$/,/^}$/p' "$SRC" > "$HAM"

[ -s "$HAM" ] || { echo "KHONG TRICH DUOC HAM TU $SRC"; exit 1; }
bash -n "$HAM" || { echo "HAM TRICH RA KHONG HOP LE"; exit 1; }

# Hàm phải thật sự có vòng lặp và phép so hạn — một hàm `return 0` trần cũng
# làm mọi khẳng định dưới đây xanh.
grep -q 'CHO_NEXT_GIAY' "$HAM" || { echo "HAM TRICH RA KHONG DOC HAN CHO"; exit 1; }
grep -q 'sleep 1' "$HAM"       || { echo "HAM TRICH RA KHONG CHO LAN NAO"; exit 1; }

# ── Khung chạy: `ma()` giả đọc kịch bản, mỗi lần gọi lấy mã kế tiếp ─────────
#
# `date` cũng bị thay: hàm đo thời gian trôi bằng `date +%s`, và một bộ thử
# chờ thật sẽ mất hàng chục giây. Bản giả đếm mỗi lần hỏi giờ là một giây,
# nên hạn chờ được đo đúng theo logic mà không ai phải ngồi đợi.
#
# ══ Vì sao hai bộ đếm nằm trong TỆP chứ không trong biến ══
#
# Hàm thật gọi cả hai trong `$( )`: `m=$(ma /login)` và
# `troi=$(( $(date +%s) - bat_dau ))`. Command substitution chạy trong một
# **subshell**, nên một biến tăng bên trong sẽ mất khi subshell kết thúc —
# `ma` trả mãi phần tử đầu của kịch bản và đồng hồ đứng im ở 0. Bản đầu của
# bộ thử này dùng biến và treo vô hạn ngay lần chạy đầu tiên: kịch bản
# '503 503 200' không bao giờ tới được 200, mà hạn chờ cũng không bao giờ
# tới. Tệp thì sống qua subshell.
chay() {
    local kich_ban="$1" han="$2"
    local dem_luot="$D/luot" dem_giay="$D/giay"

    echo 0 > "$dem_luot"
    echo 0 > "$dem_giay"

    KICH_BAN="$kich_ban" CHO_NEXT_GIAY="$han" \
    DEM_LUOT="$dem_luot" DEM_GIAY="$dem_giay" bash -c '
        set -u

        # Mỗi lần `ma` được gọi thì lấy phần tử kế tiếp của kịch bản; hết thì
        # giữ nguyên phần tử cuối.
        ma() {
            local luot i=0 m
            luot=$(( $(cat "$DEM_LUOT") + 1 ))
            echo "$luot" > "$DEM_LUOT"

            for m in $KICH_BAN; do
                i=$((i + 1))
                [ "$i" -eq "$luot" ] && { echo "$m"; return; }
            done

            echo "$m"
        }

        # Thời gian trôi khi NGỦ, không phải mỗi lần hỏi giờ.
        #
        # Bản đầu tăng đồng hồ trong chính `date`, và ca "qua ngay thì không
        # in dòng chờ" đỏ: hàm hỏi giờ hai lần trong một vòng, nên lượt đầu
        # tiên đã thành "chờ 1 giây" dù chưa ngủ lần nào. Đó là một con số do
        # bộ thử bịa ra, không phải hành vi của hàm thật — với `date` thật,
        # hai lần hỏi trong cùng một vòng rơi vào cùng một giây.
        date() { cat "$DEM_GIAY"; }

        sleep() {
            local g
            g=$(( $(cat "$DEM_GIAY") + 1 ))
            echo "$g" > "$DEM_GIAY"
        }

        . '"$HAM"'

        if cho_next_het_qua_do; then echo "KET:0"; else echo "KET:1"; fi
    ' 2>&1
}

ket() { chay "$1" "$2" | grep -o 'KET:[01]' | tail -1; }
dong_bao() { chay "$1" "$2" | grep -c 'Next.js nghe lại sau' || true; }

echo "── Thử cho_next_het_qua_do ──"

# ── Chiều 1: đang quá độ thì phải CHỜ, không đỏ ngay ───────────────────────

khang_dinh 'nghe ngay từ lượt đầu → qua' \
    "$(ket '200' 60)" 'KET:0'

khang_dinh '503 vài nhịp rồi 200 → vẫn qua (đúng lỗi 06/11/2026)' \
    "$(ket '503 503 503 200' 60)" 'KET:0'

khang_dinh '000 (chưa nối được) rồi 200 → qua' \
    "$(ket '000 000 200' 60)" 'KET:0'

khang_dinh '502 rồi 504 rồi 200 → qua' \
    "$(ket '502 504 200' 60)" 'KET:0'

# ── Chiều 2: không quá độ thì THOÁT NGAY, để canary tự đánh giá ────────────

khang_dinh '404 (conf chưa dán) → thoát ngay, không ngốn hạn chờ' \
    "$(ket '404' 60)" 'KET:0'

khang_dinh '500 (Next lên nhưng lỗi) → thoát ngay, canary sẽ nói' \
    "$(ket '500' 60)" 'KET:0'

khang_dinh '302 → thoát ngay' \
    "$(ket '302' 60)" 'KET:0'

# ── Chiều 3: quá độ mãi không hết thì ĐỎ ───────────────────────────────────

khang_dinh '503 mãi → đỏ sau khi hết hạn' \
    "$(ket '503' 4)" 'KET:1'

khang_dinh '000 mãi → đỏ sau khi hết hạn' \
    "$(ket '000' 4)" 'KET:1'

# ── Chiều 4: im lặng khi không phải chờ ────────────────────────────────────
#
# Một dòng "Next.js nghe lại sau 0s" ở mọi lượt deploy là nhiễu; dòng đó chỉ
# đáng in khi thật sự có chờ, vì khi ấy nó là số đo người đọc log cần.

khang_dinh 'qua ngay thì KHÔNG in dòng chờ' \
    "$(dong_bao '200' 60)" '0'

khang_dinh 'có chờ thì CÓ in dòng chờ' \
    "$(dong_bao '503 503 200' 60)" '1'

# ── Chiều 5: hạn chờ đọc được từ môi trường ────────────────────────────────
#
# Nếu hàm viết cứng 60 thì ba khẳng định "đỏ sau khi hết hạn" ở trên vẫn xanh
# — chỉ là chúng chạy 60 vòng thay vì 4. Khẳng định này bắt đúng chuyện đó.

khang_dinh 'hạn 2 giây vẫn đỏ được (không viết cứng 60)' \
    "$(ket '503' 2)" 'KET:1'

echo ""
echo "Tổng: $so_dung đúng, $so_sai sai."

rm -rf "$D"

[ "$so_sai" -eq 0 ] || exit 1
