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

## Lát 2 — Tham số hoá `deploy.sh`, và workflow cho dev ✅ 06/11/2026

**Bốn** biến, không phải ba — biến thứ tư lộ ra khi viết: bước [11] gọi script
root viết cứng đường dẫn production, nên một lượt deploy dev sẽ đồng bộ conf
của production và chạy canary **vào** production. Tức dev có đường làm
production đỏ. Phải có cờ bỏ qua tới khi lát 3 xong.

```sh
APP_DIR="${OOHX_APP_DIR:-/www/wwwroot/dash.oohx.net}"
BRANCH="${OOHX_BRANCH:-main}"
SERVICE="${OOHX_SERVICE:-oohx-webapp}"
SYNC="${OOHX_SYNC:-1}"
```

Bốn chỗ `oohx-webapp` viết cứng đổi sang `$SERVICE`; bước [11] thêm nhánh
`if [ "$SYNC" != "1" ]` **đứng trước** nhánh kiểm `-x "$SYNC_BIN"` (đảo thứ tự
thì dev vẫn rơi vào đường gọi script của production). Header in thêm dòng
`Service:`.

`deploy-dev.yml` khác `deploy.yml` ở bốn chỗ:

| | `deploy.yml` | `deploy-dev.yml` |
|---|---|---|
| trigger | `push: [main]` | `workflow_dispatch` trước, `push: [dev]` sau khi lát 4 xong |
| `concurrency` | group `production-deploy`, `cancel-in-progress: true` | **cùng chuỗi** `production-deploy`, `cancel-in-progress: false` |
| `envs:` của ssh-action | `MAPTILER_*` | thêm cả bốn `OOHX_*` |
| lệnh | `cd /www/wwwroot/dash.oohx.net` | `cd /www/wwwroot/dev.oohx.net` |

**Đổi ý so với bản đầu của plan: không đổi tên `concurrency.group` thành
`vps-deploy`.** Lý do là một quan sát về cách GitHub khoá: nó khoá theo **chuỗi**,
không theo cái tên. `deploy.yml` nằm trên `main`, và với sự kiện `push` thì
GitHub dùng tệp workflow **của nhánh được push** — nên đổi tên ở hai tệp chỉ có
hiệu lực sau khi về `main`, tức sau khi mở băng. Dùng lại đúng chuỗi
`production-deploy` thì ổ khoá có hiệu lực **ngay hôm nay**, không tốn một lượt
deploy production nào để sửa một cái tên. Cái tên giờ hơi lệch nghĩa (nó là ổ
khoá của cả VPS) và điều đó được ghi trong chú thích của cả hai tệp.

**Chiều của việc huỷ có ý nghĩa, và nó phải đúng chiều.** GitHub đọc
`cancel-in-progress` từ lượt chạy **đang vào hàng**, không phải lượt đang chạy:

| Tình huống | Kết quả |
|---|---|
| dev vào khi production đang chạy | dev **xếp hàng đợi** (`false`) |
| production vào khi dev đang chạy | production **huỷ** dev (`true`) |

Nên production luôn thắng, và dev không bao giờ ngắt được một lượt deploy
production đang giữa migration. Giá phải trả: một lượt deploy dev bị huỷ giữa
đường có thể để dev nằm trong maintenance mode (bước [1] gọi `php artisan down`)
— chữa bằng `php artisan up` trong thư mục dev rồi chạy lại.

### Bộ thử: `tests/shell/thu-bien-moi-truong.sh` (29 ca)

Cả bốn biến hỏng theo cùng một kiểu — **sai mà vẫn xanh** — nên không chiều nào
tự làm CI đỏ. Bộ thử trích bốn dòng gán ra khỏi `deploy.sh` thật rồi source,
và canh chín chiều:

| Chiều | Bắt được gì |
|---|---|
| bộ trích không rỗng | một tệp rỗng làm mọi khẳng định khác xanh |
| không tham số | mặc định phải **bằng đúng** production |
| có tham số | cả bốn nhận được, không biến nào đè biến nào |
| biến rỗng | rơi về mặc định, không thành chuỗi rỗng |
| tên service | còn **một** lần `oohx-webapp` trong cả `deploy.sh` (dòng mặc định) |
| nhánh bỏ qua | tồn tại, và đứng **trước** nhánh kiểm script root |
| `env:` ↔ `envs:` | so **hai chiều**: khai mà không mang sang, và mang sang mà không khai |
| `concurrency.group` | hai workflow cùng **chuỗi**, và dev khai `false` |
| bộ test | dev gọi lại `tests.yml` và `needs: tests` |

Ca đắt nhất là `env:` ↔ `envs:` hai chiều. Thiếu một tên trong `envs:` thì biến
sống trong runner, máy chủ rơi về mặc định production, deploy báo **success** —
cùng cái bẫy đã làm khóa MapTiler không tới production ngày 18/10/2026.

`tests.yml` cũng thêm `dev` và `docs/**` vào `push:`.

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

## Lát 3 — `oohx-sync-proxy` v10: đa-site, danh sách trắng giữ nguyên ✅ 07/11/2026

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

### Bốn chỗ khác plan, lộ ra khi viết

**Bảng có BỐN cột, không ba.** Cột thứ tư là tên service của Next, và nó chỉ đi
vào **câu gợi ý** trong thông báo lỗi — không lệnh nào trong script chạy nó.
Phân biệt đó có chủ ý: một tên service cũ trong bảng chỉ làm một dòng log chỉ
sai chỗ, chứ không restart sai thứ. Mà một dòng log chỉ sai chỗ vẫn tệ, và v9
đã có đúng lỗi đó: nó in `systemctl status oohx-next`, một unit **chưa bao giờ
tồn tại** trên máy này, ngay trong thông báo của lần hỏng nặng nhất (06/11).

**`kiem_conf` phải đếm dòng `address`, và trước lát này nó không đếm.** Nhánh
`address)` chỉ nói "mỗi dòng address đều đúng" — nó im lặng khi KHÔNG CÓ dòng
nào. Tới v9 đó là một lỗ nhỏ. Từ v10 nó thành chỗ hỏng im lặng thật, vì dòng
đó do `sed` điền: `sed` không khớp gì thì file vẫn qua mọi phép kiểm khác và
`extprocessor` ra máy chủ **không có đích nào**. Nên có thêm luật "đúng MỘT
dòng address" — nhiều hơn một cũng chặn, vì khai `address` lần thứ hai bên
trong khối `context` là cách giấu một đích thứ hai ở nơi mắt người đọc bỏ qua.

**Bước [4] phải bỏ qua thư mục rewrite của MỌI site, không chỉ site đang chạy.**
Đây là cái bẫy của v3 quay lại qua một cửa mới. `urlrewrite-nextjs.conf:42`
nhắc `127.0.0.1:3001` trong một dòng **ghi chú**, và file ấy được dán y nguyên
vào thư mục rewrite của cả hai site. Nên một lượt đồng bộ production sẽ bắt được
file rewrite của **dev**, đưa nó qua `kiem_conf` — phép kiểm dành cho conf
proxy, mà nó là file rewrite — rồi DỪNG. Tức **dựng dev lên là làm production
thôi deploy được**, trong khi không ai chạm gì vào production.

**`deploy.sh` cần biến thứ năm, và mặc định của nó là RỖNG.** Không có nó thì
`--site` không có người gọi. Nhưng mặc định không được là `"oohx.net"`: dòng
sudoers của production kết thúc bằng `""`, tức "đúng không đối số nào", nên
gọi `--site oohx.net` — tên hoàn toàn đúng — bị **sudo** từ chối trước khi
script chạy một dòng. Rỗng nghĩa là gọi không đối số, y như `deploy.sh` vẫn
gọi từ bản đầu. Kèm theo đó: mỗi site một **file** sudoers riêng, vì câu gợi ý
ghi bằng `>` nên hai dòng trong một file thì dòng thứ hai xoá dòng thứ nhất —
và dòng bị xoá là dòng production đang dựa vào.

### Bộ thử

| Bộ | Trước | Sau |
|---|---|---|
| `tests/shell/thu-kiem-conf.sh` | 110 | **142** (PHẦN 8: bảng, `--site`, dòng `address`) |
| `tests/shell/thu-bien-moi-truong.sh` | 29 | **45** (biến thứ năm, mảng đối số, dòng sudoers) |
| `tests/shell/thu-cho-next.sh` | 12 | 12 (chỉ truyền thêm hai biến của bảng) |

PHẦN 8 chạy **chính script thật** cho phần đọc đối số: phần đó nằm trước phép
kiểm root, nên mã thoát 64 đo được mà không cần quyền gì. Sáu ca cho các dạng
đối số sai, hai ca cho dạng đúng (chỉ khẳng định **không** phải 64 — không
root thì 77, mà root thì script đi tiếp rồi dừng ở bước [1]).

Ca đắt nhất là `dựng cho production giống file repo TỪNG BYTE`: lệch một khoảng
trắng thì `cmp` ở bước [2] báo "có đổi" ở mọi lượt deploy production, và mỗi
lượt kéo theo một `lswsctrl restart` cùng một lần canary — tốn và ồn, trong khi
không có gì đổi. Và hai ca soi ngược nhau: `kiem_conf` phải **nhận** bản dựng
cho 3002 khi đang đồng bộ 3002, và phải **từ chối** file repo thô ở cùng lúc đó
— tức phép kiểm vẫn có tác dụng sau khi thay.

Ba bộ thử khác (`thu-cho-next`, và hai phần canary trong `thu-kiem-conf`) đỏ
ngay khi v10 lên, vì chúng trích `ma`/`la_next` ra chạy với `curl` giả và hai
hàm đó giờ đọc `$SITE`. Chữa bằng cách khai site trong shell của bộ thử, **viết
thẳng** chứ không đọc từ bảng: PHẦN 8 có một ca canh rằng bảng vẫn khai đúng
site ấy là mặc định, và đọc từ bảng thì ca đó tự đúng với bất cứ giá trị nào.

**Sau khi merge, v10 chỉ có tác dụng khi cài lại as root** — `deploy.sh` so
`VERSION=` và in cảnh báo, chứ không tự cập nhật. Chuyện này đã làm hai lượt
deploy đỏ ngày 06/11/2026.

Cài từ cây **production**, không từ cây dev: một bản cài phục vụ cả hai site,
nên cài từ `/www/wwwroot/dev.oohx.net` là đưa script của nhánh `dev` lên phục
vụ production.

```sh
install -o root -g root -m 0755 \
    /www/wwwroot/dash.oohx.net/docs/deploy/nextjs-proxy/oohx-sync-proxy.sh \
    /usr/local/sbin/oohx-sync-proxy
/usr/local/sbin/oohx-sync-proxy --version   # phải in 10
```

**Bật đồng bộ cho dev là HAI việc, không một.** `deploy-dev.yml` đã khai
`OOHX_SITE: dev.oohx.net` sẵn, nhưng `OOHX_SYNC` còn là `'0'`. Đổi nó thành
`'1'` **chỉ sau khi** dòng sudoers cho `--site dev.oohx.net` tồn tại; thiếu
dòng đó thì sudo từ chối và bước [11] đỏ. Hai việc này thuộc lát 4.

## Lát 4 — Dựng hạ tầng dev trên VPS ✅ 07/11/2026 (trừ bước 10)

Chín trong mười bước đã chạy trên máy chủ ngày 07/11/2026. Bước 10 còn chờ
lát 6 — lý do ở mục "Lát 4 và lát 6 không tách rời được" cuối lát này.

Thứ tự có ý nghĩa: DNS trước, vhost sau, Next sau cùng.

### Ba tệp của lát này đã nằm trong repo

Chín trong mười bước dưới đây cần quyền root. Ba thứ thì không, và để chúng
cho lúc ngồi trước terminal là để chúng được gõ tay — mỗi lần một kiểu.

| Tệp | Bước | Thay cho việc gõ tay |
|---|---|---|
| `docs/deploy/nextjs-proxy/oohx-webapp-dev.service` | 7 | Unit Next của dev. Năm chỗ khác bản production, liệt kê ngay trong tệp. |
| `docs/deploy/nextjs-proxy/dev-noindex.conf` | 4 | Nguyên văn hai khối chặn Google, và vì sao Cloudflare là lớp chính. |
| `app/Console/Commands/TayDuLieuDev.php` | 6 | `php artisan oohx:tay-du-lieu-dev` — 35 cột trên 7 bảng, cộng 8 bảng dọn sạch. |

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

   Nguyên văn hai khối ở `docs/deploy/nextjs-proxy/dev-noindex.conf`. **Lớp
   chính là Cloudflare Transform Rule**, không phải vhost: nó ở ngoài máy chủ
   nên không một lượt deploy, một lần `lswsctrl restart`, hay một lần aaPanel
   ghi lại `detail/*.conf` nào xoá được nó — và bot chỉ tới được dev QUA
   Cloudflare, vì IP gốc không công bố ở đâu cả.
5. **Mã nguồn**: clone repo vào `/www/wwwroot/dev.oohx.net`, checkout `dev`.
6. **CSDL**: `dev_oohx_net`, user riêng. Sao từ production rồi **tẩy**:
   `bank_*`, `tax_code`, `business_license_path`, email người mua.
   `.env` của dev giữ `MAIL_MAILER=log`, `FRONTPAGE_DOMAIN=dev.oohx.net`,
   `DASH_DOMAIN=dev-dash.oohx.net`, `OOHX_TRIAL_MODE=true`.

   Tẩy bằng lệnh, không bằng SQL gõ tay:

   ```sh
   cd /www/wwwroot/dev.oohx.net
   php artisan oohx:tay-du-lieu-dev --dem                     # xem trước, không ghi
   php artisan oohx:tay-du-lieu-dev --mo-khoa=<email thật của bạn>
   ```

   Plan này viết gọn bốn thứ phải tẩy. Đếm trong schema ra **35 cột trên 7
   bảng, cộng 8 bảng phải dọn sạch**, và ba chỗ không nằm ở nơi ai cũng nghĩ
   tới: `public_reflections.contact_*` là liên hệ của người dân thật;
   `quote_requests.contact_email` là NOT NULL nên `SET NULL` đổ giữa đường và
   để lại một CSDL tẩy nửa vời; `failed_jobs.payload` chứa cả thân email.

   Lệnh tự đếm lại từng cột sau khi ghi và **thoát khác 0 nếu còn một hàng
   bẩn**. Nó cũng chặn ba lớp, trong đó lớp đầu là **danh sách trắng** tên
   CSDL — thêm môi trường mới thì phải sửa hằng số, quên sửa thì lệnh từ chối
   chạy.

   `--mo-khoa` là bắt buộc ở lượt đầu: lệnh băm một chuỗi ngẫu nhiên rồi bỏ
   đi, nên sau khi tẩy **không còn mật khẩu nào khớp với bất kỳ tài khoản
   nào**. Tuỳ chọn đó đặt lại một đường đăng nhập và in nó đúng một lần.

   **Bẫy `APP_ENV`:** lệnh từ chối chạy khi `APP_ENV=production`. Môi trường
   dev là một bản Laravel đã deploy, nên phản xạ là đặt `production` cho nó —
   đặt vậy thì lệnh tẩy không chạy được. Dev khai `APP_ENV=dev`. Và vẫn giữ
   `APP_DEBUG=false`: dev có noindex nhưng vẫn mở với cả internet, nên một
   trang lỗi đầy stack trace ở đó là một trang lỗi đầy stack trace công khai.
7. **Next**: `oohx-webapp-dev.service` — cổng **3002**,
   `OOHX_API_BASE=https://dev.oohx.net/api/v2`,
   `OOHX_PUBLIC_ORIGIN=https://dev.oohx.net`.
   `OOHX_API_BASE` phải là **tên miền**, không phải `127.0.0.1`
   (`docs/deploy/nextjs-proxy/README.md:340`).

   Unit đầy đủ ở `docs/deploy/nextjs-proxy/oohx-webapp-dev.service`. Thêm hai
   dòng `/etc/hosts` — `127.0.0.1 dev.oohx.net` và `127.0.0.1
   dev-dash.oohx.net`. Ở production thiếu hai dòng này chỉ làm mỗi lần dựng
   trang đi ra Cloudflare rồi quay lại; ở dev thì nó đi qua đúng tầng mà Cache
   Rule ở bước 2 vừa bảo đừng cache, nên quên một trong hai là đọc một bản dev
   cũ và sửa mãi không thấy đổi.
8. **sudoers**: thêm quyền `systemctl restart oohx-webapp-dev` cho user `deploy`,
   và quyền gọi `oohx-sync-proxy --site dev.oohx.net`.
9. **Cài v10** as root, kiểm `--version` in `10`.
10. Chạy `deploy-dev.yml` bằng tay, đọc dòng `Branch :` trong log — phải là `dev`.

### Bước 8 và 9 là hai việc, và thứ tự giữa chúng không đổi được

```sh
# [9] Cài v10 — TỪ CÂY PRODUCTION. Một bản cài phục vụ cả hai site, nên cài
#     từ cây dev là đưa script của nhánh dev lên phục vụ cả production.
install -o root -g root -m 0755 \
    /www/wwwroot/dash.oohx.net/docs/deploy/nextjs-proxy/oohx-sync-proxy.sh \
    /usr/local/sbin/oohx-sync-proxy
/usr/local/sbin/oohx-sync-proxy --version      # phải in 10

# [8] Hai dòng sudoers cho dev — một cho script proxy, một cho restart Next.
#     TÊN TỆP KHÔNG ĐƯỢC CÓ DẤU CHẤM. Xem ngay dưới khối này.
echo '<user> ALL=(root) NOPASSWD: /usr/local/sbin/oohx-sync-proxy --site dev.oohx.net' \
    > /etc/sudoers.d/oohx-sync-proxy-dev
chmod 440 /etc/sudoers.d/oohx-sync-proxy-dev
echo '<user> ALL=(root) NOPASSWD: /usr/bin/systemctl restart oohx-webapp-dev' \
    > /etc/sudoers.d/oohx-webapp-dev-restart
chmod 440 /etc/sudoers.d/oohx-webapp-dev-restart
visudo -c
sudo -l -U <user>      # phải thấy CẢ BỐN dòng: hai của production, hai của dev
```

**Tên tệp sudoers không được chứa dấu `.`** — và bản đầu của plan này viết
`/etc/sudoers.d/oohx-sync-proxy-dev.oohx.net`, tức sai. `/etc/sudoers.d/README`
trên chính máy chủ nói rõ: sudo chỉ đọc những tệp trong thư mục đó **không kết
thúc bằng `~` và không chứa dấu `.`**. Tên có hai dấu chấm nên sudo bỏ qua cả
tệp.

Cái làm nó khó truy: `visudo -c` vẫn báo `parsed OK`, vì nó cũng bỏ qua tệp ấy.
Không lỗi nào xuất hiện ở đâu. Triệu chứng duy nhất là bước [11] của
`deploy.sh` đỏ ở **mọi** lượt deploy dev với một dòng "sudo từ chối", và người
đọc log sẽ đi tìm cái sai trong *nội dung* dòng sudoers — nơi không có gì sai.

Nên dòng `sudo -l -U <user>` ở cuối khối không phải trang trí: nó là phép kiểm
duy nhất phân biệt được "tệp đúng" với "tệp bị bỏ qua". Tệp của production tên
`oohx-sync-proxy`, không dấu chấm, nên nó vẫn chạy suốt — và đó là lý do cái
bẫy này không lộ ra sớm hơn.

Chỉ **sau khi** cả hai xong mới đổi `OOHX_SYNC` trong `deploy-dev.yml` thành
`'1'`. Thiếu dòng sudoers thì `sudo` từ chối trước khi script chạy một dòng,
và bước [11] của `deploy.sh` đỏ ở mọi lượt deploy dù nội dung đúng hết.

**Trạng thái 07/11/2026: `OOHX_SYNC` vẫn là `'0'`.** Cả [8] và [9] đã xong, và
conf proxy của dev đã được dán — nhưng bằng một lời gọi **as root**, không qua
`sudo` của user `deploy`. Hai thứ đó không chứng minh cho nhau: `sudo -l -U
deploy` cho thấy dòng quyền *có mặt*, còn thứ `deploy.sh` thật sự chạy là
`sudo -n`. Phép kiểm đúng, chạy as root:

```sh
sudo -u deploy sudo -n /usr/local/sbin/oohx-sync-proxy --site dev.oohx.net
```

Xanh thì đổi `OOHX_SYNC` thành `'1'`. Đỏ thì cái sai nằm ở dòng sudoers chứ
không ở script, và đổi cờ chỉ làm mọi lượt deploy dev đỏ theo.

### Năm chỗ plan không nói, tìm ra lúc chạy thật

Plan viết bước 6 là "sao từ production rồi tẩy" và bước 4 là "khai header ở
vhost". Cả hai câu đều đúng về ý và thiếu ở chỗ quyết định.

**1. `.env` của dev cần đổi 20 khoá, không phải bốn.** Plan kể bốn
(`MAIL_MAILER`, `FRONTPAGE_DOMAIN`, `DASH_DOMAIN`, `OOHX_TRIAL_MODE`). Ba nhóm
nữa mà quên thì dev chạm vào production:

| Khoá | Vì sao |
|---|---|
| `SESSION_COOKIE=oohx-dev-session` | production không khai khoá này nên đang dùng mặc định `oohx-session`, và `SESSION_DOMAIN` của cả hai phải là `.oohx.net` (`dev.oohx.net` và `dev-dash.oohx.net` không có hậu tố chung nào hẹp hơn). Trùng tên cookie trên cùng domain, hai CSDL session khác nhau: đăng nhập dev ghi đè cookie của production, trình duyệt gửi nó sang `oohx.net`, production không tìm thấy session đó và đẩy người ta ra. Qua lại mãi, không lỗi nào hiện ra. |
| `DB_OOHX_CONTROL_USERNAME` / `_PASSWORD` = cặp **readonly** | `oohx_control` không phải kết nối của lệnh artisan — nó là kết nối của luồng web Filament (`ConfigManagerService`, `JobOrchestrator`, `CollectorManager`, `Filament/Resources/OohxConfig/*`). Chép nguyên bốn khoá `DB_OOHX_*` nghĩa là ai mở `/admin` trên **dev** và bấm lưu thì ghi vào PostgreSQL của **production**: đổi công thức, đổi hệ số vùng, xếp job tính lại cho sàn thật. |
| `ANTHROPIC_API_KEY`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `OOHX_SSH_KEY`, `OOHX_INGEST_CMD` → **rỗng** | `FILESYSTEM_DISK=local` và `AWS_BUCKET=` rỗng nên S3 không nằm trên đường chạy, nhưng khoá còn đó là khoá còn dùng được. `OOHX_INGEST_CMD` trỏ vào cây thật và ghi vào `oohx_data`. |

**2. `.env` production có khoá trùng, và dòng SAU thắng.** `SESSION_DOMAIN` và
`DB_OOHX_CONTROL_USERNAME` mỗi cái xuất hiện hai lần. Đo ngày 07/11/2026 bằng
đúng repository mà `Illuminate\Support\Env::getRepository()` dựng
(`createWithDefaultAdapters` + `PutenvAdapter` + `->immutable()`): **dòng cuối
ghi đè dòng trước**. `->immutable()` chỉ chặn ghi đè những biến đã có *từ bên
ngoài* trước lượt nạp.

Hệ quả cho việc dựng `.env` dev: một `sed` thay **lần xuất hiện đầu** — cách
viết mặc định — để nguyên dòng thứ hai, tức dòng thật sự có hiệu lực. Nên thứ
tự đúng là **dọn khoá trùng trước, rồi mới thay giá trị, rồi đếm lại từng khoá
phải đúng một dòng**.

**3. Tám bảng không được chép dữ liệu sang dev.** "Tẩy" là xoá *sau khi* đã
ghi, và `failed_jobs.payload` chứa cả thân email của người dùng thật. Chép nó
sang đĩa dev rồi xoá là để dữ liệu ấy nằm trên đĩa một quãng — và nằm trong tệp
dump cho tới khi tệp bị xoá. Tám bảng mà `oohx:tay-du-lieu-dev` **dọn sạch**
(`sessions`, `password_reset_tokens`, `personal_access_tokens`,
`user_invitations`, `failed_jobs`, `jobs`, `cache`, `cache_locks`) không có giá
trị nào ở dev, nên `mysqldump` chúng ở dạng `--no-data`. Cộng `impression_logs`
nếu nó quá 500 MB.

**4. `extraHeaders` ở vhost KHÔNG với tới phản hồi đi qua proxy.** Khối ở
`dev-noindex.conf` đã dán vào `detail/dev.oohx.net.conf`, `lswsctrl restart` đã
chạy, OpenLiteSpeed không báo lỗi — và `X-Robots-Tag` không xuất hiện trên `/`
lẫn `/explore`. Lý do sâu hơn cú pháp: cả hai đường đều đi qua `context` reverse
proxy sang Next, nên phản hồi mang header của backend. Mà 13 context ấy do
`oohx-sync-proxy` sinh ra và ghi đè mỗi lượt deploy, nên không sửa tay vào đó
được.

**Với kiến trúc này, Cloudflare không phải "lớp chính" mà là lớp DUY NHẤT.**
Transform Rule `dev noindex` (Response Header Transform, `Set static`,
`http.host eq "dev.oohx.net" or http.host eq "dev-dash.oohx.net"`) triển khai
07/11/2026; đo lại thấy header có trên `/`, `/explore` và
`dev-dash.oohx.net/admin/login`, và `0` dòng trên cả ba đường production.

**5. Hai thứ nhỏ hơn nhưng quên thì mất một lượt.** aaPanel đặt cờ immutable
(`chattr +i`) lên `.user.ini`, nên `chown -R` trong thư mục site luôn trả
`Operation not permitted` cho đúng tệp đó — và để nó thuộc `root` là **đúng**.
Và trong aaPanel phải đặt **Running directory = `/public`** sau khi clone; thiếu
dòng đó thì docRoot trỏ vào gốc repo, tức `.env`, `app/`, `vendor/` nằm dưới web.

### Ngoài phạm vi lát 4: hai điều phát hiện trên production

Khi đọc `.env` production để dựng `.env` dev, thấy hai thứ không liên quan tới
môi trường dev nhưng không để lại được:

- **`APP_ENV=local` và `APP_DEBUG=true` trên production**, và `php artisan about`
  xác nhận chúng đã nướng vào `bootstrap/cache/config.php` từ lượt deploy
  06/11. Mọi exception chưa bắt trả về trang gỡ lỗi kèm **giá trị biến môi
  trường** — tức `APP_KEY`, `DB_PASSWORD`, `ANTHROPIC_API_KEY`,
  `AWS_SECRET_ACCESS_KEY` nằm cách một lỗi 500 công khai. `APP_ENV=local` cũng
  làm lớp chặn `app()->environment('production')` của `oohx:tay-du-lieu-dev` và
  `oohx:gia-lap-kho` không nhận ra production. Sửa 07/11/2026 bằng ba dòng
  trong `.env` + `php artisan config:cache`; bốn đường dẫn vẫn 200 sau đó.
- **Hai tài khoản đăng ký rác** trong `users` của sàn thật (tên miền email dùng
  một lần). Chưa đụng tới.

### Lát 4 và lát 6 không tách rời được

`workflow_dispatch` chỉ kích được khi tệp workflow đã có trên **nhánh mặc
định**. `deploy-dev.yml` sống ở `dev`, nên bước 10 ở trên — "chạy bằng tay" —
không chạy được cho tới khi `dev` đã merge vào `main`, tức là tới lát 6. Đo
được ngày 07/11/2026, sau khi merge #194.

## Lát 5 — Đo bộ nhớ và đặt mức chặn ✅ 07/11/2026

### Số đo, 07/11/2026

| | |
|---|---|
| RAM tổng | **7,8 GiB** |
| RAM `available` | **4,9 GiB** (used 2,4 · buff/cache 4,8 · free 516 Mi) |
| Swap | 957 MiB, **đã dùng 185 MiB** |
| Đĩa `/` | 157 GB, dùng 46 GB, **còn 104 GB** (31%) |
| CPU | 4 nhân |

### Luật của plan đọc theo `total` là đọc sai con số

Luật viết ở trên là **≥8GB thoải mái · 4GB được kèm giới hạn · ≤2GB không an
toàn**. Đọc theo `total` thì 7,8 GiB rơi vào băng trên và không phải làm gì.

Con số đúng là `available` = **4,9 GiB**: một `next build` xin bộ nhớ ngay
bây giờ, không xin phần hệ điều hành sẽ nhả ra dần. 4,8 GiB kia đang là
buff/cache. Lấy `available` thì máy nằm đúng **băng giữa** — được, kèm giới
hạn.

Hai chi tiết nữa đẩy về phía thận trọng:

- **Swap đã chạm tới**: 185 MiB trong tổng 957 MiB, lúc không build gì. Phần
  đệm khi một build vọt lên chỉ còn ~770 MiB.
- **Khi OOM killer vào cuộc, nó chọn hộ.** Nó chọn theo cỡ bộ nhớ, và con béo
  nhất trên máy này là MySQL hoặc chính tiến trình Next đang phục vụ khách.
  Tức một lượt build của **dev** giết **production**, còn log deploy của dev
  không nói một chữ nào — nó chỉ thấy build của mình xong hay không.

Đĩa và CPU không phải ràng buộc: 104 GB còn trống thừa cho cây thứ hai cộng
`node_modules`, và `concurrency` dùng chung đã xếp hàng hai lượt deploy nên 4
nhân không bị hai build giành.

### Bốn mức chặn

| # | Chặn | Ở đâu |
|---|---|---|
| 1 | `concurrency: production-deploy` dùng chung; dev để `cancel-in-progress: false`, production để `true` nên production luôn thắng | lát 2 |
| 2 | `NODE_OPTIONS=--max-old-space-size=1536` cho build của dev | `OOHX_BUILD_RAM_MB` |
| 3 | `nice -n 10` cho build của dev | `OOHX_BUILD_NICE` |
| 4 | Đo `MemAvailable` trước khi build; dưới 2048 MB thì **dừng, đỏ** | `OOHX_RAM_TOI_THIEU_MB` |

**Cả ba biến mặc định RỖNG**, và rỗng nghĩa là production chạy y như trước
lát 5. Mức chặn là thứ dev tự khoác vào mình, không phải thứ áp lên lượt
deploy của production trong lúc `main` đang đóng băng.

### Mức thứ tư là mức plan chưa có, và là mức đáng kể nhất

Ba mức đầu làm build **ít khả năng** vỡ. Mức thứ tư quyết định **ai chọn nạn
nhân** khi nó vẫn vỡ. Dừng sớm một lượt deploy dev là mất vài phút; để OOM
killer chọn là mất MySQL của sàn thật.

Ba điều đã cân nhắc rồi bỏ:

- **Đo `MemFree` thay `MemAvailable`.** `MemFree` ở đây là 516 MB và sẽ luôn
  dưới mọi ngưỡng hợp lý, vì Linux dùng phần còn lại làm cache rồi nhả khi
  cần. Đo nó là chặn nhầm **mọi** lượt deploy.
- **Đo trước bước 5 (build asset của Laravel) nữa.** Hỏng ở bước 5 để lại
  trạng thái nửa vời: mã nguồn đã thay, cache chưa xoá, migration chưa chạy.
  Bước 9 thì hỏng sạch — Laravel đã deploy trọn vẹn, chỉ bản Next là cũ.
  Phép đo chỉ đặt ở chỗ hỏng sạch được.
- **Áp trần heap cho cả build của Vite.** Chưa đo bao giờ. Giới hạn một thứ
  mình chưa đo là đoán, và đoán sai ở đây là làm đỏ một lượt deploy đúng.

Phép đo hỏng **về phía cho qua**: không đọc được `/proc/meminfo`, hoặc tệp
không có dòng `MemAvailable`, thì nó in một dòng rồi cho đi tiếp. Một phép đo
không đo được không được biến thành một lần chặn.

### Bộ thử

`tests/shell/thu-bien-moi-truong.sh`: 45 → **72 ca**. Hai mục mới trích chính
`chay_build_next` và `kiem_ram_truoc_build` ra chạy — với `npm`/`nice` giả ghi
lại lệnh chúng nhận, và với tệp `meminfo` giả có `MemFree` thấp `MemAvailable`
cao, đúng hình dạng của máy chủ thật.

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
