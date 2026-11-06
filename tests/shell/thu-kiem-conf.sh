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

# ══ Tên site: v10 đưa nó vào trong chính các hàm canary ══
#
# `ma`, `vi_tri` và `la_next` gọi `curl --resolve "$SITE:443:127.0.0.1"`. Các
# phần dưới đây source những hàm đó ra để chạy với một `curl` giả, nên biến
# này phải tồn tại trong shell của bộ thử — thiếu nó thì `set -u` làm tám ca
# đỏ với "unbound variable", tức một thông báo nói về biến chứ không phải về
# hành vi đang được thử.
#
# Viết thẳng ở đây, KHÔNG đọc từ bảng trong script: PHẦN 8 có một ca canh rằng
# bảng vẫn khai đúng site này là mặc định. Đọc từ bảng thì ca đó tự đúng với
# bất cứ giá trị nào bảng đưa ra, và nó thôi nói được điều gì.
SITE="oohx.net"

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

# ═════════════════════════════════════════════════════════════════════════════
# PHẦN 6: kiem_nen và phép thử lại khi không nối được (v7)
#
# Từ v7 canary chạy ở MỌI lượt deploy, kể cả lượt không đổi conf. Hai thứ đi
# kèm thay đổi đó, và cả hai đều là loại sai chỉ hiện ra trên máy thật:
#
#   - `ma`/`la_next` thử lại một lần khi KHÔNG NỐI ĐƯỢC. Thiếu nó thì một lần
#     curl hỏng tạm làm đỏ một deploy hoàn toàn đúng — và giờ mọi deploy đều
#     chạy canary, nên rủi ro đó không còn nhỏ như khi chỉ lượt đổi conf chạy.
#   - thử lại CHỈ cho `000`. Thử lại một câu trả lời thật (404, 500) là cách
#     làm yếu phép kiểm cho tới khi nó hết nghĩa.
# ═════════════════════════════════════════════════════════════════════════════
echo
echo "── PHẦN 6: kiem_nen + thử lại ──"

N="${TMPDIR:-/tmp}/nen-thu-$$"
mkdir -p "$N"

{
    sed -n '/^kiem_nen() {/,/^}/p' "$SRC"
    # Từ v9 `kiem_nen` gọi tiếp `cho_next_het_qua_do`. Thiếu hàm đó ở đây thì
    # mọi ca dưới đây đỏ với "command not found" — và đó là cách bộ thử này
    # báo rằng hợp đồng của `kiem_nen` vừa đổi, chứ không phải một lỗi vặt.
    # Hành vi riêng của hàm mới có bộ thử của nó: `tests/shell/thu-cho-next.sh`.
    sed -n '/^cho_next_het_qua_do() {/,/^}/p' "$SRC"
} > "$TMP.nn"
bash -n "$TMP.nn" || { echo "HAM kiem_nen TRICH RA KHONG HOP LE"; exit 1; }

grep -q 'cho_next_het_qua_do' "$TMP.nn" \
    || { echo "kiem_nen KHONG CON CHO NEXT — xem tests/shell/thu-cho-next.sh"; exit 1; }

thu_nen() {
    local ten="$1" mong_doi="$2" ket_qua
    (
        # shellcheck disable=SC1090
        . "$TMP.nn"
        kiem_nen >/dev/null 2>&1
    ) && ket_qua=xanh || ket_qua=do

    if [ "$ket_qua" = "$mong_doi" ]; then
        printf '  OK    %-38s %s\n' "$ten" "$ket_qua"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %-38s duoc %s, can %s\n' "$ten" "$ket_qua" "$mong_doi"
        so_sai=$((so_sai + 1))
    fi
}

ma() { echo 200; }
thu_nen nen-200-thi-do-duoc            xanh
ma() { echo 000; }
thu_nen nen-000-khong-do-duoc          do
ma() { echo 403; }
thu_nen nen-403-host-khong-khop-vhost  do
ma() { echo 404; }
thu_nen nen-404-sitemap-mat            do

# Hợp đồng mới của `kiem_nen` từ v9: nền xanh THÔI chưa đủ.
#
# `/sitemap.xml` do Laravel sinh, nên nó 200 cả khi Next.js chưa nghe lại sau
# `systemctl restart` — đúng tình huống 06/11/2026, nơi canary đọc 503 ở bốn
# đường rồi làm đỏ một deploy hoàn toàn đúng. Hạn chờ để 2 giây và `sleep` để
# thật: hai nhịp là đủ chứng minh, và một `sleep` giả sẽ làm đồng hồ đứng im
# rồi treo vòng lặp.
ma() { case "$1" in /sitemap.xml) echo 200 ;; *) echo 503 ;; esac; }
CHO_NEXT_GIAY=2 thu_nen nen-200-nhung-next-503-mai  do

ma() { case "$1" in /sitemap.xml) echo 200 ;; *) echo 404 ;; esac; }
thu_nen nen-200-next-404-thi-de-canary-noi         xanh

# ── Phép thử lại: chỉ `000`, không phải mọi mã ─────────────────────────────
#
# Thay `curl` bằng một bản giả đếm số lần gọi và trả theo kịch bản. Đây là chỗ
# duy nhất kiểm được "có thử lại đúng một lần" mà không cần mạng.
echo
echo "── thử lại chỉ khi không nối được ──"

{
    sed -n '/^ma() {/,/^}/p' "$SRC"
    sed -n '/^la_next() {/,/^}/p' "$SRC"
} > "$TMP.rt"
bash -n "$TMP.rt" || { echo "HAM ma/la_next TRICH RA KHONG HOP LE"; exit 1; }

thu_rt() {
    local ten="$1" mong_ma="$2" mong_lan="$3"
    local got_ma got_lan
    got_ma=$(
        # shellcheck disable=SC1090
        . "$TMP.rt"
        sleep() { :; }   # không chờ thật trong test
        ma /gi-do
    )
    # `wc -l`, không phải `cat`: bản đầu tôi đọc thẳng file và so "x" với "1".
    # Mã HTTP đã đúng cả năm ca, chỉ phép đếm của test là sai — đúng kiểu một
    # ca đỏ vì lý do không liên quan đến thứ nó định canh.
    got_lan=$(wc -l < "$N/dem" | tr -d ' ')
    : > "$N/dem"

    if [ "$got_ma" = "$mong_ma" ] && [ "$got_lan" = "$mong_lan" ]; then
        printf '  OK    %-38s ma=%s lan=%s\n' "$ten" "$got_ma" "$got_lan"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %-38s duoc ma=%s lan=%s, can ma=%s lan=%s\n' \
            "$ten" "$got_ma" "$got_lan" "$mong_ma" "$mong_lan"
        so_sai=$((so_sai + 1))
    fi
}

# `curl` giả: ghi một dòng vào $N/dem mỗi lần được gọi, in theo $KICH_BAN.
curl() {
    echo x >> "$N/dem"
    local lan
    lan=$(wc -l < "$N/dem" | tr -d ' ')
    echo "$KICH_BAN" | cut -d, -f"$lan"
}
: > "$N/dem"

KICH_BAN='200,200'; thu_rt ma-200-goi-dung-mot-lan        200 1
KICH_BAN='404,404'; thu_rt ma-404-la-cau-tra-loi-that     404 1
KICH_BAN='500,500'; thu_rt ma-500-cung-khong-thu-lai      500 1
KICH_BAN='000,200'; thu_rt ma-000-thu-lai-roi-duoc-200    200 2
KICH_BAN='000,000'; thu_rt ma-000-hai-lan-thi-chiu        000 2


# ── la_next cũng phải thử lại, và ở đây hậu quả nặng hơn ───────────────────
#
# Thân rỗng cho ra 0 lần khớp, và 0 nghĩa là "không phải từ Next.js" — tức một
# đường ĐÃ KHAI sẽ bị báo "luật không có tác dụng" trong khi luật hoàn toàn
# đúng. Ở nhánh đổi conf, script sẽ lùi lại một thay đổi đúng vì chuyện đó.
#
# Thân rỗng không phân biệt được với một trang thật không chứa
# `/_next/static`, nên thử lại khi rỗng là cách duy nhất tách hai trường hợp.
echo
echo "── la_next thử lại khi thân rỗng ──"

thu_rt_next() {
    local ten="$1" mong_dem="$2" mong_lan="$3"
    local got_dem got_lan
    got_dem=$(
        # shellcheck disable=SC1090
        . "$TMP.rt"
        sleep() { :; }
        la_next /gi-do
    )
    got_lan=$(wc -l < "$N/dem" | tr -d ' ')
    : > "$N/dem"

    if [ "$got_dem" = "$mong_dem" ] && [ "$got_lan" = "$mong_lan" ]; then
        printf '  OK    %-38s dem=%s lan=%s\n' "$ten" "$got_dem" "$got_lan"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %-38s duoc dem=%s lan=%s, can dem=%s lan=%s\n' \
            "$ten" "$got_dem" "$got_lan" "$mong_dem" "$mong_lan"
        so_sai=$((so_sai + 1))
    fi
}

# Trang CÓ dấu Next.js: lấy được ngay, không thử lại.
KICH_BAN='<link href=/_next/static/x.css>,<link href=/_next/static/x.css>'
thu_rt_next next-co-dau-goi-mot-lan 1 1

# Trang thật KHÔNG có dấu Next.js — đây là câu trả lời hợp lệ, không thử lại.
KICH_BAN='<html>trang Laravel</html>,<html>trang Laravel</html>'
thu_rt_next laravel-khong-thu-lai 0 1

# Thân rỗng lần đầu, lần hai lấy được: phải ra 1, không phải 0.
KICH_BAN=',<link href=/_next/static/x.css>'
thu_rt_next than-rong-thu-lai-roi-duoc 1 2
unset -f curl
rm -rf "$N" "$TMP.nn" "$TMP.rt"

# ── PHẦN 7: bảng đường đã khai tử (`CHUYEN_HUONG`) ─────────────────────────
#
# Thêm 22/10/2026, sau một deploy đỏ. Lát 7 của
# `docs/PLAN-GOP-EXPLORE-BAN-DO.md` đổi `/map` thành chuyển hướng 301, nhưng
# canary vẫn đòi mọi context đã khai trả 200 — nên nó làm đỏ một deploy hoàn
# toàn đúng: Laravel và Next lên xong, conf không đổi, chỉ bảng kỳ vọng là cũ.
#
# Hai thứ canh ở đây, và cả hai đều là thứ hỏng im lặng:
#
#   • bảng **có** `/map`, kèm đúng mã và đúng đích. Gõ nhầm đích thì canary
#     xanh với một chuyển hướng dẫn đi đâu cũng được.
#   • đường KHÔNG trong bảng thì hàm trả mã 1, để canary quay về luật 200.
#     Một hàm trả rỗng-mà-thành-công sẽ biến mọi context thành "được phép
#     chuyển hướng".

echo
echo "── PHẦN 7: bảng đường đã khai tử ──"

TMP4="${TMPDIR:-/tmp}/chuyen-huong-$.sh"
{
    sed -n '/^CHUYEN_HUONG=(/,/^)/p' "$SRC"
    sed -n '/^dich_chuyen_huong() {/,/^}/p' "$SRC"
} > "$TMP4"

bash -n "$TMP4" || { echo "HAM TRICH RA KHONG HOP LE"; exit 1; }
# shellcheck disable=SC1090
. "$TMP4"

thu_ch() {
    local duong="$1" mong="$2" duoc
    duoc=$(dich_chuyen_huong "$duong") || duoc="(khong-co)"

    if [ "$duoc" = "$mong" ]; then
        printf '  OK    %-34s %s\n' "$duong" "$duoc"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %-34s duoc "%s", can "%s"\n' "$duong" "$duoc" "$mong"
        so_sai=$((so_sai + 1))
    fi
}

thu_ch /map "301 /explore?view=map"

# Mọi context còn lại phải RƠI RA KHỎI bảng, nếu không canary thôi đòi 200 ở
# đó — tức mất đúng phép kiểm nó sinh ra để làm.
thu_ch /explore "(khong-co)"
thu_ch /owners "(khong-co)"
thu_ch /products "(khong-co)"
thu_ch / "(khong-co)"
thu_ch /mapp "(khong-co)"

rm -f "$TMP4"

# ═════════════════════════════════════════════════════════════════════════════
# PHẦN 8: bảng site, `--site`, và dòng `address` do script điền (v10)
#
# Ba thứ lát 3 thêm vào, và cả ba hỏng theo kiểu KHÔNG làm CI đỏ:
#
#   - một dòng trong bảng trùng cổng với dòng khác → hai site trỏ cùng một
#     tiến trình Next, và bước [4] của script coi conf của site kia là "conf
#     cạnh tranh" rồi DỪNG. Tức dựng site thứ hai lên là làm site thứ nhất
#     thôi deploy được, mà không ai chạm gì vào nó;
#   - `--site` nhận một tên ngoài bảng → nếu script tự dựng đường dẫn từ cái
#     tên nhận được thì một đối số trở thành một đường dẫn, tức mất đúng ranh
#     giới quyền mà cả script này sinh ra để giữ;
#   - `sed` không khớp dòng `address` → file vẫn qua mọi phép kiểm khác và
#     `extprocessor` ra máy chủ mà không có đích nào.
# ═════════════════════════════════════════════════════════════════════════════
echo
echo "── PHẦN 8: bảng site + --site + dòng address ──"

kd() {
    if [ "$2" = "$3" ]; then
        printf '  OK    %s\n' "$1"
        so_dung=$((so_dung + 1))
    else
        printf '  SAI   %s\n        mong đợi [%s]\n        thực tế  [%s]\n' "$1" "$3" "$2"
        so_sai=$((so_sai + 1))
    fi
}

# ── Bảng: hình dạng ─────────────────────────────────────────────────────────
BANG=$(sed -n '/^SITE_CHO_PHEP=(/,/^)/p' "$SRC" | grep -E '^[[:space:]]*"' | tr -d ' "')

kd 'bảng site đọc được, có ít nhất hai dòng' \
    "$([ "$(echo "$BANG" | wc -l | tr -d ' ')" -ge 2 ] && echo co || echo thieu)" 'co'

kd 'mọi dòng có đúng bốn cột' \
    "$(echo "$BANG" | awk -F'|' 'NF != 4' | wc -l | tr -d ' ')" '0'

kd 'tên site không trùng nhau' \
    "$(echo "$BANG" | cut -d'|' -f1 | sort | uniq -d | tr '\n' ' ' | sed 's/ *$//')" ''

# Hai site chung một cổng nghĩa là một tiến trình Next phục vụ cả hai — và khi
# đó bước [4] bắt được conf của site kia như một conf cạnh tranh rồi dừng.
kd 'địa chỉ Next không trùng nhau' \
    "$(echo "$BANG" | cut -d'|' -f3 | sort | uniq -d | tr '\n' ' ' | sed 's/ *$//')" ''

kd 'repo root không trùng nhau' \
    "$(echo "$BANG" | cut -d'|' -f2 | sort | uniq -d | tr '\n' ' ' | sed 's/ *$//')" ''

# Mọi địa chỉ phải là loopback. Một dòng trỏ ra máy khác là đưa lưu lượng công
# khai ra khỏi máy này, và từ v10 bảng này là chỗ DUY NHẤT quyết định điều đó
# — `kiem_conf` chỉ so với giá trị bảng đưa cho nó, nên một bảng sai làm chính
# phép kiểm đồng ý với cái sai.
kd 'mọi địa chỉ đều là 127.0.0.1' \
    "$(echo "$BANG" | cut -d'|' -f3 | grep -vc '^127\.0\.0\.1:[0-9][0-9]*$')" '0'

kd 'mọi repo root nằm ngay trong /www/wwwroot/' \
    "$(echo "$BANG" | cut -d'|' -f2 | grep -vc '^/www/wwwroot/[A-Za-z0-9._-]*$')" '0'

MAC_DINH=$(grep -m1 '^SITE_MAC_DINH=' "$SRC" | cut -d'"' -f2)

kd 'site mặc định có trong bảng' \
    "$(echo "$BANG" | cut -d'|' -f1 | grep -cx "$MAC_DINH")" '1'

# Mặc định phải là production: không có `--site` là cách `deploy.sh` của
# production gọi script từ bản đầu, và là dạng duy nhất dòng sudoers của nó
# cho phép.
kd 'site mặc định là production' "$MAC_DINH" 'oohx.net'

# 110 ca ở các phần trên source `kiem_conf` với `NEXT_ADDR` viết tay ở đầu
# file. Nếu bảng đổi cổng production mà dòng đó ở lại, chúng sẽ thử một địa
# chỉ không còn tồn tại — và tất cả vẫn xanh.
kd 'địa chỉ production trong bảng khớp giá trị bộ thử đang dùng' \
    "$(echo "$BANG" | grep "^$MAC_DINH|" | cut -d'|' -f3)" "$NEXT_ADDR"

# ── Không còn sự thật nào của một site nằm ngoài bảng ───────────────────────
#
# Chín chỗ v9 viết cứng: repo root, `detail/<site>.conf`, `proxy/<site>/`,
# cổng, và bốn lần `--resolve`. Sót một chỗ nghĩa là script sửa một site rồi
# đo một site khác, và cả hai kết quả đều vô nghĩa mà không gì báo.
kd 'ngoài bảng không còn dòng mã nào nhắc cổng/thư mục/service của một site' \
    "$(sed '/^SITE_CHO_PHEP=(/,/^)/d' "$SRC" | grep -vE '^[[:space:]]*#' \
        | grep -cE '127\.0\.0\.1:300|oohx-webapp|/www/wwwroot/')" '0'

kd 'canary không còn resolve theo tên viết cứng' \
    "$(grep -c 'resolve "oohx' "$SRC")" '0'

# Năm lời gọi curl: `ma` hai lần (có thử lại), `vi_tri` một, `la_next` hai.
# Sót một cái là đo site khác với site vừa sửa.
kd 'cả năm lời gọi curl đều resolve theo $SITE' \
    "$(grep -c 'resolve "\$SITE:443:127\.0\.0\.1"' "$SRC")" '5'

# ── `--site`: chỉ chọn dòng, không mang đường dẫn ──────────────────────────
#
# Chạy CHÍNH script thật. Phần đọc đối số nằm TRƯỚC phép kiểm root, nên những
# ca dưới đây đo được mà không cần quyền gì — mã 64 là "đối số không dùng
# được", và nó phải xảy ra trước mọi thứ khác.
ma_thoat() {
    bash "$SRC" "$@" >/dev/null 2>&1
    echo "$?"
}

kd '--version in đúng số phiên bản của repo' \
    "$(bash "$SRC" --version 2>/dev/null)" \
    "$(grep -m1 '^VERSION=' "$SRC" | cut -d= -f2)"

kd 'tên site ngoài bảng → thoát 64' "$(ma_thoat --site khong-co-trong-bang)" '64'
kd 'tên site là đường dẫn → thoát 64' "$(ma_thoat --site ../../etc)" '64'
kd 'tên site rỗng → thoát 64' "$(ma_thoat --site '')" '64'
kd 'cờ gõ sai → thoát 64' "$(ma_thoat --sit oohx.net)" '64'
kd 'một đối số lẻ → thoát 64' "$(ma_thoat --site)" '64'
kd 'ba đối số → thoát 64' "$(ma_thoat --site oohx.net thua)" '64'

# Site HỢP LỆ phải đi qua được phần đọc đối số. Không khẳng định mã thoát cụ
# thể: không root thì 77, mà root thì script đi tiếp rồi dừng ở bước [1] vì
# không có repo ở đường dẫn đó. Chỉ khẳng định nó KHÔNG bị chặn ở 64.
kd 'site trong bảng đi qua được phần đọc đối số' \
    "$([ "$(ma_thoat --site dev.oohx.net)" != "64" ] && echo qua || echo chan-64)" 'qua'

kd 'không đối số cũng đi qua được' \
    "$([ "$(ma_thoat)" != "64" ] && echo qua || echo chan-64)" 'qua'

# ── Dòng `address` do script điền ──────────────────────────────────────────
#
# Lấy đúng lệnh `sed` ra khỏi script thật, không chép lại — chép lại thì thử
# một phép thay khác.
LENH_SED=$(grep -m1 '^sed "s|' "$SRC")
CONF_REPO="docs/deploy/nextjs-proxy/nextjs.conf"

kd 'tìm được lệnh sed dựng conf trong script' \
    "$([ -n "$LENH_SED" ] && echo co || echo khong)" 'co'

dung_conf() {
    NEXT_ADDR="$1" REPO_CONF="$2" CONF_DAN="$3" bash -c "$LENH_SED"
}

# Với production thì `$NEXT_ADDR` bằng đúng chuỗi đã có trong file, nên bản
# dựng phải giống file repo TỪNG BYTE. Lệch một khoảng trắng thì `cmp` ở bước
# [2] báo "có đổi" ở mọi lượt deploy production, và mỗi lượt kéo theo một
# `lswsctrl restart` cùng một lần canary — tốn và ồn, trong khi không có gì đổi.
dung_conf "$NEXT_ADDR" "$CONF_REPO" "$D/dung-prod"
kd 'dựng cho production giống file repo TỪNG BYTE' \
    "$(cmp -s "$CONF_REPO" "$D/dung-prod" && echo giong || echo lech)" 'giong'

dung_conf "127.0.0.1:3002" "$CONF_REPO" "$D/dung-dev"
kd 'dựng cho dev đổi ĐÚNG MỘT dòng' \
    "$(diff "$CONF_REPO" "$D/dung-dev" | grep -c '^<')" '1'

kd 'dòng được đổi là dòng address' \
    "$(diff "$CONF_REPO" "$D/dung-dev" | grep '^>' | grep -c 'address.*127\.0\.0\.1:3002')" '1'

kd 'số context không đổi sau khi dựng' \
    "$(grep -cE '^context ' "$D/dung-dev")" "$(grep -cE '^context ' "$CONF_REPO")"

# ── Thay KHÔNG được phép bỏ qua phép kiểm ─────────────────────────────────
#
# `kiem_conf` chạy trên bản ĐÃ DỰNG. Hai ca dưới đây là bằng chứng rằng phép
# kiểm vẫn có tác dụng sau khi thay: nó phải nhận bản dựng cho địa chỉ đang
# đồng bộ, và phải từ chối một file mang địa chỉ khác.
NEXT_ADDR_CU="$NEXT_ADDR"
NEXT_ADDR="127.0.0.1:3002"

kd 'kiem_conf NHẬN bản dựng cho 3002 khi đang đồng bộ 3002' \
    "$(kiem_conf "$D/dung-dev" dev >/dev/null 2>&1 && echo nhan || echo tu-choi)" 'nhan'

kd 'kiem_conf TỪ CHỐI file repo thô khi đang đồng bộ 3002' \
    "$(kiem_conf "$CONF_REPO" tho >/dev/null 2>&1 && echo nhan || echo tu-choi)" 'tu-choi'

NEXT_ADDR="$NEXT_ADDR_CU"

# ── Đúng MỘT dòng `address` ────────────────────────────────────────────────
#
# Nhánh `address)` của `kiem_conf` chỉ nói "mỗi dòng address đều đúng" — nó im
# lặng khi KHÔNG CÓ dòng nào. Tới v9 đó là một lỗ nhỏ; từ v10 nó thành chỗ
# hỏng im lặng thật, vì dòng đó do `sed` điền: `sed` không khớp gì thì file vẫn
# qua mọi phép kiểm khác và `extprocessor` ra máy chủ mà không có đích nào.
hop_le khong-address
grep -v '^  address' "$D/khong-address" > "$D/khong-address.moi"
mv "$D/khong-address.moi" "$D/khong-address"
thu khong-address tu-choi

hop_le hai-address
sed -i 's|^  address .*|&\n  address                 127.0.0.1:3001|' "$D/hai-address"
thu hai-address tu-choi

# Và `sed` KHÔNG tự thêm dòng nào khi file không có dòng để thay — nên thứ tự
# "thay rồi kiểm" là thứ duy nhất bắt được ca này.
dung_conf "127.0.0.1:3002" "$D/khong-address" "$D/khong-address-dung"
kd 'sed không tự thêm dòng address khi file không có' \
    "$(grep -c '^  address' "$D/khong-address-dung")" '0'

echo
echo "── tổng: $so_dung đúng, $so_sai sai ──"
rm -rf "$D" "$TMP"
[ "$so_sai" -eq 0 ]
