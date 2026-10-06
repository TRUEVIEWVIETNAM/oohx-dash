# Định tuyến Next.js qua OpenLiteSpeed — giai đoạn 6

Cấu hình để chuyển **từng đường dẫn** của trang công khai sang Next.js, giữ nguyên tên miền và đường dẫn.

## Chuỗi phục vụ thật, đo ngày 02/10/2026

```
người dùng → Cloudflare → litespeed :443 → PHP-FPM (lsphp84) → Laravel
```

| Thành phần | Thực tế |
|---|---|
| Web server | **OpenLiteSpeed 1.8.5**, service `lsws` |
| nginx | `inactive` — các file trong `vhost/nginx/` là tàn dư |
| Caddy | **không có** |
| `nghttpx` | `127.0.0.1:3000` → `127.0.0.1:80`, proxy nội bộ |
| vhost thật | `/www/server/panel/vhost/openlitespeed/detail/oohx.net.conf` |

**Lộ trình ghi "Caddy".** Không có Caddy trên máy chủ này; sơ đồ trong lộ trình không dùng được như viết. Cấu hình ở đây theo thực tế đo được.

**Cổng 3000 đã bị chiếm** bởi `nghttpx`. Next.js dùng **3001**.

## App Next.js nằm trong repo này

Từ 03/10/2026, trang công khai trên Next.js sống ở **`webapp/`** trong chính repo `oohx-dash`, không ở repo riêng.

`oohx-webapp` cũ (repo `tuanna0703/oohx-webapp`) **không dùng**: Next 14.2.5 dừng từ 25/03, route là `/browse` `/screens` `/solutions` — không khớp `/explore` `/products` `/map`, và nó ra đời trước API v2 sáu tháng nên toàn bộ tầng gọi dữ liệu phải viết lại. Nó cũng commit `.next` vào git, 63MB.

Ba lý do đặt trong repo này:

- **Kiểu dữ liệu** sinh từ `docs/openapi/v2.yaml` ra `resources/js/types/api-v2.d.ts`. `webapp/tsconfig.json` trỏ thẳng ra đó, nên đặc tả đổi mà code gọi API không đổi theo thì `next build` đỏ. Hai repo thì phải đồng bộ file kiểu bằng tay, và không gì báo khi nó trôi.
- **CI** có sẵn. Job `Next.js (trang công khai)` trong `.github/workflows/tests.yml` sinh lại kiểu rồi build — phép kiểm duy nhất cho app đó.
- **`deploy.sh`** có sẵn, và bước `[5b/9]` build `webapp/` rồi restart service — nhưng **chỉ khi** `/etc/systemd/system/oohx-webapp.service` tồn tại, nên nó inert cho tới khi được bật.

**Stylesheet dùng chung.** `webapp/app/globals.css` nhập `resources/css/frontpage.css` — 2210 dòng CSS thuần, không directive Tailwind. Giai đoạn 6 chuyển từng đường dẫn trên cùng một tên miền, nên `/explore` (Next) và `/map` (Laravel) nằm cạnh nhau trong một lượt duyệt; hai stylesheet là hai giao diện cho cùng một trang web.

### Trạng thái từng đường dẫn

Hai cột khác nhau, và đừng đọc gộp: **dựng** là trang Next đã có và test xanh; **mở** là OpenLiteSpeed đang thật sự đưa người dùng tới đó.

| Đường dẫn | Dựng | Khai trong `nextjs.conf` | Mở trên production |
|---|---|---|---|
| `/explore`, `/explore/{slug}` | xong | có | **có** |
| `/owners`, `/owners/{slug}` | xong | có | **có** |
| `/products`, `/products/{slug}` | xong | có | **có** |
| `/map` | xong | có | **chưa** |
| 4 trang chính sách | xong | có | **chưa** |
| `/` (trang chủ) | xong | không | không |

Trang chủ không mở được bằng `context /`: OpenLiteSpeed khớp context theo **tiền tố**, nên một `context /` nuốt luôn `/api/v1`, `/cart`, `/sitemap.xml` và bốn slug chính sách. Nó cần một cách khác.

#### Vì sao cột 3 và cột 4 đang lệch nhau

`deploy.sh` bước `[11/11]` có nhiệm vụ đồng bộ `nextjs.conf` của repo sang máy chủ, nhưng log deploy ngày 06/10/2026 nói:

```
[11/11] Đồng bộ cấu hình proxy OpenLiteSpeed
Bỏ qua: /www/server/panel/vhost/openlitespeed/proxy/oohx.net chưa tồn tại — proxy chưa được dựng lần nào.
```

Nghĩa là `/explore`, `/owners`, `/products` đang chạy từ một conf dán tay **ở chỗ khác**, không phải thư mục bước này trông vào. Nên mọi thay đổi `nextjs.conf` trong repo đều không tới máy chủ, và cột 3 đi trước cột 4 vô thời hạn.

Chữa một lần, rồi nó tự đồng bộ mãi:

```sh
# 1. Tìm conf đang thật sự chạy. Đừng đoán — tìm theo cổng 3001.
grep -rl "127.0.0.1:3001" /www/server/panel/vhost/openlitespeed/ /usr/local/lsws/conf/ 2>/dev/null

# 2. Xem nó, để biết nó khai những context nào.
#    (thay ĐƯỜNG_DẪN bằng kết quả bước 1)
grep -E "^\s*(extprocessor|context)" ĐƯỜNG_DẪN

# 3. Dựng thư mục deploy.sh trông vào, và dán conf của repo vào đó.
mkdir -p /www/server/panel/vhost/openlitespeed/proxy/oohx.net
cp /www/wwwroot/dash.oohx.net/docs/deploy/nextjs-proxy/nextjs.conf \
   /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf

# 4. XOÁ conf cũ ở bước 1. Giữ cả hai là hai file khai trùng `extprocessor nextjs`
#    — OpenLiteSpeed nạp song song và hành vi không đoán được.
#    Đổi tên thành .bak chứ không xoá hẳn, và .bak KHÔNG khớp *.conf nên nó
#    không còn được nạp.
mv ĐƯỜNG_DẪN ĐƯỜNG_DẪN.truoc-dong-bo

# 5. Kiểm vhost có nạp thư mục mới không. Nếu dòng include này không có thì
#    bước 3 vô nghĩa và phải thêm nó vào detail/oohx.net.conf.
grep -n "proxy/oohx.net" /www/server/panel/vhost/openlitespeed/detail/oohx.net.conf

/usr/local/lsws/bin/lswsctrl restart
```

Và cấp quyền sudo cho `deploy` để bước `[11/11]` ghi được. Bảy dòng dưới đây là **nguyên văn** những gì `deploy.sh` in ra khi thiếu quyền — nếu log deploy in khác thì tin log, không tin file này:

```sh
visudo -f /etc/sudoers.d/oohx-deploy
```
```
deploy ALL=(root) NOPASSWD: /usr/bin/cp docs/deploy/nextjs-proxy/nextjs.conf /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf
deploy ALL=(root) NOPASSWD: /usr/bin/cp /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf.truoc-deploy
deploy ALL=(root) NOPASSWD: /usr/bin/cp /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf.truoc-deploy /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf
deploy ALL=(root) NOPASSWD: /bin/rm -f /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf
deploy ALL=(root) NOPASSWD: /bin/rm -f /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf.truoc-deploy
deploy ALL=(root) NOPASSWD: /usr/local/lsws/bin/lswsctrl restart
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl restart oohx-webapp
```

Dòng đầu có đường dẫn **tương đối** (`docs/deploy/...`), và đó không phải lỗi gõ: `deploy.sh` gọi `sudo -n cp "$REPO_CONF" ...` với `REPO_CONF="docs/deploy/nextjs-proxy/nextjs.conf"`, và sudoers so khớp đối số đúng như lúc gọi. Viết thành đường dẫn tuyệt đối thì luật không khớp và bước đó vẫn bị từ chối.

### Header và chân trang: đã dựng lại

Dựng ở lát 2 (PR #9): `webapp/components/SiteHeader.tsx` và `SiteFooter.tsx`.

`TRONG_APP` ở `webapp/lib/duong-dan.ts` liệt kê những đường **đang được proxy**, và `AppLink` dùng nó để chọn `<Link>` (điều hướng trong app) hay `<a>` (tải lại cả trang). Cả thanh điều hướng và chân trang đi qua `AppLink`, nên mở thêm một context thì chỉ sửa một danh sách.

Lệch theo chiều nào cũng sai, nhưng **hai kiểu khác nhau**:

| Lệch | Hệ quả |
|---|---|
| Có trong `TRONG_APP`, proxy chưa mở | `<Link>` hiện trang Next, tải lại cùng URL ra trang Blade — **hai trang cho một URL**, cả hai đều 200 nên không gì báo |
| Proxy đã mở, thiếu trong `TRONG_APP` | `<a>` tải lại cả trang cho một điều hướng nội bộ. Chậm hơn, không sai |

Chiều thứ nhất **đã xảy ra**: `/map` nằm trong `TRONG_APP` từ PR #11 trong khi proxy chưa mở nó. Nên luật này giờ có phép kiểm — `webapp/test/seo.mjs::kiemKhopConf()` đọc thẳng `nextjs.conf` và so, chứ không chỉ là một dòng ghi chú.

## Cài đặt

### 1. Ứng dụng Next.js

App nằm trong repo nên đã có sẵn trên máy chủ sau lần deploy đầu tiên.

```sh
cd /www/wwwroot/dash.oohx.net/webapp
sudo -u deploy -H npm ci
sudo -u deploy -H npm run build

# Kiểm chạy được TRƯỚC khi dựng service.
sudo -u deploy -H env \
  OOHX_API_BASE=https://oohx.net/api/v2 \
  OOHX_PUBLIC_ORIGIN=https://oohx.net \
  npx next start --port 3001 --hostname 127.0.0.1 &

sleep 5
curl -s -o /dev/null -w "/explore → %{http_code}\n" http://127.0.0.1:3001/explore
curl -s http://127.0.0.1:3001/explore | grep -c 'rel="canonical"'   # phải 1
kill %1
```

### Hai biến môi trường, và vì sao cả hai đều cần

**`OOHX_API_BASE` phải trỏ vào tên miền, không phải `127.0.0.1`.**

Bản đầu của tài liệu này đặt `http://127.0.0.1/api/v2` với lý lẽ "gọi nội bộ,
không vòng ra ngoài". Đo trên máy chủ ngày 03/10/2026: mọi lời gọi trả **403**
kèm `body: null` — không phải envelope lỗi của Laravel mà là OpenLiteSpeed
chặn ở tầng ngoài. Yêu cầu tới `127.0.0.1` mang `Host: 127.0.0.1`, không khớp
virtual host `oohx.net`, nên nó **không vào tới Laravel lần nào**. Trang trả
500.

Cách chữa hiển nhiên — tự đặt `Host: oohx.net` khi gọi — **không làm được**:
`fetch` của Node bỏ qua header `Host` do người gọi đặt và luôn gửi host của
URL. Đã thử cả `Host` và `host`; cả ba lần máy chủ nhận đúng
`127.0.0.1:<cổng>`.

Nên trỏ vào tên miền thật, và cho tên đó phân giải về chính máy chủ:

```sh
grep -q 'oohx.net' /etc/hosts || echo '127.0.0.1  oohx.net' >> /etc/hosts

# Kiểm: phải ra 200 và KHÔNG đi qua Cloudflare
curl -s -o /dev/null -w 'api noi bo → %{http_code}\n' https://oohx.net/api/v2/stats
curl -sI https://oohx.net/api/v2/stats | grep -ci 'cf-ray'    # phải 0
```

Khi đó `Host` và SNI đều đúng, kết nối vẫn không ra khỏi máy, và không vòng
qua Cloudflare — không thêm độ trễ, không thêm điểm hỏng, không tính vào hạn
mức tần suất của người dùng thật.

Không thêm dòng `/etc/hosts` thì vẫn chạy, chỉ là mỗi lần dựng trang đi ra
Cloudflare rồi quay lại.

**`OOHX_PUBLIC_ORIGIN` dựng canonical, og:url và og:image.** Phải khai riêng:
nếu canonical dựng từ `OOHX_API_BASE` thì khi biến đó trỏ nội bộ, mọi trang sẽ
phát `<link rel="canonical" href="http://127.0.0.1/...">` — một URL không ai
ngoài máy chủ mở được, và công cụ tìm kiếm coi đó là địa chỉ chuẩn của trang.
Trang vẫn hiện bình thường, nên không ai thấy.

Chạy bằng user `deploy`, không phải root: đó là user GitHub Actions deploy bằng, và `npm ci` chạy bằng root sẽ để lại `node_modules` thuộc root — lần deploy tự động sau đó không ghi được.

### 2. Service systemd

```sh
cp docs/deploy/nextjs-proxy/oohx-webapp.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now oohx-webapp
systemctl is-active oohx-webapp

# sleep TRƯỚC khi curl, và nó không phải tuỳ chọn.
#
# `systemctl is-active` trả `active` ngay khi systemd đã fork tiến trình, CHƯA
# phải khi tiến trình mở cổng. Gọi curl ngay sau `enable --now` cho `000` —
# không phải mã lỗi HTTP mà là không có kết nối nào.
#
# Ngày 03/10/2026 tôi mất một vòng chẩn đoán vì đúng chỗ này: thấy `active` mà
# `000` nên đi tìm đường dẫn `node` sai trong unit, trong khi service hoàn toàn
# bình thường và chỉ cần thêm vài giây.
sleep 5
curl -s -o /dev/null -w "qua service → %{http_code}\n" http://127.0.0.1:3001/explore
```

Ra `000` dù đã chờ thì mới cần đào, và chỗ nói rõ nhất là log:

```sh
journalctl -u oohx-webapp -n 40 --no-pager
readlink -f "$(command -v node)"    # phải khớp ExecStart trong unit
ss -ltnp | grep 3001
```

Chưa ra 200 thì **dừng**. Chưa chạy được qua localhost thì thêm proxy chỉ làm trang công khai hỏng.

### 3. Proxy — mở một đường, kiểm, rồi mới mở tiếp

```sh
mkdir -p /www/server/panel/vhost/openlitespeed/proxy/oohx.net
cp docs/deploy/nextjs-proxy/nextjs.conf \
   /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf

/usr/local/lsws/bin/lswsctrl restart
```

Kiểm **từ ngoài vào**, không phải từ máy chủ:

```sh
curl -s -o /dev/null -w "/explore        → %{http_code} %{time_total}s\n" https://oohx.net/explore
curl -s -o /dev/null -w "/               → %{http_code} %{time_total}s\n" https://oohx.net/
curl -s -o /dev/null -w "/products       → %{http_code} %{time_total}s\n" https://oohx.net/products
curl -s -o /dev/null -w "/api/v2/stats   → %{http_code} %{time_total}s\n" https://oohx.net/api/v2/stats
curl -s -o /dev/null -w "/sitemap.xml    → %{http_code} %{time_total}s\n" https://oohx.net/sitemap.xml
```

Bốn đường sau **phải vẫn do Laravel phục vụ**. `/explore` ra 200 từ Next.js thì mới mở `/owners`, `/products`, `/map` — mỗi lần một đường, bỏ dấu `#` trong `nextjs.conf`.

### 3b. Bốn trang chính sách — mở riêng, không mở cùng nhóm khác

Trang Next đã dựng (`webapp/app/(chinh-sach)/[slug]/page.tsx`). Nó **không** chứa văn bản: phần thân lấy từ `GET /api/v2/policies/{slug}`, và endpoint đó render đúng partial Blade mà trang Laravel render (`resources/views/frontpage/policies/bodies/*.blade.php`). Một nguồn, một bộ render — `PublicContentApiTest::test_than_van_ban_la_dung_phan_than_trang_blade_phat_ra` so `body_html` với HTML trang Blade thật sự phát ra.

Bốn context đã **khai** trong `nextjs.conf` (duyệt ngày 06/10/2026), và `TRONG_APP` ở `webapp/lib/duong-dan.ts` đã khớp — có phép kiểm đọc thẳng file conf nên hai bên không lệch được mà CI vẫn xanh.

Còn lại là đưa conf lên máy chủ (xem "Vì sao cột 3 và cột 4 đang lệch nhau" ở trên). Sau khi lên, kiểm **nội dung**, không chỉ mã trạng thái:

```sh
# Sau khi bỏ dấu # cho MỘT khối rồi copy conf + lswsctrl restart:
curl -s https://oohx.net/quy-che-hoat-dong | grep -c 'class="pol-body"'   # phải 1
curl -s https://oohx.net/quy-che-hoat-dong | grep -c 'class="pol-draft"'  # 1 khi còn nháp, 0 khi đã ban hành
curl -s https://oohx.net/quy-che-hoat-dong | grep -c '&lt;h2&gt;'         # phải 0 — thân bị escape thì ra chữ

# Ba đường này PHẢI vẫn là Laravel sau khi mở:
curl -s -o /dev/null -w "/cart        → %{http_code}\n" https://oohx.net/cart
curl -s -o /dev/null -w "/sitemap.xml → %{http_code}\n" https://oohx.net/sitemap.xml
curl -s -o /dev/null -w "/phan-anh-to-chuc-xa-hoi → %{http_code}\n" https://oohx.net/phan-anh-to-chuc-xa-hoi
```

Vì sao nhóm này được duyệt riêng chứ không đi kèm `/map`: văn bản pháp lý được đóng dấu `version` vào **từng bản ghi đồng ý** của người dùng. Một lỗi hiển thị ở đây không phải lỗi giao diện — nó là chữ mà người dùng được coi là đã đồng ý.

Ba trong bốn văn bản hiện vẫn là **bản nháp** (`effective_from = null`), và trang Next hiện ô `pol-draft` đúng cho chúng. Phép kiểm `test:seo` canh ô đó **cả hai chiều** — có khi còn nháp, không có khi đã ban hành. Chỉ canh một chiều thì một trang luôn hiện cảnh báo vẫn qua được, và nó nói sai về văn bản đã có hiệu lực.

### 4. Lùi lại

```sh
rm /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf
/usr/local/lsws/bin/lswsctrl restart
```

Toàn bộ quay về Laravel. Không mất dữ liệu, không migration nào phải lùi.

## Sau khi chuyển: đối chiếu SEO

`tests/Feature/Frontpage/SeoBaselineTest.php` là mốc chụp ngày 02/10/2026 — điều kiện hoàn thành giai đoạn 6 theo lộ trình là *"SEO không tụt, sitemap và canonical giữ nguyên"*, và lớp đó biến nó thành phép kiểm chạy được.

Bản Next.js phải giữ, cho mỗi đường dẫn đã chuyển:

- `<link rel="canonical">` trỏ đúng chính nó
- `<meta name="description">` **không rỗng**
- `og:title`, `og:image`, `og:url`, `twitter:card`
- JSON-LD là **JSON hợp lệ**, có `@context` và `@type`

`sitemap.xml` và `robots.txt` **vẫn do Laravel sinh** — không proxy chúng. Chúng liệt kê đường dẫn, không phụ thuộc ai render trang.

## Hai điều đáng biết trước

**aaPanel có thể ghi đè.** `detail/oohx.net.conf` do panel sinh ra. File `nextjs.conf` nằm trong thư mục `proxy/oohx.net/` được include nên panel không chạm tới — nhưng nếu panel xoá cả thư mục đó khi bạn đổi cài đặt site, proxy mất **không báo gì**. Cách phát hiện:

```sh
test -f /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf \
  && echo "proxy còn" || echo "PROXY ĐÃ MẤT — chép lại từ repo"
```

Đáng đặt vào cron hoặc kiểm sau mỗi lần sửa site trong panel.

**`autoLoadHtaccess 1`** đang bật trong vhost, nên `.htaccess` của Laravel vẫn chạy. Context proxy được khớp trước luật rewrite, nhưng đây là chỗ hai cơ chế gặp nhau — nên bước 3 mở **một** đường và kiểm ngay, thay vì mở cả nhóm rồi đi tìm nguyên nhân.
