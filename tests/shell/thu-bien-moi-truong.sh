#!/bin/bash
# Thử tám tham số môi trường của `deploy.sh`, và hợp đồng giữa nó với
# `.github/workflows/deploy-dev.yml`.
#
# ══ Vì sao phần này đáng một bộ thử riêng ══
#
# Cả tám biến (`OOHX_APP_DIR`, `OOHX_BRANCH`, `OOHX_SERVICE`, `OOHX_SYNC`,
# `OOHX_SITE`, `OOHX_BUILD_RAM_MB`, `OOHX_BUILD_NICE`,
# `OOHX_RAM_TOI_THIEU_MB`) hỏng theo cùng một kiểu: **sai mà vẫn xanh**.
#
#   - Mặc định lệch khỏi production → một lượt deploy `main` lặng lẽ đi sai
#     thư mục, sai nhánh, hoặc restart sai service. Không lỗi nào được ghi.
#   - Tên biến thiếu trong `envs:` của workflow → `appleboy/ssh-action` không
#     mang biến sang phiên SSH, `deploy.sh` rơi về mặc định production, và
#     máy chủ dev chạy `git reset --hard origin/main`. Deploy vẫn "success".
#     Đây không phải giả thuyết: cùng cái bẫy đã làm khóa MapTiler không tới
#     production ngày 18/10/2026, và lần đó cũng xanh.
#   - `concurrency.group` của hai workflow lệch chuỗi → hai `next build` chạy
#     cùng lúc trên một VPS, và cái chết vì thiếu bộ nhớ rơi vào production.
#   - `OOHX_SITE` có mặc định "oohx.net" thay vì RỖNG → `deploy.sh` gọi script
#     root kèm `--site oohx.net`, một tổ hợp đối số mà dòng sudoers của
#     production (kết thúc bằng `""`) KHÔNG cấp quyền. Ở đây thì ngược lại:
#     bước [11] đỏ ở mọi lượt deploy production, trong khi nội dung đúng hết.
#
#   - Ba biến của lát 5 có mặc định KHÁC rỗng → production bỗng chạy build
#     dưới `nice` và dưới một trần heap mà không ai chọn cho nó, trong lúc
#     `main` đang đóng băng. Hoặc ngược lại: `OOHX_RAM_TOI_THIEU_MB` rơi về
#     mặc định rỗng ở dev → phép đo biến mất, và lần thiếu bộ nhớ kế tiếp do
#     OOM killer phân xử. Cả hai chiều đều xanh.
#
# Không chiều nào trong năm chiều trên làm CI đỏ, nên chúng phải có phép kiểm.
#
# Bộ thử đọc giá trị từ CHÍNH `deploy.sh` (trích rồi source), không chép lại —
# chép lại là thử một bản khác.

set -u

SH="deploy.sh"
WF_DEV=".github/workflows/deploy-dev.yml"
WF_PROD=".github/workflows/deploy.yml"

D="${TMPDIR:-/tmp}/thu-bien-moi-truong-$$"
BIEN="$D/bien.sh"

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

echo "── Thử tám tham số môi trường của deploy.sh ──"

# ── Trích tám dòng gán từ script thật ──────────────────────────────────────
#
# Lấy đúng tám dòng gán, bỏ mọi chú thích giữa chúng. Khoảng trích kết thúc ở
# `RAM_TOI_THIEU_MB=` — dòng cuối của khối. Thêm biến thứ chín mà không dời
# mốc này nghĩa là biến đó không được thử, và bộ thử vẫn xanh.
sed -n '/^APP_DIR=/,/^RAM_TOI_THIEU_MB=/p' "$SH" \
    | grep -E '^(APP_DIR|BRANCH|SERVICE|SYNC|PROXY_SITE|BUILD_RAM_MB|BUILD_NICE|RAM_TOI_THIEU_MB)=' > "$BIEN"

so_dong=$(wc -l < "$BIEN" | tr -d ' ')

# Bộ trích không rỗng: một tệp rỗng làm MỌI khẳng định dưới đây xanh vì biến
# nào cũng rỗng và so với rỗng là bằng nhau. Ca này canh chính bộ trích.
khang_dinh 'trích được đúng tám dòng gán' "$so_dong" '8'

if [ "$so_dong" != "8" ]; then
    echo ""
    echo "KHONG TRICH DUOC TAM DONG GAN TU $SH — dung lai."
    rm -rf "$D"
    exit 1
fi

bash -n "$BIEN" || { echo "NAM DONG TRICH RA KHONG HOP LE"; rm -rf "$D"; exit 1; }

# Mỗi dòng phải dùng dạng `${BIEN:-mặc định}`. Một dòng gán thẳng vẫn chạy,
# vẫn cho mặc định đúng, và KHÔNG nhận được tham số — tức dev im lặng chạy
# như production.
khang_dinh 'cả tám dòng đều đọc biến môi trường' \
    "$(grep -cE '^\w+="\$\{OOHX_[A-Z_]+:-' "$BIEN")" '8'

# ── Chiều 1: không tham số → ĐÚNG production ───────────────────────────────
#
# Đây là chiều quan trọng nhất của lát này. `main` đang đóng băng cho Sở kiểm
# tra; một mặc định lệch nghĩa là lượt deploy production kế tiếp đi sai chỗ.

doc() {
    env -u OOHX_APP_DIR -u OOHX_BRANCH -u OOHX_SERVICE -u OOHX_SYNC -u OOHX_SITE \
        -u OOHX_BUILD_RAM_MB -u OOHX_BUILD_NICE -u OOHX_RAM_TOI_THIEU_MB \
        "$@" bash -c '. "$0"; printf "%s|%s|%s|%s|%s|%s|%s|%s" "$APP_DIR" "$BRANCH" "$SERVICE" "$SYNC" "$PROXY_SITE" "$BUILD_RAM_MB" "$BUILD_NICE" "$RAM_TOI_THIEU_MB"' "$BIEN"
}

khang_dinh 'không tham số → đúng tám giá trị production' \
    "$(doc)" '/www/wwwroot/dash.oohx.net|main|oohx-webapp|1||||'

# ══ Và giá trị thứ năm phải RỖNG, không phải "oohx.net" ══
#
# Ca này nhìn như thừa sau ca trên, nhưng nó canh một chuyện khác: rỗng ở đây
# KHÔNG phải "chưa điền", nó là giá trị đúng và duy nhất dùng được. Rỗng nghĩa
# là `deploy.sh` gọi script root KHÔNG kèm đối số nào — dạng duy nhất mà dòng
# sudoers của production cho phép, vì dòng đó kết thúc bằng `""`.
#
# Điền "oohx.net" vào đây thì tên site hoàn toàn đúng mà **sudo** vẫn từ chối,
# và bước [11] đỏ ở mọi lượt deploy production.
khang_dinh 'mặc định của OOHX_SITE là RỖNG, không phải tên site' \
    "$(doc | cut -d'|' -f5)" ''

# ── Chiều 2: có tham số → nhận đủ cả năm ──────────────────────────────────

khang_dinh 'OOHX_APP_DIR đổi được thư mục' \
    "$(doc OOHX_APP_DIR=/www/wwwroot/dev.oohx.net | cut -d'|' -f1)" \
    '/www/wwwroot/dev.oohx.net'

khang_dinh 'OOHX_BRANCH đổi được nhánh' \
    "$(doc OOHX_BRANCH=dev | cut -d'|' -f2)" 'dev'

khang_dinh 'OOHX_SERVICE đổi được tên service' \
    "$(doc OOHX_SERVICE=oohx-webapp-dev | cut -d'|' -f3)" 'oohx-webapp-dev'

khang_dinh 'OOHX_SYNC đổi được cờ đồng bộ' \
    "$(doc OOHX_SYNC=0 | cut -d'|' -f4)" '0'

khang_dinh 'OOHX_SITE đổi được site của script root' \
    "$(doc OOHX_SITE=dev.oohx.net | cut -d'|' -f5)" 'dev.oohx.net'

khang_dinh 'tám biến đổi cùng lúc, không biến nào đè biến nào' \
    "$(doc OOHX_APP_DIR=/a OOHX_BRANCH=b OOHX_SERVICE=c OOHX_SYNC=0 OOHX_SITE=s \
          OOHX_BUILD_RAM_MB=1 OOHX_BUILD_NICE=2 OOHX_RAM_TOI_THIEU_MB=3)" '/a|b|c|0|s|1|2|3'

# ══ Ba biến của lát 5: mặc định RỖNG là một quyết định, không phải chỗ trống ══
#
# Rỗng ở đây nghĩa là production chạy **y như trước lát 5**: không `nice`,
# không trần heap, không phép đo nào. Mức chặn là thứ dev tự khoác vào mình.
#
# Điền một con số vào mặc định — kể cả con số "an toàn" — là đổi cách build
# của production trong lúc `main` đang đóng băng cho Sở kiểm tra, và đổi nó
# bằng một dòng mà không ai duyệt.
khang_dinh 'mặc định của OOHX_BUILD_RAM_MB là RỖNG' \
    "$(doc | cut -d'|' -f6)" ''

khang_dinh 'mặc định của OOHX_BUILD_NICE là RỖNG' \
    "$(doc | cut -d'|' -f7)" ''

khang_dinh 'mặc định của OOHX_RAM_TOI_THIEU_MB là RỖNG' \
    "$(doc | cut -d'|' -f8)" ''

khang_dinh 'OOHX_BUILD_RAM_MB đổi được trần heap' \
    "$(doc OOHX_BUILD_RAM_MB=1536 | cut -d'|' -f6)" '1536'

khang_dinh 'OOHX_BUILD_NICE đổi được mức nhường CPU' \
    "$(doc OOHX_BUILD_NICE=10 | cut -d'|' -f7)" '10'

khang_dinh 'OOHX_RAM_TOI_THIEU_MB đổi được ngưỡng dừng' \
    "$(doc OOHX_RAM_TOI_THIEU_MB=2048 | cut -d'|' -f8)" '2048'

# Biến rỗng KHÔNG được coi là "đã đặt": `${X:-mặc định}` trả mặc định khi X
# rỗng, và đó là hành vi đúng — một `OOHX_BRANCH=` rỗng đi tới máy chủ thì
# `git reset --hard origin/` là lệnh vô nghĩa, còn rơi về `main` thì ít nhất
# là một hành vi biết trước.
khang_dinh 'biến rỗng rơi về mặc định, không thành chuỗi rỗng' \
    "$(doc OOHX_BRANCH= | cut -d'|' -f2)" 'main'

# ── Chiều 3: tên service không còn viết cứng ở đâu ────────────────────────
#
# Bốn chỗ trong `deploy.sh` từng viết cứng `oohx-webapp`: điều kiện tồn tại
# unit, câu lỗi thiếu npm, lệnh restart, và câu "chưa tồn tại". Sót một chỗ
# nghĩa là deploy dev restart service của PRODUCTION.

khang_dinh 'chỉ còn MỘT lần `oohx-webapp` trong deploy.sh (dòng mặc định)' \
    "$(grep -c 'oohx-webapp' "$SH")" '1'

khang_dinh 'lệnh restart dùng $SERVICE' \
    "$(grep -c 'systemctl restart "\$SERVICE"' "$SH")" '1'

khang_dinh 'điều kiện tồn tại unit dùng $SERVICE' \
    "$(grep -c 'if \[ -f "/etc/systemd/system/\$SERVICE.service" \]' "$SH")" '1'

# ── Chiều 4: nhánh bỏ qua đồng bộ proxy ───────────────────────────────────
#
# Bước [11] gọi script root viết cứng đường dẫn production. Nhánh bỏ qua phải
# tồn tại VÀ phải đứng TRƯỚC nhánh kiểm `-x "$SYNC_BIN"`: đảo thứ tự thì dev
# vẫn rơi vào đường gọi script của production.

khang_dinh 'có nhánh bỏ qua khi SYNC khác 1' \
    "$(grep -c 'if \[ "\$SYNC" != "1" \]; then' "$SH")" '1'

dong_sync=$(grep -n 'if \[ "\$SYNC" != "1" \]; then' "$SH" | cut -d: -f1)
dong_bin=$(grep -n 'elif \[ ! -x "\$SYNC_BIN" \]; then' "$SH" | cut -d: -f1)

khang_dinh 'nhánh bỏ qua đứng TRƯỚC nhánh kiểm script root' \
    "$([ -n "$dong_sync" ] && [ -n "$dong_bin" ] && [ "$dong_sync" -lt "$dong_bin" ] && echo truoc || echo sai)" \
    'truoc'

# ── Chiều 5: dòng in ra để người đọc log phát hiện biến không tới ─────────
#
# Nếu `OOHX_BRANCH` không tới được máy chủ thì không lỗi nào được ghi. Hai
# dòng này là chỗ DUY NHẤT nhìn thấy chuyện đó.

khang_dinh 'header in nhánh đang deploy' \
    "$(grep -c '^echo "Branch : \$BRANCH"' "$SH")" '1'

khang_dinh 'header in service đang restart' \
    "$(grep -c '^echo "Service: \$SERVICE"' "$SH")" '1'

echo ""
echo "── Thử hợp đồng với $WF_DEV ──"

[ -f "$WF_DEV" ] || { echo "KHONG CO $WF_DEV"; rm -rf "$D"; exit 1; }

# ── Chiều 6: mọi biến khai trong `env:` phải có tên trong `envs:` ─────────
#
# Đây là ca đắt nhất của cả bộ thử. `appleboy/ssh-action` chỉ mang sang phiên
# SSH những biến có tên trong `envs:`. Thiếu một tên thì biến đó sống trong
# runner, máy chủ rơi về mặc định production, và deploy vẫn "success".
#
# Kiểm cả hai chiều: khai mà không mang sang (biến vô nghĩa), và mang sang mà
# không khai (tên gõ sai).

khoa_env=$(grep -oE '^          [A-Z_]+:' "$WF_DEV" | tr -d ' :' | sort)
ten_envs=$(grep -E '^          envs:' "$WF_DEV" | sed 's/.*envs: *//' | tr ',' '\n' | tr -d ' ' | sort)

khang_dinh 'danh sách envs không rỗng' \
    "$([ -n "$ten_envs" ] && echo co || echo rong)" 'co'

khang_dinh 'mọi biến khai trong env: đều có tên trong envs:' \
    "$(comm -23 <(echo "$khoa_env") <(echo "$ten_envs") | tr '\n' ' ' | sed 's/ *$//')" ''

khang_dinh 'mọi tên trong envs: đều được khai ở env:' \
    "$(comm -13 <(echo "$khoa_env") <(echo "$ten_envs") | tr '\n' ' ' | sed 's/ *$//')" ''

khang_dinh 'tám biến OOHX_* đều có mặt' \
    "$(echo "$ten_envs" | grep -c '^OOHX_')" '8'

# ── Chiều 7: giá trị của dev phải thật sự là của dev ──────────────────────

khang_dinh 'OOHX_BRANCH của workflow dev là `dev`' \
    "$(grep -cE '^          OOHX_BRANCH: dev$' "$WF_DEV")" '1'

# Bật thành `1` ngày 07/11/2026, sau khi lát 4 dựng vhost `dev.oohx.net` và
# dòng sudoers cho đúng `--site dev.oohx.net`, VÀ sau khi phép kiểm
# `sudo -u deploy sudo -n ... --site dev.oohx.net` chạy xanh as root.
khang_dinh 'OOHX_SYNC của workflow dev là 1' \
    "$(grep -cE "^          OOHX_SYNC: '1'\$" "$WF_DEV")" '1'

# Cờ trên đã bật, nên dòng này giờ ĐƯỢC DÙNG thật. Thiếu nó nghĩa là
# `deploy.sh` gọi script root KHÔNG kèm `--site`, tức một lượt deploy dev đi
# đồng bộ conf của production — đúng cái mà cả lát 2 và lát 3 dựng lên để
# tránh. Và nó sẽ không đỏ: dòng sudoers của production nhận lời gọi không
# đối số, nên sudo cho qua và conf của production bị ghi bằng cổng của dev.
khang_dinh 'OOHX_SITE của workflow dev là dev.oohx.net' \
    "$(grep -cE '^          OOHX_SITE: dev\.oohx\.net$' "$WF_DEV")" '1'

khang_dinh 'thư mục trong `cd` khớp OOHX_APP_DIR' \
    "$(grep -oE '/www/wwwroot/dev\.oohx\.net' "$WF_DEV" | sort -u | wc -l | tr -d ' ')" '1'

khang_dinh 'workflow dev KHÔNG trỏ vào thư mục production' \
    "$(grep -c 'wwwroot/dash\.oohx\.net' "$WF_DEV")" '0'

# ── Chiều 8: ổ khoá dùng chung giữa hai workflow ──────────────────────────
#
# GitHub xếp hàng theo CHUỖI `concurrency.group`. Hai chuỗi lệch nhau nghĩa là
# hai `next build` chạy được cùng lúc trên một VPS — và bước đó là bước ngốn
# bộ nhớ nhất của cả đường deploy.

nhom_dev=$(grep -A1 '^concurrency:' "$WF_DEV"  | grep 'group:' | sed 's/.*group: *//')
nhom_prod=$(grep -A1 '^concurrency:' "$WF_PROD" | grep 'group:' | sed 's/.*group: *//')

khang_dinh 'đọc được group của workflow production' \
    "$([ -n "$nhom_prod" ] && echo co || echo rong)" 'co'

khang_dinh 'hai workflow dùng CÙNG MỘT chuỗi concurrency.group' \
    "$nhom_dev" "$nhom_prod"

# Chiều của việc huỷ: dev vào hàng thì ĐỢI, không ngắt production đang chạy
# migration. GitHub đọc cờ này từ lượt đang vào hàng.
khang_dinh 'dev không huỷ lượt đang chạy (cancel-in-progress: false)' \
    "$(grep -c '^  cancel-in-progress: false$' "$WF_DEV")" '1'

# ── Chiều 9: dev chạy cùng bộ test như production ─────────────────────────

khang_dinh 'workflow dev gọi lại tests.yml' \
    "$(grep -c 'uses: \./\.github/workflows/tests\.yml' "$WF_DEV")" '1'

khang_dinh 'job deploy của dev chờ tests xong' \
    "$(grep -c '^    needs: tests$' "$WF_DEV")" '1'

echo ""
echo "── Thử chỗ bàn giao sang script root ──"

# ── Chiều 10: lời gọi `sudo` và dòng sudoers phải khớp nhau ────────────────
#
# sudoers so khớp CẢ ĐỐI SỐ. Nên ba thứ phải cùng nói một chuyện: lời gọi
# thật, câu gợi ý in ra khi thiếu quyền, và phép dò `sudo -n -l`. Lệch nhau
# thì người đọc log được chỉ sang một dòng sudoers không cấp quyền cho lời gọi
# đang thất bại — và câu chẩn đoán đó tự tin y như một câu đúng.

khang_dinh 'lời gọi script root truyền mảng đối số' \
    "$(grep -c 'sudo -n "$SYNC_BIN" "${SYNC_ARGS\[@\]}"' "$SH")" '1'

khang_dinh 'phép dò quyền mang CÙNG mảng đối số đó' \
    "$(grep -c 'sudo -n -l "$SYNC_BIN" "${SYNC_ARGS\[@\]}"' "$SH")" '1'

# Không liệt kê từng dạng gọi sai — bắt theo chiều ngược lại: mọi dòng gọi
# `sudo` tới script root đều phải mang mảng. Một dạng gọi mới quên mảng cũng
# rơi vào ca này mà không cần thêm ca.
khang_dinh 'mọi lời gọi sudo tới script root đều mang mảng đối số' \
    "$(grep -E 'sudo -n .*$SYNC_BIN' "$SH" | grep -v '^[[:space:]]*#' | grep -cv 'SYNC_ARGS')" '0'

# Mảng, không chuỗi: một chuỗi rỗng truyền vào `sudo` là MỘT đối số rỗng, và
# script root đếm `$#` nên nó thấy một đối số rồi thoát 64.
ARG="$D/args.sh"
sed -n '/^    SYNC_ARGS=()$/,/^    fi$/p' "$SH" > "$ARG"

bash -n "$ARG" || { echo "KHOI SYNC_ARGS TRICH RA KHONG HOP LE"; rm -rf "$D"; exit 1; }

doc_arg() {
    env "$@" bash -c 'PROXY_SITE="${OOHX_SITE:-}"; . "$0"; printf "%s" "${#SYNC_ARGS[@]}"' "$ARG"
}

khang_dinh 'không có site → KHÔNG đối số nào (mảng rỗng)' \
    "$(env -u OOHX_SITE bash -c 'PROXY_SITE=""; . "$0"; printf "%s" "${#SYNC_ARGS[@]}"' "$ARG")" '0'

khang_dinh 'có site → đúng hai đối số' \
    "$(doc_arg OOHX_SITE=dev.oohx.net)" '2'

khang_dinh 'đối số thứ nhất là --site' \
    "$(env OOHX_SITE=dev.oohx.net bash -c 'PROXY_SITE="${OOHX_SITE:-}"; . "$0"; printf "%s" "${SYNC_ARGS[0]}"' "$ARG")" \
    '--site'

khang_dinh 'đối số thứ hai là tên site, nguyên một đối số' \
    "$(env OOHX_SITE='co khoang trang' bash -c 'PROXY_SITE="${OOHX_SITE:-}"; . "$0"; printf "%s" "${SYNC_ARGS[1]}"' "$ARG")" \
    'co khoang trang'

# ── Chiều 11: dòng sudoers gợi ý phải khớp tổ hợp đối số sẽ gọi ───────────

SUD="$D/sudoers.sh"
{
    echo 'SYNC_BIN="/usr/local/sbin/oohx-sync-proxy"'
    sed -n '/^if \[ -n "$PROXY_SITE" \]; then$/,/^fi$/p' "$SH"
} > "$SUD"

bash -n "$SUD" || { echo "KHOI SUDOERS TRICH RA KHONG HOP LE"; rm -rf "$D"; exit 1; }

doc_sud() {
    env "$@" bash -c 'PROXY_SITE="${OOHX_SITE:-}"; . "$0"; printf "%s|%s" "$SUDOERS_LENH" "$SUDOERS_TEP"' "$SUD"
}

khang_dinh 'không có site → dòng sudoers kết thúc bằng hai nháy rỗng' \
    "$(doc_sud OOHX_SITE= | cut -d'|' -f1)" \
    '/usr/local/sbin/oohx-sync-proxy ""'

khang_dinh 'có site → dòng sudoers mang đúng --site' \
    "$(doc_sud OOHX_SITE=dev.oohx.net | cut -d'|' -f1)" \
    '/usr/local/sbin/oohx-sync-proxy --site dev.oohx.net'

# Hai file khác nhau, không hai dòng trong một file: câu gợi ý ghi bằng `>`,
# nên hai dòng trong một file thì dòng thứ hai xoá dòng thứ nhất — và dòng bị
# xoá là dòng production đang dựa vào.
khang_dinh 'hai môi trường dùng hai file sudoers khác nhau' \
    "$([ "$(doc_sud OOHX_SITE= | cut -d'|' -f2)" != "$(doc_sud OOHX_SITE=dev.oohx.net | cut -d'|' -f2)" ] && echo khac || echo trung)" \
    'khac'

khang_dinh 'file sudoers của production giữ nguyên tên cũ' \
    "$(doc_sud OOHX_SITE= | cut -d'|' -f2)" '/etc/sudoers.d/oohx-sync-proxy'

# Câu gợi ý in ra phải dùng chính hai biến này, không viết lại chuỗi. Hai bản
# của cùng một dòng sudoers là hai bản sẽ trôi khỏi nhau.
khang_dinh 'câu gợi ý dùng $SUDOERS_LENH ở cả hai chỗ' \
    "$(grep -c 'NOPASSWD: $SUDOERS_LENH' "$SH")" '2'

khang_dinh 'đường dẫn sudoers của script root chỉ còn ở hai dòng gán' \
    "$(grep -c '/etc/sudoers.d/oohx-sync-proxy' "$SH")" '2'

# ── Chiều 12: lệnh build thật sự mang mức chặn ────────────────────────────
#
# Hai biến `BUILD_*` có đúng giá trị trong shell mà không đi vào lệnh `npm run
# build` thì chúng là hai dòng gán vô nghĩa — và đó đúng là dạng "sai mà vẫn
# xanh" mà cả bộ thử này tồn tại để bắt.
#
# Trích chính hàm `chay_build_next` ra chạy, với `npm` và `nice` giả ghi lại
# lệnh chúng nhận được.

BUILD_FN="$D/chay-build.sh"
GIA_BIN="$D/bin"
mkdir -p "$GIA_BIN"

# `npm` giả ghi lại CẢ `NODE_OPTIONS` mà nó nhận được, không chỉ đối số.
#
# Đây là chỗ bản đầu của bộ thử này sai, và đột biến bắt được: nó đọc
# `$NODE_OPTIONS` trong chính shell gọi hàm. Một dòng gán KHÔNG `export` vẫn
# cho đúng giá trị ở đó, nhưng tiến trình con — tức `node` thật — không thấy
# gì. Trần heap sẽ không có tác dụng, và không ai biết.
printf '%s\n' '#!/bin/bash' 'printf "npm %s\n" "$*" >> "$GHI"' \
    'printf "NODE_OPTIONS=%s\n" "${NODE_OPTIONS:-}" >> "$GHI"' > "$GIA_BIN/npm"
printf '%s\n' '#!/bin/bash' 'printf "nice %s\n" "$*" >> "$GHI"' \
    'shift 2; exec "$@"' > "$GIA_BIN/nice"
chmod +x "$GIA_BIN/npm" "$GIA_BIN/nice"

sed -n '/^chay_build_next() {$/,/^}$/p' "$SH" > "$BUILD_FN"

khang_dinh 'trích được hàm chay_build_next' \
    "$(bash -n "$BUILD_FN" >/dev/null 2>&1 && echo hop-le || echo hong)" 'hop-le'

# `$3` là NODE_OPTIONS có sẵn trong môi trường trước khi hàm chạy.
build() {
    GHI="$D/ghi.txt"
    : > "$GHI"

    env -u NODE_OPTIONS GHI="$GHI" PATH="$GIA_BIN:$PATH" \
        BUILD_RAM_MB="$1" BUILD_NICE="$2" ${3:+NODE_OPTIONS=$3} \
        bash -c '. "$0"; chay_build_next >/dev/null 2>&1' "$BUILD_FN"

    cat "$GHI"
}

khang_dinh 'không đặt gì → npm run build trần' \
    "$(build '' '' | grep -c '^npm run build$')" '1'

khang_dinh 'không đặt gì → không đi qua nice' \
    "$(build '' '' | grep -c '^nice ')" '0'

khang_dinh 'không đặt gì → tiến trình con thấy NODE_OPTIONS rỗng' \
    "$(build '' '' | sed -n 's/^NODE_OPTIONS=//p')" ''

khang_dinh 'có BUILD_NICE → build chạy qua nice' \
    "$(build '' 10 | grep -c '^nice -n 10 ')" '1'

# Khẳng định này đọc giá trị mà `npm` CON nhìn thấy, không phải giá trị trong
# shell cha — xem chú thích ở `npm` giả bên trên.
khang_dinh 'có BUILD_RAM_MB → tiến trình con nhận đúng trần heap' \
    "$(build 1536 '' | sed -n 's/^NODE_OPTIONS=//p')" '--max-old-space-size=1536'

khang_dinh 'cả hai cùng lúc → vừa nice vừa có trần heap' \
    "$(build 1536 10 | grep -c '^nice -n 10 npm run build$')" '1'

# Và trần heap phải NỐI vào `NODE_OPTIONS` sẵn có, không đè lên nó. Ghi đè là
# lặng lẽ vứt đi mọi tuỳ chọn Node mà môi trường đã đặt — và vứt im lặng, vì
# build vẫn chạy xong.
khang_dinh 'NODE_OPTIONS sẵn có được giữ lại, không bị đè' \
    "$(build 1536 '' --enable-source-maps | sed -n 's/^NODE_OPTIONS=//p')" \
    '--enable-source-maps --max-old-space-size=1536'

# ── Chiều 13: phép đo RAM, và nó phải đo MemAvailable ─────────────────────
#
# `MemFree` trên máy chủ này là 516 MB và sẽ luôn dưới mọi ngưỡng hợp lý, vì
# Linux dùng phần còn lại làm cache rồi nhả ra khi cần. Đo `MemFree` là chặn
# nhầm MỌI lượt deploy — một kiểu hỏng ồn ào, nhưng vẫn là hỏng.

RAM_FN="$D/kiem-ram.sh"
sed -n '/^kiem_ram_truoc_build() {$/,/^}$/p' "$SH" > "$RAM_FN"

khang_dinh 'trích được hàm kiem_ram_truoc_build' \
    "$(bash -n "$RAM_FN" >/dev/null 2>&1 && echo hop-le || echo hong)" 'hop-le'

# MemFree thấp, MemAvailable cao — đúng hình dạng của máy chủ thật.
printf '%s\n' 'MemTotal:        8166188 kB' 'MemFree:          528384 kB' \
    'MemAvailable:    5033984 kB' > "$D/meminfo-du"

printf '%s\n' 'MemTotal:        8166188 kB' 'MemFree:          528384 kB' \
    'MemAvailable:    1048576 kB' > "$D/meminfo-thieu"

printf '%s\n' 'MemTotal:        8166188 kB' 'MemFree:          528384 kB' > "$D/meminfo-khong-co-dong"

ram() {
    RAM_TOI_THIEU_MB="$1" OOHX_MEMINFO="$2" \
        bash -c '. "$0"; kiem_ram_truoc_build >/dev/null 2>&1; echo "$?"' "$RAM_FN"
}

khang_dinh 'ngưỡng rỗng → không đo gì, luôn cho qua' \
    "$(ram '' "$D/meminfo-thieu")" '0'

khang_dinh 'MemAvailable trên ngưỡng → cho qua' \
    "$(ram 2048 "$D/meminfo-du")" '0'

khang_dinh 'MemAvailable dưới ngưỡng → ĐỎ' \
    "$(ram 2048 "$D/meminfo-thieu")" '1'

# Hai ca hỏng-về-phía-cho-qua. Một phép đo không đo được KHÔNG được biến thành
# một lần chặn: máy nào không có /proc/meminfo thì vẫn phải deploy được.
khang_dinh 'không đọc được tệp → bỏ qua phép đo, không chặn' \
    "$(ram 2048 "$D/khong-ton-tai")" '0'

khang_dinh 'tệp không có dòng MemAvailable → bỏ qua, không chặn' \
    "$(ram 2048 "$D/meminfo-khong-co-dong")" '0'

# Và chính chỗ gọi: một hàm đúng mà không ai gọi thì không chặn gì.
khang_dinh 'deploy.sh gọi phép đo và thoát khi nó đỏ' \
    "$(grep -c '^    kiem_ram_truoc_build || exit 1$' "$SH")" '1'

khang_dinh 'build của Next đi qua chay_build_next, không gọi npm thẳng' \
    "$(grep -c 'cd webapp && npm ci && chay_build_next' "$SH")" '1'

khang_dinh 'không còn lời gọi npm run build thẳng cho webapp' \
    "$(grep -c 'cd webapp && npm ci && npm run build' "$SH")" '0'

# ── Chiều 14: ba giá trị của workflow dev ─────────────────────────────────

khang_dinh 'OOHX_BUILD_RAM_MB của workflow dev là 1536' \
    "$(grep -cE "^          OOHX_BUILD_RAM_MB: '1536'\$" "$WF_DEV")" '1'

khang_dinh 'OOHX_BUILD_NICE của workflow dev là 10' \
    "$(grep -cE "^          OOHX_BUILD_NICE: '10'\$" "$WF_DEV")" '1'

khang_dinh 'OOHX_RAM_TOI_THIEU_MB của workflow dev là 2048' \
    "$(grep -cE "^          OOHX_RAM_TOI_THIEU_MB: '2048'\$" "$WF_DEV")" '1'

# Production KHÔNG khai ba biến này. Khai một cái ở đó là đổi cách build của
# production trong lúc `main` đóng băng.
khang_dinh 'workflow production không khai biến nào của lát 5' \
    "$(grep -cE '^          OOHX_(BUILD_RAM_MB|BUILD_NICE|RAM_TOI_THIEU_MB):' "$WF_PROD")" '0'

# ══ Hai biến mà production phải KHÔNG khai, và vì sao mỗi cái nguy theo một
#    kiểu khác ══
#
# `deploy.sh` khai `SYNC="${OOHX_SYNC:-1}"`, tức production đồng bộ conf proxy
# nhờ MẶC ĐỊNH, không nhờ một dòng nào trong workflow. Nên:
#
#   · `OOHX_SYNC: '0'` ở production = ngừng đồng bộ. `nextjs.conf` đổi trong
#     repo nhưng không bao giờ được dán lên máy chủ; production phục vụ conf
#     cũ và lượt deploy VẪN XANH. Đột biến này lọt ở lượt đo 07/11/2026 —
#     hai ca dưới đây là thứ được thêm vì nó lọt.
#   · `OOHX_SITE` ở production = gọi script kèm `--site`, tức một tổ hợp đối
#     số mà dòng sudoers của production (kết thúc bằng `""`) từ chối. Cái này
#     đỏ ở mọi lượt deploy, nên thấy được — nhưng vẫn không nên có.
#
# `OOHX_SYNC: '1'` ở production thì vô hại (bằng đúng mặc định), nên hai ca
# này canh sự VẮNG MẶT của tên biến, không canh giá trị.
khang_dinh 'workflow production KHÔNG khai OOHX_SYNC' \
    "$(grep -cE '^ *OOHX_SYNC:' "$WF_PROD")" '0'

khang_dinh 'workflow production KHÔNG khai OOHX_SITE' \
    "$(grep -cE '^ *OOHX_SITE:' "$WF_PROD")" '0'

echo ""
echo "Tổng: $so_dung đúng, $so_sai sai."

rm -rf "$D"

[ "$so_sai" -eq 0 ] || exit 1
