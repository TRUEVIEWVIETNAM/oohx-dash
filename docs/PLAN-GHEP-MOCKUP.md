# Ghép mockup vào site thật

Mockup chốt ngày 08/11/2026 — 45 artboard. Plan này nói ghép **cái gì vào đâu**,
theo thứ tự nào, và ba chỗ nó **không ghép được** như hình vẽ.

## Mockup có 45 board, site thật có ba loại nền khác nhau

Đây là việc đầu tiên phải nói, vì nó quyết định cả plan: 45 board **không** ứng
với 45 trang cùng loại.

| Nhóm mockup | Số board | Site thật | Bản chất |
|---|---|---|---|
| Công khai + khám phá | **18** | 14 trang Next.js | HTML/CSS tự viết — **ghép trực tiếp được** |
| Luồng người mua | **13** | 11 trang Blade | Blade, và CLAUDE.md mục 3 cấm thêm tính năng mới vào Blade |
| Khu media owner (`O-*`) | **13** | panel Filament `/publisher` | **Không phải HTML** — là component Filament |

Chỉ nhóm đầu là một việc port. Hai nhóm sau là hai việc khác hẳn, xem mục dưới.

## Hai hệ thống thiết kế này KHÔNG trộn được

Tôi đã khuyên sai ở bảng duyệt lần trước. Tôi đề nghị *"ghép font + token màu
trước, không đụng bố cục — lát này an toàn nhất"*. **Nó không an toàn, và nó
không chạy được.** Lý do đo được:

| | `resources/css/frontpage.css` (site thật) | Chuẩn mockup |
|---|---|---|
| Ngôn ngữ | kiểu Apple | TailAdmin phẳng |
| `box-shadow` | **171 khai báo** | **0** |
| `border-radius` | ~250 khai báo, 6 giá trị (980px, 16, 14, 12, 10, 8, 4) | **6px**, một giá trị |
| Font | Open Sans | Montserrat |
| Hero | `clamp(40px, 8vw, 80px)`, letter-spacing −2px | 28–34px |
| Xanh chính | `#2A4FF6` | `#3C50E0` |

Đổi font và màu mà giữ 171 cái bóng với 250 bán kính lớn thì ra một ngoại hình
**thứ ba** — không phải site cũ, không phải mockup, và tệ hơn cả hai. 3.820 dòng
CSS kia được viết **cho** hệ Apple: `--r5: 28px`, `--sh4`, hero 80px. Token
không phải một lớp sơn trên chúng; chúng là chính chúng.

Nên ghép mockup là **thay** hệ thiết kế, không phải nới nó.

## Ràng buộc: một tệp CSS cho 25 trang

`resources/css/frontpage.css` được nạp bởi **cả hai** bản:

- `webapp/app/globals.css` → `@import` cho 14 trang Next;
- `resources/views/frontpage/layouts/app.blade.php` → 11 trang Blade khu người
  mua (`/cart`, `/booking/*`, `/dashboard`, `/campaigns`, `/settings`).

Và đó là **chủ ý**, ghi rõ trong docblock của `globals.css`: người dùng đi từ
trang Next sang trang Blade trong cùng một lượt duyệt, nên *"hai stylesheet khác
nhau là hai giao diện khác nhau cho cùng một trang web, và người dùng sẽ thấy
ngay ở lần bấm đầu tiên"*.

Hệ quả: không có cách nào đổi hệ thiết kế cho một nhóm trang mà không phá lời
hứa đó — **trừ khi** chia theo đúng chỗ người dùng không thấy đường may. Và có
đúng một chỗ như thế: **trang công khai** (ẩn danh, 14 trang Next) và **khu
người mua** (sau đăng nhập, 11 trang Blade). Khách duyệt `/`, `/explore`,
`/owners`, `/products` không bao giờ thấy `/cart` hay `/dashboard` trong cùng
một lượt — họ phải đăng nhập trước.

Nên đường chia là **công khai trước, khu người mua theo sau cùng đợt chuyển
Next** (`docs/PLAN-API-FIRST-NEXTJS.md`).

## Ba chỗ không ghép được như hình vẽ

**1. Khu media owner là Filament, không phải HTML.** 13 board `O-*` vẽ một
dashboard sidebar tối tự viết; site thật là panel Filament `/publisher` với 9
Resource. CLAUDE.md mục 4 nói dùng component gốc của Filament, không viết
Livewire rời cho CRUD. Nên có hai đường, và chúng khác nhau về quy mô một bậc:

- **Theme Filament** cho gần mockup (màu, font, sidebar tối, bán kính). Được
  khoảng 70% ngoại hình, giữ toàn bộ nghiệp vụ đang chạy. Vài ngày.
- **Viết lại khu owner ngoài Filament** để giống 100%. Mất toàn bộ bảng, biểu
  mẫu, phân quyền đang chạy và phải dựng lại — và đi ngược CLAUDE.md mục 4.
  Nhiều tuần, rủi ro cao.

**Khuyến nghị của tôi: theme Filament.** 13 board đó giá trị nhất ở chỗ chúng
chốt *luồng* và *nội dung* — và phần đó áp vào Filament được. Ngoại hình 100%
không đáng giá bằng việc mất phân quyền đang chạy.

**2. Luồng người mua đang là Blade.** CLAUDE.md mục 3: *"Không thêm tính năng
mới vào Blade trong giai đoạn chuyển đổi; Blade chỉ được sửa lỗi."* Ghép 13
board đó vào Blade là vi phạm trực tiếp. Ghép đúng cách = **chuyển chúng sang
Next trước**, tức làm đợt chuyển API-first. Nên nhóm này không nằm trong plan
ghép — nó nằm trong plan chuyển.

**3. Bản đồ.** Mọi khung bản đồ trong mockup là khung nét đứt rỗng, vì
BizmapLive do anh tích hợp. Plan này giữ nguyên thế: ghép khung, không ghép bản
đồ.

## Lát cắt

### Lát 1 — hệ thiết kế mới, và **một** trang chứng minh nó

Trang chủ (`/`, board `P-TrangChu` + `P-M-TrangChu`).

| Việc | Chi tiết |
|---|---|
| `webapp/app/oohx.css` | Bộ token + base + lớp dùng chung, bê thẳng từ `:root` của board mockup |
| Montserrat | `next/font/google`, 400/500/600/700, tự host |
| Material Symbols Rounded | mockup không dùng SVG gõ tay nào |
| `webapp/app/page.tsx` | dựng lại theo board: dải pháp lý → header → hero + ô tìm → **dải menu ngang một khối** → cam kết → hai panel → lưới vị trí → gói → mức hoàn thiện dữ liệu → footer 4 cột |
| Phạm vi CSS | hệ mới bọc trong một lớp gốc, `frontpage.css` **không sửa** — trang chưa ghép vẫn chạy y nguyên |

**Vì sao một trang chứ không cả 14:** hệ thiết kế mới chưa chạy trên một trang
thật nào. Dựng 14 trang cùng lúc là 14 chỗ cùng sai một kiểu nếu token đặt
nhầm. Trang chủ là chỗ đúng để thử: lưu lượng cao nhất, và là board anh sửa
nhiều nhất nên nó định nghĩa hệ rõ nhất.

**Giá phải trả, nói trước:** trong lúc chỉ trang chủ ghép xong, người dùng bấm
từ trang chủ mới sang `/explore` cũ sẽ **thấy đường may**. Đó là chi phí thật
của việc cắt lát, và nó kéo dài đúng tới lát 3.

### Lát 2 — chrome dùng chung

`SiteHeader.tsx` (159 dòng) và `SiteFooter.tsx` (173 dòng) theo mockup. Hai
component này có mặt trên cả 14 trang, nên lát này làm **mọi** trang công khai
đổi phần đầu và chân cùng lúc — thu hẹp đường may xuống phần thân.

### Lát 3 — khám phá và chi tiết

`/explore` (board `Main` + `M-Explore`) và `/explore/[slug]` (board
`ChiTietManHinh`). Hai trang nặng nhất: cột lọc dính 240px, khay so sánh tối ở
đáy, khung 3 cột kiểu Alibaba, hộp mua dính bên phải.

Hết lát này thì **đường may đóng**: toàn bộ đường người mua đi (chủ → tìm →
chi tiết) nằm trong một hệ.

### Lát 4 — phần công khai còn lại

`/owners`, `/owners/[slug]`, `/products`, `/products/[slug]`, `/agency`,
`/login`, `/register`, chính sách, phản ánh, báo giá. Mười trang, mỗi trang một
board, ít rủi ro vì hệ đã chạy ba lát.

### Lát 5 — theme Filament cho khu owner

Chỉ làm sau khi chủ dự án chốt câu hỏi ở mục trên.

### Ngoài plan — luồng người mua

Đi theo `docs/PLAN-API-FIRST-NEXTJS.md`, không đi theo plan này.

## Cái plan này cố ý KHÔNG làm

- **Không** sửa `resources/css/frontpage.css`. Nó còn phục vụ 11 trang Blade và
  mọi trang Next chưa ghép. Sửa nó là đổi 25 trang cùng lúc, trong đó có những
  trang chưa ai dựng lại.
- **Không** ghép nội dung mockup vào Blade. CLAUDE.md mục 3.
- **Không** vẽ bản đồ. Khung nét đứt, chờ BizmapLive.
- **Không** bê những ô "chưa có dữ liệu" của mockup sang site thật như một
  thiết kế vĩnh viễn. Ba trong số đó đã có đường dữ liệu thật sau hai việc vừa
  rồi (`docs/PLAN-LUOT-XEM.md`, `docs/PLAN-CONG-NGHE-MAN-HINH.md`); chúng chỉ
  cần dữ liệu vào là bật được.

## Việc chủ dự án cần chốt

| Việc | Vì sao cần chốt |
|---|---|
| **Khu owner: theme Filament hay viết lại** | Khác nhau một bậc về quy mô và rủi ro. Khuyến nghị: theme Filament — xem mục trên |
| **Chấp nhận đường may tạm hay không** | Nếu không chấp nhận được việc trang chủ mới nằm cạnh `/explore` cũ, thì lát 1–4 phải gộp thành một lần ra mắt. Gộp thì không thấy đường may, nhưng cũng không kiểm được từng phần, và không có gì lên production cho tới khi xong cả 14 trang |
| **Font Montserrat cho cả khu quản trị không** | Mockup chốt Montserrat *"cho toàn bộ website"*. Filament và Blade đang Open Sans. Nếu "toàn bộ" tính cả hai thì đó là một việc riêng, nhỏ nhưng không nằm trong lát nào ở trên |

## Đứng ở đâu — 09/11/2026

| Lát | Trạng thái |
|---|---|
| 1 — hệ thiết kế + trang chủ | **xong** 08/11 |
| 2 — chrome dùng chung | **xong** 09/11 |
| 3 — khám phá + chi tiết | **xong** 09/11 |
| 4 — công khai còn lại | **xong** 09/11 |
| 5 — theme Filament | chưa; chờ chốt |

Hết lát 2, **cả 14 trang Next** đã đổi phần đầu và chân; thân trang thì chỉ
trang chủ. Đường may còn lại nằm đúng ở thân của 13 trang.

### Năm chỗ lát 2 không ghép như board vẽ

| Board vẽ | Đã làm | Vì sao |
|---|---|---|
| `Media owner đã xác minh` ở hàng hai | `Media owner` | `owners.verified` là cờ thủ công, không phải quy trình kiểm định; cả 2 owner đang `false`. Chốt cấm chữ này từ 18/10 |
| `Đang xem · Hồ Chí Minh` ở hàng một | bỏ | Sàn không có trạng thái tỉnh toàn cục. Một ô chọn tỉnh trên 14 trang mà không trang nào đọc nó là lỗi F-15 |
| Icon `Hộp thư` | bỏ | Không có route hộp thư cho người mua; board tự trỏ nó về trang đăng nhập |
| `Bản đồ vị trí` ở hàng hai | bỏ khỏi thanh đầu, giữ ở chân trang | Quyết định 23/10: bản đồ là kiểu xem thứ hai của `/explore`, không phải khu vực. Có chốt canh riêng, và chốt thắng bản vẽ |
| Logo = ô icon `tv_signin` | logo thật ở thanh đầu | Ô icon là chỗ đỡ của mockup — canvas không có tệp logo. Chân trang nền tối thì vẫn dùng mark board vẽ, vì logo là PNG màu không kiểm được trên nền tối |

Bốn mục chân trang board vẽ mà không có trang thật (`Về OOHX`,
`Quy trình đặt chỗ sáu bước`, `Chất lượng dữ liệu kho`, `Biên bản mẫu`) đều
bỏ, theo luật đã ghi trong `SiteFooter`. Dòng cuối của board
(*"Mockup để duyệt luồng…"*) không ship.

Hai mục board vẽ mà **có** đích thật thì thêm, và trước nay không có đường nào
từ trang công khai dẫn vào: `Khu quản lý chiến dịch` (`/my/campaigns`) và
`Khu quản lý kho` (`/publisher`).

### Lát 3 — rail lọc quay lại, và vì sao điều đó không mâu thuẫn

Cột lọc dọc bị gỡ ngày 22/10/2026 dựa trên **số đo thật**: ở 1440×900 nó lấy
260px trong 1176px và **cao 1358px** — cao hơn khung nhìn — để phục vụ 23 ô
tick, trong khi lưới chỉ còn 2 thẻ 255px. Board đặt rail trở lại.

Tiền đề đã đổi ở lát 1: thẻ nay **186px**, không 280px. Tính lại ở điểm gãy
1240px của `.wrap`:

| | bề rộng lưới | số cột (`auto-fill minmax(186px)`) |
|---|---|---|
| không rail | 1200px | **6** |
| có rail 240px | 938px | **5** |

Rail tốn **một cột**, không nửa lưới. Nên nó quay lại — bằng chính `BoLocKho`
đang chạy, qua một prop `bienThe: 'chip' \| 'rail'`. Chế độ `tat-ca` (cả năm
nhóm mở sẵn, xếp thành cột) đã có từ 22/10; rail chỉ bật sẵn nó. **Không** dựng
bộ lọc thứ hai: `/explore` và `/map` gộp lại chính vì đã có hai bộ trôi khỏi
nhau (405 + 330 dòng cho cùng một kho).

Kiểu xem bản đồ giữ biến thể `chip`: panel danh sách của nó rộng ~360px và nổi
trên canvas, nên một rail dọc trong đó là một cột lồng trong một cột.

### Lát 3 — năm chỗ board vẽ mà không dựng

| Board vẽ | Vì sao không |
|---|---|
| Lọc **kích thước tối thiểu (m)** | `GET /api/v2/screens` không có tham số kích thước nào. `min_price`/`max_price` thì có và dùng được |
| Sắp xếp **Kích thước lớn nhất** và **Có giá trước** | enum `sort` chỉ có `price_asc`, `price_desc`, `newest`, `distance` |
| Khung nét đứt **"Nhúng BizmapLive"** trong luồng lưới | Trang đã có bản đồ CHẠY THẬT ở `?view=map` (Leaflet, tới 500 pin). Cùng lý do như bản đồ trang chủ ở lát 1 |
| Dải tối **"DỮ LIỆU THẬT"**, ô cảnh báo ảnh nhắc Alibaba và in `photo_source: "owner_cover"`, ghi chú *"Alibaba có Verified Supplier…"* | Chữ của bản nháp nói với người duyệt mockup |
| Pill **`Chưa xác minh`** trên thẻ | Cùng lý do đã cấm `Đã xác minh` từ 18/10: không có quy trình kiểm định nào, nên cả hai chiều đều ngụ ý một quy trình không tồn tại |
| **Cột thứ ba** của trang chi tiết (thư viện + owner + bản đồ dồn vào cột trái) | `KhoiViTri` là bản đồ thật kèm điểm quan tâm — nhét vào cột 300px là mất một bản đồ dùng được. Thư viện ảnh là chỗ duy nhất người mua xét tài sản vật lý, và vấn đề của kho là ngược với Alibaba: hai tấm ảnh bìa owner cho 104 vị trí. Hộp đặt dính bên phải thì **đã khớp** sẵn |

### Lát 3 — một trường API thêm vào, và nó là phần thật nhất của lát

`photo_source` có ở DTO **chi tiết** từ 24/10/2026, với lý do ghi ngay trong
`ScreenDetailResource`. Nhưng DTO **danh sách** không có nó, và `ScreenCard`
chưa bao giờ đọc nó — nên `/explore`, nơi người mua **sàng** kho, hiện ra 104
thẻ đều như có ảnh riêng, trong khi cả kho dùng đúng hai tấm ảnh bìa owner.

Lát này thêm trường vào `ScreenSummaryResource` (không thêm truy vấn nào —
`displayPhotoWithSource()` đã trả về `source` rồi bỏ đi) và dán nhãn lên thẻ:
`Ảnh bìa owner`, `Ảnh khu vực`, `Ảnh hệ thống`, `Ảnh minh hoạ loại hình`. Bậc
`screen` **không** có nhãn, và nó cũng không có trong bảng nhãn — viết
`NHAN_NGUON_ANH['screen']` không biên dịch được.

### Lát 4 — hai tầng, vì 168 luật viết tay là sai cách

Mười trang và component của chúng dùng **179 tên class**, 168 trong đó chưa có
luật nào trong `oohx.css`. Đo `frontpage.css`:

| | tổng khai báo | đọc qua token |
|---|---|---|
| `border-radius` | 362 | **26** (7%) |
| `box-shadow` | 170 | **89** (52%) |
| xanh chính | 271 | **268** `var(--bl)` (99%) |

Nên lát 4 có hai tầng: **khai lại token trong `.v2`** (lật 99% màu, toàn bộ
font đọc qua `var(--font)`, nửa số bóng — không một luật per-class nào), rồi
**mười khối theo họ class** cho bán kính và 81 bóng viết literal.

Bóng đặt `0 0 #0000`, **không** `none`: năm luật trong `frontpage.css` ghép
token với một bóng khác (`0 0 0 4px rgba(...), var(--sh2)`), và `none` làm cả
danh sách không hợp lệ nên trình duyệt bỏ luôn khai báo — một trong năm chỗ đó
là vòng focus của ô tìm kiếm.

**Đây không phải lời khuyên đã rút lại.** Lời khuyên cũ là đổi font và màu mà
*giữ* 171 bóng với 250 bán kính lớn. Tầng 1 ở đây **gỡ** chính cái đó.

Nói thẳng mức độ: lát 4 là lượt ghép **nhẹ hơn** ba lát trước. Trang chủ,
`/explore` và trang chi tiết được **dựng lại** theo board; mười trang này được
**đưa vào hệ**. Chỗ nào board vẽ bố cục khác hẳn — `P-ChiTietGoi` chẳng hạn —
là một lát riêng.

### Lát 4 — hai món nợ của lát 1 đã trả, một món vẫn còn

| Món | Trạng thái |
|---|---|
| **Open Sans** | **đã gỡ.** Cả 15 trang có `.v2` nên không chữ nào còn hiển thị bằng nó, trong khi tệp vẫn tải mọi lượt. Build xác nhận: 5 tệp woff2, toàn Montserrat. `globals.css` trỏ `--font` sang Montserrat để 43 luật `var(--font)` của `frontpage.css` cũng theo |
| **Material Symbols Outlined** | **vẫn nạp cạnh Rounded.** `MegaSearch` còn dùng `material-symbols-outlined`, và đổi bộ icon của nó là đổi markup của một component mang hành vi thật |
| **Bỏ lớp `.v2`** | **chưa, và còn lâu.** Plan hứa "lát 4 xong thì bỏ `.v2`" — sai. Mười trang vào hệ bằng cách **ghi đè** `frontpage.css`, không thay nó: bố cục của `.fp-*`, `.pol-*`, `.auth-*`, `.pd-*` vẫn sống ở tệp kia. Bỏ `.v2` là phải viết lại bố cục mười trang — một việc lớn hơn cả lát 4, và nó chỉ đáng làm khi bản Blade cũng rời `frontpage.css` |

### `--hdr` phải khai lại — ghi lại vì nó không hiển nhiên

`frontpage.css` khai `--hdr: 52px` rồi nuôi **14 phép tính** bằng nó. Ba cái vỡ
nhìn thấy được nếu con số sai: `.ex-app{height:calc(100vh - var(--hdr) - var(--nav))}`
của `/explore`, `.tabs-bar{top:var(--hdr)}` của trang chi tiết, và ba khối dính
`top:calc(var(--hdr) + 16px)`. Thanh đầu mới hai hàng nên cao 92px từ 768px và
53px dưới đó; `oohx.css` khai lại `--hdr` theo đúng hai mức. An toàn vì
`oohx.css` chỉ 14 trang Next nạp — 11 trang Blade vẫn dùng thanh đầu Blade cao
52px.

**Nợ kỹ thuật phải trả trước lát 1:** tám bộ test PHP của hai việc trước chưa
chạy được vì máy hết bộ nhớ. Bắt đầu một việc lớn hơn trên nền đó là chồng nợ.
