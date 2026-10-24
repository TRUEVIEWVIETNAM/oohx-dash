# Plan — trang chi tiết vị trí thành trang ra quyết định mua

Chốt 24/10/2026. Bản thiết kế đã duyệt:
<https://claude.ai/artifact/3kceVCPvxkRumfJ1dM7WFX>

Đối tượng: `/explore/{slug}` — `webapp/app/explore/[slug]/page.tsx` (659 dòng) và
các khối nó gọi (`ThuVienAnh`, `TabChiTiet`, `KhoiInsights`, `LichTrong`,
`PanelGia`, `KhoiViTri`).

---

## Vì sao plan này tồn tại

Đo trên một vị trí thật, `GET /api/v2/screens/oohx-led-cto-can-tho` ngày
24/10/2026: hồ sơ có **8 trong 18 trường** mà một người mua DOOH cần trước khi
chốt.

Thiếu nặng nhất là hai thứ đi cùng nhau: `pricing.io_rate.amount = null` và
**cả bảy trường `insights` đều null**. Không có giá và không có số khán giả thì
**không tính được CPM**, nên người mua không thể đặt vị trí này cạnh vị trí
khác để so sánh. Đó không phải lỗi giao diện và plan này không chữa được nó —
nhưng trang đang **che** chỗ thiếu thay vì nói ra, nên người mua phải đọc hết
trang mới biết mình không có gì để quyết định.

Plan này đổi trang từ *liệt kê những gì có* sang *nói rõ cả cái có và cái
thiếu*.

---

## Nguyên tắc xuyên suốt

1. **Không bịa một con số nào.** Trang này nói chuyện tiền; một số không có
   nguồn ở đây dẫn tới một quyết định mua sai.
2. **Trống thì nói trống, đừng ẩn.** Ẩn một khối làm người mua tưởng sàn không
   có phần đó. Nói trống làm họ biết phải đòi chủ vị trí.
3. **Không lát nào đổi `/api/v1`** (hợp đồng đối tác) và không lát nào đổi hình
   dạng phản hồi `/api/v2` trừ lát 5, nơi nói rõ.
4. **JSON-LD không được khai giá khi chưa có giá.** `offers.price = 0` nghĩa là
   *miễn phí* với schema.org — chỗ này page.tsx đã làm đúng, mọi lát phải giữ.
5. Mỗi lát là **một PR, deploy được một mình**, có chốt test riêng.

---

## Lát 1 — Tên hiển thị, bốn ô số liệu, tách tỉnh/phường

Ba đề xuất cùng một tính chất: dữ liệu **đã có sẵn trong phản hồi**, chỉ trình
bày sai. Làm trước vì rủi ro thấp nhất và thấy ngay.

### Việc

- **`h1` không còn in mã kho.** Đang in `OOHX_LED_CTO_CAN_THO` làm tiêu đề.
  Dựng tiêu đề từ `site.name` + tên đường tách từ `site.address`; mã kho
  (`screen.name`) xuống một nhãn phụ dạng `code`.
  Giữ `screen.name` trong `generateMetadata`, JSON-LD và breadcrumb — đổi ở đó
  là đổi thứ công cụ tìm kiếm đã lập chỉ mục.
- **Bốn ô `stats-row` đổi nội dung**: từ `Site · Network · Kích thước · Floor
  CPM` sang `Giá thuê · Impressions/ngày · Kích thước · SOV tối đa`.
  "Site" và "Network" là cách sàn tổ chức kho, không phải thứ agency cân nhắc;
  "Floor CPM" rỗng trên phần lớn kho. Site và Network vẫn còn trong bảng thông
  số ở tab Thông tin, không mất đi.
- **Tách `location.city`.** API trả `"Cần Thơ > Phường Tân An"` — hai cấp hành
  chính dồn một chuỗi. Viết hàm thuần `tachDiaGioi(city)` trả
  `{ tinh, phuong }` ở `webapp/lib/`, dùng cho breadcrumb, `h1` phụ và khối vị
  trí. **Không** sửa dữ liệu, chỉ tách khi hiển thị.

### Chốt

- `webapp/test/khoi-giao-dien.mjs`: `h1` của trang chi tiết **không chứa dấu
  `_`** (mã kho luôn có), và chứa `site.name`.
- `tests/js/dia-gioi.test.mjs` (mới) cho `tachDiaGioi`: chuỗi có `>`, chuỗi
  không có `>`, chuỗi rỗng, `null`, chuỗi có hai dấu `>`, khoảng trắng quanh
  dấu. Đột biến phải cho tín hiệu: bỏ `trim()` → một ca đỏ.
- Chốt bốn nhãn ô đúng thứ tự, để lần sửa sau không lặng lẽ đổi lại.

### Không làm

Không thêm cột `tinh` vào CSDL. Đó là việc riêng, đã ghi trong lộ trình
(`SUBSTRING_INDEX` còn 164ms) và cần migration.

---

## Lát 2 — Trống thì nói trống

### Việc

- **Khối khán giả.** Hiện tại tab `Insights` chỉ hiện khi
  `screen.insights` tồn tại. Với vị trí đo được, đối tượng `insights` **có**
  nhưng mọi trường bên trong là `null`, nên tab vẫn hiện và **rỗng**. Thêm hàm
  `coSoKhanGia(insights)` trả `false` khi mọi trường đo được đều null, rồi
  render một khối nói rõ: *"Chủ vị trí chưa cung cấp số liệu khán giả"* + nút
  **Yêu cầu số liệu khán giả** + câu giải thích rằng không có số thì không tính
  được CPM.
- **Lịch trống.** `getScreenAvailability()` trả `null` khi lỗi. Khi null thì
  nói *"chưa lấy được"*, phân biệt với *"đã lấy được và không còn chỗ"* — hai
  trạng thái này hiện đang hiển thị giống nhau.
- **Mỗi ô trống ghi nguồn.** Ô `Giá thuê` rỗng ghi rõ `io_rate` chưa niêm yết,
  không để một dấu gạch ngang vô nghĩa.

### Chốt

- `tests/js/so-khan-gia.test.mjs` (mới): `coSoKhanGia` trả `false` cho đối
  tượng toàn null, `false` cho `null`, `true` khi có **đúng một** trường có số.
  Ca này canh đúng cái bẫy đã thấy trên production.
- `webapp/test/khoi-giao-dien.mjs`: với dữ liệu giả `insights` toàn null, HTML
  render ra **chứa** câu "chưa cung cấp số liệu" và **không chứa** một bảng số
  rỗng.
- `stub-api.mjs` thêm một màn hình có `insights` toàn null — hiện chưa có ca
  nào như vậy, và đó là lý do lỗi này sống được tới giờ.

---

## Lát 3 — Lịch trống ra khỏi tab thứ ba

### Việc

Người mua sàng mười vị trí không mở ba tab cho mỗi vị trí. "Đặt được từ tháng
nào" phải thấy được mà không bấm.

- Đưa khối lịch lên ngay dưới hàng bốn ô số liệu, dạng gọn (hai tháng, mỗi
  tháng một ô trạng thái).
- Tab `Lịch trống` **giữ lại** cho bản chi tiết đầy đủ — không bỏ, vì nó chứa
  mức SOV theo ngày mà khối gọn không chứa được.
- Khối gọn và tab đọc **cùng một** `lich` đã lấy; không gọi API thêm lần nào.

### Chốt

- `webapp/test/khoi-giao-dien.mjs`: khối lịch gọn xuất hiện trong HTML **trước**
  thẻ chứa tab (kiểm bằng vị trí chuỗi), tức thấy được không cần tương tác.
- Chốt rằng chỉ có **một** lời gọi `getScreenAvailability` cho mỗi lần render —
  đếm số lần gọi trong `stub-api.mjs`.

---

## Lát 4 — Khối "hồ sơ này đủ để ra quyết định chưa"

Lát giá trị nhất và cũng phức tạp nhất, nên làm sau khi ba lát trên đã ổn định.

### Việc

- Hàm thuần `danhGiaHoSo(screen)` ở `webapp/lib/` trả về danh sách 18 trường
  chia bốn nhóm (nhận diện & vị trí · kỹ thuật · khán giả · thương mại), mỗi
  trường có trạng thái `co | thieu | dang_ngo` và một câu ghi chú.
- Render thành khối có điểm tổng (`8/18`), một dòng kết luận
  (*"Chưa đủ để báo giá"*), thanh tiến độ, và bốn nhóm liệt kê.
- **Tính hoàn toàn ở client từ `ScreenDetail` hiện có** — mọi trường cần đã nằm
  trong phản hồi, nên lát này **không đổi OpenAPI**, không đổi API, không cần
  `npm run types:api`.
- Hàm viết sao cho `/publisher` dùng lại được: chủ vị trí xem hồ sơ của mình
  thấy đúng danh sách cần bổ sung. Lát này **chưa** dựng giao diện
  `/publisher`; chỉ đảm bảo hàm không phụ thuộc gì của trang công khai.

### Chốt

- `tests/js/danh-gia-ho-so.test.mjs` (mới), các ca đáng kể:
  - vị trí thật `oohx-led-cto-can-tho` (dữ liệu dán vào fixture) ra **đúng
    `8/18`** — ca này là cái chốt chống trôi khi có người thêm trường.
  - hồ sơ đủ mọi trường ra `18/18`.
  - `insights` toàn null → cả năm trường nhóm khán giả là `thieu`, không phải
    `co`.
  - trường `dang_ngo` chỉ xuất hiện khi có lý do kiểm được (ảnh từ
    `owners/covers/`, tỉ lệ lệch) — không phải mặc định.
  - **tổng ba trạng thái luôn bằng 18**; thêm một trường mà quên phân loại là
    một ca đỏ.
- Đột biến bắt buộc cho tín hiệu: đổi một trường từ `thieu` sang `co` → ít nhất
  một ca đỏ.

---

## Lát 5 — Bốn thẻ "Điểm nổi bật" và ảnh vị trí

Hai việc còn lại, cùng một chủ đề: **trang không được hứa điều hệ thống không
chứng minh được**.

### Việc

- **Bỏ khối `DIEM_NOI_BAT`** (`page.tsx`, bốn thẻ `hl-card`). Chúng in **vô
  điều kiện cho mọi màn hình**, không đọc trường nào của `screen`. Trên vị trí
  đo được, chúng nói sai cùng lúc ba chỗ:
  - `owner.verified = false` — ngay dưới thẻ *"Verified Location · kiểm tra
    thực địa 2024"*.
  - `impression_logs` chưa có bản ghi nào, nên không có proof-of-play nào để
    *"video xác nhận hàng ngày"*.
  - Bảy trường `insights` null, nên không có *"AI Traffic Data · cập nhật theo
    giờ thực"*.

  Thay bằng huy hiệu tình trạng thật ở khối chủ vị trí (`Chưa xác minh` /
  `Đã xác minh`, đọc `owner.verified`).

  **Đây là đảo một quyết định cũ.** Ngày 18/10/2026 chủ dự án chốt dựng lại bốn
  thẻ nguyên văn bản Blade; ngày 24/10/2026 chốt bỏ trên riêng trang chi tiết,
  vì trang này nói chuyện tiền. Bốn thẻ ở các trang khác **không** thuộc phạm
  vi lát này.

- **Ảnh vị trí.** `photo_urls` của vị trí đo được có đúng một phần tử, và nó là
  `storage/owners/covers/…` — ảnh bìa của chủ sở hữu dùng thay ảnh biển. Thêm
  trường `photo_source` (`screen | owner_cover`) vào DTO và OpenAPI; khi là
  `owner_cover` thì thư viện ảnh nói rõ *"chưa có ảnh chụp vị trí"*.

  Đây là **lát duy nhất đổi `/api/v2`**, nên nó kéo theo `npm run types:api` và
  commit lại `resources/js/types/api-v2.d.ts`. Quên bước đó làm **hai** job CI
  đỏ vì cùng một nguyên nhân — đã mắc ở #169.

### Chốt

- `webapp/test/khoi-giao-dien.mjs`: HTML trang chi tiết **không chứa** các chuỗi
  `AI Traffic Data`, `Proof of Play`, `Verified Location`, `Fast Booking`.
- Chốt huy hiệu đọc đúng `owner.verified`: hai ca, `true` và `false`.
- `tests/Feature/Api/V2/`: `photo_source` là `owner_cover` khi ảnh đến từ bìa
  owner, `screen` khi có ảnh riêng; DTO **không** thêm trường nào ngoài nó
  (danh sách trắng).

---

## Hai việc tìm thấy khi đọc dữ liệu — chờ chốt riêng

Không nằm trong tám đề xuất đã duyệt, và không lát nào ở trên phụ thuộc chúng.

### Kích thước và độ phân giải không thể cùng đúng

`1080 × 1920 px` là tỉ lệ 0,5625. `23,04 × 28,8 m` là 0,8. Nếu biển thật rộng
23,04 m và cao 28,8 m thì bước điểm ngang 21,3 mm còn dọc 15 mm — không tấm LED
nào được chế tạo như vậy. Một trong hai trường đang sai, và trang hiện tại in
cả hai cạnh nhau mà không ai thấy.

Đề nghị: một phép kiểm so tỉ lệ từ hai nguồn, cảnh báo khi lệch quá 2%. Nó bắt
mọi vị trí nhập sai về sau, không riêng vị trí này. Nhưng **phải biết trường nào
sai trước** — đó là câu hỏi cho chủ vị trí, không phải cho mã.

Text để duyệt: `sửa lệch tỉ lệ`

### `/api/v2/screens/{slug}` trả `owner.phone` và `owner.email` công khai

Với vị trí đo được: `0943668996` và một địa chỉ email cá nhân. Trong khi chính
sách đã chốt (19/10/2026) là **mở liên hệ sau khi yêu cầu được duyệt**. Bản
thiết kế không hiển thị chúng, nhưng chúng vẫn nằm trong JSON mà ai cũng gọi
được.

Text để duyệt: `che liên hệ owner`

---

## Đứng ở đâu

| Lát | Nội dung | Đổi API? | Trạng thái |
|---|---|---|---|
| 1 | Tên hiển thị · bốn ô số liệu · tách tỉnh/phường | không | chưa làm |
| 2 | Trống thì nói trống (khán giả, lịch) | không | chưa làm |
| 3 | Lịch ra khỏi tab | không | chưa làm |
| 4 | Khối "hồ sơ đủ chưa" (18 trường) | không | chưa làm |
| 5 | Bỏ bốn thẻ · `photo_source` | **có** | chưa làm |

Thứ tự là thứ tự rủi ro tăng dần, không phải thứ tự giá trị. Lát 4 có giá trị
cao nhất nhưng đứng thứ tư vì nó cần ba lát trước ổn định để không phải sửa
hai lần.
