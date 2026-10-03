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

### Đã chuyển

| Đường dẫn | Trạng thái |
|---|---|
| `/explore` | xong — danh sách, phân trang bằng thẻ `<a>` |
| `/explore/{slug}` | xong — canonical, og:type=product, JSON-LD Product, 404 thật cho slug lạ |
| `/owners`, `/owners/{slug}` | chưa |
| `/products`, `/products/{slug}` | chưa |
| `/map` | chưa |
| `/` (trang chủ) | chưa |
| 4 trang chính sách | **chuyển cuối cùng**, giữ nguyên văn bản |

### Chưa dựng lại header/footer, có chủ ý

`webapp/app/layout.tsx` không có thanh điều hướng. Dựng lại mà lệch một chữ so với `resources/views/frontpage/layouts/app.blade.php` thì người dùng thấy header nhảy khi bấm từ `/explore` sang `/map` — đúng kiểu lỗi lộ trình gọi là "hai giao diện lệch hành vi". Việc đó làm một lần cho tất cả trang đã chuyển, và phải đối chiếu HTML với bản Blade.

## Cài đặt

### 1. Ứng dụng Next.js

App nằm trong repo nên đã có sẵn trên máy chủ sau lần deploy đầu tiên.

```sh
cd /www/wwwroot/dash.oohx.net/webapp
sudo -u deploy -H npm ci
sudo -u deploy -H npm run build

# Kiểm chạy được TRƯỚC khi dựng service.
# Hai biến đều cần: OOHX_API_BASE để gọi dữ liệu, OOHX_PUBLIC_ORIGIN để dựng
# canonical — thiếu biến sau thì mọi trang phát
# <link rel="canonical" href="http://127.0.0.1/...">, một URL không ai mở được.
sudo -u deploy -H env \
  OOHX_API_BASE=http://127.0.0.1/api/v2 \
  OOHX_PUBLIC_ORIGIN=https://oohx.net \
  npx next start --port 3001 --hostname 127.0.0.1 &

curl -s -o /dev/null -w "/explore → %{http_code}\n" http://127.0.0.1:3001/explore
curl -s http://127.0.0.1:3001/explore | grep -c 'rel="canonical"'   # phải 1
kill %1
```

Chạy bằng user `deploy`, không phải root: đó là user GitHub Actions deploy bằng, và `npm ci` chạy bằng root sẽ để lại `node_modules` thuộc root — lần deploy tự động sau đó không ghi được.

### 2. Service systemd

```sh
cp docs/deploy/nextjs-proxy/oohx-webapp.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now oohx-webapp
systemctl status oohx-webapp --no-pager
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:3001/explore
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
