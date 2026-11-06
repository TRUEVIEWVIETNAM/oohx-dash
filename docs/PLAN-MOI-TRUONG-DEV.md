# Plan — tách `dev.oohx.net` khỏi `oohx.net`, trên cùng một VPS

Chốt 06/11/2026, ngay sau khi #191 và #192 lên production.

Đối tượng: `deploy.sh` · `.github/workflows/deploy.yml` và `deploy-dev.yml` ·
`docs/deploy/nextjs-proxy/oohx-sync-proxy.sh` (v9 → v10) ·
`docs/deploy/nextjs-proxy/oohx-webapp.service` (bản thứ hai cho dev) ·
vhost OpenLiteSpeed `dev.oohx.net` · CSDL `dev_oohx_net`.

---

## Vì sao plan này tồn tại

`oohx.net` đang được Sở kiểm tra cho việc đăng ký giấy phép sàn thương mại điện
tử. Trong lúc đó, đường deploy hiện tại là:

```
.github/workflows/deploy.yml:4-6   on: push: branches: [main]
```

Tức **mỗi lần merge vào `main` là trang đổi ngay dưới tay người đang kiểm.** Và
`webapp/app/layout.tsx:69` cho thấy `OOHX_TRIAL_MODE` mặc định bật, nghĩa là
trang đang hiện thông báo pháp lý "chạy thử" — đúng trạng thái một sàn chờ xác
nhận đăng ký. Không chỉ mã nguồn, mà cả nội dung pháp lý (quy chế hoạt động,
bảng phí, chính sách bảo mật, giải quyết tranh chấp) phải đứng yên và khớp hồ sơ.

Nên cần hai thứ, và chúng tách nhau:

1. **Đóng băng `oohx.net`** — không tốn gì, không dựng gì, chỉ ngừng merge vào
   `main`. Làm được ngay.
2. **Một nơi để làm việc tiếp** — `dev.oohx.net`, trên cùng VPS (phương án A,
   chủ dự án chốt 06/11/2026).

## Vì sao phương án A, và cái giá của nó

Ba phương án đã trình: A cùng VPS, B một VPS thứ hai, C local qua Cloudflare
Tunnel. Chủ dự án chốt **A**.

A rẻ nhất về tiền và đắt nhất về việc, vì mọi thứ trên VPS hiện đang viết cứng
cho **một** site. Đo ngày 06/11/2026:

| Viết cứng ở đâu | Giá trị |
|---|---|
| `deploy.sh:218` | `APP_DIR="/www/wwwroot/dash.oohx.net"` |
| `deploy.sh:219` | `BRANCH="main"` |
| `deploy.sh:514,579,586,597` | tên service `oohx-webapp` (bốn chỗ) |
| `oohx-sync-proxy.sh:157` | `REPO_ROOT="/www/wwwroot/dash.oohx.net"` |
| `oohx-sync-proxy.sh:160` | `VHOST_CONF=".../detail/oohx.net.conf"` |
| `oohx-sync-proxy.sh:161` | `PROXY_DIR=".../proxy/oohx.net"` |
| `oohx-sync-proxy.sh:178` | `NEXT_ADDR="127.0.0.1:3001"` |
| `oohx-sync-proxy.sh:668,680,685,717` | `--resolve "oohx.net:443:127.0.0.1"` |
| `oohx-webapp.service` | cổng 3001, `OOHX_API_BASE=https://oohx.net/api/v2` |

Còn phần **mã ứng dụng** thì đã sẵn — không chỗ nào viết cứng tên miền:

| Chỗ | Cách lấy |
|---|---|
| `config/domains.php` | `env('FRONTPAGE_DOMAIN')`, `env('DASH_DOMAIN')` |
| `webapp/lib/api.ts:42,45` | `OOHX_API_BASE`, `OOHX_PUBLIC_ORIGIN` |
| `webapp/next.config.ts:111` | `OOHX_PUBLIC_HOST` |
| `webapp/app/layout.tsx:69` | `OOHX_TRIAL_MODE` |

Nên công việc nằm hết ở tầng triển khai, không ở tầng ứng dụng.

## Bốn nguyên tắc của plan này

**1. Production không được đổi hành vi.** Mọi tham số mới phải có giá trị mặc
định **bằng đúng giá trị đang chạy**. `deploy.sh` không truyền gì thì phải chạy
y như hôm nay. Đây không phải sự thận trọng chung: `deploy.sh` thay được toàn bộ
mã nguồn và chạy migration trên CSDL production.

**2. Script root giữ nguyên triết lý danh sách trắng.** `oohx-sync-proxy` chạy
**as root** và đổi được cấu hình web server. Nó **không** được nhận đường dẫn tự
do từ tham số dòng lệnh. Thêm site nghĩa là thêm một dòng vào một bảng **bên
trong chính script** — và vì script chỉ cài được bằng tay as root, bảng đó mới
là danh sách trắng. Một `--site` nhận đường dẫn tùy ý sẽ biến script thành công
cụ dán conf bất kỳ vào web server, tức phá đúng thứ nó được viết ra để bảo vệ.

**3. Dev không chạm gì của người thật.** CSDL riêng, không bao giờ trỏ
`dash_oohx_net`; `MAIL_MAILER=log`; không được Google thấy; dữ liệu sao chép
phải tẩy `bank_*`, `tax_code`, `business_license_path` và email người mua.

**4. Không bao giờ hai `next build` cùng lúc trên một máy.** Đây là rủi ro chính
của phương án A: `next build` là bước ngốn bộ nhớ nhất của cả đường deploy, và
nếu build của dev chạy trùng lúc build của production thì cái chết vì thiếu bộ
nhớ có thể rơi vào production. Chốt chặn là cơ học, không phải kỷ luật: hai
workflow dùng **cùng một `concurrency.group`**, GitHub sẽ xếp hàng.

---

## Lát 1 — Đóng băng `oohx.net` ✅ 06/11/2026

- Nhánh `dev` tạo từ `main` tại `858edca`.
- Mọi PR từ nay đặt **base = `dev`**.
- `main` chỉ còn cho **hotfix**: nếu Sở yêu cầu sửa quy chế, bảng phí hay chính
  sách thì đi đường đó, và merge `main` → `dev` ngay sau đó.
- CI không cần sửa gì để chạy: `tests.yml:6` khai `pull_request:` **không lọc
  nhánh**, nên PR vào `dev` vẫn chạy đủ bốn job. Chỉ nên thêm `dev` vào
  `tests.yml:5` (`push:`) cho trọn.

Lát này **không dựng gì trên VPS** và không cần quyết định nào khác. Nó đã có
hiệu lực: `main` đứng ở `858edca`, và vì `deploy.yml:4-6` chỉ nghe `push` vào
`main`, không lượt deploy nào có thể xảy ra nữa nếu không có một merge.

## Lát 2 — Tham số hoá `deploy.sh`, và workflow cho dev

Ba biến, mỗi biến có mặc định bằng giá trị hiện tại:

```sh
APP_DIR="${OOHX_APP_DIR:-/www/wwwroot/dash.oohx.net}"
BRANCH="${OOHX_BRANCH:-main}"
SERVICE="${OOHX_SERVICE:-oohx-webapp}"
```

Rồi thay bốn chỗ `oohx-webapp` viết cứng (`:514,579,586,597`) bằng `$SERVICE`.
Không đổi gì khác trong script.

`deploy-dev.yml` là bản sao của `deploy.yml` với bốn khác biệt:

| | `deploy.yml` | `deploy-dev.yml` |
|---|---|---|
| trigger | `push: [main]` | `workflow_dispatch` trước, `push: [dev]` sau khi VPS sẵn |
| `concurrency.group` | `production-deploy` | **`vps-deploy`** — và `deploy.yml` cũng đổi sang `vps-deploy` |
| `envs:` của ssh-action | `MAPTILER_*` | thêm `OOHX_APP_DIR,OOHX_BRANCH,OOHX_SERVICE` |
| lệnh | `cd /www/wwwroot/dash.oohx.net; bash deploy.sh` | `cd /www/wwwroot/dev.oohx.net; bash deploy.sh` |

**Bẫy đã biết:** `appleboy/ssh-action` chỉ mang sang phiên SSH những biến có tên
trong `envs:`. Thiếu tên trong danh sách đó thì biến sống trong runner, máy chủ
không thấy gì, và **deploy vẫn xanh** — đúng cái bẫy mà `deploy.yml:48-51` đã
ghi lại cho khóa MapTiler. Với `OOHX_BRANCH` thì hậu quả nặng hơn khóa bản đồ:
máy chủ dev sẽ lặng lẽ `git reset --hard origin/main`, tức **kéo production vào
thư mục dev**.

Chốt chặn: `deploy.sh` in `Branch : $BRANCH` ngay ở đầu (`:231`) — bước đầu tiên
sau khi dựng dev là đọc dòng đó trong log và xác nhận nó nói `dev`.

Để `deploy-dev.yml` ở `workflow_dispatch` tới khi lát 4 xong, vì một workflow
`push: [dev]` tồn tại trước khi hạ tầng dev tồn tại sẽ đỏ ở mọi push.

## Lát 3 — `oohx-sync-proxy` v10: đa-site, danh sách trắng giữ nguyên

Bảng site nằm **trong** script:

```sh
# Mỗi dòng: tên-site|repo-root|địa-chỉ-Next
SITE_CHO_PHEP=(
    "oohx.net|/www/wwwroot/dash.oohx.net|127.0.0.1:3001"
    "dev.oohx.net|/www/wwwroot/dev.oohx.net|127.0.0.1:3002"
)
```

`--site <tên>` chỉ **chọn một dòng trong bảng**; tên không có trong bảng thì
thoát với mã lỗi. Không có `--site` thì mặc định `oohx.net`, nên `deploy.sh`
hiện tại gọi `sudo -n "$SYNC_BIN"` không tham số vẫn chạy y như cũ.

Từ dòng được chọn, script dựng `REPO_ROOT`, `VHOST_CONF`, `PROXY_DIR`,
`REWRITE_*`, `NEXT_ADDR`, và **tên dùng cho `--resolve`** của canary
(`:668,680,685,717`).

**Một nguồn sự thật cho 13 context.** Dev cần cổng 3002, production 3001. Hai
tệp conf trong repo là hai bản sẽ trôi khỏi nhau — và danh sách 13 context chính
là thứ `nextjs.conf:177-179` cảnh báo là dễ sai nhất. Nên v10 đọc **đúng một**
`nextjs.conf`, rồi thay duy nhất dòng `address` theo site:

```sh
sed "s|^\(\s*address\s*\).*|\1$NEXT_ADDR|" "$REPO_CONF" > "$TMP"
```

và **vẫn kiểm `$TMP` qua danh sách trắng như cũ** — phép thay thế không được
phép bỏ qua bước kiểm. Thứ tự là: thay → kiểm → dán → canary → lùi nếu đỏ.

Bộ thử: `tests/shell/thu-kiem-conf.sh` (hiện 110 ca) thêm các ca cho `--site`
không có trong bảng, cho phép thay địa chỉ, và cho việc mặc định không tham số
phải cho ra đúng cấu hình production. `tests/shell/thu-cho-next.sh` (12 ca) chỉ
cần đổi nếu hàm chờ đổi chữ ký — hiện không cần.

**Sau khi merge, v10 chỉ có tác dụng khi cài lại as root** — `deploy.sh:662-676`
so `VERSION=` và in cảnh báo, chứ không tự cập nhật. Chuyện này đã làm hai lượt
deploy đỏ ngày 06/11/2026.

## Lát 4 — Dựng hạ tầng dev trên VPS (việc as root của chủ dự án)

Thứ tự có ý nghĩa: DNS trước, vhost sau, Next sau cùng.

1. **Cloudflare**: hai bản ghi A một cấp — `dev` và `dev-dash` → `172.104.188.62`.
   **Phải một cấp.** Universal SSL chỉ phủ `*.oohx.net` một tầng, nên
   `dash.dev.oohx.net` sẽ không có chứng chỉ.
2. **Cache**: thêm Cache Rule *Bypass cache* cho host `dev.oohx.net`. Trang công
   khai cache 60 giây là đúng cho production và là cái bẫy cho dev.
3. **aaPanel**: site mới `dev.oohx.net`, bind thêm `dev-dash.oohx.net`, phát
   chứng chỉ Let's Encrypt.
4. **`X-Robots-Tag: noindex, nofollow`** khai ở vhost dev. **Không** sửa
   `public/robots.txt`: tệp đó được git theo dõi, và `deploy.sh:247` chạy
   `git reset --hard origin/$BRANCH` nên mọi sửa tay sẽ bị xoá ở lượt deploy kế
   tiếp. Header thì không git-reset được.
5. **Mã nguồn**: clone repo vào `/www/wwwroot/dev.oohx.net`, checkout `dev`.
6. **CSDL**: `dev_oohx_net`, user riêng. Sao từ production rồi **tẩy**:
   `bank_*`, `tax_code`, `business_license_path`, email người mua.
   `.env` của dev giữ `MAIL_MAILER=log`, `FRONTPAGE_DOMAIN=dev.oohx.net`,
   `DASH_DOMAIN=dev-dash.oohx.net`, `OOHX_TRIAL_MODE=true`.
7. **Next**: `oohx-webapp-dev.service` — cổng **3002**,
   `OOHX_API_BASE=https://dev.oohx.net/api/v2`,
   `OOHX_PUBLIC_ORIGIN=https://dev.oohx.net`.
   `OOHX_API_BASE` phải là **tên miền**, không phải `127.0.0.1`
   (`docs/deploy/nextjs-proxy/README.md:340`).
8. **sudoers**: thêm quyền `systemctl restart oohx-webapp-dev` cho user `deploy`,
   và quyền gọi `oohx-sync-proxy --site dev.oohx.net`.
9. **Cài v10** as root, kiểm `--version` in `10`.
10. Chạy `deploy-dev.yml` bằng tay, đọc dòng `Branch :` trong log — phải là `dev`.

## Lát 5 — Đo bộ nhớ và đặt mức chặn

Phương án A chỉ an toàn khi còn dư bộ nhớ cho một `next build`. Cần số đo:

```
free -h ; df -h /www ; nproc
```

Luật: **≥8GB thoải mái · 4GB được, kèm `NODE_OPTIONS=--max-old-space-size`
cho build của dev · ≤2GB thì phương án A không an toàn** và phải quay lại B.

Ngoài `concurrency` dùng chung, nên cho build của dev chạy `nice -n 10` để
production luôn được ưu tiên CPU.

## Lát 6 — Mở băng

Khi có xác nhận đăng ký:

1. Merge `dev` → `main`. Đây sẽ là **một lượt deploy lớn**, nên phải giữ
   `main` → `dev` đồng bộ suốt thời gian đóng băng để diff chỉ đi một chiều.
2. `OOHX_TRIAL_MODE=false` trên production — `webapp/components/TrialNotice.tsx:14`
   nói rõ đó là cách tắt dải thông báo khi đã có xác nhận.
3. Giữ `dev.oohx.net` làm môi trường thử thường trực; `dev` thành nhánh tích hợp
   lâu dài, `main` thành nhánh phát hành.

---

## Rủi ro còn lại

| Rủi ro | Mức | Giảm bằng |
|---|---|---|
| Hai `next build` trùng lúc làm production chết vì thiếu bộ nhớ | **cao** nếu không chặn | `concurrency` dùng chung + `nice` + đo RAM ở lát 5 |
| `OOHX_BRANCH` không tới được máy chủ → dev kéo `main` | trung bình | tên biến trong `envs:`, và đọc dòng `Branch :` trong log |
| Sửa script root làm hỏng đường deploy production | trung bình | mặc định không tham số = hành vi cũ; 110 ca `thu-kiem-conf.sh` canh |
| Dev bị Google đánh chỉ mục, trùng nội dung với production | trung bình | `X-Robots-Tag` ở vhost, không phải `robots.txt` |
| Dữ liệu thật rò qua dev | trung bình | tẩy khi sao chép; `.env` dev không bao giờ trỏ `dash_oohx_net` |
| Người kiểm mở nhầm `dev.oohx.net` và tưởng là trang chính thức | thấp | `OOHX_TRIAL_MODE=true` + một dải ghi rõ môi trường thử |
| Hai conf 13-context trôi khỏi nhau | **đã dẹp** | v10 đọc một tệp, chỉ thay dòng `address` |
