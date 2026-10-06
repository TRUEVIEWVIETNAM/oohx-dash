#!/bin/bash
# Thử hàm `kiem_conf` của oohx-sync-proxy.sh ngoài VPS.
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
for c in /api /cart /livewire /sitemap.xml /admin; do
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
for c in /explore /owners /products /map /bang-phi /quy-che-hoat-dong /_next /giai-quyet-tranh-chap; do
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

echo
echo "── tổng: $so_dung đúng, $so_sai sai ──"
rm -rf "$D" "$TMP"
[ "$so_sai" -eq 0 ]
