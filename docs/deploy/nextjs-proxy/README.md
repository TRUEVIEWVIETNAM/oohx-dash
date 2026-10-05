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

| Đường dẫn | Dựng | Mở trên production |
|---|---|---|
| `/explore`, `/explore/{slug}` | xong | **có** |
| `/owners`, `/owners/{slug}` | xong | **có** |
| `/products`, `/products/{slug}` | xong | **có** |
| `/map` | xong | khai trong `nextjs.conf`, chờ dán lên máy chủ |
| `/` (trang chủ) | xong | **không** — xem ghi chú `context /` dưới |
| 4 trang chính sách | xong | **không** — xem mục riêng dưới |

Trang chủ không mở được bằng `context /`: OpenLiteSpeed khớp context theo **tiền tố**, nên một `context /` nuốt luôn `/api/v1`, `/cart`, `/sitemap.xml` và bốn slug chính sách. Nó cần một cách khác.

### Header và chân trang: đã dựng lại

Dựng ở lát 2 (PR #9): `webapp/components/SiteHeader.tsx` và `SiteFooter.tsx`.

Một chỗ trong `SiteHeader.tsx` cần biết: `const TRONG_APP = new Set([...])` liệt kê những đường **đang được proxy**, và nó quyết định một mục nav dùng `<Link>` (điều hướng trong app, nhanh) hay `<a>` (tải lại cả trang). Mở thêm một context mà quên sửa set này thì người dùng vẫn tới đúng trang, chỉ là chậm hơn cần thiết — một lỗi không ai báo. Sửa cùng lượt với `nextjs.conf`.

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

Bốn context để **đóng** trong `nextjs.conf`, dạng comment. Mở từng khối một, và sau mỗi khối kiểm **nội dung**, không chỉ mã trạng thái:

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

Vì sao nhóm này mở riêng chứ không đi kèm `/map`: văn bản pháp lý được đóng dấu `version` vào **từng bản ghi đồng ý** của người dùng. Một lỗi hiển thị ở đây không phải lỗi giao diện — nó là chữ mà người dùng được coi là đã đồng ý. Mở khi có người xem được kết quả, không trong một lượt deploy tự động.

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
