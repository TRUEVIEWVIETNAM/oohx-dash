# Test JS của các trang Blade đọc qua API

Chạy: `npm run test:js` (từ gốc repo). CI gọi nó ở job **JS của trang Blade**.

## Vì sao tồn tại

Bốn trang khu người mua — `/cart`, `/booking/{campaign}/payment`,
`/my/campaigns/{campaign}` và `/my/campaigns` — đã chuyển sang đọc `/api/v2` từ trình duyệt. Phía máy chủ canh API và canh
"trang không render sẵn số tiền" — nhưng phần JS *vẽ* ra giao diện thì không có
gì canh. Đó là chỗ đã được ghi vào lộ trình là **chưa canh được**, và là chỗ ba
lỗi thật đã xảy ra khi viết hai trang đầu:

| Lỗi | Hậu quả nếu không ai bắt |
|---|---|
| `chu()` không thoát dấu nháy kép | Tên màn hình từ CSV của media owner thoát ra khỏi `src="…"` |
| `new Date('2026-10-08')` cho ngày theo lịch | Ngày bắt đầu lùi một ngày ở múi giờ âm |
| 401 xử lý bằng `location.reload()` | Vòng lặp nạp trang không có lối ra khi phiên web còn mà phiên API thì không |

Cả ba đều **không** làm đỏ một test PHPUnit nào.

## Cách làm, và giới hạn của nó

Test **trích đúng khối `<script>` đang chạy** ra khỏi tệp `.blade.php`, chạy nó
trong jsdom với `fetch` giả, rồi đọc DOM. Nên nó canh mã thật sẽ lên
production, không canh một bản sao.

Thứ nó **không** canh được, nói rõ để không ai coi đây là bằng chứng "trang
chạy đúng":

- **CSS.** Một khối vẽ ra đúng mà bị `display:none` thì test vẫn xanh.
- **Việc Blade ghép cấu hình.** Cấu hình thật đến từ PHP qua khối `@json`; ở
  đây nó do test dựng. Phía PHP có phép kiểm riêng cho việc trang trỏ đúng vào
  endpoint nào.
- **Trình duyệt thật.** jsdom không phải Chrome; nó không chạy layout.

## Chống trôi giữa hai phía

`moc-dom.json` là **nguồn sự thật dùng chung**, đọc bởi cả hai phía:

- phía JS dựng bộ khung DOM từ danh sách đó, nên script tìm thấy đúng những
  móc được khai;
- `tests/Feature/Buyer/MocDomTrangBladeTest.php` đọc **cùng tệp đó** và đòi mọi
  móc phải có mặt trong HTML trang đã render.

Nên đổi tên một móc trong Blade mà quên sửa chỗ khác là **đỏ ở cả hai phía**:
phía PHP báo trang thiếu móc, phía JS báo script không tìm thấy phần tử. Đó là
điều một bộ khung DOM viết tay trong test không làm được — nó sẽ xanh mãi trong
khi trang thật đã đổi.

Thêm vào đó, mỗi tệp test có một ca đòi tập móc script **đi tìm** trùng khít
tập móc được **khai**. Cần vì một móc biến mất **không** làm trang vỡ ồn ào:
script bắt `null` bên trong một `.then()` nên chính `.catch()` của nó hứng lấy,
và trang hiện một thông báo lỗi sai nguyên nhân.

### Năm trường của `moc-dom.json`

| Trường | Nghĩa |
|---|---|
| `cauHinh` | Thuộc tính của khối `<script type="application/json">` mang cấu hình từ PHP |
| `moc` | Móc trang Blade **phải** render ra. Phía PHP đòi từng móc; phía JS dựng bộ khung từ đúng danh sách này |
| `tuTao` | Móc script tự gắn vào phần tử do **chính nó** vẽ ra, nên trang Blade không có. Bị loại khỏi phép so trùng khít, nhưng vẫn phải khai — không khai thì phép so báo "thừa", khai bừa thì nó mất tác dụng |
| `the` | Thẻ HTML của móc khi **không** phải `div`. Thẻ là một phần của hợp đồng, không phải chi tiết trình bày: một `<input>` dựng thành `<div>` thì `el.value` ra `undefined`, và test sẽ chạy nhưng đang chạy trên một thứ khác với trang thật. Phía PHP cũng đòi trang render đúng thẻ đó |
| `linkDangNhapTrongMarkup` | Lối "Đăng nhập lại" nằm trong markup trang (`true`) hay do script vẽ khi nhận 401 (`false`). Quyết định chỗ canh: `true` → phía PHP đọc HTML; `false` → phía JS trả 401 rồi tìm thẻ `a` |

### Tham số `url` của `dungTrang()`

URL của **chính trang**, không của API. Cần cho trang đọc trạng thái từ địa chỉ:
`/my/campaigns` lấy `?page=`, `?status=` và `?q=` từ đó, và ghi lại bằng
`pushState`. Mặc định là một đường trung tính.

Ranh giới "script vẽ gì / trang có gì" là thứ dễ quên nhất khi thêm test cho một
trang mới. `tuTao` và `linkDangNhapTrongMarkup` tồn tại để ranh giới đó là một
**quyết định được ghi lại**, không phải một chỗ im lặng bỏ qua.
