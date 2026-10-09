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

- **Cách trang hiện ra.** jsdom không chạy layout, nên một khối vẽ ra đúng mà
  bị `display:none`, tràn khung hay chữ trắng trên trắng thì test vẫn xanh.
  Phần *tên* class thì có người canh — xem `class-css.test.mjs` bên dưới.
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

## `class-css.test.mjs` — tên class phải có người định nghĩa

Hai lỗi thật đã lên production vì không ai đối chiếu tên class với stylesheet:

| Lỗi | Hậu quả |
|---|---|
| `.b-gray` **chưa từng** có trong `frontpage.css`, mà mười chỗ trong `resources/views` tham chiếu nó | Thẻ "Nháp", "Hoàn thành", "Đã hoàn tiền" và nhánh mặc định của mọi bảng màu ra DOM không nền, không màu chữ |
| `b-green` ở trang thanh toán, CSS chỉ có `.b-grn` | Dấu "Đã ghi nhận" cho từng media owner mất nền xanh |

Các test jsdom **có** chọn `.badge`, nhưng không khẳng định class *màu* — nên cả
hai lỗi lọt qua 84 ca.

Tệp này đối chiếu mọi tên class bốn trang có thể đặt vào DOM — gồm giá trị của
**bảng tra cứu dùng ở vị trí class**, vì tên class ở đó không nằm trong một chuỗi
`class="…"` nào — với tập class định nghĩa trong `frontpage.css`.

Nó **không** nói class đó trông đúng hay không; nó nói class đó có người định
nghĩa. Một tên không có định nghĩa thì không bao giờ là cố ý.

### Ba vòng canh, rộng dần

| Vòng | Phủ gì | Vì sao cần riêng |
|---|---|---|
| Bốn trang đọc API | **mọi** class, trích từ khối `<script>` thật sẽ lên production | Tên class ở đây do JS ghép lúc chạy, không có trong markup |
| Mọi tệp Blade | chỉ token `b-*` | Thẻ trạng thái là chỗ các bản chép tụ lại; phủ cả tệp Filament, nơi stylesheet khác |
| Mọi tệp dùng `frontpage.css` | **mọi** class, 22 tệp / 334 token | Bảy trang người mua còn lại, layout, partial và thân trang chính sách |

Vòng thứ ba tìm phạm vi bằng **tham chiếu**, không viết cứng đường dẫn: layout
nào `@vite` `frontpage.css` → trang nào `@extends` layout đó → view nào
`config/policies.php` dựng thành `body_html` → rồi đệ quy theo `@include`. Thêm
một trang người mua mới là nó tự vào phạm vi; một danh sách viết cứng thì không.

Thân bốn trang chính sách thuộc phạm vi này vì `webapp/app/globals.css` chỉ có
một dòng thật — `@import '../../resources/css/frontpage.css'`. Trang Next và
trang Blade dùng **cùng** stylesheet, nên HTML Laravel sinh ra rồi Next nhét vào
`dangerouslySetInnerHTML` cũng phải khớp cùng tập class đó.

Bộ trích của vòng này lấy chuỗi **bên trong** biểu thức Blade — cách duy nhất
thấy được `b-gray` nằm trong một ternary — nhưng **bỏ toán hạng so sánh**:
`{{ $x === 'pending_approval' ? 'b-org' : 'b-gray' }}` có ba chuỗi, chỉ hai là
tên class. Có một ca canh đúng việc đó, vì một test báo oan thì sẽ bị tắt.

### Những gì cố ý nằm ngoài

| Nhóm | Vì sao |
|---|---|
| `resources/views/filament/**` | Khu quản trị dùng CSS riêng của Filament |
| `resources/views/vendor/pagination/**` | Bản mặc định của Laravel, không phải mã dự án |
| `welcome.blade.php` | Dùng `app.css` (Tailwind): ở đó "có định nghĩa" nghĩa là *sinh ra theo yêu cầu*, nên phép kiểm tĩnh không nói được gì |
| `invitations/**` | Mỗi trang có khối `<style>` riêng trong chính nó |

## Trường API: chốt đã chuyển sang phía PHP

`truong-api.test.mjs` từng đối chiếu tên trường snake_case mà sáu khối script
đọc với **cả tệp** `docs/openapi/v2.yaml`. Nó bị thay bởi
`tests/Feature/Api/V2/TruongTrangDocTest.php`, chặt hơn ở chỗ đáng kể: chốt
mới đối chiếu với schema của **đúng endpoint** trang gọi.

Khác biệt đó không phải chi tiết. `bank_name` **có** trong đặc tả, nên bản tìm
chuỗi cho qua một trang đọc nó từ `GET payments` — nơi không trả `bank_*`.
Mà `bank_*` và `tax_code` chỉ ra ngoài qua MỘT đường (ngoại lệ đã duyệt
08/10), nên đúng chỗ đó là chỗ phải canh chặt.

Chốt ở PHP vì `symfony/yaml` đã có ở đó và `OpenApiContractTest` đã phân tích
cùng tệp đặc tả. Viết một bộ phân tích YAML bằng tay trong JS là cách chắc
chắn để có một bộ phân tích sai — bản JS đã gặp đúng chuyện đó với bộ bỏ chú
thích của nó.
