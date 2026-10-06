#!/bin/bash
#
# Đồng bộ cấu hình proxy Next.js của OpenLiteSpeed từ repo sang máy chủ.
#
# Chạy AS ROOT, gọi qua sudo từ `deploy.sh`:
#
#     sudo -n /usr/local/sbin/oohx-sync-proxy
#
# ══════════════════════════════════════════════════════════════════════════════
# VÌ SAO LÀ MỘT SCRIPT ROOT, KHÔNG PHẢI MẤY DÒNG SUDOERS
# ══════════════════════════════════════════════════════════════════════════════
#
# Đo được ngày 06/10/2026 (lần chạy CI 37471490002): user mà CI đăng nhập
# KHÔNG ĐỌC ĐƯỢC thư mục cấu hình OpenLiteSpeed —
#
#     grep: /www/server/panel/vhost/openlitespeed/detail/oohx.net.conf: Permission denied
#
# Nên cách "cấp vài dòng NOPASSWD cho cp/rm" không chạy nổi: `deploy.sh` còn
# phải `cmp -s` bản đang chạy để biết có đổi gì không, phải đọc nó để sao lưu,
# và phải tìm xem có file nào khác đang khai trùng. Tất cả đều là ĐỌC, mà cấp
# quyền đọc cả thư mục cấu hình web server cho user deploy thì rộng hơn hẳn
# việc cần làm.
#
# Một script chạy as-root thì đọc/so/sao lưu/dán/nạp lại/lùi lại nằm gọn một
# chỗ, và sudoers chỉ cần MỘT dòng.
#
# ══════════════════════════════════════════════════════════════════════════════
# RANH GIỚI QUYỀN — ĐỌC TRƯỚC KHI CÀI
# ══════════════════════════════════════════════════════════════════════════════
#
# Script này đọc conf từ `$REPO_CONF`, và đường dẫn đó nằm trong cây mã nguồn
# mà user deploy GHI ĐƯỢC. Nghĩa là: ai ghi được repo (hoặc merge được vào
# `main`) thì ảnh hưởng được tới cấu hình web server.
#
# Đó không phải quyền mới hoàn toàn — người đó vốn đã thay được toàn bộ mã
# nguồn PHP qua `deploy.sh`. Nhưng cấu hình web server rộng hơn mã ứng dụng,
# nên script KHÔNG dán nguyên xi những gì nó đọc. Nó kiểm theo DANH SÁCH
# TRẮNG (hàm `kiem_conf`):
#
#   - chỉ chấp nhận đúng những directive của một proxy conf;
#   - `extprocessor` phải tên `nextjs`, `type proxy`, địa chỉ phải đúng
#     `127.0.0.1:3001` — không cho trỏ ra ngoài máy;
#   - `context` phải là đường dẫn chữ-số-gạch, KHÔNG được là `/`, và không
#     được trùng nhóm cấm (`/api`, `/my`, `/cart`, `/booking`, `/livewire`,
#     `sitemap.xml`, `robots.txt`);
#   - gặp bất cứ dòng nào ngoài danh sách thì DỪNG, không dán.
#
# Nên kịch bản xấu nhất một conf độc hại làm được là mở thêm/bớt một đường dẫn
# công khai trỏ vào chính tiến trình Next.js trên máy này. Không chạy được lệnh,
# không trỏ ra máy khác, không đọc được file khác.
#
# ══════════════════════════════════════════════════════════════════════════════
# BẢN ĐANG CHẠY KHÔNG TỰ CẬP NHẬT THEO REPO
# ══════════════════════════════════════════════════════════════════════════════
#
# Script này ở `/usr/local/sbin/` và thuộc root. Sửa file trong repo thì
# `/usr/local/sbin/oohx-sync-proxy` VẪN LÀ BẢN CŨ cho tới khi root cài lại.
#
# Đó là chủ ý — nếu nó tự cập nhật từ repo thì cái danh sách trắng ở trên vô
# nghĩa, vì ai sửa được repo sẽ sửa luôn phần kiểm. Nhưng nó cũng là một cái
# bẫy đã từng sập ở dự án này theo kiểu khác ("bash nạp script trước khi bước 3
# thay nó", một lượt deploy chậm hơn một nhịp). Nên có `VERSION` dưới đây, và
# `deploy.sh` so hai bản rồi CẢNH BÁO khi lệch.
#
# Cài / cài lại (chạy as root):
#
#     install -o root -g root -m 0755 \
#         /www/wwwroot/dash.oohx.net/docs/deploy/nextjs-proxy/oohx-sync-proxy.sh \
#         /usr/local/sbin/oohx-sync-proxy
#
set -e

VERSION=1

# In số phiên bản rồi thoát — `deploy.sh` dùng cái này để so với bản trong repo.
# Không cần quyền gì, nên để trước mọi phép kiểm khác.
if [ "${1:-}" = "--version" ]; then
    echo "$VERSION"
    exit 0
fi

# ══ Không nhận đối số nào khác ═══════════════════════════════════════════════
#
# Mọi đối số đều do bên gọi (user deploy) kiểm soát. Một script root nhận đường
# dẫn từ bên gọi là một script root dán file tuỳ ý vào chỗ tuỳ ý. Đường dẫn ở
# đây viết cứng, và `$#` phải bằng 0.
if [ "$#" -ne 0 ]; then
    echo "LỖI: script này không nhận đối số (nhận được: $*)." >&2
    echo "      Đường dẫn viết cứng có chủ ý — xem khối RANH GIỚI QUYỀN." >&2
    exit 64
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "LỖI: phải chạy as root. Gọi qua: sudo -n /usr/local/sbin/oohx-sync-proxy" >&2
    exit 77
fi

# ── Đường dẫn, tất cả viết cứng ─────────────────────────────────────────────
REPO_ROOT="/www/wwwroot/dash.oohx.net"
REPO_CONF="$REPO_ROOT/docs/deploy/nextjs-proxy/nextjs.conf"
OLS_VHOST_DIR="/www/server/panel/vhost/openlitespeed"
VHOST_CONF="$OLS_VHOST_DIR/detail/oohx.net.conf"
PROXY_DIR="$OLS_VHOST_DIR/proxy/oohx.net"
PROXY_CONF="$PROXY_DIR/nextjs.conf"
# Hậu tố KHÔNG kết thúc bằng `.conf`: thư mục được nạp bằng `*.conf`, nên một
# bản lưu tên `nextjs.conf.bak` vẫn bị nạp song song. Đây đúng là cái bẫy
# `deploy.sh` đã tránh một lần.
BAK_SUFFIX=".truoc-dong-bo"
LSWSCTRL="/usr/local/lsws/bin/lswsctrl"
NEXT_ADDR="127.0.0.1:3001"

# ── Danh sách đường dẫn KHÔNG BAO GIỜ được proxy ────────────────────────────
#
# Giống danh sách trong `nextjs.conf`, nhưng ở đây nó được THI HÀNH. Trong conf
# nó chỉ là ghi chú cho người đọc.
CAM=(
    # OpenLiteSpeed khớp theo tiền tố, nên một context `/` nuốt tất cả.
    "/"
    "/api"
    "/my"
    "/cart"
    "/booking"
    "/livewire"
    "/sitemap.xml"
    "/robots.txt"
    "/admin"
    "/publisher"
    "/login"
    "/register"
    "/logout"
)

# ═════════════════════════════════════════════════════════════════════════════
# Kiểm một file conf theo danh sách trắng.
#
# Trả 0 nếu file CHỈ chứa những directive của một proxy conf hợp lệ.
# Dùng hai chỗ: kiểm conf của repo trước khi dán, và nhận diện một conf lạ có
# phải "conf proxy thuần" hay không trước khi dám đổi tên nó.
# ═════════════════════════════════════════════════════════════════════════════
kiem_conf() {
    local f="$1"
    local nhan="$2"
    local so_dong=0 so_context=0 co_extprocessor=0 mo=0 dong=0
    local dong_txt khoa gia_tri

    if [ ! -f "$f" ]; then
        echo "   $nhan: không phải file thường." >&2
        return 1
    fi

    while IFS= read -r dong_txt || [ -n "$dong_txt" ]; do
        so_dong=$((so_dong + 1))

        # Bỏ khoảng trắng hai đầu.
        dong_txt="${dong_txt#"${dong_txt%%[![:space:]]*}"}"
        dong_txt="${dong_txt%"${dong_txt##*[![:space:]]}"}"

        # Dòng trống và ghi chú: bỏ qua.
        [ -z "$dong_txt" ] && continue
        case "$dong_txt" in '#'*) continue ;; esac

        if [ "$dong_txt" = "}" ]; then
            dong=$((dong + 1))
            continue
        fi

        khoa="${dong_txt%%[[:space:]]*}"
        gia_tri="${dong_txt#"$khoa"}"
        gia_tri="${gia_tri#"${gia_tri%%[![:space:]]*}"}"

        case "$khoa" in
            extprocessor)
                # `extprocessor nextjs {`
                if [ "$gia_tri" != "nextjs {" ]; then
                    echo "   $nhan dòng $so_dong: extprocessor phải là đúng 'nextjs {', nhận '$gia_tri'." >&2
                    return 1
                fi
                co_extprocessor=$((co_extprocessor + 1))
                mo=$((mo + 1))
                ;;

            context)
                # `context /duong-dan {`
                local duong="${gia_tri% \{}"
                if [ "$duong" = "$gia_tri" ]; then
                    echo "   $nhan dòng $so_dong: context thiếu dấu '{'." >&2
                    return 1
                fi

                case "$duong" in
                    /*) ;;
                    *) echo "   $nhan dòng $so_dong: context '$duong' phải bắt đầu bằng '/'." >&2; return 1 ;;
                esac

                # Chỉ chữ, số, gạch ngang, gạch dưới, gạch chéo và dấu chấm.
                # Chặn khoảng trắng, dấu nháy, `..`, ký tự lạ.
                if ! printf '%s' "$duong" | grep -qE '^/[A-Za-z0-9._/-]*$'; then
                    echo "   $nhan dòng $so_dong: context '$duong' có ký tự không cho phép." >&2
                    return 1
                fi

                case "$duong" in
                    *..*) echo "   $nhan dòng $so_dong: context '$duong' chứa '..'." >&2; return 1 ;;
                esac

                # ══ So HAI CHIỀU, không so bằng đúng ══
                #
                # OpenLiteSpeed khớp context theo TIỀN TỐ. Nên so bằng đúng là
                # không đủ, và bộ thử `scratchpad/thu-kiem-conf.sh` đã cho thấy
                # lỗ: `context /ap` không trùng dòng nào trong `CAM`, nhưng nó
                # là tiền tố của `/api` — nó nuốt `/api/v1`, tức hợp đồng với
                # đối tác.
                #
                # Hai chiều cần chặn:
                #
                #   - `c` là tiền tố của `duong`  → `/apixyz` nuốt gì thì không
                #     rõ, nhưng nó đã ở trong vùng `/api`.
                #   - `duong` là tiền tố của `c`  → `/ap` nuốt `/api`. Đây là
                #     chiều bản đầu bỏ sót.
                #
                # `/` xử lý riêng: mọi đường đều bắt đầu bằng `/`, nên nếu để
                # nó vào phép so tiền tố thì không đường nào qua được.
                if [ "$duong" = "/" ]; then
                    echo "   $nhan dòng $so_dong: context '/' khớp theo tiền tố nên nuốt TẤT CẢ." >&2
                    return 1
                fi

                local c
                for c in "${CAM[@]}"; do
                    [ "$c" = "/" ] && continue

                    case "$duong" in
                        "$c"*)
                            echo "   $nhan dòng $so_dong: context '$duong' nằm trong vùng CẤM '$c'." >&2
                            return 1
                            ;;
                    esac

                    case "$c" in
                        "$duong"*)
                            echo "   $nhan dòng $so_dong: context '$duong' là tiền tố của đường CẤM '$c' — nó sẽ nuốt đường đó." >&2
                            return 1
                            ;;
                    esac
                done

                # `/_next` là context asset của chính Next.js — hợp lệ.
                so_context=$((so_context + 1))
                mo=$((mo + 1))
                ;;

            type)
                [ "$gia_tri" = "proxy" ] || {
                    echo "   $nhan dòng $so_dong: type phải là 'proxy', nhận '$gia_tri'." >&2
                    return 1
                }
                ;;

            address)
                [ "$gia_tri" = "$NEXT_ADDR" ] || {
                    echo "   $nhan dòng $so_dong: address phải là '$NEXT_ADDR', nhận '$gia_tri'." >&2
                    echo "      Một địa chỉ khác nghĩa là trỏ lưu lượng công khai ra ngoài máy này." >&2
                    return 1
                }
                ;;

            handler)
                [ "$gia_tri" = "nextjs" ] || {
                    echo "   $nhan dòng $so_dong: handler phải là 'nextjs', nhận '$gia_tri'." >&2
                    return 1
                }
                ;;

            maxConns|initTimeout|retryTimeout|respBuffer)
                printf '%s' "$gia_tri" | grep -qE '^[0-9]+$' || {
                    echo "   $nhan dòng $so_dong: $khoa phải là số, nhận '$gia_tri'." >&2
                    return 1
                }
                ;;

            addDefaultCharset)
                case "$gia_tri" in
                    off|on) ;;
                    *) echo "   $nhan dòng $so_dong: addDefaultCharset phải off/on." >&2; return 1 ;;
                esac
                ;;

            *)
                echo "   $nhan dòng $so_dong: directive '$khoa' không nằm trong danh sách trắng." >&2
                return 1
                ;;
        esac
    done < "$f"

    if [ "$co_extprocessor" -ne 1 ]; then
        echo "   $nhan: phải có đúng MỘT khối extprocessor, đếm được $co_extprocessor." >&2
        return 1
    fi

    if [ "$so_context" -lt 1 ]; then
        echo "   $nhan: không khai context nào. Dán vào là vô nghĩa." >&2
        return 1
    fi

    if [ "$mo" -ne "$dong" ]; then
        echo "   $nhan: ngoặc không cân ($mo mở, $dong đóng)." >&2
        return 1
    fi

    echo "   $nhan: hợp lệ — 1 extprocessor, $so_context context."
    return 0
}

# ═════════════════════════════════════════════════════════════════════════════
# Canary: kiểm qua ĐÚNG virtual host, từ chính máy này.
#
# `--resolve` thay vì gọi `https://oohx.net` thường: như vậy header `Host` và
# SNI đều đúng `oohx.net` (nên khớp virtual host), mà kết nối không ra khỏi máy
# — không vòng qua Cloudflare, nên không thêm độ trễ, không thêm một điểm hỏng,
# và không tính vào hạn mức tần suất của người dùng thật.
#
# Đây là chỗ `OOHX_API_BASE=http://127.0.0.1/api/v2` từng sai: gọi thẳng
# `127.0.0.1` thì header Host là `127.0.0.1`, không khớp vhost, và OpenLiteSpeed
# trả 403 trước khi tới Laravel. `curl` tôn trọng `--resolve`; `fetch` của Node
# thì bỏ qua header Host do người gọi đặt, nên ở đây dùng curl.
# ═════════════════════════════════════════════════════════════════════════════
ma() {
    curl -s -k -o /dev/null -w '%{http_code}' --max-time 15 \
        --resolve "oohx.net:443:127.0.0.1" "https://oohx.net$1" 2>/dev/null || echo "000"
}

la_next() {
    curl -s -k --max-time 15 --resolve "oohx.net:443:127.0.0.1" \
        "https://oohx.net$1" 2>/dev/null | grep -c '/_next/static' || true
}

canary() {
    local loi=0 m

    # ── Những đường PHẢI do Laravel phục vụ ──
    for p in /api/v2/stats /sitemap.xml /robots.txt; do
        m=$(ma "$p")
        if [ "$m" != "200" ]; then
            echo "   LỖI canary: $p trả $m, cần 200." >&2
            loi=$((loi + 1))
        elif [ "$(la_next "$p")" != "0" ]; then
            echo "   LỖI canary: $p do Next.js phục vụ — nó phải là Laravel." >&2
            loi=$((loi + 1))
        fi
    done

    # `/cart` có thể chuyển hướng về /login khi chưa đăng nhập.
    m=$(ma /cart)
    case "$m" in
        200|302) ;;
        *) echo "   LỖI canary: /cart trả $m, cần 200 hoặc 302." >&2; loi=$((loi + 1)) ;;
    esac

    # ── Mỗi context đã khai PHẢI thật sự ra từ Next.js ──
    #
    # Chỉ kiểm mã 200 là không đủ: trước khi dán conf, `/bang-phi` cũng đã 200
    # — từ Laravel. Một canary như thế xanh cả khi conf không có tác dụng gì.
    local duong
    while read -r duong; do
        [ -z "$duong" ] && continue
        [ "$duong" = "/_next" ] && continue

        m=$(ma "$duong")
        if [ "$m" != "200" ]; then
            echo "   LỖI canary: $duong trả $m, cần 200." >&2
            loi=$((loi + 1))
            continue
        fi

        if [ "$(la_next "$duong")" = "0" ]; then
            echo "   LỖI canary: $duong ra 200 nhưng KHÔNG phải từ Next.js — context không có tác dụng." >&2
            loi=$((loi + 1))
        fi
    done < <(grep -E '^context ' "$PROXY_CONF" | awk '{print $2}')

    return "$loi"
}

# ═════════════════════════════════════════════════════════════════════════════
echo "── oohx-sync-proxy v$VERSION ──"

# ── 1. Kiểm conf của repo TRƯỚC khi chạm vào bất cứ gì ──────────────────────
echo "[1/7] Kiểm conf trong repo theo danh sách trắng"
if [ ! -f "$REPO_CONF" ]; then
    echo "LỖI: không có $REPO_CONF." >&2
    exit 1
fi
kiem_conf "$REPO_CONF" "repo" || {
    echo "LỖI: conf trong repo không qua được phép kiểm. KHÔNG dán gì." >&2
    exit 1
}

# ── 2. Không đổi gì thì dừng sớm ────────────────────────────────────────────
echo "[2/7] So với bản đang chạy"
if [ -f "$PROXY_CONF" ] && cmp -s "$REPO_CONF" "$PROXY_CONF"; then
    echo "   Không đổi: bản đang chạy khớp repo. Không nạp lại."
    exit 0
fi

# ── 3. Vhost có nạp thư mục này không ───────────────────────────────────────
#
# Làm TRƯỚC khi đổi tên conf cũ. Nếu include không có mà ta đã cách ly conf cũ
# thì kết quả là KHÔNG CÒN conf nào có tác dụng — `/explore` rơi về Laravel,
# tức tự tay làm hỏng thứ đang chạy.
echo "[3/7] Kiểm vhost có include thư mục proxy"
if [ ! -f "$VHOST_CONF" ]; then
    echo "LỖI: không có $VHOST_CONF. Không đoán tiếp." >&2
    exit 1
fi

if ! grep -q "proxy/oohx.net" "$VHOST_CONF"; then
    echo "LỖI: $VHOST_CONF KHÔNG include $PROXY_DIR." >&2
    echo "      Dán conf vào đó sẽ không có tác dụng gì." >&2
    echo "      Thêm dòng này vào $VHOST_CONF rồi chạy lại:" >&2
    echo "          include $PROXY_DIR/*.conf" >&2
    echo "      (Đó là file aaPanel sinh ra. Panel có thể ghi đè khi bạn đổi" >&2
    echo "       cài đặt site trong giao diện — nên kiểm lại dòng này sau mỗi" >&2
    echo "       lần làm việc đó.)" >&2
    exit 1
fi
echo "   có include."

# ── 3b. Canary có chạy được ở máy này không ─────────────────────────────────
#
# Làm TRƯỚC khi đổi gì, và đây không phải phép kiểm dư.
#
# Canary gọi `https://oohx.net` qua `--resolve` về 127.0.0.1. Nếu cách đó không
# chạy được trên máy này — tường lửa chỉ cho IP Cloudflare vào 443, cổng khác,
# TLS từ chối — thì MỌI đường trong canary trả `000`, canary coi là lỗi, và
# script sẽ LÙI LẠI một thay đổi hoàn toàn đúng. Tệ hơn cả không làm gì: nó
# sửa xong rồi tự phá, và log đọc như thể conf mới có vấn đề.
#
# `/sitemap.xml` do Laravel sinh và không phụ thuộc conf proxy, nên nó là phép
# đo nền đúng: 200 ở đây nghĩa là đường canary dùng được.
echo "[3b/7] Kiểm đường canary dùng được"
NEN=$(ma /sitemap.xml)
if [ "$NEN" != "200" ]; then
    echo "LỖI: canary không chạy được ở máy này — /sitemap.xml trả $NEN qua" >&2
    echo "      --resolve oohx.net:443:127.0.0.1, trong khi nó phải 200 bất kể" >&2
    echo "      conf proxy thế nào (Laravel sinh nó)." >&2
    echo "      KHÔNG đổi gì: không kiểm được thì không dám dán." >&2
    echo "      Kiểm tay: curl -ski --resolve oohx.net:443:127.0.0.1 https://oohx.net/sitemap.xml | head" >&2
    exit 1
fi
echo "   nền: /sitemap.xml → 200."

# ── 4. Tìm conf lạ đang khai cùng extprocessor ──────────────────────────────
#
# Hai file cùng khai `extprocessor nextjs` thì OpenLiteSpeed nạp song song và
# hành vi không đoán được. Phải cách ly, nhưng CHỈ cách ly file là "conf proxy
# thuần" — nếu ai đã dán context thẳng vào file vhost do panel sinh ra thì đổi
# tên file đó là làm sập cả site.
echo "[4/7] Tìm conf khác đang trỏ $NEXT_ADDR"
LA=()
while read -r f; do
    [ -z "$f" ] && continue
    [ "$f" = "$PROXY_CONF" ] && continue
    LA+=("$f")
done < <(grep -rl "$NEXT_ADDR" "$OLS_VHOST_DIR" /usr/local/lsws/conf 2>/dev/null || true)

if [ "${#LA[@]}" -eq 0 ]; then
    echo "   không có file nào khác."
else
    for f in "${LA[@]}"; do
        echo "   thấy: $f"
        if ! kiem_conf "$f" "$(basename "$f")" >/dev/null 2>&1; then
            echo "LỖI: $f không phải conf proxy thuần — nó có directive khác." >&2
            echo "      Rất có thể context đã được dán thẳng vào file vhost." >&2
            echo "      Đổi tên file đó sẽ làm sập site, nên script DỪNG ở đây." >&2
            echo "      Cần người xem tay: bỏ phần proxy khỏi $f, rồi chạy lại." >&2
            exit 1
        fi
        echo "      → conf proxy thuần, sẽ cách ly."
    done
fi

# ── 5. Dán, có đường lùi ────────────────────────────────────────────────────
#
# ══ `lui_lai` định nghĩa TRƯỚC mutation đầu tiên, và có `trap` ══
#
# Bản nháp đầu đặt `lui_lai` sau khối dán và chỉ gọi nó ở hai chỗ: lswsctrl
# thất bại, và canary đỏ. Chỗ hở là KHOẢNG GIỮA: `set -e` + một `cp` hay `mv`
# thất bại ở giữa khối dán thì script thoát mà KHÔNG lùi — để lại conf cũ đã
# cách ly và conf mới chưa dán xong, tức không còn conf nào có tác dụng.
# `/explore` rơi về Laravel và không ai được báo.
#
# Nên: cờ `DA_DOI` bật ngay trước mutation đầu tiên, và `trap ... ERR` lùi lại
# cho mọi lỗi sau đó. `lui_lai` tự tắt trap để nó không gọi lại chính mình khi
# một lệnh trong đó thất bại.
CO_BAN_CU=0
DA_CACH_LY=()
DA_DOI=0

lui_lai() {
    trap - ERR
    echo "── LÙI LẠI ──" >&2

    if [ "$CO_BAN_CU" -eq 1 ]; then
        mv -f "$PROXY_CONF$BAK_SUFFIX" "$PROXY_CONF" \
            && echo "   trả lại bản cũ của $PROXY_CONF" >&2 \
            || echo "   KHÔNG trả lại được bản cũ của $PROXY_CONF — cần xem tay." >&2
    else
        rm -f "$PROXY_CONF"
        echo "   xoá $PROXY_CONF (trước đó không có)" >&2
    fi

    local f
    for f in "${DA_CACH_LY[@]}"; do
        mv -f "$f$BAK_SUFFIX" "$f" \
            && echo "   trả lại $f" >&2 \
            || echo "   KHÔNG trả lại được $f — cần xem tay." >&2
    done

    "$LSWSCTRL" restart >/dev/null 2>&1 || true
    sleep 3
    echo "   đã nạp lại cấu hình cũ." >&2
}

echo "[5/7] Cài conf mới"
trap 'if [ "$DA_DOI" -eq 1 ]; then lui_lai; fi' ERR

mkdir -p "$PROXY_DIR"

DA_DOI=1

if [ -f "$PROXY_CONF" ]; then
    cp -p "$PROXY_CONF" "$PROXY_CONF$BAK_SUFFIX"
    CO_BAN_CU=1
fi

for f in "${LA[@]}"; do
    mv "$f" "$f$BAK_SUFFIX"
    DA_CACH_LY+=("$f")
    echo "   cách ly: $f → $f$BAK_SUFFIX"
done

cp "$REPO_CONF" "$PROXY_CONF"
chown root:root "$PROXY_CONF"
chmod 644 "$PROXY_CONF"
echo "   đã dán $PROXY_CONF ($(grep -cE '^context ' "$PROXY_CONF") context)."

# ── 6. Nạp lại ──────────────────────────────────────────────────────────────
echo "[6/7] Nạp lại OpenLiteSpeed"
if ! "$LSWSCTRL" restart; then
    echo "LỖI: lswsctrl restart thất bại." >&2
    lui_lai
    exit 1
fi

# `lswsctrl restart` là SIGUSR1 — nạp lại mềm, tiến trình cũ phục vụ xong request
# đang dở. Cần vài giây mới ăn cấu hình mới; canary ngay thì đo bản cũ.
sleep 4

# ── 7. Canary ───────────────────────────────────────────────────────────────
echo "[7/7] Canary"
if ! canary; then
    echo "LỖI: canary không qua." >&2
    lui_lai
    exit 1
fi

echo "   canary xanh."
echo "── xong ──"
