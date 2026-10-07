#!/bin/bash
# Thử oohx-sync-proxy.sh ở những phần chạy được ngoài VPS.
#
# Phần 1: hàm `kiem_conf` — danh sách trắng, thứ duy nhất ngăn một conf độc
#         hại được dán vào cấu hình web server.
# Phần 2: mẫu lọc chọn file — chỗ v1 dừng nhầm trên máy thật.
#
# Lấy đúng hàm đó ra khỏi script thật (từ dòng `kiem_conf() {` tới dòng `}` ở
# cột 0 đầu tiên sau nó) chứ không chép lại — chép lại thì thử một bản khác.

set -u
SRC="docs/deploy/nextjs-proxy/oohx-sync-proxy.sh"
TMP="${TMPDIR:-/tmp}/kiem-conf-$$.sh"

{
    echo 'NEXT_ADDR="127.0.0.1:3001"'
    sed -n '/^CAM=(/,/^)/p' "$SRC"
    sed -n '/^kiem_conf() {/,/^}/p' "$SRC"
} > "$TMP"

bash -n "$TMP" || { echo "HAM TRICH RA KHONG HOP LE"; exit 1; }
# shellcheck disable=SC1090
. "$TMP"

D="${TMPDIR:-/tmp}/conf-thu-$$"
mkdir -p "$D"

so_dung=0
so_sai=0

thu() {
    local ten="$1"
    local mong_doi="$2"
    local f="$D/$ten"
    local ket_qua
    if kiem_conf "$f" "$ten" >/dev/null 2>&1; then ket_qua=nhan; else ket_qua=tu-choi; fi

    if [ "$ket_qua" = "$mong_doi" ]; then
        printf '  OK    %-34s %s\n' "$ten" "$ket_qua"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %-34s duoc %s, can %s\n' "$ten" "$ket_qua" "$mong_doi"
        so_sai=$((so_sai + 1))
    fi
}

hop_le() {
    cat > "$D/$1" <<'EOF'
# ghi chu
extprocessor nextjs {
  type                    proxy
  address                 127.0.0.1:3001
  maxConns                100
  initTimeout             60
  retryTimeout            0
  respBuffer              0
}

context /_next {
  type                    proxy
  handler                 nextjs
  addDefaultCharset       off
}

context /explore {
  type                    proxy
  handler                 nextjs
  addDefaultCharset       off
}
EOF
}

echo "── conf thật của repo ──"
cp docs/deploy/nextjs-proxy/nextjs.conf "$D/conf-that"
thu conf-that nhan
kiem_conf "$D/conf-that" "conf-that" | sed 's/^/  /'

echo
echo "── phải NHẬN ──"
hop_le toi-thieu
thu toi-thieu nhan

echo
echo "── phải TỪ CHỐI ──"

# Trỏ ra ngoài máy: đây là thứ nguy hiểm nhất một conf độc hại làm được.
hop_le dia-chi-ngoai
sed -i 's|127.0.0.1:3001|10.0.0.9:3001|' "$D/dia-chi-ngoai"
thu dia-chi-ngoai tu-choi

# context / — nuốt /api/v1, /cart, sitemap.xml
hop_le context-goc
printf 'context / {\n  type proxy\n  handler nextjs\n}\n' >> "$D/context-goc"
thu context-goc tu-choi

# Nhóm cấm
for c in /api /cart /livewire /sitemap.xml /admin /logout; do
    ten="cam$(echo "$c" | tr -d '/.')"
    hop_le "$ten"
    printf 'context %s {\n  type proxy\n  handler nextjs\n}\n' "$c" >> "$D/$ten"
    thu "$ten" tu-choi
done

# Tien to cua duong CAM — cho sot cua ban dau. OpenLiteSpeed khop theo tien to,
# nen `/ap` nuot `/api/v1`.
for c in /ap /a /c /ca /l /s /sitemap /adm /boo; do
    ten="tiento$(echo "$c" | tr -d "/.")"
    hop_le "$ten"
    printf "context %s {
  type proxy
  handler nextjs
}
" "$c" >> "$D/$ten"
    thu "$ten" tu-choi
done

# Nam TRONG vung CAM nhung khong trung bang dung.
for c in /api/v1 /cart/abc /livewire/x /admin/y; do
    ten="trong$(echo "$c" | tr -d "/.")"
    hop_le "$ten"
    printf "context %s {
  type proxy
  handler nextjs
}
" "$c" >> "$D/$ten"
    thu "$ten" tu-choi
done

# Van phai NHAN: khong lien quan vung cam nao.
for c in /explore /owners /products /map /bang-phi /quy-che-hoat-dong /_next /giai-quyet-tranh-chap /phan-anh-to-chuc-xa-hoi /login /register; do
    ten="nhan$(echo "$c" | tr -d "/.")"
    hop_le "$ten"
    printf "context %s {
  type proxy
  handler nextjs
}
" "$c" >> "$D/$ten"
    thu "$ten" nhan
done

# Directive ngoài danh sách trắng
hop_le directive-la
printf 'context /x {\n  type proxy\n  handler nextjs\n  extraHeaders X: y\n}\n' >> "$D/directive-la"
thu directive-la tu-choi

# extprocessor tên khác / type khác
hop_le ten-khac
sed -i 's|extprocessor nextjs {|extprocessor khac {|' "$D/ten-khac"
thu ten-khac tu-choi

hop_le type-cgi
sed -i 's|  type                    proxy|  type                    cgi|' "$D/type-cgi"
thu type-cgi tu-choi

# Không có extprocessor
printf 'context /explore {\n  type proxy\n  handler nextjs\n}\n' > "$D/thieu-extproc"
thu thieu-extproc tu-choi

# Hai extprocessor
hop_le hai-extproc
printf 'extprocessor nextjs {\n  type proxy\n  address 127.0.0.1:3001\n}\n' >> "$D/hai-extproc"
thu hai-extproc tu-choi

# Không có context nào
printf 'extprocessor nextjs {\n  type proxy\n  address 127.0.0.1:3001\n}\n' > "$D/khong-context"
thu khong-context tu-choi

# Ngoặc lệch
hop_le ngoac-lech
printf 'context /y {\n  type proxy\n  handler nextjs\n' >> "$D/ngoac-lech"
thu ngoac-lech tu-choi

# Ký tự lạ trong đường dẫn + ..
hop_le ky-tu-la
printf 'context /a;b {\n  type proxy\n  handler nextjs\n}\n' >> "$D/ky-tu-la"
thu ky-tu-la tu-choi

hop_le hai-cham
printf 'context /a/../b {\n  type proxy\n  handler nextjs\n}\n' >> "$D/hai-cham"
thu hai-cham tu-choi

# handler khác
hop_le handler-khac
printf 'context /z {\n  type proxy\n  handler khac\n}\n' >> "$D/handler-khac"
thu handler-khac tu-choi

# File không tồn tại
thu khong-ton-tai tu-choi

# ════════════════════════════════════════════════════════════════════════════
# Phần 2: script CHỌN file nào để xét là "conf cạnh tranh"
# ════════════════════════════════════════════════════════════════════════════
#
# Đây là chỗ v1 dừng nhầm trên máy thật (06/10/2026). Nó tìm mọi file chứa địa
# chỉ Next.js và bắt được `detail/oohx.net.conf.txt` — một bản sao aaPanel giữ
# cho trình soạn thảo, OpenLiteSpeed không bao giờ nạp.
#
# Chuỗi nạp thật (httpd_config.conf dòng 256):
#     include /www/server/panel/vhost/openlitespeed/*.conf
# nên chỉ file kết thúc bằng `.conf` mới vào được. aaPanel giữ thêm
# `.conf.txt`, `.conf0`, `.conf0,v`, `.conf.backup`.
#
# Lấy mẫu lọc ĐỌC TỪ SCRIPT THẬT, không viết lại — viết lại là thử một bản khác.

echo
echo "── chọn file: chỉ xét thứ OpenLiteSpeed nạp ──"

MAU=$(grep -oE "\-\-include='[^']+'" "$SRC" | head -1 | sed "s/--include='//; s/'$//")

if [ -z "$MAU" ]; then
    echo "  SAI   script không dùng --include — nó sẽ xét cả bản sao của panel"
    so_sai=$((so_sai + 1))
else
    echo "  mẫu lọc đọc từ script: $MAU"

    CAY="${TMPDIR:-/tmp}/cay-ols-$"
    mkdir -p "$CAY/detail" "$CAY/proxy/oohx.net"

    # File OLS THẬT SỰ nạp.
    printf 'extprocessor nextjs {\n  address 127.0.0.1:3001\n}\ncontext /explore {\n}\n' \
        > "$CAY/proxy/oohx.net/nextjs.conf"

    # Những bản sao aaPanel giữ. Tất cả đều nhắc địa chỉ Next.js, và KHÔNG
    # bản nào được nạp.
    for ten in "proxy/oohx.net/nextjs.conf0" \
               "detail/oohx.net.conf.txt" \
               "detail/oohx.net.conf0" \
               "detail/oohx.net.conf0,v" \
               "detail/oohx.net.conf.backup"; do
        cp "$CAY/proxy/oohx.net/nextjs.conf" "$CAY/$ten"
    done

    THAY=$(grep -rl --include="$MAU" "127.0.0.1:3001" "$CAY" | sed "s|$CAY/||" | sort | tr '\n' ' ')
    CAN="proxy/oohx.net/nextjs.conf "

    if [ "$THAY" = "$CAN" ]; then
        printf '  OK    %-34s chi file OLS nap\n' "chon-file"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %-34s thay [%s], can [%s]\n' "chon-file" "$THAY" "$CAN"
        so_sai=$((so_sai + 1))
    fi

    rm -rf "$CAY"
fi


# ════════════════════════════════════════════════════════════════════════════
# Phần 3: `kiem_rewrite` — danh sách trắng cho luật viết lại URL
# ════════════════════════════════════════════════════════════════════════════
#
# Chặt hơn phần 1 có lý do: một `RewriteRule` sai nguy hiểm hơn một `context`
# sai. `context /api` ít nhất đọc bằng mắt còn thấy nó bắt gì. Còn
# `RewriteRule ^/(.*)$ http://nextjs/$1 [P]` trông gần giống luật trang chủ
# nhưng nuốt TOÀN BỘ site — kể cả `/api/v1` của đối tác — và khác biệt chỉ vài
# ký tự.

{
    echo 'NEXT_ADDR="127.0.0.1:3001"'
    sed -n '/^CAM=(/,/^)/p' "$SRC"
    sed -n '/^kiem_rewrite() {/,/^}/p' "$SRC"
    sed -n '/^duong_rewrite() {/,/^}/p' "$SRC"
} > "$TMP.rw"

bash -n "$TMP.rw" || { echo "HAM REWRITE TRICH RA KHONG HOP LE"; exit 1; }
# shellcheck disable=SC1090
. "$TMP.rw"

R="${TMPDIR:-/tmp}/rw-thu-$$"
mkdir -p "$R"

thu_rw() {
    local ten="$1"
    local mong_doi="$2"
    local f="$R/$ten"
    local ket_qua
    if kiem_rewrite "$f" "$ten" >/dev/null 2>&1; then ket_qua=nhan; else ket_qua=tu-choi; fi

    if [ "$ket_qua" = "$mong_doi" ]; then
        printf '  OK    %-38s %s\n' "$ten" "$ket_qua"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %-38s duoc %s, can %s\n' "$ten" "$ket_qua" "$mong_doi"
        so_sai=$((so_sai + 1))
    fi
}

luat() { printf '%s\n' "$2" > "$R/$1"; }

echo
echo "── luật rewrite: file thật của repo ──"
cp docs/deploy/nextjs-proxy/urlrewrite-nextjs.conf "$R/that"
thu_rw that nhan
kiem_rewrite "$R/that" "that" | sed 's/^/  /'

echo
echo "── phải NHẬN ──"
luat trang-chu       'RewriteRule ^/?$ http://nextjs/ [P]'
thu_rw trang-chu nhan
luat duong-co-dinh   'RewriteRule ^/gioi-thieu$ http://nextjs/gioi-thieu [P]'
thu_rw duong-co-dinh nhan

echo
echo "── phải TỪ CHỐI ──"

# Nuốt cả site. Đây là thứ phép kiểm này tồn tại để chặn.
luat nuot-tat-ca     'RewriteRule ^/(.*)$ http://nextjs/$1 [P]'
thu_rw nuot-tat-ca tu-choi
luat sao-cuoi        'RewriteRule ^/.*$ http://nextjs/ [P]'
thu_rw sao-cuoi tu-choi
luat cham-cong       'RewriteRule ^/.+$ http://nextjs/ [P]'
thu_rw cham-cong tu-choi

# Không neo -> khớp theo tiền tố, đúng vấn đề của `context /`.
luat khong-neo-dau   'RewriteRule /gi-do$ http://nextjs/ [P]'
thu_rw khong-neo-dau tu-choi
luat khong-neo-cuoi  'RewriteRule ^/gi-do http://nextjs/ [P]'
thu_rw khong-neo-cuoi tu-choi

# Nhóm, lựa chọn, lớp ký tự.
luat co-nhom         'RewriteRule ^/(a|b)$ http://nextjs/ [P]'
thu_rw co-nhom tu-choi
luat co-lop          'RewriteRule ^/[a-z]$ http://nextjs/ [P]'
thu_rw co-lop tu-choi

# Đích sai: trỏ ra ngoài máy, hoặc khai cổng lần thứ hai.
luat dich-ngoai      'RewriteRule ^/?$ http://10.0.0.9:3001/ [P]'
thu_rw dich-ngoai tu-choi
luat dich-ip         'RewriteRule ^/?$ http://127.0.0.1:3001/ [P]'
thu_rw dich-ip tu-choi
luat dich-ten-khac   'RewriteRule ^/?$ http://khac/ [P]'
thu_rw dich-ten-khac tu-choi

# Cờ sai.
luat co-sai          'RewriteRule ^/?$ http://nextjs/ [R=301]'
thu_rw co-sai tu-choi
luat thieu-co        'RewriteRule ^/?$ http://nextjs/'
thu_rw thieu-co tu-choi

# Directive ngoài danh sách trắng.
luat co-cond         'RewriteCond %{HTTP_HOST} ^oohx'
thu_rw co-cond tu-choi
luat co-base         'RewriteBase /'
thu_rw co-base tu-choi

# Vùng cấm.
luat vung-cam-api    'RewriteRule ^/api$ http://nextjs/api [P]'
thu_rw vung-cam-api tu-choi
luat vung-cam-cart   'RewriteRule ^/cart$ http://nextjs/cart [P]'
thu_rw vung-cam-cart tu-choi

# Rỗng / không tồn tại.
: > "$R/rong"
thu_rw rong tu-choi
thu_rw khong-co-file tu-choi

echo
echo "── duong_rewrite trích đúng đường ──"
luat ba-luat 'RewriteRule ^/?$ http://nextjs/ [P]'
printf 'RewriteRule ^/gioi-thieu$ http://nextjs/gioi-thieu [P]\n' >> "$R/ba-luat"
GOT=$(duong_rewrite "$R/ba-luat" | tr '\n' ' ')
if [ "$GOT" = "/ /gioi-thieu " ]; then
    printf '  OK    %-38s %s\n' "duong_rewrite" "$GOT"
    so_dung=$((so_dung + 1))
else
    printf '  SAI   %-38s duoc [%s], can [/ /gioi-thieu ]\n' "duong_rewrite" "$GOT"
    so_sai=$((so_sai + 1))
fi

rm -rf "$R" "$TMP.rw"
echo
echo "── tổng: $so_dung đúng, $so_sai sai ──"
rm -rf "$D" "$TMP"
[ "$so_sai" -eq 0 ]
