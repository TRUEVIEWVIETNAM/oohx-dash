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

# v5 = v4 (bỏ qua thư mục rewrite) + bỏ /login,/register khỏi danh sách cấm.
#
# Hai thay đổi đó làm trên hai nhánh song song và CẢ HAI đặt VERSION=4. Git
# gộp im lặng vì hai dòng giống hệt nhau — nhưng số phiên bản khi ấy không còn
# phân biệt được hai bản khác nhau, tức phép cảnh báo lệch của deploy.sh mất
# tác dụng đúng lúc cần nhất. Nhảy lên 5 cho một bản gộp là rẻ hơn nhiều so
# với một con số nói dối.
VERSION=5

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

# Luật rewrite: thư mục RIÊNG, và nó được nạp từ TRONG khối `rewrite { }` của
# vhost (dòng 60 của detail/oohx.net.conf), khác hẳn thư mục proxy ở trên —
# thư mục kia được nạp ở cấp vhost (dòng 67). Hai chỗ nạp khác nhau nghĩa là
# nội dung hai file phải khác nhau: file proxy chứa `extprocessor`/`context`,
# file rewrite chứa directive rewrite TRẦN. Dán nhầm chỗ thì OpenLiteSpeed
# hỏng cả khối, không phải bỏ qua một dòng.
REWRITE_DIR="$PROXY_DIR/urlrewrite"
REWRITE_CONF="$REWRITE_DIR/nextjs.conf"
REPO_REWRITE="$REPO_ROOT/docs/deploy/nextjs-proxy/urlrewrite-nextjs.conf"
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

    # `/logout` ở lại danh sách cấm sau khi `/login` và `/register` rời nó
    # (07/10/2026). Không phải sót — ba đường này khác nhau về bản chất:
    #
    #   /login, /register  là trang NGƯỜI DÙNG MỞ. Chúng đã dựng trên Next, và
    #                      biểu mẫu của chúng gửi sang `/api/v2/auth/*`.
    #   /logout            là một route POST từ khu người mua trên Blade, không
    #                      phải trang. Proxy nó sang Next là trả 405 cho nút
    #                      đăng xuất — và khu đó vẫn là Blade, giai đoạn 8 đang
    #                      hoãn.
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
                if ! printf '%s\n' "$duong" | grep -qE '^/[A-Za-z0-9._/-]*$'; then
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
# Kiểm file luật rewrite theo danh sách trắng RIÊNG, chặt hơn `kiem_conf`.
#
# ══ Vì sao chặt hơn ══
#
# Một `RewriteRule` sai nguy hiểm hơn một `context` sai. `context /api` ít nhất
# còn đọc được bằng mắt là nó bắt gì. Còn `RewriteRule ^/(.*)$ http://nextjs/$1`
# trông gần giống luật trang chủ nhưng nuốt TOÀN BỘ site — kể cả `/api/v1` của
# đối tác — và khác biệt chỉ là vài ký tự.
#
# Nên luật ở đây phải là ĐƯỜNG DẪN CỐ ĐỊNH, neo hai đầu:
#
#   - bắt đầu `^`, kết thúc `$` — không neo thì nó khớp theo tiền tố, tức quay
#     lại đúng vấn đề của `context /`;
#   - không chứa ký tự biểu thức chính quy có sức bắt rộng: `.` `*` `+` `(` `)`
#     `|` `[` `]` `{` `}` `\`. Dấu `?` chỉ cho phép trong đúng cụm `/?$` của
#     luật trang chủ;
#   - đích phải là `http://nextjs/...`, không địa chỉ khác — cổng và máy đích
#     chỉ được khai MỘT nơi, là `extprocessor` trong nextjs.conf;
#   - cờ phải đúng `[P]`.
#
# Những gì KHÔNG cho phép ở đây, có chủ ý: `RewriteCond`, biến, backreference.
# Chúng cần thiết cho luật phức tạp, mà luật phức tạp là thứ không nên nằm
# trong một file được dán tự động bởi lượt merge.
# ═════════════════════════════════════════════════════════════════════════════
kiem_rewrite() {
    local f="$1"
    local nhan="$2"
    local so_dong=0 so_luat=0
    local dong_txt mau dich co

    if [ ! -f "$f" ]; then
        echo "   $nhan: không phải file thường." >&2
        return 1
    fi

    while IFS= read -r dong_txt || [ -n "$dong_txt" ]; do
        so_dong=$((so_dong + 1))

        dong_txt="${dong_txt#"${dong_txt%%[![:space:]]*}"}"
        dong_txt="${dong_txt%"${dong_txt##*[![:space:]]}"}"

        [ -z "$dong_txt" ] && continue
        case "$dong_txt" in '#'*) continue ;; esac

        # Đúng ba trường: RewriteRule <mẫu> <đích> <cờ>
        # shellcheck disable=SC2086
        set -- $dong_txt

        if [ "$#" -ne 4 ] || [ "$1" != "RewriteRule" ]; then
            echo "   $nhan dòng $so_dong: chỉ cho phép 'RewriteRule <mẫu> <đích> [P]', nhận '$dong_txt'." >&2
            return 1
        fi

        mau="$2"
        dich="$3"
        co="$4"

        if [ "$co" != "[P]" ]; then
            echo "   $nhan dòng $so_dong: cờ phải là đúng '[P]', nhận '$co'." >&2
            return 1
        fi

        case "$mau" in
            '^'*) ;;
            *) echo "   $nhan dòng $so_dong: mẫu '$mau' không neo đầu bằng '^' — nó sẽ khớp theo tiền tố." >&2; return 1 ;;
        esac

        case "$mau" in
            *'$') ;;
            *) echo "   $nhan dòng $so_dong: mẫu '$mau' không neo cuối bằng '\$' — nó sẽ khớp theo tiền tố." >&2; return 1 ;;
        esac

        # Bỏ hai dấu neo rồi soi phần giữa. `/?` của luật trang chủ được tha,
        # và CHỈ cụm đó.
        local giua="${mau#^}"
        giua="${giua%$}"
        giua="${giua%/\?}"

        # `\n` không thừa: với luật trang chủ, `$giua` rỗng sau khi bỏ hai dấu
        # neo và cụm `/?`. `printf '%s'` của một chuỗi rỗng không phát ra DÒNG
        # nào, nên `grep` không có gì để khớp và trả 1 — tức luật đúng bị coi
        # là sai. Thêm xuống dòng để grep thấy một dòng rỗng.
        if ! printf '%s\n' "$giua" | grep -qE '^/?[A-Za-z0-9._/-]*$'; then
            echo "   $nhan dòng $so_dong: mẫu '$mau' chứa ký tự biểu thức có sức bắt rộng." >&2
            echo "      Luật ở đây phải là đường dẫn cố định, neo hai đầu." >&2
            return 1
        fi

        case "$giua" in
            *..*) echo "   $nhan dòng $so_dong: mẫu '$mau' chứa '..'." >&2; return 1 ;;
        esac

        case "$dich" in
            'http://nextjs/'*) ;;
            *)
                echo "   $nhan dòng $so_dong: đích phải bắt đầu bằng 'http://nextjs/', nhận '$dich'." >&2
                echo "      Tên khác hoặc địa chỉ thẳng là khai cổng ở hai nơi." >&2
                return 1
                ;;
        esac

        # Đường dẫn luật này bắt, dùng cho canary và cho phép so với TRONG_APP.
        local duong="/${giua#/}"
        [ "$giua" = "" ] && duong="/"

        local c
        for c in "${CAM[@]}"; do
            [ "$c" = "/" ] && continue

            case "$duong" in
                "$c"*) echo "   $nhan dòng $so_dong: '$duong' nằm trong vùng CẤM '$c'." >&2; return 1 ;;
            esac
        done

        so_luat=$((so_luat + 1))
    done < "$f"

    if [ "$so_luat" -lt 1 ]; then
        echo "   $nhan: không có luật nào. Dán vào là vô nghĩa." >&2
        return 1
    fi

    echo "   $nhan: hợp lệ — $so_luat luật rewrite."
    return 0
}

# Những đường dẫn file rewrite bắt, mỗi dòng một đường. Dùng cho canary.
duong_rewrite() {
    local f="$1"
    [ -f "$f" ] || return 0

    grep -E '^[[:space:]]*RewriteRule[[:space:]]' "$f" 2>/dev/null \
        | awk '{print $2}' \
        | sed 's/^\^//; s/\$$//; s|/?$||' \
        | sed 's|^$|/|; s|^\([^/]\)|/\1|'
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
    # Gộp hai nguồn: đường khai bằng `context`, và đường khai bằng luật
    # rewrite. Bản v2 chỉ duyệt `context`, nên một luật rewrite không có tác
    # dụng vẫn qua được canary — trang chủ lặng lẽ ra Laravel và deploy báo
    # xanh. Đó đúng là loại hỏng im lặng mà canary tồn tại để bắt.
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
            echo "   LỖI canary: $duong ra 200 nhưng KHÔNG phải từ Next.js — luật không có tác dụng." >&2
            loi=$((loi + 1))
        fi
    done < <(
        grep -E '^context ' "$PROXY_CONF" | awk '{print $2}'
        duong_rewrite "$REWRITE_CONF"
    )

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

if [ ! -f "$REPO_REWRITE" ]; then
    echo "LỖI: không có $REPO_REWRITE." >&2
    exit 1
fi
kiem_rewrite "$REPO_REWRITE" "rewrite" || {
    echo "LỖI: file rewrite trong repo không qua được phép kiểm. KHÔNG dán gì." >&2
    exit 1
}

# ── 2. Không đổi gì thì dừng sớm ────────────────────────────────────────────
#
# CẢ HAI file phải khớp mới được bỏ qua. Chỉ so file proxy thì một thay đổi ở
# luật rewrite sẽ không bao giờ tới máy chủ, và lượt deploy vẫn báo "không đổi"
# — đúng kiểu hỏng im lặng mà cả chặng này đã mất nhiều vòng vì nó.
echo "[2/7] So với bản đang chạy"
if [ -f "$PROXY_CONF" ] && cmp -s "$REPO_CONF" "$PROXY_CONF" \
    && [ -f "$REWRITE_CONF" ] && cmp -s "$REPO_REWRITE" "$REWRITE_CONF"; then
    echo "   Không đổi: cả conf proxy lẫn luật rewrite đang chạy đều khớp repo."
    exit 0
fi

# ── 3. Vhost có nạp thư mục này không ───────────────────────────────────────
#
# Làm TRƯỚC khi đổi tên conf cũ. Nếu include không có mà ta đã cách ly conf cũ
# thì kết quả là KHÔNG CÒN conf nào có tác dụng — `/explore` rơi về Laravel,
# tức tự tay làm hỏng thứ đang chạy.
# `$VHOST_CONF` là `detail/oohx.net.conf`, và đó ĐÚNG là file giữ dòng include
# (xác nhận 06/10/2026: dòng 67 của nó). Nó không nằm trong glob nào — nó được
# nạp vì `/www/server/panel/vhost/openlitespeed/oohx.net.conf` ở cấp trên trỏ
# `configFile` vào đúng nó. Đừng đổi sang file cấp trên: dòng include nằm ở đây.
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

# Thư mục rewrite được nạp từ TRONG khối `rewrite { }`, nên nó là một dòng
# include KHÁC và phải kiểm riêng. Thiếu nó thì file luật dán vào im lặng
# không có tác dụng — trang chủ vẫn ra Laravel và không gì báo.
if ! grep -q "proxy/oohx.net/urlrewrite" "$VHOST_CONF"; then
    echo "LỖI: $VHOST_CONF KHÔNG include $REWRITE_DIR." >&2
    echo "      Luật rewrite dán vào đó sẽ không có tác dụng gì." >&2
    echo "      Thêm dòng này vào TRONG khối 'rewrite { }' của $VHOST_CONF:" >&2
    echo "          include $REWRITE_DIR/*.conf" >&2
    exit 1
fi
echo "   có include thư mục rewrite."

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
# ══ CHỈ xét file OpenLiteSpeed thật sự nạp, tức tên kết thúc bằng `.conf` ══
#
# Bản v1 tìm mọi file chứa `$NEXT_ADDR` và nó DỪNG NHẦM ngay lần chạy đầu trên
# máy thật (06/10/2026): nó bắt được
# `detail/oohx.net.conf.txt`, thấy file đó có directive ngoài danh sách trắng,
# và từ chối đi tiếp.
#
# Nhưng file đó OpenLiteSpeed **không bao giờ nạp**. Chuỗi nạp thật, đọc từ
# `/usr/local/lsws/conf/httpd_config.conf` dòng 256:
#
#     httpd_config.conf
#       └─ include /www/server/panel/vhost/openlitespeed/*.conf
#            └─ oohx.net.conf                  ← cấp TRÊN, không phải detail/
#                 └─ configFile → detail/oohx.net.conf
#                      └─ include proxy/oohx.net/*.conf
#                           └─ nextjs.conf     ← file sống
#
# Glob là `*.conf`, và `detail/` chỉ vào được qua `configFile` trỏ đích danh.
# aaPanel thì giữ một loạt bản sao cho trình soạn thảo và cho phiên bản:
# `.conf.txt`, `.conf0`, `.conf0,v`, `.conf.backup`. Không bản nào được nạp.
#
# Nên phép lọc đúng là theo ĐUÔI TÊN, không theo nội dung: một bản sao nhắc
# tới địa chỉ Next.js không phải một conf cạnh tranh.
echo "[4/7] Tìm conf khác đang trỏ $NEXT_ADDR"
# ══ Bỏ qua CẢ THƯ MỤC rewrite, không chỉ file proxy ══
#
# v3 chỉ loại trừ `$PROXY_CONF`, và nó hỏng từ LẦN CHẠY THỨ HAI: file rewrite
# do chính script cài có nhắc `127.0.0.1:3001` trong phần ghi chú, nên bước này
# bắt được nó, đưa nó qua `kiem_conf` — một phép kiểm dành cho conf proxy, mà
# nó là file rewrite — rồi dừng.
#
# Lần chạy tay đầu tiên qua được vì file chưa tồn tại. Lượt deploy sau đó đỏ.
# Đó là loại lỗi "chạy được một lần", và nó chỉ lộ ra khi có người chạy lần
# thứ hai.
#
# Thư mục rewrite bị loại theo ĐƯỜNG DẪN, không theo nội dung: mọi file trong
# đó là luật rewrite theo định nghĩa, và chúng được kiểm bằng `kiem_rewrite`.
LA=()
while read -r f; do
    [ -z "$f" ] && continue
    [ "$f" = "$PROXY_CONF" ] && continue

    case "$f" in
        "$REWRITE_DIR"/*) continue ;;
    esac

    LA+=("$f")
done < <(grep -rl --include='*.conf' "$NEXT_ADDR" "$OLS_VHOST_DIR" /usr/local/lsws/conf 2>/dev/null || true)

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
CO_REWRITE_CU=0
DA_CACH_LY=()
DA_DOI=0

# Trả lại MỘT file: về bản cũ nếu trước đó có, xoá hẳn nếu trước đó không có.
# Hai trường hợp đó khác nhau, và gộp chúng là cách để lần cài đầu tiên lùi
# xong vẫn còn một file lạ nằm lại.
tra_lai_mot() {
    local dich="$1"
    local co_cu="$2"

    if [ "$co_cu" -eq 1 ]; then
        mv -f "$dich$BAK_SUFFIX" "$dich" \
            && echo "   trả lại bản cũ của $dich" >&2 \
            || echo "   KHÔNG trả lại được bản cũ của $dich — cần xem tay." >&2
    else
        rm -f "$dich"
        echo "   xoá $dich (trước đó không có)" >&2
    fi
}

lui_lai() {
    trap - ERR
    echo "── LÙI LẠI ──" >&2

    tra_lai_mot "$PROXY_CONF" "$CO_BAN_CU"
    tra_lai_mot "$REWRITE_CONF" "$CO_REWRITE_CU"

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

mkdir -p "$PROXY_DIR" "$REWRITE_DIR"

DA_DOI=1

if [ -f "$PROXY_CONF" ]; then
    cp -p "$PROXY_CONF" "$PROXY_CONF$BAK_SUFFIX"
    CO_BAN_CU=1
fi

if [ -f "$REWRITE_CONF" ]; then
    cp -p "$REWRITE_CONF" "$REWRITE_CONF$BAK_SUFFIX"
    CO_REWRITE_CU=1
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

cp "$REPO_REWRITE" "$REWRITE_CONF"
chown root:root "$REWRITE_CONF"
chmod 644 "$REWRITE_CONF"
echo "   đã dán $REWRITE_CONF ($(duong_rewrite "$REWRITE_CONF" | tr '\n' ' '))."

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
