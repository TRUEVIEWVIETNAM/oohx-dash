# Test JS của hai trang Blade

Chạy: `npm run test:js` (từ gốc repo). CI gọi nó ở job **JS của trang Blade**.

## Vì sao tồn tại

Hai trang `/cart` và `/booking/{campaign}/payment` đã chuyển sang đọc `/api/v2`
từ trình duyệt. Sau lần chuyển đó, phía máy chủ còn **29 ca PHPUnit** canh API
và canh "trang không render sẵn số tiền" — nhưng phần JS *vẽ* ra giao diện thì
không có gì canh. Đó là chỗ đã được ghi vào lộ trình là **chưa canh được**, và
là chỗ ba lỗi thật đã xảy ra khi viết hai trang đó:

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
