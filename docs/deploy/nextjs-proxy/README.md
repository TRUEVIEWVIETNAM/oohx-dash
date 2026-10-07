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
| `/map` | xong | có | **có** (06/10/2026) |
| 4 trang chính sách | xong | có | **có** (06/10/2026) |
| 2 trang phản ánh TCXH | xong | có | chờ deploy |
| `/login`, `/register` | xong | có | chờ deploy |
| `/agency` | xong | có | chờ deploy |
| `/` (trang chủ) | xong | luật rewrite | **có** (07/10/2026) |

Trang chủ không mở bằng `context` mà bằng một luật rewrite khớp đúng một đường dẫn — xem mục "Trang chủ: luật rewrite, không phải `context`" dưới đây.

#### Đã đóng vòng — 06/10/2026

Hai cột trên khớp nhau từ 06/10/2026. Trước đó chúng lệch suốt từ PR #11, và nguyên nhân mất ba vòng chẩn đoán mới ra, nên ghi lại cả đường đi:

| Tưởng là | Thật ra |
|---|---|
| `deploy.sh` nói thư mục proxy *"chưa tồn tại"* | Thư mục **đang có**, cùng `nextjs.conf` đang chạy. `[ -d ]` trả sai khi user deploy không duyệt được thư mục cha — nó không phân biệt "không có" với "không đọc được" |
| Chẩn đoán nói *"KHÔNG tìm thấy conf"* | `Permission denied`. Cấu hình OpenLiteSpeed là root-only với user CI |
| `detail/oohx.net.conf.txt` là conf cạnh tranh | aaPanel giữ `.conf.txt` cho trình soạn thảo. OpenLiteSpeed không nạp nó |

Cả ba đều là **một suy luận được trình bày như một quan sát**. Phép kiểm thì đúng; câu thông báo mới là chỗ sai. Giờ `deploy.sh` nói "không chạy được từ user này" thay vì "không tồn tại", và chẩn đoán thử lại bằng `sudo` để phân biệt hai khả năng.

Chuỗi hoàn chỉnh sau khi cài: đổi `nextjs.conf` → merge → `deploy.sh [11/11]` → `sudo -n oohx-sync-proxy` → kiểm danh sách trắng → dán → `lswsctrl restart` → canary → xong (hoặc tự lùi).

#### Cloudflare viết lại HTML — đừng so byte qua CDN

Cloudflare bật **Email Address Obfuscation**: mọi `mailto:` trong HTML trả về bị thay bằng `/cdn-cgi/l/email-protection#...` kèm `<span class="__cf_email__">`.

Nó không chạm vào JSON, nên một phép so `body_html` (từ API) với HTML trang (qua CDN) sẽ **trượt** ở đúng những chỗ có email — và trượt vì CDN, không vì Laravel và Next lệch nhau.

So ở **origin** thì khớp nguyên văn:

```sh
curl -sk --resolve oohx.net:443:<IP-origin> https://oohx.net/bang-phi
curl -s  --resolve oohx.net:443:<IP-origin> https://oohx.net/api/v2/policies/bang-phi
```

Đã đo 06/10/2026: HTML trang chứa nguyên văn 2309 ký tự `body_html` của API. Qua Cloudflare thì khớp 2212 ký tự đầu rồi lệch ở `mailto:`.

### Cài `oohx-sync-proxy` — một lần, cần root

Sau bước này, mọi thay đổi `nextjs.conf` về sau đi qua CI. Không cần mở SSH nữa.

```sh
# 1. Cài script. Nó thuộc root và user deploy KHÔNG ghi được — xem "Ranh giới
#    quyền" dưới đây để biết vì sao điều đó quan trọng.
install -o root -g root -m 0755 \
    /www/wwwroot/dash.oohx.net/docs/deploy/nextjs-proxy/oohx-sync-proxy.sh \
    /usr/local/sbin/oohx-sync-proxy

# 2. Một dòng sudoers. Thay <user> bằng user mà CI đăng nhập — deploy.sh in
#    sẵn dòng đầy đủ khi thiếu quyền, và khối 1 của workflow chẩn đoán cũng
#    in tên user đó.
#    Hai dấu nháy rỗng ở cuối KHÔNG phải lỗi gõ. Trong sudoers, một lệnh không
#    kèm đối số nghĩa là CHO PHÉP MỌI ĐỐI SỐ; dấu "" là cách viết "đúng không
#    đối số nào". Script cũng tự từ chối đối số, nên đây là lớp thứ hai.
echo '<user> ALL=(root) NOPASSWD: /usr/local/sbin/oohx-sync-proxy ""' \
    > /etc/sudoers.d/oohx-sync-proxy
chmod 440 /etc/sudoers.d/oohx-sync-proxy
visudo -c

# 3. Chạy thử ngay, as root. Nó tự lùi lại nếu canary đỏ.
/usr/local/sbin/oohx-sync-proxy
```

Bước 3 làm những việc sau, theo đúng thứ tự đó, và **dừng trước khi đổi gì** nếu một phép kiểm trượt:

| Bước | Dừng ở đây nghĩa là |
|---|---|
| Kiểm conf repo theo danh sách trắng | conf có directive không cho phép — không dán gì |
| So với bản đang chạy | không đổi thì thoát, không nạp lại web server vô ích |
| Kiểm vhost có `include` thư mục proxy | **làm trước khi cách ly conf cũ** — thiếu bước này mà đã cách ly thì không còn conf nào có tác dụng, tức tự tay làm `/explore` rơi về Laravel |
| Kiểm đường canary dùng được (`/sitemap.xml` → 200) | không kiểm được thì không dán. Bỏ bước này thì một máy không probe được qua `--resolve` sẽ làm canary đỏ **toàn bộ**, và script lùi lại một thay đổi hoàn toàn đúng — tệ hơn cả không làm gì, vì log đọc như thể conf mới có vấn đề |
| Tìm conf lạ đang trỏ `127.0.0.1:3001` | nếu file đó **không** phải conf proxy thuần (ai đã dán context thẳng vào file vhost) thì script **dừng** — đổi tên file đó sẽ làm sập site |
| Dán + nạp lại + canary | canary đỏ → tự lùi lại, nạp lại, thoát khác 0 |

### Trang chủ: luật rewrite, không phải `context`

`context` của OpenLiteSpeed khớp theo **tiền tố**, nên `context /` là một luật bắt tất cả — nó nuốt `/api/v1` (hợp đồng với đối tác), `/cart`, `/sitemap.xml`, `/livewire`, và mọi đường chưa ai nghĩ tới. Cách duy nhất để dùng nó là liệt kê đủ đường Laravel bằng context dài hơn, và "liệt kê đủ" là thứ không ai bảo đảm được.

Trang chủ thì là **một đường dẫn chính xác**, nên nó dùng một luật neo hai đầu:

```
RewriteRule ^/?$ http://nextjs/ [P]
```

`^/?$` khớp đúng `/` và không khớp gì khác. Đây không phải "an toàn hơn" — nó là một loại luật khác, và nó không cần biết Laravel có những đường nào.

| Cách mở | Khớp | Dùng cho |
|---|---|---|
| `context /x` trong `nextjs.conf` | tiền tố — `/x` và mọi `/x/...` | một nhóm đường: `/explore`, `/owners`, … |
| `RewriteRule` trong `urlrewrite-nextjs.conf` | đúng một đường | trang chủ |

#### Hai thư mục, hai chỗ nạp — đừng dán nhầm

```
detail/oohx.net.conf
  ├─ rewrite {
  │    include .../proxy/oohx.net/urlrewrite/*.conf   ← luật rewrite TRẦN
  │  }
  └─ include .../proxy/oohx.net/*.conf                ← extprocessor + context
```

File rewrite nằm **trong** khối `rewrite { }`, nên nội dung của nó là directive trần — bọc thêm `rewrite { }` là lỗi cú pháp, và OpenLiteSpeed hỏng cả khối chứ không bỏ qua một dòng. File proxy nằm ở cấp vhost, nên nó chứa `extprocessor` và `context`.

`oohx-sync-proxy` v3 đồng bộ **cả hai**, và kiểm `include` của cả hai trước khi dán. Thiếu dòng include nào thì file tương ứng dán vào im lặng không có tác dụng.

#### Danh sách trắng cho luật rewrite chặt hơn cho context

Một `RewriteRule` sai nguy hiểm hơn một `context` sai: `context /api` đọc bằng mắt còn thấy nó bắt gì, còn

```
RewriteRule ^/(.*)$ http://nextjs/$1 [P]
```

trông gần giống luật trang chủ nhưng nuốt **toàn bộ** site, và khác biệt chỉ vài ký tự. Nên `kiem_rewrite` đòi:

- neo **hai đầu** (`^` và `$`) — không neo là quay lại đúng vấn đề của `context /`;
- không có ký tự biểu thức bắt rộng (`.` `*` `+` `(` `)` `|` `[` `]`), trừ đúng cụm `/?` của luật trang chủ;
- đích phải là `http://nextjs/…` — tên external app, không phải địa chỉ. Viết thẳng IP cũng chạy, nhưng khi ấy cổng bị khai hai nơi và hai nơi sẽ trôi khỏi nhau;
- cờ đúng `[P]`;
- `RewriteCond`, biến, backreference **không** được phép. Chúng cần cho luật phức tạp, mà luật phức tạp không nên nằm trong một file được dán tự động bởi một lượt merge.

`tests/shell/thu-kiem-conf.sh` phần 3 chạy 22 trường hợp cho riêng phần này, trong đó ba trường hợp là đúng những biến thể nuốt cả site.

#### Canary phải biết về luật rewrite

Canary của v2 chỉ duyệt các dòng `context`. Một luật rewrite không có tác dụng vì thế vẫn qua được — trang chủ lặng lẽ ra Laravel và deploy báo xanh. v3 gộp hai nguồn, và với mỗi đường nó đòi **200 và đến từ Next.js**, không chỉ 200.

### Hai nhóm `/api/v2`, hai mức yêu cầu

| Nhóm | Middleware | CSRF | Ví dụ |
|---|---|---|---|
| Công khai | chỉ `throttle` | **không** | `/screens`, `/reflections`, `/policies` |
| Xác thực | `EnsureFrontendRequestsAreStateful` | **có** | `/auth/login`, `/auth/register`, `/auth/logout` |
| Cần quyền | thêm `auth:sanctum` | **có** | `/me`, `/campaigns`, `/payments` |

Phân chia này có lý do cho từng nhóm, không phải một mặc định:

- Nhóm **xác thực** tạo phiên. Một endpoint tạo phiên mà không có CSRF là một endpoint người khác đăng nhập hộ được.
- Nhóm **công khai** không tạo phiên. Bắt nó đi qua CSRF là buộc biểu mẫu phản ánh phải lấy cookie trước khi gửi, đổi lại không được gì.
- `EnsureFrontendRequestsAreStateful` gắn ở **từng nhóm**, không gọi `statefulApi()` ở `bootstrap/app.php`. Gọi ở đó là thêm middleware phiên và CSRF cho **toàn bộ** `/api/*`, tức đụng vào `/api/v1` — hợp đồng đang chạy với đối tác, và CLAUDE.md mục 1 cấm đổi hành vi của nó.

Bên gọi nhóm xác thực phải: `GET /sanctum/csrf-cookie`, rồi POST kèm header `X-XSRF-TOKEN` mang **giá trị đã giải mã** của cookie. Gửi nguyên văn (còn URL-encode) thì nhận **419** — một mã không nói gì về nguyên nhân, và người đọc sẽ đi tìm lỗi ở thông tin đăng nhập.

#### `/logout` KHÔNG được proxy

`/login` và `/register` là trang người dùng mở, đã dựng trên Next. `/logout` thì là một route **POST** từ khu người mua trên Blade — proxy nó sang Next là trả 405 cho nút đăng xuất. Nó ở lại danh sách cấm của `oohx-sync-proxy` (v4), và đó là lý do danh sách ấy bỏ hai đường kia mà giữ đường này.

### aaPanel giữ nhiều bản sao — chỉ `*.conf` được nạp

Đo ngày 06/10/2026, chuỗi nạp thật của OpenLiteSpeed:

```
/usr/local/lsws/conf/httpd_config.conf  (dòng 256)
  └─ include /www/server/panel/vhost/openlitespeed/*.conf
       └─ oohx.net.conf                  ← cấp TRÊN, không phải detail/
            └─ configFile → detail/oohx.net.conf
                 └─ include proxy/oohx.net/*.conf   (dòng 67)
                      └─ nextjs.conf     ← file sống
```

Cạnh mỗi file đó, aaPanel giữ `.conf.txt` (bản cho trình soạn thảo của panel), `.conf0` và `.conf0,v` (bản sao lưu/phiên bản), `.conf.backup`. **Không bản nào được nạp** — glob là `*.conf`, và `detail/` chỉ vào được qua `configFile` trỏ đích danh.

Điều này đã làm `oohx-sync-proxy` v1 dừng nhầm ngay lần chạy đầu: nó tìm mọi file chứa `127.0.0.1:3001`, bắt được `detail/oohx.net.conf.txt`, thấy file đó có directive ngoài danh sách trắng và từ chối đi tiếp. v2 lọc theo đuôi tên, và `tests/shell/thu-kiem-conf.sh` phần 2 canh đúng chuyện đó — mẫu lọc nó dùng được **đọc ra từ script thật**.

Một hệ quả cần biết: `detail/oohx.net.conf.txt` hiện vẫn còn bản sao **cũ** của khối proxy (4 context, thiếu `/map` và nhóm chính sách). Nó vô hại vì không được nạp, nhưng nếu sau này bạn sửa cài đặt site trong giao diện aaPanel thì panel có thể ghi bản đó đè lên file sống. Sau mỗi lần làm việc đó, kiểm lại dòng `include` ở `detail/oohx.net.conf`.

### Ranh giới quyền

Script đọc conf từ `docs/deploy/nextjs-proxy/nextjs.conf`, và đường dẫn đó nằm trong cây mã nguồn mà user deploy **ghi được**. Nghĩa là: ai ghi được repo (hoặc merge được vào `main`) thì ảnh hưởng được tới cấu hình web server.

Đó không phải quyền mới hoàn toàn — người đó vốn đã thay được toàn bộ mã nguồn PHP qua `deploy.sh`. Nhưng cấu hình web server rộng hơn mã ứng dụng, nên script **không dán nguyên xi** những gì nó đọc. Nó kiểm theo danh sách trắng:

- chỉ chấp nhận đúng những directive của một proxy conf (`extprocessor`, `context`, `type`, `address`, `handler`, `maxConns`, `initTimeout`, `retryTimeout`, `respBuffer`, `addDefaultCharset`) — gặp dòng nào khác thì dừng;
- `extprocessor` phải tên `nextjs`, `type proxy`, địa chỉ phải đúng `127.0.0.1:3001` — không cho trỏ ra ngoài máy;
- `context` phải là đường dẫn chữ-số-gạch, không chứa `..`, và so **hai chiều** với nhóm cấm.

Chiều thứ hai của phép so đó là một lỗ thật của bản đầu: danh sách cấm so bằng đúng, trong khi OpenLiteSpeed khớp theo **tiền tố** — nên `context /ap` không trùng dòng nào trong danh sách, nhưng nó nuốt `/api/v1`, tức hợp đồng với đối tác. `tests/shell/thu-kiem-conf.sh` chạy 41 trường hợp trong CI, và nó **trích đúng hàm kiểm ra khỏi script thật** chứ không chép lại — chép lại là thử một bản khác.

Kịch bản xấu nhất một conf độc hại làm được, sau danh sách trắng: mở thêm hoặc bớt một đường dẫn công khai trỏ vào chính tiến trình Next.js trên máy này. Không chạy được lệnh, không trỏ ra máy khác, không đọc được file khác.

### Bản đã cài không tự cập nhật theo repo

`/usr/local/sbin/oohx-sync-proxy` thuộc root. Sửa file trong repo **không** đổi nó, cho tới khi root cài lại.

Đó là chủ ý: nếu nó tự cập nhật từ repo thì danh sách trắng vô nghĩa, vì ai sửa được repo sẽ sửa luôn phần kiểm. Nhưng nó cũng là một cái bẫy đã sập ở dự án này theo kiểu khác — bash nạp script trước khi bước 3 thay nó, nên một sửa đổi chỉ có tác dụng ở lượt deploy **sau**. Nên script có `VERSION`, và `deploy.sh` so hai bản rồi **cảnh báo** khi lệch thay vì im lặng chạy bản cũ.

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

### 3. Proxy — một lần cài, sau đó đi qua CI

Lần đầu: xem "Cài `oohx-sync-proxy` — một lần, cần root" ở trên. Sau đó **không chép tay nữa** — đổi `nextjs.conf` trong repo rồi merge, `deploy.sh` bước `[11/11]` gọi script root và script tự kiểm, dán, nạp lại, canary, lùi lại khi đỏ.

Chép tay vẫn chạy, nhưng đừng: lượt deploy sau sẽ thấy bản đang chạy khác repo và dán lại bản của repo lên. Tức một thay đổi chép tay sống được tới lần merge kế tiếp, rồi mất không báo.

Kiểm **từ ngoài vào**, sau khi đã lên:

```sh
curl -s -o /dev/null -w "/explore        → %{http_code} %{time_total}s\n" https://oohx.net/explore
curl -s -o /dev/null -w "/products       → %{http_code} %{time_total}s\n" https://oohx.net/products
curl -s -o /dev/null -w "/map            → %{http_code} %{time_total}s\n" https://oohx.net/map
curl -s -o /dev/null -w "/api/v2/stats   → %{http_code} %{time_total}s\n" https://oohx.net/api/v2/stats
curl -s -o /dev/null -w "/sitemap.xml    → %{http_code} %{time_total}s\n" https://oohx.net/sitemap.xml
curl -s -o /dev/null -w "/cart           → %{http_code} %{time_total}s\n" https://oohx.net/cart
```

Ba đường sau **phải vẫn do Laravel phục vụ**. Script root canary đúng những đường này từ trong máy, nên nếu nó báo xanh mà lệnh trên cho kết quả khác thì khác biệt nằm ở Cloudflare, không ở OpenLiteSpeed.

### 3b. Bốn trang chính sách

Trang Next đã dựng (`webapp/app/(chinh-sach)/[slug]/page.tsx`). Nó **không** chứa văn bản: phần thân lấy từ `GET /api/v2/policies/{slug}`, và endpoint đó render đúng partial Blade mà trang Laravel render (`resources/views/frontpage/policies/bodies/*.blade.php`). Một nguồn, một bộ render — `PublicContentApiTest::test_than_van_ban_la_dung_phan_than_trang_blade_phat_ra` so `body_html` với HTML trang Blade thật sự phát ra.

Bốn context đã khai trong `nextjs.conf` (duyệt ngày 06/10/2026), và `TRONG_APP` ở `webapp/lib/duong-dan.ts` đã khớp — có phép kiểm đọc thẳng file conf nên hai bên không lệch được mà CI vẫn xanh.

Sau khi conf lên máy chủ, kiểm **nội dung**, không chỉ mã trạng thái. Script root canary đã kiểm "ra 200 **và** đến từ Next.js", nhưng nó không biết nội dung đúng hay không:

```sh
curl -s https://oohx.net/quy-che-hoat-dong | grep -c 'class="pol-body"'   # phải 1
curl -s https://oohx.net/quy-che-hoat-dong | grep -c 'class="pol-draft"'  # 1 khi còn nháp, 0 khi đã ban hành
curl -s https://oohx.net/quy-che-hoat-dong | grep -c '&lt;h2&gt;'         # phải 0 — thân bị escape thì ra chữ
curl -s https://oohx.net/bang-phi          | grep -c 'class="pol-draft"'  # phải 0 — bản này đã ban hành
```

Vì sao nhóm này được duyệt riêng chứ không đi kèm `/map`: văn bản pháp lý được đóng dấu `version` vào **từng bản ghi đồng ý** của người dùng. Một lỗi hiển thị ở đây không phải lỗi giao diện — nó là chữ mà người dùng được coi là đã đồng ý.

Ba trong bốn văn bản hiện vẫn là **bản nháp** (`effective_from = null`), và trang Next hiện ô `pol-draft` đúng cho chúng. Phép kiểm `test:seo` canh ô đó **cả hai chiều** — có khi còn nháp, không có khi đã ban hành. Chỉ canh một chiều thì một trang luôn hiện cảnh báo vẫn qua được, và nó nói sai về văn bản đã có hiệu lực.

### 4. Lùi lại

Cách nhanh nhất, và **không cần SSH**: bỏ context khỏi `nextjs.conf` trong repo rồi merge. Lượt deploy sẽ dán bản mới và canary lại.

Cần lùi ngay, as root:

```sh
rm /www/server/panel/vhost/openlitespeed/proxy/oohx.net/nextjs.conf
/usr/local/lsws/bin/lswsctrl restart
```

Toàn bộ quay về Laravel. Không mất dữ liệu, không migration nào phải lùi.

Nhưng nó chỉ sống tới lần merge kế tiếp: `deploy.sh` thấy máy chủ khác repo và dán lại bản repo lên. Nên sau khi lùi gấp, phải sửa cả `nextjs.conf` trong repo — nếu không thì lần deploy sau tự bật lại đúng cái vừa tắt.

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
