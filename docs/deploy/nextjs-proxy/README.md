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

## Điều kiện tiên quyết, chưa đạt

Cấu hình này **chưa kích hoạt được**, vì chưa có ứng dụng Next.js phục vụ `/explore`.

`oohx-webapp` (repo `tuanna0703/oohx-webapp`) là một app Next.js nhưng:

- Next **14.2.5**, đẩy lần cuối 25/03/2026
- Route là `/browse`, `/screens`, `/solutions`, `/about`, `/how-it-works` — **không khớp** đường dẫn đang chạy (`/explore`, `/products`, `/map`)
- Ra đời 21/03, tức **sớm hơn API v2 sáu tháng**, nên chưa nối vào hợp đồng ở `docs/openapi/v2.yaml`
- `.next` (build output) bị commit vào git, 63MB

`CLAUDE.md` mục 3: *"Giữ nguyên đường dẫn cũ khi thay trang Blade"*. Nên phần route và phần gọi dữ liệu phải viết lại trước khi proxy có nghĩa.

## Cài đặt

### 1. Ứng dụng Next.js

```sh
# Đặt tại /www/wwwroot/oohx-webapp, thuộc deploy:www
cd /www/wwwroot/oohx-webapp
npm ci
npm run build

# Kiểm chạy được TRƯỚC khi dựng service
PORT=3001 HOSTNAME=127.0.0.1 npm start &
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:3001/explore   # phải 200
kill %1
```

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
