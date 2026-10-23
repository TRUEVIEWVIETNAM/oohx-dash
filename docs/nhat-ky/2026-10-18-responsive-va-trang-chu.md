# Nhật ký 18/10/2026 — mười hai PR, responsive, thang chữ, bố cục trang chủ

> Bốn lần cùng một hình dạng lỗi trong một ngày; rồi refactor responsive, thang chữ tám nấc và bố cục trang chủ (#107 → #118).
>
> Tách ra từ `docs/LO-TRINH-SAU-GIAI-DOAN-0.md` ngày 23/10/2026, khi tệp đó
> chạm 2.988 dòng. Kế hoạch theo giai đoạn ở lại tệp gốc; mục này là nhật ký.

---

## Ngày 18/10/2026 — mười hai PR, và bốn lần cùng một hình dạng lỗi

`main` ở `0f6617e`. CI **1.016 ca / 3.901 khẳng định** (sáng nay là 1.000 / 3.804),
`test:js` **190/190**, ba bộ thử shell **102 + 10 + 7**. Không còn PR nào mở.

Ngày bắt đầu bằng việc dựng nốt vài khối giao diện và kết thúc bằng một chuỗi
sự cố nguồn ảnh nền bản đồ. Phần đáng ghi lại không phải từng sự cố, mà là
chuyện **cả bốn đều cùng một hình dạng**.

### Phần đầu ngày — giao diện (#93 → #98)

| PR | Việc |
|---|---|
| #93 | Cập nhật lộ trình cho bảy lát |
| #94 | Ba khối lời hứa dựng lại **nguyên văn** bản Blade, theo quyết định của chủ dự án |
| #95 → #96 | Ô "3.2×": tải ảnh Unsplash về, rồi **đảo lại** thành hotlink đúng như bản cũ |
| #97 | Bốn thẻ WORKFLOW trả lại nguyên văn |
| #98 | Hai lỗi bản đồ thật: `/map` **không hiện pin nào**, và pin trang chủ là **ảnh vỡ** |

#98 tìm ra bằng Chrome, không bằng test: `a.markerClusterGroup is not a function`
vì `await import('leaflet')` trả về namespace ESM chứ không phải `L` — thiếu
`.default` ở sáu chỗ. Pin trang chủ hỏng vì Leaflet đi tìm `marker-icon.png`
theo đường dẫn tương đối; sửa bằng `divIcon`.

Cùng lúc đó phát hiện tile OSM trả **HTTP 503**, 20/20 ô, từ mạng Việt Nam.

### Bốn lần nguồn tile hỏng, và vì sao không lần nào có một dòng lỗi

| # | Nguồn | Hỏng thế nào | Vì sao không ai thấy |
|---|---|---|---|
| 1 | `tile.openstreetmap.org` | HTTP 503 mọi ô | Nền xám; pin, cụm, nút vẫn đúng. Sống nhiều ngày |
| 2 | `basemaps.cartocdn.com` | **HTTP 200** + PNG 2.049 byte "API KEY REQUIRED" | Mã trạng thái đúng, kiểu nội dung đúng, `tileerror` không bắn |
| 3 | MapTiler, khóa chưa tới máy chủ | `deploy.sh` chạy **bản cũ của chính nó** | Deploy báo `success`, in đủ mười một bước |
| 4 | MapTiler, style rỗng | URL `maps//{z}/…` → 503 mọi ô | **Dự phòng Esri nhảy vào**, trang trông hoàn hảo |

Điểm chung: bản đồ **trông như đang chạy**. Không log nào đỏ, không ngoại lệ
nào ném ra, không chốt nào vỡ. Lần 2 lên production và nằm đó cho tới khi có
người mở trang ra nhìn; lần 4 thì ngay cả nhìn cũng không thấy, vì lớp dự
phòng đã vá chỗ thủng — nó vừa cứu trang, vừa giấu lỗi cấu hình.

### Số đo, cùng một ô ở trung tâm Hà Nội (z13), cùng một mạng

| Nguồn | Kết quả | Nhãn địa danh |
|---|---|---|
| MapTiler `streets-v2` | 200, **82.078 byte** | **tiếng Anh** — "West Lake", "Red River" |
| Esri `World_Street_Map` | 200, **27.862 byte** | **tiếng Việt** — "Hồ Tây", "Phường Tứ Liên" |
| Carto `voyager` | 200, **2.049 byte** watermark | — |
| Wikimedia | 403 | — |
| OSM | không nối được | — |

MapTiler **không đổi được ngôn ngữ nhãn** cho raster: `?language=vi` và
`?lang=vi` đều trả đúng 82.078 byte như khi không có tham số. Chủ dự án chấp
nhận nhãn tiếng Anh (18/10). Muốn tiếng Việt thì tạo style riêng trong MapTiler
Cloud, đặt ngôn ngữ nhãn là Vietnamese, rồi `MAPTILER_STYLE=<tên style>` — một
biến môi trường, không phải một lần sửa mã.

### Nguồn tile sống ở đâu, và đường đi của khóa

```
GitHub Secret MAPTILER_KEY
  → envs: của appleboy/ssh-action        (.github/workflows/deploy.yml)
  → deploy.sh bước 3b ghi vào .env
  → bước 8 config:cache                   → 9 view Filament
  → bước 10 build Next (NEXT_PUBLIC_*)    → 3 bản đồ trang công khai
```

`config/map.php` (Blade) và `webapp/lib/ban-do.ts` (Next) giữ **hai bản sao**
của cùng giá trị — đánh đổi có ý thức, vì Next không đọc được config PHP và
kéo một chuỗi hằng qua một lời gọi API là thêm một vòng mạng. Chỗ trôi bịt
bằng một chốt đọc **cả hai tệp** và quy chúng về cùng một dạng trước khi so.

Có khóa thì dùng MapTiler, không khóa thì Esri. Nhờ vậy máy lập trình và CI
chạy được mà không cần khóa, và một khóa gõ sai ở production cho bản đồ Esri
chứ không cho nền xám.

### Ba lớp canh mới, mỗi lớp sinh ra từ một lỗi cụ thể

| Lớp | Sinh ra vì | Canh cái gì |
|---|---|---|
| `php artisan map:kiem-tile` | Carto trả 200 kèm rác | Tải một ô đã biết rồi **cân nó**, vì mã trạng thái nói dối được. Chạy mỗi 6 giờ qua scheduler |
| `tests/shell/thu-nap-lai.sh` | `deploy.sh` chạy bản cũ của chính nó | Script phải `exec` lại khi vừa tự ghi đè — và **không** được exec khi không đổi (vòng lặp vô hạn trên máy chủ) |
| `tests/shell/thu-dat-env.sh` | `dat_env()` là chỗ **duy nhất** ghi đè `.env` production | Cùng tệp đó giữ `APP_KEY` và `DB_PASSWORD` — hai thứ không lấy lại được từ repo |

Cả ba đều trích hàm/khối **thật** ra khỏi script, không chép lại, và cả ba đều
đã mutation-test.

### Bốn cái bẫy ngôn ngữ, ghi lại để không đạp lại

1. **`??` không bắt chuỗi rỗng.** `process.env.X ?? 'mặc định'` giữ nguyên
   `''`, và URL thành `maps//{z}/…`. Dùng `||`. Phía PHP có đúng cái bẫy đó
   với tham số mặc định của `env()` — dùng `?:`.
2. **Bash đọc script đang chạy từ đĩa.** Một script tự cập nhật mình giữa
   chừng là một canh bạc về offset byte.
3. **`[ -n "$X" ] && export X` dưới `set -e` giết script** khi `X` rỗng. Viết
   ra trong lúc sửa lỗi #4 và suýt thành lỗi #5 — deploy sẽ chết giữa lúc site
   đang ở maintenance mode. `bash -n` không bắt được. Dùng `if`.
4. **`replace()` của JS diễn giải `$'` và `$&` trong chuỗi thay thế**, và
   trong một template literal thì `\s` là `s`. Hai lần viết hỏng chốt bằng
   đúng hai chuyện này; chốt vẫn **chạy**, chỉ khớp sai.

### Khóa MapTiler: công khai theo thiết kế

Khóa nằm trong JS gửi cho mọi khách, vì chính trình duyệt gọi URL tile — mọi
khóa tile client-side đều vậy. Thứ bảo vệ hạn mức không phải sự bí mật mà là
**giới hạn origin** trong MapTiler Cloud, đã bật cho `oohx.net` và `*.oohx.net`
(18/10). Đo lại sau khi bật:

| Gửi gì | Kết quả |
|---|---|
| Không `Referer`/`Origin` | **403** kèm ảnh "Invalid key" 10.260 byte |
| `Referer: https://oohx.net/` | 200 |
| `Referer: https://dash.oohx.net/` | 200 |
| `Referer` tên miền lạ | **403** |

Hai hệ quả:

- `map:kiem-tile` chạy từ dòng lệnh **không gửi header nào**, nên nó phải tự
  gửi `Referer` lấy từ `config('app.url')` — nếu không, nó báo HỎNG cho một
  nguồn đang phục vụ khách bình thường, và một phép kiểm báo oan sẽ bị tắt.
- Ảnh 403 là một PNG **hợp lệ**, nên `<img>` vẫn vẽ nó và `tileerror` không
  bắn. Khi hạn mức 100.000/tháng cạn giữa tháng, mười hai bản đồ sẽ hiện chữ
  "Invalid key" mà lớp dự phòng **không** cứu. Đó chính là kịch bản
  `map:kiem-tile` sinh ra để bắt.

### Còn mở sau ngày 18/10

| Việc | Chặn bởi |
|---|---|
| **Kênh cảnh báo cho `map:kiem-tile`** | Chủ dự án chọn. `onFailure` ghi mức `error`, nhưng dự án **không có Sentry, Slack hay bất kỳ kênh nào** — nên dòng đó chưa đánh thức ai. Hai lối rẻ: gửi mail khi đỏ, hoặc một widget trên `/admin` |
| **Xác nhận `schedule:run` có trong crontab của VPS** | Cần người vào máy chủ. Không có thì mục lịch nằm im và không ai biết |
| **Khối tự-nạp-lại chưa chạy thật lần nào** | Nó mới chỉ xanh trong 7 ca của bộ thử. Lần deploy đầu tiên sau một thay đổi `deploy.sh` nữa sẽ là lần nó chứng minh được mình |
| **Chín view Filament không có dự phòng tile** | Có chủ ý: thêm logic vào chín chỗ là thêm chín chỗ có thể sai. Một nguồn chết = nền xám trong khu quản trị |
| **Ngưỡng 8.000 byte của `map:kiem-tile` là số chọn tay** | Nằm giữa 27.862 và 2.049. Ảnh "Invalid key" của MapTiler **nặng 10.260 byte**, tức vượt ngưỡng — lệnh bắt được nó nhờ kiểm mã trạng thái 403 **trước**, không nhờ cân. Nếu MapTiler đổi sang trả 200 như Carto từng làm thì lệnh sẽ nói "ok" |
| **`Referrer-Policy: no-referrer`** | Chưa host nào đặt. Nếu việc *"chặn referrer cho ảnh Unsplash"* được làm bằng cách đặt header đó cho **toàn site** thì bản đồ MapTiler chết. Khi tới việc đó: chỉ đặt trên thẻ `<img>` của Unsplash |
## Chiều 18/10/2026 — responsive, thang chữ, rồi bố cục trang chủ (#107 → #118)

`main` ở `e975dd7`. CI **1.037 ca / 3.973 khẳng định** (sáng nay 1.016 / 3.901),
`test:js` **207/207** (190 sáng nay), `test:khoi` **330 khẳng định trên 10
trang**, `test:seo` **16 trang**. Không còn PR nào mở.

Buổi sáng dựng lại giao diện cũ và chữa nguồn tile. Buổi chiều là ba việc khác
hẳn nhau, theo đúng thứ tự chủ dự án nêu: trang **vỡ** trên điện thoại → cỡ
chữ **không theo tỉ lệ** → các khối **không nối vào nhau**. Mỗi câu là một
tầng sâu hơn câu trước, và lần cuối mới là câu về sản phẩm chứ không về CSS.

### Phần một — responsive (#107 → #111, #113, #114)

| PR | Việc | Đo được |
|---|---|---|
| #107 | Lát 1: padding ngang ở năm chỗ | 0 → 22/32/52px |
| #108 | Lát 3: `screen_type_label`, ẩn hàng rỗng | `lcd` → `LCD` |
| #109 | Hai lưới "Loại địa điểm" + "Tỉnh thành" có ảnh | — |
| #110 | Lát 5: `next/image` + `next/font` | ảnh **10,68 → 0,23 MB** (−97,9%) |
| #111 | Lát 4+6: vùng chạm 44px, nén chiều dọc | 37 vùng dưới chuẩn → **0**; mobile 12,4 → 11,4 màn |
| #113, #114 | Hai lỗi ảnh 400 trên production | — |

**Lát 2 bị bỏ khỏi kế hoạch vì nó không tồn tại.** Tôi báo "khoảng hở ở
1024px" dựa trên một phép đo bằng iframe: iframe có thanh cuộn nên
`innerWidth` bên trong lệch một bậc breakpoint so với cửa sổ thật. Không có
khoảng hở nào.

Lỗi đo đó quay lại ám cả buổi chiều, và lần thứ hai nó đi theo chiều ngược:
ở #116 tôi "sửa" phép đo sang `documentElement.clientWidth` và đọc sai mốc
767/768. **Media query đi theo `innerWidth`** — bề ngang CÓ cả thanh cuộn.
Đo thẳng điều đó trước khi tin bất cứ con số nào khác:

| Khung iframe | `innerWidth` | `clientWidth` | `(max-width:767px)` |
|---|---|---|---|
| 767px | 767 | 752 | **khớp** |
| 768px | 768 | 753 | không |
| 782px | 782 | **767** | không |

Hai lỗi đo khác cùng loại, cũng của tôi: *"8/10 ảnh hỏng"* (`loading="lazy"`
không kích hoạt trong iframe — lặp lại ba lần), và lần đầu viết
`next-image.test.mjs` thì chốt đỏ vì regex bắt phải **câu trích dẫn dòng cũ
nằm trong chú thích**. Một chốt bắt lỗi trong lời kể về lỗi là một chốt cấm
người ta ghi lại bài học.

### Phần hai — một thang chữ tám nấc (#112)

Chủ dự án nói "font chưa chuẩn" và làm rõ: **cỡ chữ không theo tỉ lệ giữa
heading và mô tả**. Đo thì `<h1>` của năm trang ra **năm cỡ khác nhau**:
18, 28, 30, 44, 80px — và 30px là `2em` mặc định của trình duyệt, vì
stylesheet **chưa bao giờ có luật cho `h1`–`h3` trần**.

```
--fs-hero  clamp(36px, 5.5vw, 64px)      --fs-4  clamp(16px, 1.2vw, 18px)
--fs-1     clamp(28px, 3.4vw, 44px)      --fs-5  15px
--fs-2     clamp(22px, 2.4vw, 32px)      --fs-6  13px
--fs-3     clamp(18px, 1.6vw, 22px)      --fs-7  11px
```

Mười tám class tiêu đề quy về tám token, và `h1/h2/h3` trần có mức nền để
chỗ quên gắn class cũng rơi đúng thang. Chủ dự án chốt **"dựng thang mới"**,
chấp nhận trang đổi diện mạo.

### Phần ba — bố cục trang chủ (#115 → #117)

Đây là câu hỏi sản phẩm, không phải câu hỏi CSS: *"các khối trang chủ nên gồm
những gì và sắp xếp ra sao để người mua và người bán đều browse được?"*

Khảo sát trước khi đụng code tìm ra **ba đứt gãy**: ba lối vào kho nằm rời
nhau, hai cách mua cách nhau **bốn khối**, và mục mời media owner chen vào
giữa luồng của người mua. Chủ dự án duyệt phương án C (gom theo nhiệm vụ) qua
hai bản HTML mock, rồi chốt triển khai.

| PR | Lát | Việc |
|---|---|---|
| #115 | 1 | Thanh đầu thêm "Gói chiến dịch"; chân trang tách "Người mua" / "Media owner" |
| #116 | 2 | Hero hai cột: chữ + ô tìm kiếm trái, **bản đồ thật** phải |
| #117 | 3 | Thân trang: **9 khối → 6**, theo thứ tự người mua hỏi |

Thứ tự mới: hero → duyệt kho (hai tab) → hai cách mua → bốn bước → media
owner + ba bảo đảm → mời lên sàn. Khối cho người bán nay đứng **cuối**, không
chen giữa.

Đo ở stub: **5.541 → 3.712px** ở 1440 (−33%), **6.378 → 4.931px** ở 390
(−23%).

Ba khối gỡ, ba lý do khác nhau:

- **"Vị trí nổi bật"** — `getFeaturedScreens()` đòi ảnh RIÊNG + có giá, và
  **1 trên 104** màn hình thoả cả hai, nên mục này luôn ra đúng một thẻ. Lời
  gọi `/screens/featured` nuôi nó cũng đi: tám endpoint còn bảy.
- **"Gói chiến dịch"** — cùng hình dạng, một lưới cho một gói.
- **"Marketplace OOH đầu tiên có AI"** — chủ dự án chốt bỏ, **đảo** quyết
  định dựng nguyên văn của chính sáng hôm đó (#94), và kéo theo tấm ảnh
  hotlink Unsplash đã chốt ba lần (#95 tự host → #96 hotlink). Trang chủ nay
  không còn phụ thuộc CDN bên thứ ba lúc chạy.

Bản đồ lên hero **không tốn thêm một byte nào**: `useEffect` của
`BanDoTrangChu` chạy ngay khi component gắn vào, không chờ cuộn, nên mọi lượt
vào trang chủ đã tải Leaflet và tile từ trước. Tôi đã hỏi chủ dự án chọn "bản
đồ sống ~1,5–2 MB" hay "ảnh tĩnh" — **câu hỏi đó dựa trên một tiền đề sai**.

### Hai lỗi lát 3 làm lộ ra, và một lớp canh mới cho mỗi lỗi

**Va tên class.** `.tabs`/`.tab` thêm cho khối duyệt kho trùng với thanh tab
gạch chân của trang chi tiết (dòng 1533). Cùng độ cụ thể nên luật sau thắng:
thanh mới nhận `border-bottom` của thanh cũ, giữ `border-radius` của mình, và
vẽ một đường kẻ chạy ngang dưới hai viên.

`next build` xanh. `test:js` xanh. `test:khoi` xanh — nó hỏi *"class này có
trong HTML không"*, không hỏi *"class này ra hình gì"*. **Chỉ nhìn ảnh chụp
mới thấy.**

Lớp canh mới trong `class-css.test.mjs`: hai **định nghĩa gốc** của cùng một
tên cách nhau hơn 120 dòng là lỗi. Nó tìm ra **năm chỗ đã trùng từ trước** —
ba cố ý (khối thang chữ #112), hai là lỗi thật (`.loc-item`, `.mpop-price`,
mỗi cái hai định nghĩa khác hẳn nhau, cái sau thắng). Năm chỗ đó vào **danh
sách nợ** khoá theo tên, không khoá theo số dòng.

**Thứ tự khối không ai canh.** `co` chỉ hỏi "khối có mặt không" — đảo chỗ hai
mục thì mọi khẳng định vẫn xanh, trong khi thứ tự **là** toàn bộ nội dung của
lát 3. Thêm khoá `thuTu` vào `khoi-giao-dien.mjs`.

Kèm theo: `formatProductPrice()` in thẳng mã enum — `5.000.000 ₫/week` trên
một trang mà CLAUDE.md quy định tiếng Việt là mặc định. Trước lát 3 nó chỉ
xuất hiện ở `/products`; lát 3 đưa nó lên trang chủ.

### Một lần báo động sai, và cái chốt sinh ra từ nó (#118)

Sau khi #117 deploy, trang chủ production vẫn là bản cũ. Tôi gọi đó là *"lần
thứ tám trong ngày của đúng kiểu hỏng này"* sau **bốn phút** chờ.

Không có lỗi nào. Chẩn đoán qua workflow cho thấy:

```
tệp prerender trên đĩa  : dk-tabs=1, khối AI=0, ghi lúc 20:15:25   ← bản mới
tiến trình trả về 20:47 : dk-tabs=0, khối AI=1, 126.490 byte       ← bản cũ
tiến trình trả về 20:52 : dk-tabs=1,            112.177 byte       ← đã tự đổi
.next/cache             : thư mục mang dấu 06/10 — build KHÔNG xoá
```

Build ghi đúng, service khởi động đúng, và bản cache cũ được phục vụ thêm vài
phút rồi tự thay. **Bảy lần hỏng thật trước đó làm tôi nghiêng về kết luận
sai ở lần thứ tám.**

Việc đo được làm qua `chan-doan-proxy.yml` vì tôi không có SSH — và chính nó
cũng hỏng theo kiểu nó được dựng để chống: `script_stop: true` giết script ở
khối 2 (`grep` trả 2 vì `Permission denied`), nên **khối 3–6 chưa bao giờ in
ra lần nào**. Chú thích đầu tệp đã mô tả đúng loại hỏng này từ lần sửa trước
rồi kết luận `set +e` trong thân đã đủ. Kết luận đó sai.

#118 sửa ba chỗ: `script_stop: false` cho cả hai bước; khối 4 phân biệt
"không tồn tại" với "không vào được" (nó từng in `(chưa tồn tại)` ngay dưới
dòng `Permission denied` cho cùng thư mục); và thêm khối 7 hỏi **"bản nào
đang chạy"** — cặp 7d/7e tách "build ghi sai" khỏi "build đúng nhưng phục vụ
sai", 7k hỏi thẳng "chờ thêm thì có tự hết không".

Khối 3–6 lần đầu chạy được cũng trả lời ngược một giả định cũ: bốn thư mục
cấu hình OpenLiteSpeed là **không vào được**, không phải "chưa tồn tại". Conf
proxy có thể vẫn nằm đúng chỗ.

### Còn mở sau chiều 18/10

| Việc | Chặn bởi |
|---|---|
| **Badge "Đã xác minh" trên thẻ owner** | `owners.verified` là cờ thủ công, không phải quy trình kiểm định — cùng loại với những câu `KhoiLoiHua.tsx` ghi lại. Nó đứng ngay dưới tiêu đề mới của khối ⑤ |
| **Thanh "trust" vẫn nói "Traffic real-time", "AI-powered search", "Secure payment"** | Chủ dự án chốt giữ nguyên văn (#94, #97). Sau khi gỡ khối AI, đây là chỗ **duy nhất** còn những câu đó |
| **Hai định nghĩa CSS trùng** (`.loc-item`, `.mpop-price`) | Nằm trong danh sách nợ của chốt mới. Việc của một lần dọn CSS |
| **`.next/cache` không bị xoá khi deploy** | Trang mới mất vài phút mới hiện. Xoá được, nhưng đánh đổi là mất cache ảnh đã tối ưu ở **mỗi** lần deploy |
| **Dải 768–899px** | Hero một cột, cao 977–1.058px. Vừa iPad dọc, hụt một chút ở 899px |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên style vào `MAPTILER_STYLE` |
| **Tên màn hình `OOHX_LED_SLA_SON_LA`** | Chủ dự án chọn: đổi tiêu đề thẻ hay sửa dữ liệu |
| **Ba loại hình chưa có hàng** | Chủ dự án chọn: hiện mờ "sắp có" hay ẩn |


---

← [13/10 — hàng rào mạng lưới](2026-10-13-hang-rao-mang-luoi.md) · [Lộ trình](../LO-TRINH-SAU-GIAI-DOAN-0.md) · [19–20/10 — /explore ra quyết định](2026-10-19-20-explore-quyet-dinh-mua.md) →
