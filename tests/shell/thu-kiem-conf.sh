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

    # File rewrite do CHÍNH script cài. Nó có đuôi `.conf` và phần ghi chú của
    # nó nhắc `127.0.0.1:3001`, nên phép lọc theo đuôi tên KHÔNG loại được nó —
    # phải loại theo đường dẫn.
    #
    # Đây là lỗi v3 thật: nó chỉ loại trừ file proxy, nên từ lần chạy THỨ HAI
    # nó bắt được file rewrite của chính mình, đưa qua `kiem_conf` (phép kiểm
    # dành cho conf proxy) rồi dừng. Lần chạy tay đầu tiên qua được vì file
    # chưa tồn tại — loại lỗi chỉ lộ ra khi có người chạy lần thứ hai.
    mkdir -p "$CAY/proxy/oohx.net/urlrewrite"
    printf '# nextjs = 127.0.0.1:3001\nRewriteRule ^/?$ http://nextjs/ [P]\n' \
        > "$CAY/proxy/oohx.net/urlrewrite/nextjs.conf"

    THAY=$(grep -rl --include="$MAU" "127.0.0.1:3001" "$CAY" \
               | grep -v "/urlrewrite/" \
               | sed "s|$CAY/||" | sort | tr '\n' ' ')
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

# ═════════════════════════════════════════════════════════════════════════════
# PHẦN 4: bi_phu — đường nào đã được một luật nhận
#
# Hàm này canh phép kiểm 404 của canary: một đường chỉ chứng minh được điều gì
# khi nó CHƯA được khai. Nó phải phân biệt hai thứ trông giống nhau:
#
#   - chuỗi `/` đến từ `context /`  → luật bắt-tất-cả, phải bị bắt
#   - chuỗi `/` đến từ `^/?$`       → luật trang chủ, khớp đúng một đường
#
# Bản đầu của `bi_phu` gộp hai nguồn làm một và so cả hai theo tiền tố. Luật
# trang chủ khi đó phủ mọi đường, canary mất hết đường để thử, và nó sẽ đỏ ở
# MỌI lần deploy trong khi không có gì sai. Hai ca `trang-chu-*` dưới đây canh
# đúng chuyện đó.
# ═════════════════════════════════════════════════════════════════════════════
echo
echo "── PHẦN 4: bi_phu ──"

{
    sed -n '/^bi_phu() {/,/^}/p' "$SRC"
} > "$TMP.bp"

bash -n "$TMP.bp" || { echo "HAM bi_phu TRICH RA KHONG HOP LE"; exit 1; }
# shellcheck disable=SC1090
. "$TMP.bp"

# thu_bp <tên> <đường> <ctx cách nhau bởi dấu cách> <rw cách nhau bởi dấu cách> <phu|khong>
thu_bp() {
    local ten="$1" duong="$2" ctx="$3" rw="$4" mong_doi="$5" ket_qua
    local l_ctx l_rw
    l_ctx=$(printf '%s\n' $ctx)
    l_rw=$(printf '%s\n' $rw)

    if bi_phu "$duong" "$l_ctx" "$l_rw"; then ket_qua=phu; else ket_qua=khong; fi

    if [ "$ket_qua" = "$mong_doi" ]; then
        printf '  OK    %-38s %s\n' "$ten" "$ket_qua"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %-38s duoc %s, can %s\n' "$ten" "$ket_qua" "$mong_doi"
        so_sai=$((so_sai + 1))
    fi
}

CTX_THAT='/_next /explore /owners /products /map /quy-che-hoat-dong /chinh-sach-bao-mat /giai-quyet-tranh-chap /bang-phi /phan-anh-to-chuc-xa-hoi /agency /login /register'

# ── Cái bẫy: luật trang chủ KHÔNG được phủ đường khác ──
thu_bp trang-chu-khong-phu-duong-khac /gioi-thieu   "$CTX_THAT" '/' khong
thu_bp trang-chu-khong-phu-canary     /oohx-canary-404-khong-bao-gio-khai "$CTX_THAT" '/' khong
thu_bp trang-chu-van-phu-chinh-no     /            "$CTX_THAT" '/' phu

# ── context / thì PHẢI phủ tất cả ──
thu_bp catch-all-phu-duong-la         /gioi-thieu   '/' ''  phu
thu_bp catch-all-phu-canary           /oohx-canary-404-khong-bao-gio-khai '/' '' phu
thu_bp catch-all-phu-api              /api/v1/screens '/' '' phu

# ── Đường thử thật phải KHÔNG bị phủ bởi cấu hình đang chạy ──
thu_bp canary-khong-bi-phu            /oohx-canary-404-khong-bao-gio-khai "$CTX_THAT" '/' khong
thu_bp gioi-thieu-khong-bi-phu        /gioi-thieu   "$CTX_THAT" '/' khong
thu_bp lien-he-khong-bi-phu           /lien-he      "$CTX_THAT" '/' khong
thu_bp blog-khong-bi-phu              /blog/bai-1   "$CTX_THAT" '/' khong

# ── Context khớp theo tiền tố ──
thu_bp context-phu-chinh-no           /explore      '/explore' '' phu
thu_bp context-phu-duong-con          /explore/abc  '/explore' '' phu
thu_bp context-khong-phu-duong-khac   /owners       '/explore' '' khong

# ── Ngày /gioi-thieu thành trang thật thì nó tự rời nhóm thử ──
thu_bp duoc-khai-thi-bi-phu           /gioi-thieu   "/gioi-thieu $CTX_THAT" '/' phu

# ── Danh sách rỗng: không gì phủ gì ──
thu_bp ctx-rong-rw-rong               /gioi-thieu   '' '' khong
thu_bp chi-co-rw-khop-dung            /gioi-thieu   '' '/gioi-thieu' phu
thu_bp chi-co-rw-khong-khop-tien-to   /gioi-thieu/x '' '/gioi-thieu' khong

rm -f "$TMP.bp"

# ═════════════════════════════════════════════════════════════════════════════
# PHẦN 5: canary — chạy thật logic, thay phần mạng bằng bản giả
#
# `canary` là thứ duy nhất đứng giữa một conf sai và production. `bi_phu` ở
# phần 4 chỉ là một mảnh của nó; phần này chạy chính hàm `canary`, với `ma` và
# `la_next` thay bằng bản giả trả theo bảng. Nhờ vậy kiểm được đúng thứ không
# kiểm được bằng mắt: canary có ĐỎ khi cần đỏ, và có XANH khi mọi thứ đúng.
#
# Một canary luôn xanh vô dụng hơn không có canary — nó tạo cảm giác đã kiểm.
# Nên nửa số ca dưới đây canh nó ĐỎ.
# ═════════════════════════════════════════════════════════════════════════════
echo
echo "── PHẦN 5: canary ──"

{
    sed -n '/^bi_phu() {/,/^}/p' "$SRC"
    sed -n '/^canary() {/,/^}/p' "$SRC"
} > "$TMP.cn"

bash -n "$TMP.cn" || { echo "HAM canary TRICH RA KHONG HOP LE"; exit 1; }

C="${TMPDIR:-/tmp}/cn-thu-$$"
mkdir -p "$C"

# Conf giả: ba context và một luật rewrite trang chủ — hình dạng thật thu nhỏ.
cat > "$C/proxy.conf" <<'CONF'
extprocessor nextjs {
  type                    proxy
  address                 127.0.0.1:3001
}
context /_next {
  type                    proxy
  handler                 nextjs
}
context /explore {
  type                    proxy
  handler                 nextjs
}
context /agency {
  type                    proxy
  handler                 nextjs
}
CONF
printf 'RewriteRule ^/?$ http://nextjs/ [P]\n' > "$C/rewrite.conf"

# `duong_rewrite` thật, không giả: nó là phần rút đường từ luật, và nếu nó sai
# thì cả hai mặt của canary sai theo. Phần 3 đã kiểm nó riêng.
# shellcheck disable=SC1090
. <(sed -n '/^duong_rewrite() {/,/^}/p' "$SRC")

# thu_cn <tên> <xanh|do> — chạy canary với `ma`/`la_next` đang định nghĩa
thu_cn() {
    local ten="$1" mong_doi="$2" ket_qua
    (
        PROXY_CONF="$C/proxy.conf"
        REWRITE_CONF="$C/rewrite.conf"
        # shellcheck disable=SC1090
        . "$TMP.cn"
        canary >/dev/null 2>&1
    ) && ket_qua=xanh || ket_qua=do

    if [ "$ket_qua" = "$mong_doi" ]; then
        printf '  OK    %-38s %s\n' "$ten" "$ket_qua"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %-38s duoc %s, can %s\n' "$ten" "$ket_qua" "$mong_doi"
        so_sai=$((so_sai + 1))
    fi
}

# ── Bản giả "mọi thứ đúng" ──────────────────────────────────────────────────
#
# Laravel: /api/v2/stats, /sitemap.xml, /robots.txt → 200, không phải Next.
# /cart → 302. Đường đã khai → 200 từ Next. Đường chưa khai → 404 từ Laravel.
# /api/v1 sai đường → 404 từ Laravel.
ma() {
    case "$1" in
        /api/v2/stats|/sitemap.xml|/robots.txt) echo 200 ;;
        /cart)                                  echo 302 ;;
        /_next|/explore|/agency|/)              echo 200 ;;
        /api/v1/khong-ton-tai)                  echo 404 ;;
        *)                                      echo 404 ;;
    esac
}
la_next() {
    case "$1" in
        /_next|/explore|/agency|/) echo 1 ;;
        *)                         echo 0 ;;
    esac
}
thu_cn tat-ca-dung xanh

# ── Đường chết trả 302: đúng lỗi fallback cũ của Laravel ────────────────────
ma() {
    case "$1" in
        /api/v2/stats|/sitemap.xml|/robots.txt) echo 200 ;;
        /cart)                                  echo 302 ;;
        /_next|/explore|/agency|/)              echo 200 ;;
        /api/v1/khong-ton-tai)                  echo 404 ;;
        *)                                      echo 302 ;;
    esac
}
thu_cn duong-chet-tra-302 do

# ── Đường chết trả 200 từ Next: có luật bắt-tất-cả ─────────────────────────
ma() { echo 200; }
la_next() {
    case "$1" in
        /api/v2/stats|/sitemap.xml|/robots.txt) echo 0 ;;
        *)                                      echo 1 ;;
    esac
}
thu_cn duong-chet-ra-tu-next do

# ── /api/v1 sai đường ra từ Next: hợp đồng đối tác bị cắt ──────────────────
ma() {
    case "$1" in
        /api/v2/stats|/sitemap.xml|/robots.txt) echo 200 ;;
        /cart)                                  echo 302 ;;
        /_next|/explore|/agency|/)              echo 200 ;;
        *)                                      echo 404 ;;
    esac
}
la_next() {
    case "$1" in
        /_next|/explore|/agency|/|/api/v1/khong-ton-tai) echo 1 ;;
        *)                                               echo 0 ;;
    esac
}
thu_cn api-v1-ra-tu-next do

# ── context / được dán vào: canary mất hết đường để thử ────────────────────
#
# Ca này canh phép đếm `con`. Với `context /` thì MỌI đường thử đều "đã khai",
# nên vòng lặp bỏ qua hết và không phép kiểm 404 nào chạy. Không có phép đếm
# thì canary xanh trơn — đúng lúc nó phải kêu to nhất.
cat >> "$C/proxy.conf" <<'CONF'
context / {
  type                    proxy
  handler                 nextjs
}
CONF
ma() {
    case "$1" in
        /api/v2/stats|/sitemap.xml|/robots.txt) echo 200 ;;
        /cart)                                  echo 302 ;;
        /api/v1/khong-ton-tai)                  echo 404 ;;
        *)                                      echo 200 ;;
    esac
}
la_next() {
    case "$1" in
        /api/v2/stats|/sitemap.xml|/robots.txt|/api/v1/khong-ton-tai) echo 0 ;;
        *)                                                           echo 1 ;;
    esac
}
thu_cn catch-all-khong-lot-qua-duoc do

rm -rf "$C" "$TMP.cn"
echo
echo "── tổng: $so_dung đúng, $so_sai sai ──"
rm -rf "$D" "$TMP"
[ "$so_sai" -eq 0 ]
