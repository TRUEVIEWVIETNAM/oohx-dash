#!/bin/bash
# Thử `don_cache_next()` của deploy.sh — hàm dọn cache build của Next.
#
# Vì sao hàm này đáng một bộ thử riêng: nó chạy `rm -rf` trên một thư mục
# nằm trong cây ứng dụng production, mỗi lần deploy. Một `find` viết lệch
# một ký tự và nó xoá cả `.next/` chứ không chỉ `.next/cache/` — tức xoá
# luôn bản build vừa dựng, và service khởi động lại trên một thư mục rỗng.
#
# Ba điều phải đúng:
#
#  1. Cache ảnh (`images`) PHẢI còn. Nó là thứ duy nhất trong cache đắt để
#     dựng lại và không liên quan gì tới mã nguồn.
#  2. Mọi thứ khác PHẢI đi, kể cả tệp ẩn. `rm -rf cache/*` bỏ sót
#     `.rscinfo`, `.previewinfo`, `.tsbuildinfo` — cả ba có thật trên máy
#     chủ, hai cái mang dấu thời gian cũ hơn lần build gần nhất.
#  3. Không gì NGOÀI `cache/` được đụng tới.
#
# Lấy đúng hàm ra khỏi script thật (từ `don_cache_next() {` tới `}` ở cột 0
# đầu tiên) chứ không chép lại — chép lại thì thử một bản khác.

set -u

SRC="deploy.sh"
TMP="${TMPDIR:-/tmp}/don-cache-$$.sh"

sed -n '/^don_cache_next() {/,/^}/p' "$SRC" > "$TMP"

bash -n "$TMP" || { echo "HAM TRICH RA KHONG HOP LE"; exit 1; }
# shellcheck disable=SC1090
. "$TMP"

D="${TMPDIR:-/tmp}/don-cache-thu-$$"
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

# Dựng lại đúng hình dạng đo được trên máy chủ ngày 18/10/2026.
dung_cay() {
    rm -rf w
    mkdir -p w/.next/cache/images/abc \
             w/.next/cache/fetch-cache \
             w/.next/cache/turbopack/v16.3.8-b0fad0d4 \
             w/.next/server/app
    echo anh        > w/.next/cache/images/abc/0.webp
    echo du-lieu    > w/.next/cache/fetch-cache/deadbeef
    echo bien-dich  > w/.next/cache/turbopack/v16.3.8-b0fad0d4/00000648.sst
    echo an1        > w/.next/cache/.rscinfo
    echo an2        > w/.next/cache/.previewinfo
    echo an3        > w/.next/cache/.tsbuildinfo
    echo trang      > w/.next/server/app/index.html
    echo id         > w/.next/BUILD_ID
}

co() { [ -e "$1" ] && echo co || echo khong; }

echo "Thử don_cache_next() trích từ $SRC"
echo

dung_cay
don_cache_next w >/dev/null

khang_dinh 'giữ lại cache ảnh'          "$(co w/.next/cache/images/abc/0.webp)" 'co'
khang_dinh 'xoá cache fetch'            "$(co w/.next/cache/fetch-cache)"       'khong'
khang_dinh 'xoá cache turbopack'        "$(co w/.next/cache/turbopack)"         'khong'

# Ba tệp ẩn: lý do dùng `find` chứ không `rm -rf cache/*`.
khang_dinh 'xoá cả .rscinfo (tệp ẩn)'      "$(co w/.next/cache/.rscinfo)"      'khong'
khang_dinh 'xoá cả .previewinfo (tệp ẩn)'  "$(co w/.next/cache/.previewinfo)"  'khong'
khang_dinh 'xoá cả .tsbuildinfo (tệp ẩn)'  "$(co w/.next/cache/.tsbuildinfo)"  'khong'

# Nhánh nguy hiểm nhất: không được chạm gì ngoài `cache/`.
khang_dinh 'KHÔNG đụng bản prerender' "$(co w/.next/server/app/index.html)" 'co'
khang_dinh 'KHÔNG đụng BUILD_ID'      "$(co w/.next/BUILD_ID)"              'co'
khang_dinh 'thư mục cache vẫn còn'    "$(co w/.next/cache)"                 'co'

# Chạy hai lần liên tiếp phải cho cùng kết quả — deploy có thể chạy lại.
don_cache_next w >/dev/null
khang_dinh 'chạy lần hai vẫn giữ cache ảnh' "$(co w/.next/cache/images/abc/0.webp)" 'co'
khang_dinh 'chạy lần hai không hỏng'        "$(co w/.next/BUILD_ID)"                'co'

# Không có cache thì im lặng đi qua, không được trả mã lỗi: `set -e` ở đầu
# deploy.sh sẽ giết cả script giữa lúc site đang ở maintenance mode.
rm -rf w && mkdir -p w/.next
don_cache_next w >/dev/null
khang_dinh 'chưa có cache thì trả 0' "$?" '0'
khang_dinh 'chưa có cache thì không tạo gì' "$(co w/.next/cache)" 'khong'

# Thư mục ứng dụng chưa có luôn — cùng lý do.
rm -rf w
don_cache_next w >/dev/null
khang_dinh 'không có cả .next thì trả 0' "$?" '0'

cd / || exit 1
rm -rf "$D" "$TMP"

echo
echo "  $so_dung đúng, $so_sai sai"
[ "$so_sai" -eq 0 ] || exit 1
