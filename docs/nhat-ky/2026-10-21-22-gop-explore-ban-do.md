# Nhật ký 21–22/10/2026 — ngăn kéo, gộp `/explore` với `/map`, rồi định vị

> Ngăn kéo kéo được bằng ngón tay (#144, #145); gộp `/explore` và `/map` làm một trang (#147 → #157); xin quyền vị trí khi mở trang, và một chỗ lệch nó làm lộ ra (#159, #160).
>
> Tách ra từ `docs/LO-TRINH-SAU-GIAI-DOAN-0.md` ngày 23/10/2026, khi tệp đó
> chạm 2.988 dòng. Kế hoạch theo giai đoạn ở lại tệp gốc; mục này là nhật ký.

---

## Đêm 20 → sáng 21/10/2026 — ngăn kéo lên, hai PR (#144, #145)

Ba ngăn kéo lên của trang công khai — `.map-panel.open` ở `/map`,
`.sidebar.open` ở `/explore` và `/products` — mang hai lỗi cùng một họ: ngăn
**lấy quá nhiều chỗ**, và tay nắm trên đỉnh nó **hứa một cử chỉ rồi không
làm**. #144 sửa cái thứ nhất, #145 cái thứ hai. Cả hai đều là dòng đã nằm
trong bảng "còn mở" từ ngày hôm trước.

### #144 — `60vh` là 60% của cái gì

`height:60vh` cố định. Đo ở viewport 742px: ngăn chiếm **445px**, bản đồ còn
**245px** — trên một trang mà bản đồ *là* nội dung, không phải hình minh hoạ.

Và `vh` tính theo viewport **khi thanh địa chỉ ẩn**. Trên điện thoại thật,
lúc thanh ấy đang hiện, 60vh chiếm tới **~73% vùng nhìn thấy**. Đó là chênh
lệch giữa con số trong CSS và cái người dùng thấy, và nó đi đúng chiều xấu.

| Đo ở 390×844 | Trước | Sau |
|---|---|---|
| `.map-panel.open` | 506px (60vh) | **492px** |
| Bản đồ còn lại | 286px | **300px**, đã chừa trước |
| Trên máy thanh địa chỉ hiện | ~73% vùng nhìn | 60% của vùng nhìn **thật** |

Phép tính mới đọc từ giữa `clamp` ra: lấy **phần còn lại sau khi chừa 300px
cho bản đồ**, nhưng không dưới `40dvh` (ngăn thấp quá thì không đọc được danh
sách) và không quá `60dvh` (cao quá thì quay về đúng lỗi này).

```css
height:60vh;                                              /* dự phòng */
height:clamp(40dvh,calc(100dvh - var(--hdr) - 300px),60dvh);
```

Hai dòng `height` là cố ý: trình duyệt không biết `dvh` bỏ dòng sau và giữ
dòng trước. `.sidebar.open` và `.ds-panel` cũng đổi `vh` → `dvh`, nhưng giữ
nguyên 60% — hai ngăn ấy không có bản đồ phía sau, nên chỉ cần đổi *60% của
cái gì*.

### #145 — tay nắm hứa cử chỉ, nay làm thật

Tay nắm hình viên thuốc ở đỉnh ngăn là ký hiệu quy ước của một bottom sheet
kéo được. Từ lát 6 tới hôm qua nó **chỉ đóng ngăn khi bấm**: kéo xuống không
có gì xảy ra, kéo lên cũng không. Giao diện mượn một ký hiệu rồi không trả nợ
nó.

Nay cả ba ngăn có **hai bậc** và một cử chỉ thật:

| Người dùng làm | Ngăn làm |
|---|---|
| Kéo xuống | thấp dần **theo ngón tay**, trôi khỏi màn hình — xem trước việc đóng |
| Thả ở gần bậc nào | về bậc đó; ngang nhau thì **giữ ngăn lại**, không đóng |
| Vẩy (≥ 0.5px/ms) | đi **một bậc** theo hướng vẩy, không nhảy thẳng tới cuối |
| Nhấn | đóng, như cũ |
| ↑ / ↓ / Enter | mở rộng / thu rồi đóng / đóng, và `aria-expanded` nói ra bậc đang ở |

Vẩy đi một bậc chứ không nhảy thẳng: vẩy mạnh từ bậc rộng mà đóng luôn là
lấy đi cả ngăn của người chỉ muốn thấy bản đồ nhiều hơn.

### Vì sao đổi `height` thật chứ không `transform:translateY()`

Cách thường gặp là dựng ngăn cao sẵn rồi dịch xuống — rẻ hơn với trình duyệt
vì không reflow. Nhưng ngăn này **chứa một danh sách cuộn bên trong**. Dựng
cao sẵn rồi dịch xuống thì phần dưới của khung cuộn nằm ngoài vùng nhìn, và
**mục cuối cùng của danh sách không ai đọc được**: cuộn tới cuối rồi vẫn còn
một đoạn nằm dưới mép màn hình.

Nên: đổi `height` thật, ghi qua ref (`el.style.height`) chứ không qua
`setState`, một lần ghi mỗi `requestAnimationFrame`. Ngăn là `position:fixed`
nên reflow chỉ chạy trong cây con của nó.

Phép đo trên production xác nhận đúng chỗ ấy: ở bậc rộng của `/map` (88 thẻ
thật), cuộn tới cuối thì **thẻ cuối cùng ở 824px trong vùng nhìn 844px**.

### Ba lỗi mà chỉ phép ĐO tìm ra, không phải đọc code

| Lỗi | Biểu hiện | Sửa |
|---|---|---|
| React mất **330ms** mới gắn lớp bậc mới sau `setState` | Thả tay xong ngăn **tụt về bậc cũ** rồi mới bò lên bậc mới | `classList.toggle('sheet-rong', …)` thẳng vào DOM, rồi mới `setRong()` |
| Cờ "vừa kéo" không được xoá ở đầu cử chỉ | `click` không tới sau một cú kéo (chuột nhả ngoài tay nắm) → **cú nhấn kế tiếp bị ăn mất**, ngăn không đóng được | `vuaKeo.current = false` ngay trong `batDau` |
| Phép đo bậc thu rơi vào **giữa animation** | Ghi nhớ ~700px cho bậc thật 492px → cú kéo sau tính ngưỡng theo số sai | Đo lại ở `transitionend` |

Lỗi thứ nhất đo bằng vòng lấy mẫu 14×100ms: chiều cao đứng ở 492 tại mốc
107/216/325ms, rồi mới 654 → 756 → 780 ở mốc 650ms. Không đọc code nào ra
được con số 330ms đó.

Lỗi thứ hai không phải lỗi của phép đo: chuột nhả ngoài tay nắm trên máy thật
cho cùng kết quả.

### Chốt được gì, và chốt bằng cách nào

Không chốt nào của dự án chạy được một cử chỉ thật: jsdom không chạy layout,
`khoi-giao-dien.mjs` đọc HTML đã render chứ không chạy CSS, Chrome thì phải
đo tay. Nên phép **quyết định** của cử chỉ tách khỏi DOM, vào
`webapp/lib/keo-sheet.ts` — hàm thuần, nhận số, trả bậc — và
`tests/js/sheet-keo-duoc.test.mjs` gọi thẳng bằng số (Node 24 bóc chú thích
kiểu khi nạp, nên chốt `.mjs` import được `.ts`). Phần gắn DOM ở
`dung-keo-sheet.ts` mỏng đi đúng chừng ấy: đọc toạ độ, ghi `style.height`,
đổi class.

14 ca, ba nhóm:

1. **Phép tính**: chiều cao tối đa, chiều cao trong lúc kéo, bậc khi thả —
   vẩy một bậc, vẩy thắng vị trí, gần nhất, ngang nhau thì giữ ngăn.
2. **CSS ↔ JS khớp nhau bằng số**: `CAO_TOI_THIEU` và `KHE_TREN` xuất hiện ở
   cả hai bên, lệch nhau thì ngăn **nảy một đoạn đúng lúc nhả ngón tay**. Kèm
   `.sheet-rong` phải nằm trong `@media(max-width:1023px)`, cặp `vh`/`dvh` dự
   phòng, `touch-action:none` ở cả hai tay nắm.
3. **Ba lỗi đo được ở trên**: mỗi lỗi một ca canh đúng dòng đã sửa.

`npm run test:js` **278/278**. Đột biến: gỡ từng sửa một → đúng ca tương ứng
đỏ.

### Một ca test từng sai kỳ vọng, và nó siết được code

Ca "màn hình thấp" đỏ với `'thu' !== 'rong'`. Kỳ vọng của test sai, không
phải code sai — nhưng nhân đó siết luôn `mocKhiTha`: khi bậc rộng **thấp hơn
hoặc bằng** bậc thu (màn hình rất thấp, hoặc bàn phím ảo đang chiếm chỗ) thì
bậc rộng **không tồn tại**, và trả `'rong'` ở đó là đặt `aria-expanded="true"`
cho một ngăn không cao lên được chút nào. Trình đọc màn hình sẽ nói sai.

### Bốn cái bẫy nhỏ, ghi lại để không đạp lại

| Bẫy | Hệ quả nếu không biết |
|---|---|
| `setPointerCapture()` ném `NotFoundError` với pointerId không thật | Cử chỉ chết ngay câu đầu khi đo bằng sự kiện tổng hợp → bọc `try/catch` |
| Đặc hiệu CSS: `.sidebar.open.sheet-rong` (3 class) thắng `.sidebar.open` (2 class) | Không khoanh trong `@media(max-width:1023px)` thì nó đè `height:auto` của desktop |
| `npm start` lần hai đỏ `EADDRINUSE`, nhưng `curl` vẫn "server ok" | `TaskStop` giết wrapper npm, không giết node con → **phép đo chạy trên build cũ** |
| `javascript_tool` chặn giá trị trả về khi *tên khoá* trông như khoá bí mật | Đặt tên khoá trung tính, không phải lỗi của trang |

Cái thứ ba là bẫy thật: một phép đo "xanh" trên bản cũ tệ hơn một phép đo đỏ.

### Số đo trên production, sau khi deploy

Khung 390×844 trên `https://oohx.net`, `/map` có **88 thẻ thật**:

| Bước | `/map` | `/explore` |
|---|---|---|
| Mở ra (bậc thu) | 492px | 506px |
| Kéo lên, theo ngón tay | 532 → 572 → 612 → 652 → 692 | — |
| Thả tay (bậc rộng) | **780px** | **780px** |
| Đỉnh ngăn ở bậc rộng | 64px, dưới header | 64px |
| Khung cuộn bên trong | 499px, cuộn được, thẻ cuối ở 824px | 756px, cuộn được |
| Kéo xuống ~200px rồi thả | về 492px, vẫn mở | về 506px, vẫn mở |
| Kéo xuống > 400px rồi thả | đóng | đóng |

Desktop 1280×900 không đổi: `.sidebar` 260×680 và `.map-panel` 380×848 vẫn là
cột `position:static`, tay nắm vẫn `display:none`.

Phép đo local trước đó cho `ds_cuon_duoc: false` — không phải lỗi: API giả chỉ
có 2 màn hình nên danh sách ngắn hơn khung. Chỉ production có đủ bản ghi để đo
cái cần đo.

### Còn mở sau sáng 21/10

| Việc | Chặn bởi |
|---|---|
| **`OOHX_LED_LCU_LAI_CHAU` không có đường nào để tới qua `/map`** | Hai cách sửa, cần chủ dự án chọn: điền tỉnh, hay cho `/map` nhóm "chưa rõ tỉnh" |
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Không còn gì chặn (#136). Cần chủ dự án bấm |
| **Cử chỉ kéo chưa ai thử bằng ngón tay thật** | Phép đo dùng `PointerEvent` tổng hợp trong iframe; quán tính cuộn và tranh chấp với cử chỉ hệ thống chỉ hiện ra trên máy thật |
| **Chưa ai chạy thật lối nhập màn hình ở `/admin`** sau khi sửa kiểu ULID | Hai ca hồi quy đi qua `ScreenWriter` trực tiếp, không qua `ScreenImportService` và hàng đợi |
| **Chưa đo `/api/v1` trên production** | Cần một API client (scope `inventory`) và token của nó |
| **Hai việc nhập liệu còn lại** | Tên hay điểm đặt mới là cái sai ở hàng Aeon · sáu màn hình Yên Bái chung một toạ độ |
| **Độ phủ `ghepLechKhoa` vẫn 0/104** | Chờ lượt nhập lại đầu tiên |
| **Đối tác chưa được báo** về thứ tự mặc định mới của `/owners`, `/sites`, `/screens` | Tài liệu đã ghi, nhưng vẫn chưa biết đối tác nào đang dùng API |
| **Ô tick trên thiết bị cảm ứng chưa ai nhìn tận mắt** | `@media(hover:none)` không kiểm được từ máy tính; chốt chỉ canh quy tắc tồn tại |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| `price_per_slot_vnd` của `/api/v1` mang giá trị `floor_cpm` | Ghi chú F09: đổi là làm sai số tiền trong hệ thống đối tác |
| Báo cáo soát chưa xét giá, ảnh thực địa, kích thước | Trang in câu đó ra để không ai đọc nó như báo cáo đầy đủ |
| Mốc ba cột 1280px thay vì 1180px như plan viết | `.w` lấy 52px mỗi bên |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

Ngăn "xem lại" (`.ds-panel`) **cố ý** ở ngoài #145: nó không có tay nắm, nên
không hứa cử chỉ nào. Thêm cử chỉ vào đó là một quyết định thiết kế mới, không
phải trả nợ một lời hứa cũ.

---

## Ngày 22/10/2026 — gộp `/explore` và `/map` làm một trang (#147 → #157)

Mười một PR trong một ngày: một plan, bảy lát của plan đó, và ba bản sửa lỗi
do chính bảy lát kia đẻ ra. Plan đầy đủ ở `docs/PLAN-GOP-EXPLORE-BAN-DO.md`;
mục này ghi cái plan không biết trước.

| PR | Việc | Đổi |
|---|---|---|
| #147 | Plan gộp bản đồ, 274 dòng | — |
| #148 | Lát 1 — pin mang loại hình và kích thước | +285/−57 |
| #149 | Lát 2 — một trang, hai kiểu xem | +520/−153 |
| #150 | Lát 3 — bộ lọc thành dải chip | +818/−309 |
| #151 | *sửa* — chip đang lọc nằm ngoài tầm nhìn | +123/−4 |
| #152 | Lát 4 — "Tìm trong khu vực này" + dải tổng quan | +842/−31 |
| #153 | *sửa* — ô khoảng giá bị cắt mất đầu trên | +55/−5 |
| #154 | Lát 5 — thẻ chi tiết mở cạnh danh sách | +647/−11 |
| #155 | Lát 6 — bảng tổng kế hoạch | +754 |
| #156 | Lát 7 — khai tử `/map`, dọn 1.040 dòng | +212/−1.040 |
| #157 | *sửa* — canary deploy chấp nhận đường đã khai tử | +164/−2 |

Bộ chốt: `test:js` **289 → 334 ca**, chốt shell **102 → 108**, chốt đọc HTML
389 → 406 rồi về **380** khi trang `/map` biến mất cùng 26 khẳng định của nó.

### Vì sao gộp, nói lại bằng con số

Cùng một kho 104 vị trí được nhìn qua hai trang, với **hai** bộ lọc
(`BoLocKho` 405 dòng, `BoLocThongMinh` 330 dòng), **hai** bản đồ
(`BanDoKetQua` và `ScreenMap` 398 dòng) và hai cách bày danh sách. Mỗi
lần thêm một chiều lọc là sửa hai chỗ, và hai chỗ đó đã trôi khỏi nhau:
`/map` có thanh tỉnh thành, `/explore` có trạng thái giá, không bên nào đủ.

Đo `/explore` ở 1440×900 trước khi bắt đầu: bọc nội dung 1176px chia thành
lọc 260 (22%, **cao 1358px**) · kết quả 528 (45%, hai thẻ 255px) · bản đồ 340.
Nội dung chính nhận chưa tới một nửa chiều ngang.

Sau bảy lát, cũng ở 1440×900: dải chip **40px**, lưới **4 thẻ 280px**, và
kiểu xem bản đồ có canvas trải hết với panel 400px nổi bên trái.

### Dữ liệu thật quyết định ba ô của bảng tổng, không phải bản vẽ

Lát 4 và lát 6 đều vẽ những con số mà kho hôm nay không có. Đo trên
production, **cả 104 bản ghi**:

| Trường | Có | Hệ quả |
|---|---|---|
| `location.district` | **0/104** | không đếm được quận/huyện |
| `pricing.floor_cpm` | **0/104** | không có CPM nào để gộp |
| lượt xem/ngày | **không có trường** | không có mẫu số cho CPM |
| `pricing.io_rate` | **19/104** | chi phí tính được, cho 19 vị trí |
| `location.city` chứa `>` | 21/104 | `"Thái Nguyên > Phường Bắc Kạn"` |

Nên bảng tổng đổi hai ô (**tỉnh thành** thay quận/huyện, **chi phí** thay CPM
gộp) và để một ô nói thật là chưa có số đo.

**Và một chỗ tôi cố ý làm khác plan.** Plan viết ô lượt xem "để chữ xám —
tổng của những số ước tính". Khảo lại nguồn thì `daily_footfall` đến từ
`app/Console/Commands/SeedInsightsDefaults.php` — một **bảng hằng số viết
tay** theo loại điểm đặt. Cộng 83 con số seed được `2.075.000 lượt/ngày`:
chính xác tới hàng nghìn, đứng trên trang bán hàng, không một phép đo nào
đằng sau. Chữ xám không cứu được điều đó; nó chỉ làm con số bớt to.

### Ba lỗi của tôi đã lên production, và cách chúng bị bắt

Cả ba đều do **soát production sau khi deploy**, không do test.

**Chip đang lọc nằm ngoài tầm nhìn (#151).** Dưới 1024px và trong panel bản
đồ, dải chip cuộn ngang. Mở `/explore?owner=kim-ngan-adv&view=map`: dải dài
898px trong khung 372px, khung cuộn ở 0, chip đang lọc ở offset **541**. Danh
sách bị cắt từ 104 xuống 83 vị trí, màn hình không có một dấu hiệu nào. Sửa:
cuộn tới chip **trái nhất** đang lọc mỗi khi bộ lọc đã áp dụng đổi.

**Ô khoảng giá bị cắt (#153).** Khoảng giá thật của kho là `54,2tr–729,2tr` —
96px trong một ô 73px, ra màn hình là `54,2tr–7…`. Mất đúng đầu trên của
khoảng. Hai nguyên nhân: bốn cột chia đều (hai ô đếm chỉ chứa số ba chữ số),
và số lẻ giữ ở mọi bậc (phần lẻ của `729,2tr` là **0,03%** giá trị). Fixture
có `5tr–40tr` nên vừa thoải mái — đây đúng là thứ tôi đã ghi "chưa đo bằng
pin thật" trong risks của #152.

**Canary deploy đỏ (#157).** Trên VPS có một canary đòi **mọi** context đã
khai trả 200. Lát 7 đổi `/map` thành 301 nhưng `/map` vẫn phải là context của
Next (bỏ khỏi conf thì Laravel trả 404 và backlink cũ chết). Log liệt kê bốn
nguyên nhân khả dĩ, không cái nào đúng — nguyên nhân thứ năm chưa có tên: một
đường được khai tử có chủ đích.

> Bài học chung của cả ba: tôi soát `TRONG_APP`, soát file conf, soát test —
> nhưng **không soát bảng kỳ vọng của một script chạy ở nơi khác**, và không
> đo bằng dữ liệu có hình dạng của kho thật.

### Một lỗi nặng hơn, có từ trước, chỉ lộ ra khi đo

Trước lát 4, trình xử lý `moveend` đóng kín `napPin` của lượt render **đầu
tiên** — tức bộ lọc lúc trang mới mở. Tái hiện trên production:

| Bước | Kết quả |
|---|---|
| Mở `/explore?view=map` | 95 pin |
| Tick `Loại hình = LCD`, Áp dụng | danh sách 20 thẻ LCD, bản đồ 13 pin |
| Thu nhỏ bản đồ hai nấc | 2 lời gọi `/screens/map`, **cả hai mất** `screen_type=lcd` |
| | bản đồ vẽ lại **95 pin toàn kho**, danh sách vẫn 20 thẻ LCD |

Một URL, hai tập dữ liệu, không có gì đỏ lên. Lát 4 khép đường đó vì `moveend`
không gọi API nữa; `napRef` giữ bản mới nhất cho bất cứ ai gắn thêm việc vào
`moveend` sau này.

### Cú bấm phải chặn ở pha CAPTURE

Lát 5 cần bấm một thẻ mà **không** rời trang bản đồ. Bản đầu gắn `onClick`
lên khung cuộn và nó không chạy kịp: thẻ là một `<Link>` của Next, `Link` gắn
`onClick` lên chính thẻ `<a>`, và React gọi handler của con trước của cha —
nên lúc cú bấm tới nơi thì Next đã bắt đầu điều hướng. Bấm một thẻ là cả
trang bản đồ biến mất.

`onClickCapture` + `stopPropagation()` cắt đường tới `onClick` của `Link`.
Ba thứ **không** chặn: bấm giữa, bấm kèm Ctrl/Cmd/Shift/Alt, và cú bấm rơi
vào ô tick lồng trong thẻ.

Đột biến sau khi commit cho thấy một lỗ: đổi `onClickCapture` về `onClick` —
đúng cái lỗi vừa sửa — **không làm đỏ test nào**. Phải thêm
`tests/js/the-chi-tiet.test.mjs` (7 ca) mới có người canh.

### Cách chốt dùng cả ngày: tách phép quyết định ra khỏi DOM

Bốn tệp `lib` mới, mỗi tệp là một phép quyết định gọi được bằng số:

| Tệp | Quyết định | Ca |
|---|---|---|
| `loc-url.ts` | URL của bộ lọc; `view` phải đi qua nguyên vẹn | 13 |
| `khung-nhin.ts` | khi nào dữ liệu bản đồ cũ đi; bốn số của dải tổng quan | 21 |
| `ke-hoach.ts` | cộng tiền kế hoạch; làm tròn kỳ; tỉnh từ chuỗi địa điểm | 15 |
| `the-chi-tiet` (chốt nối dây) | ba dòng cơ chế của thẻ chi tiết | 7 |

Kèm theo là **chốt nối dây** — hàm có thật sự được gọi không — vì một phép
quyết định đúng mà không ai gọi thì không sửa được lỗi nào.

Đột biến đã thử trong ngày, tất cả đều đỏ đúng chỗ: bỏ `view` khỏi URL (2),
gỡ bộ lọc khỏi panel (4), cho `moveend` gọi lại API (1), lấy nhóm đơn vị đầu
thay vì đông nhất (2), `onClickCapture` → `onClick` (1), `pushState` →
`router.push` (1), làm tròn kỳ `ceil` → `round` (2), cộng mục chưa báo giá
như 0 (2), `kyTuNhan('CPM')` trả `'month'` (1), xoá chữ "reach thô" (1), gõ
sai đích chuyển hướng (1).

### Dọn CSS theo từng quy tắc, không theo khối

Lát 7 gỡ 129 dòng CSS. Lớp sống nằm **xen giữa** lớp chết: `.map-canvas`,
`.map-controls`, `.mc-btn`, `.map-popup`, `.mpop-*`, `.mp-drag-bar` đều còn
hai chỗ dùng. Nên phép dọn quét từng selector, đối chiếu với toàn bộ
`webapp/`, `resources/views/` và `tests/`, và chỉ bỏ quy tắc mà **mọi**
selector đều chết.

Một cái bẫy phải xử: `.on`, `.open`, `.sheet-rong` là lớp **trạng thái**, có
mặt trên nhiều phần tử. Bản đầu coi chúng là bằng chứng còn sống, nên
`.mp-card:hover,.mp-card.on` bị tỉa thành `.mp-card.on` thay vì bỏ hẳn.

Và chốt thì **đổi mục tiêu, không nới lỏng**: ca chiều cao `clamp` chuyển từ
`.map-panel.open` sang `.ex-panel.open` và `.ex-ct` (cùng công thức); ca thanh
đầu đổi `['/map']` thành `['/explore?view=map']` vì nó canh *đường đi* chứ
không canh tên đường dẫn; `SeoBaselineTest` không chỉ bớt `/map` khỏi vòng lặp
mà **thêm một khẳng định ngược** — `/map` phải không còn trong sitemap.

### Bốn cái bẫy nhỏ, ghi lại để không đạp lại

- **`git checkout --` xoá cả thay đổi chưa commit.** Sập **hai lần** trong
  ngày khi hoàn nguyên một đột biến: `next.config.ts` và
  `oohx-sync-proxy.sh` đều mất khối mới viết. Commit trước khi đột biến, hoặc
  copy sang scratchpad.
- **Quên tạo nhánh**, hai commit của lát 5 rơi thẳng lên `main` cục bộ. Chưa
  push nên không ảnh hưởng ai; tách bằng `git branch` rồi `git reset --hard
  origin/main`.
- **Heredoc dài trong Bash tool làm hỏng dấu gạch chéo ngược** của regex —
  viết tệp `.mjs` bằng công cụ Write rồi `node <tệp>`.
- **`next build` gọi bằng `npx` từ `webapp/`** dò gốc workspace ra gốc repo và
  đẻ một thư mục `.next` lạc ở đó. Dùng `npm run build`.

### Còn mở sau ngày 22/10

| Việc | Chặn bởi |
|---|---|
| **Cài `oohx-sync-proxy` v8 trên VPS** | Cần một lệnh `install` chạy as root; tôi không có SSH. Tới lúc đó bước đồng bộ proxy còn đỏ ở mọi deploy — ứng dụng không ảnh hưởng |
| **Lát định vị** — xin quyền vị trí khi mở `/explore` | Chủ dự án đã chốt *"xin quyền khi mở trang"*; chưa làm |
| **Plan gộp chưa sửa cho quy mô 10.000 vị trí** | Gom cụm ở máy chủ, bỏ phân trang sâu, chỉ mục không gian cho sắp xếp theo khoảng cách |
| **Plan nhập 10.000 vị trí từ API đối tác** | Cần biết đối tác nào, tài liệu API, kéo hay đẩy, có toạ độ không, có môi trường thử không |
| **Kế hoạch còn ở `localStorage`** | Lên máy chủ là một PR riêng có CSDL và đăng nhập |
| **Nút chữ cạnh giá thay ô tick 24px** (phần còn lại của lát 6) | Thay đổi markup `ScreenCard`, dùng ở bốn trang |
| **Dải chip chưa có dấu hiệu "còn chip bên phải"** | Quyết định thị giác, không phải lỗi |
| **Chi phí chỉ phủ 19/104 vị trí** | Chờ media owner báo giá |
| **Hai ô chờ dữ liệu**: CPM gộp, lượt xem | Chờ Data Engine và `location.district` được đổ thật |
| `owner.name` có dấu cách thừa; `site.city` chứa đường dẫn phường | Dữ liệu, không phải mã |

Những việc còn mở từ các ngày trước — `OOHX_LED_LCU_LAI_CHAU`, đường gửi yêu
cầu thật, cử chỉ kéo bằng ngón tay thật, `/api/v1` trên production, nhãn
tiếng Việt trên bản đồ, `price_per_slot_vnd` mang `floor_cpm` — vẫn nguyên.

Hai định nghĩa CSS trùng **vẫn còn cả hai**, dù lát 7 có dọn CSS: `.loc-item`
(dòng 631 và 2945) và `.mpop-price` (dòng 968 và 2333). Phép dọn của lát 7
chỉ bỏ quy tắc **không còn ai dùng**; hai cặp này thì cả hai bản đều có chỗ
dùng, nên chúng là việc của một lần dọn khác — và lần đó phải đọc xem bản nào
đang thắng ở đâu, chứ không xoá bớt một bản.

---

## Ngày 22/10/2026, chiều — định vị, và một chỗ lệch nó làm lộ ra (#159, #160)

| PR | Việc | Đổi |
|---|---|---|
| #159 | Xin vị trí khi mở `/explore`, hiện màn hình quanh người dùng | +877/−50 |
| #160 | Sắp danh sách theo khoảng cách (`sort=distance`) | +608/−6 |

Chủ dự án chốt **xin quyền khi mở trang**, không sau một cú bấm.

### Ba lưới an toàn của việc hỏi quyền lúc trang vừa mở

Hỏi quyền trước khi người dùng làm gì là cách nhanh nhất để bị bấm "Chặn", và
trình duyệt **nhớ** cú chặn đó cho tới khi người dùng tự vào cài đặt site mà
gỡ. Một lần hỏi sai thời điểm là mất tính năng vĩnh viễn với người đó.

1. **Hỏi đúng MỘT lần cho mỗi trình duyệt.** Cái bẫy không nằm ở "Chặn" mà ở
   **bỏ qua**: người dùng bấm ra ngoài hộp thoại thì trạng thái vẫn là
   `prompt` *mãi mãi*. Không nhớ thì hộp thoại bật ở mọi lượt tải trang —
   đúng hành vi làm Chrome tự chặn quyền của cả site. Đo: lượt tải thứ hai,
   quyền vẫn `prompt`, cờ đã hỏi `=1` → không hộp thoại, không dòng nào.
2. **Chặn hay quanh đó trống thì trang không đổi gì.** Đo: khung quanh người
   dùng trả `total: 0` → bản đồ lùi về khung cả nước và nói ra.
3. **HTML máy chủ không phụ thuộc vị trí.** Máy chủ không biết người dùng ở
   đâu và không được đoán; có khẳng định canh.

`enableHighAccuracy: false`: tính năng chỉ cần biết người dùng ở thành phố
nào. Trên một trang công khai, xin ít hơn mức có thể xin là mặc định đúng.

### Lời hứa không giữ được: đếm tỉ lệ bấm Chặn

Tôi đã hứa đo con số đó khi thuyết phục rằng hỏi-khi-mở-trang là chấp nhận
được. **Không làm được**: app không có một đường đo đạc nào (`grep` cho
gtag/analytics/telemetry ra rỗng), và dựng một endpoint đo đạc chạm quyền
riêng tư và CSDL — đó là quyết định của chủ dự án, không phải thứ gắn kèm một
lát giao diện.

Nghĩa là **chúng ta không biết** bao nhiêu người bấm Chặn, mà đó đúng là con
số đáng lo nhất của quyết định này. Ghi lại ở đây để nó không im lặng trôi đi.

### Chỗ lệch mà định vị làm lộ ra, và nó có từ trước

Soát production sau khi #159 lên: bản đồ bay tới Hà Nội với 13 vị trí quanh
người dùng, còn **thẻ đầu tiên trong danh sách bên cạnh ở Thái Nguyên**. Hai
tập dữ liệu cạnh nhau trên một màn hình.

Chỗ lệch đó không mới — danh sách luôn do máy chủ dựng (hàng rào SEO của lát
2), còn bản đồ luôn theo khung nhìn. Trước đây bản đồ mở ở khung cả nước nên
hai bên trông khớp. Định vị không tạo ra lỗi; nó làm một giả định cũ hiện ra.

### Toạ độ KHÔNG đi vào URL trang — ràng buộc dẫn dắt #160

Cách rẻ nhất để sắp lại danh sách là đẩy toạ độ vào `/explore?near_lat=…` rồi
để máy chủ dựng. **Không làm.** URL của một trang công khai rơi vào log máy
chủ, vào header `Referer` của mọi tài nguyên bên thứ ba, và vào nút chia sẻ.
Vị trí của một người không thuộc về chỗ đó.

Nên trang do máy chủ dựng vẫn là toàn kho — cũng là thứ giữ cho nó được index
— và phần sắp theo khoảng cách do **trình duyệt** gọi sau khi người dùng cho
phép. Hai ca chốt canh: không tệp nào đặt `near_lat` gần một phép điều hướng.

### Một lỗi bị chặn TRƯỚC khi lên production

`?q=…&sort=distance` trả **500**. Join `sites as sort_site` làm cột `name`
trong bộ lọc tìm kiếm nhập nhằng — `sites` cũng có cột `name`, và MySQL từ
chối cả truy vấn: *Column 'name' in where clause is ambiguous*.

Ca `test_bo_loc_van_chay_cung_voi_sap_theo_khoang_cach` bắt được nó ở lượt
chạy đầu tiên. Không có ca đó thì lỗi chỉ lộ ra khi có người **vừa gõ tìm
kiếm vừa sắp theo khoảng cách** — một tổ hợp không ai thử tay.

Đây là lỗi duy nhất trong ngày bị chặn trước khi deploy. Ba lỗi khác
(#151, #153, #157) đều phải lên production rồi mới bị soát ra.

### Ba cái bẫy của phép sắp theo khoảng cách

| Bẫy | Hỏng ra sao |
|---|---|
| `NULL` xếp **đầu** trong MySQL | Màn hình chưa có toạ độ đứng trên cùng của danh sách "gần tôi nhất" |
| `ACOS` của số lớn hơn 1 trả `NULL` | Sai số dấu phẩy động khi hai điểm **trùng nhau** đẩy tích cosin vượt 1, nên màn hình ở đúng chỗ người dùng đứng rơi xuống **cuối** |
| `sort=distance` thiếu toạ độ | Lặng lẽ rơi về thứ tự mặc định = một danh sách sai thứ tự mà trông bình thường → trả **422** |

### Một lỗ trong chốt của chính tôi

Đột biến bỏ sàn `Math.max(0.01, cos(lat))` **không làm đỏ ca nào**. Ở cực
`cos` không ra 0 mà ra 6,1e-17, nên khung rộng 2,2e15 độ — hữu hạn, và
`Number.isFinite` xanh. Ca phải đổi sang đo **độ rộng** mới bắt được, và
docblock cũng phải sửa: cái sàn đó chặn một khung nhìn vô lý, không chặn
`Infinity`.

### Số đo trên production, với vị trí thật

Chrome có sẵn quyền cho `oohx.net`, nên luồng chạy đủ — lần đầu nó chạy với
toạ độ thật thay vì `getCurrentPosition` giả lập.

| | |
|---|---|
| Kiểu lưới | *"13 vị trí trong 15km quanh bạn · Xem trên bản đồ"* |
| Kiểu bản đồ | bay tới Hà Nội, pin thật |
| Danh sách panel | `1,9km` → `2,7km` → `3,1km`, đều Hà Nội |
| Lọc LCD + sắp | 20 vị trí, đúng loại, `56,8km` → `1.148,6km` |
| `?q=…&sort=distance` | 200 (ca từng 500) |
| `sort=distance` thiếu toạ độ | 422 |
| URL trang | **không có toạ độ** |

### Hai điều đúng nhưng dễ đọc nhầm

**"Sắp theo khoảng cách" không phải "quanh tôi".** Lọc LCD xong, thẻ xa nhất
của trang đầu cách **1.148km**. Phép sắp trả toàn bộ tập khớp bộ lọc, gần
nhất trước, nên trang đầu luôn là 24 cái gần nhất và không bao giờ rỗng. Một
bán kính cứng đúng chữ "xung quanh" hơn nhưng có thể trả về trống.

**Hai con số cạnh nhau trả lời hai câu khác nhau.** Dải tổng quan nói
`13 Vị trí` (trong khung nhìn bản đồ), thanh kết quả nói `Hiển thị 104 vị
trí` (toàn bộ khớp bộ lọc). Mỗi cái có nhãn riêng và đều đúng, nhưng chúng
cách nhau 40px.

### Còn mở sau chiều 22/10

| Việc | Chặn bởi |
|---|---|
| **Không biết tỉ lệ bấm Chặn** | Không có đường đo đạc nào; dựng nó là một quyết định riêng |
| **Chưa bấm "Cho phép" thật trong một phiên sạch** | Hộp thoại quyền là giao diện trình duyệt; công cụ tự động không chạm được. Phiên đo dùng quyền Chrome đã lưu sẵn |
| **Danh sách không theo khung nhìn** | Kéo bản đồ đi nơi khác thì danh sách vẫn "gần bạn nhất" |
| **Haversine chạy trên từng hàng** | Ở 104 bản ghi không đo được gì; ở 10.000 cần lọc thô bằng bounding box trước |
| **Hai lượt nạp pin** khi vào thẳng `?view=map` có vị trí | Khung cả nước rồi khung người dùng |
| `.ds-panel` vs dải tổng quan: hai con số cạnh nhau | Xem mục trên |

Mọi việc còn mở của buổi sáng vẫn nguyên, trừ **lát định vị** (xong) và
**cài `oohx-sync-proxy` v8** — chủ dự án báo đã chạy lệnh `install` trên VPS
chiều 22/10; lượt deploy kế tiếp sẽ in ra phiên bản script và xác nhận.

---


---

← [19–20/10 — /explore ra quyết định](2026-10-19-20-explore-quyet-dinh-mua.md) · [Lộ trình](../LO-TRINH-SAU-GIAI-DOAN-0.md) · [23/10 — đo đạc và quy mô](2026-10-23-do-dac-va-quy-mo.md) →
