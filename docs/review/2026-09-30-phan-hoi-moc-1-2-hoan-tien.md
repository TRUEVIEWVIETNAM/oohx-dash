# Phản hồi review mốc 1–2 và hoàn tiền (R36–R41)

- reviewed_sha của báo cáo: `509dcc3a8be77288074c2e9f7dcc52247c3fb6f0` — giữ nguyên, không sửa.
- Sáu finding: **1 P1, 5 P2**. Tôi xác nhận **cả sáu đều đúng**, không tranh luận cái nào.
- Bản sửa nằm trong commit ngay sau báo cáo này. `findings.jsonl` cập nhật tại chỗ theo đúng convention R01–R35: một bản ghi mỗi finding, claim và `reviewed_sha` của Codex giữ nguyên.

## Tự nhận trước khi đi vào từng cái

Ba cái sai của tôi mà báo cáo này phơi ra, đáng ghi vì chúng lặp lại hình dạng của các vòng trước:

**1. Tôi lại tuyên bố một sự thống nhất mà mình chưa hoàn thành.** Vòng bốn tôi viết "ba nơi giờ dùng một nguồn" sau khi chỉ sửa hai (R34). Lần này là hai tuyên bố: *"định dạng lỗi thống nhất cho mọi endpoint v2"* — không đúng, hai renderer chỉ bắt hai loại exception, lỗi hệ thống đi ngoài cả hai (R40); và *"API và Blade chỉ có một đường truy vấn"* — không đúng, `screen($slug)` tôi viết truy vấn riêng ngay trong controller. Cái thứ hai không chỉ là nói quá: nó **sinh ra** R38, vì truy vấn riêng nạp đủ cột network trong khi đường dùng chung thiếu `code`.

**2. Tôi viết đặc tả mà không thử gửi một request theo đúng đặc tả đó.** `OpenApiContractTest` đối chiếu hai chiều đặc tả ↔ bảng route và xanh, nên tôi tưởng hợp đồng đã được canh. Nó không kiểm cách serialize, và `style: form, explode: true` tôi khai ra là thứ PHP không đọc được (R37). Một phép kiểm xanh chỉ chứng minh đúng cái nó kiểm.

**3. Test của tôi lại chỉ đi những thứ tự mà bản sửa chịu được.** Mọi ca hoàn tiền đều xác nhận tiền **trước** rồi mới hủy. Thứ tự ngược — hủy trong khi tiền còn chờ đối soát — là đường làm mất tiền thật, và không ca nào thử (R36). Đây đúng bài học tôi đã ghi ở R12 và vẫn chưa áp dụng.

---

## R36 — P1 — Hủy khi chuyển khoản còn pending làm mất nghĩa vụ hoàn tiền

**Xác nhận đúng.** Đã kiểm ở source: `confirmBankTransfer()` chỉ đổi trạng thái khoản thanh toán và gọi `checkAndActivate()`, không đối soát lại dòng nào đã hủy. `quote()` chỉ tính khoản `completed` — đúng, vì khoản còn chờ thì chưa có đồng nào chuyển đi — nhưng đường hủy mới cho phép hủy **trong khi** khoản đó còn chờ. Kết quả đúng như báo cáo: `paid_amount = 0`, `amount = 0`, `status = waived`, rồi tiền thật vào mà không có nghĩa vụ hoàn nào, và người mua không hủy lại được để tính lại.

**Cách sửa.** Thêm `CancellationService::reconcileAfterPayment(Campaign, ?string $ownerId)`, gọi từ `confirmBankTransfer()` ngay sau khi khoản chuyển sang `completed`.

Tôi **không** dựng lại mẫu số tại thời điểm hủy. Thông tin đó không được lưu, và đoán lại là cách sinh ra lỗi tiền khó thấy — đúng loại lỗi mà R24 và R30 đã bắt hai lần. Thay vào đó một luật đơn giản, không bao giờ hoàn quá:

- Mỗi dòng đã hủy có **trần** bằng `withVat(estimated_cost)` của chính nó. Tiền đã trả được phân bổ theo tỉ lệ `estimated_cost`, nên một dòng không bao giờ được nhận nhiều hơn phần chính nó bị tính. Không có trần thì dòng hủy trước ăn hết tiền của các dòng còn lại.
- Tiền chưa phân bổ của owner chia cho các dòng đã hủy chưa xử lý, **theo thứ tự hủy**, mỗi dòng tới trần của nó.
- `refund_pct` và ảnh chụp chính sách giữ nguyên theo lúc hủy: chính sách áp theo ngày hủy, không theo ngày tiền vào.

Luật này chỉ tăng, không giảm, và là phép không làm gì khi tiền đã phân bổ đủ — nên các ca "xác nhận tiền trước rồi hủy" không đổi hành vi.

**Một lỗ tiền tôi tự tìm ra trong lúc sửa.** Bản đầu tôi cho `reconcileAfterPayment` bỏ qua khoản đã `settled`, lý do là "không ghi đè một giao dịch đã hoàn tất". Lý do đó đúng nhưng hệ quả thì sai: phần tiền vào muộn **mất luôn**, người mua trả thêm mà không được hoàn thêm. Sửa lại: khi mọi bản ghi của dòng đó đã `settled`, ghi một **nghĩa vụ mới** cho cùng dòng. Hai lần chuyển khoản, hai bản ghi, lịch sử không bị sửa. Có test cho chính ca đó.

**Đã đo:** `tests/Feature/Buyer/CancelWithPendingPaymentTest.php`, 8 ca, đi qua đúng route người mua bấm — gồm ca tỉ lệ giữ theo ngày hủy, ca trần không cho phân bổ quá, ca chạy lại không cộng dồn, ca thứ tự cũ không đổi, và ca khoản không gắn owner.

**Chưa làm:** đối soát chưa được thử dưới tranh chấp đồng thời (hai lần xác nhận song song, hoặc xác nhận trùng lúc hủy). Đã khóa dòng chiến dịch và `lockForUpdate()` trên các bản ghi hoàn tiền, nhưng **khóa đúng chỗ không phải bằng chứng không có tranh chấp** — đúng như tôi đã phải ghi ở `SerializationPointsTest`.

## R37 — P2 — Cách serialize mảng trong OpenAPI không khớp request Laravel

**Xác nhận đúng.** PHP không ghép khóa lặp thành mảng: `city=a&city=b` cho ra `'b'`, mất `'a'`. Luật `array` trong FormRequest liền trả 422.

**Kiểm thêm được một chi tiết báo cáo chưa nói:** `FrontpageService::resolveArrayParam()` **đã** nhận scalar và đã hỗ trợ dạng `|` từ trước. Nên tầng service không hỏng; chỗ chặn duy nhất là luật `array` ở tầng request. Sửa ở đó là đủ, không phải đụng vào truy vấn dùng chung với Blade.

**Cách sửa.** Đặc tả đổi sang `style: form, explode: false` (`city=hanoi,hcm`) — dạng OpenAPI chuẩn, một khóa, không mất phần tử. `FrontpageListingRequest::prepareForValidation()` tách chuỗi theo `,` và `|` cho chín tham số nhiều giá trị. Dạng `city[]=` cũ vẫn chạy nên trang Blade không đổi. `MapViewportRequest` kế thừa nên bản đồ được luôn.

**Đã đo:** bốn ca trong CatalogApiTest — một giá trị dạng scalar, nhiều giá trị dạng dấu phẩy, dạng `[]` cũ, và bản đồ.

**Chưa làm:** chưa có client sinh thật từ đặc tả rồi gửi request. Test của tôi gửi URL tôi tự viết, nên vẫn là tôi kiểm giả định của tôi.

## R38 — P2 — network.code null ở danh sách dù có network thật

**Xác nhận đúng.** `getScreensPaginated` và `getOwnerScreens` nạp `site.network:id,name,banner`; DTO đọc `code` trên quan hệ đã nạp nên nhận null.

**Cách sửa.** Thêm `code` vào mọi eager load `site.network` trong FrontpageService (sáu chỗ). Và sửa nguyên nhân gốc mà chính báo cáo chỉ ra ở phần kiến trúc: `screen($slug)` giờ dùng `getScreenDetail()` thay vì truy vấn riêng. Hai endpoint trả khác nhau **là vì** tôi có hai truy vấn, nên vá cột mà để nguyên hai đường thì lần sau lại lệch ở cột khác.

**Đã đo:** một ca kiểm `network.code` ở cả ba nơi — danh sách, chi tiết, màn hình của owner.

## R39 — P2 — API gắn nhãn VND cho CPM có thể đang lưu bằng USD

**Xác nhận đúng, và USD là trạng thái thật chứ không phải giả định.** Đã kiểm: `ScreenImport\FieldCatalog` khai enum `['VND','USD']`, Filament có `default_floor_cpm_currency` ở network, `BaseScreenResource` hiển thị giá kèm đơn vị. `computeFloorCpmUsd()` có nhánh USD riêng.

**Cách sửa.** Đổi hình dạng v2 — làm được vì v2 mới một ngày và chưa có bên tiêu thụ nào:

| Trước | Sau |
|---|---|
| `pricing.floor_cpm_vnd` (số nguyên) | `pricing.floor_cpm{amount, currency}` |
| `pricing.io_rate_vnd`, `io_rate_unit` | `pricing.io_rate{amount, currency, unit}` |
| MapPin `price{amount_vnd, unit}` | MapPin `price{amount, currency, unit}` |
| `filters.price_range_vnd{min,max}` | `filters.price_range{currency, min, max}`, **chỉ tính hàng VND** |

Không tự quy đổi: tỷ giá cần một chính sách có người chốt, không phải một hằng số lẻn vào DTO. `amount` không làm tròn về số nguyên ở nhánh CPM — 2,50 USD làm tròn thành 3 là làm sai dữ liệu, không phải làm gọn.

`io_rate` **không có** cột currency trong CSDL nên là VND theo định nghĩa. Trường `currency` vẫn có mặt để giả định đó nhìn thấy được trong phản hồi chứ không nằm im trong đầu ai. Đây cũng là một khoảng trống của mô hình dữ liệu: nếu một owner cho thuê theo kỳ bằng USD thì không có chỗ nào ghi.

`getFilterAggregates()` cũng được sửa, nên **thanh lọc giá trên trang Blade cũng thôi bị hàng USD kéo lệch** — cùng một lỗi, cùng một nguồn.

**Đã đo:** ba ca — giá CPM bằng USD ở chi tiết và danh sách, pin bản đồ, và khoảng giá không bị hàng USD kéo lệch.

**CÒN LẠI, chưa sửa:** `min_price`/`max_price` trong `buildScreenQuery()` vẫn so sánh `floor_cpm` bất kể đơn vị. Lỗi này có trước và Blade dùng chung đúng truy vấn đó, nên sửa nó là đổi hành vi trang đang chạy — tôi để riêng thay vì gộp vào đây. Đặc tả đã ghi giới hạn này. Tôi cũng **không biết có bao nhiêu hàng USD trong dữ liệu thật**: không truy cập production được, nên không nhận định về mức độ ảnh hưởng.

## R40 — P2 — Endpoint owners nhận input chưa validate và có thể trả 500

**Xác nhận đúng.** `q` đi thẳng vào `'%' . input('q') . '%'`; mảng gây "Array to string conversion", Laravel biến warning thành ErrorException, endpoint công khai trả 500.

**Và nhận cái sai lớn hơn mà finding này phơi ra.** Báo cáo nói đúng: "tuyên bố mọi lỗi v2 có envelope thống nhất chưa đúng". Hai renderer tôi viết chỉ bắt `ValidationException` và `HttpExceptionInterface`; mọi lỗi hệ thống đi ngoài cả hai. Tôi đã viết câu tuyên bố đó vào cả commit message lẫn đặc tả.

**Cách sửa.** Thêm `OwnerListingRequest` cho `q`/`type`/pagination. Thêm renderer `Throwable` cho `api/v2/*` trả envelope 500 — thông điệp thật chỉ khi `app.debug` bật, production trả câu chung và **không bao giờ** có stack trace. Đặc tả bổ sung 422 và 500 cho endpoint này.

**Đã đo:** ba ca — `q[]` trả 422 có envelope, `type[]` trả 422, và `q` dạng chuỗi vẫn lọc đúng.

## R41 — P2 — Renderer lỗi v2 làm mất Retry-After và Allow

**Xác nhận đúng.** `response()->json([...], $status)` không mang theo `$e->getHeaders()`.

**Cách sửa.** Truyền `$e->getHeaders()` vào tham số thứ ba. Đặc tả khai `Retry-After` trên phản hồi 429.

**Đã đo:** một ca — 405 giữ được `Allow`.

**CHƯA đo:** 429 giữ `Retry-After`. Cần đẩy rate limiter thật trong bộ test và tôi chưa dựng. **Tôi không nhận là đã kiểm chuyện đó** — chỉ nhận là đã truyền header vào đúng chỗ và ca 405 chứng minh cơ chế truyền hoạt động.

---

## Những điểm báo cáo nêu mà tôi không sửa trong vòng này

- **"Blade đi qua hợp đồng API"** — vẫn chưa thực hiện, đúng như lộ trình đã ghi và báo cáo nhắc lại. Báo cáo nói đúng một điều tôi chưa nói rõ: R37 và R38 là **bằng chứng** rằng route parity và `assertJsonStructure` không bù được khoảng trống đó. Tôi giữ nguyên quyết định dùng chung service cho phần đọc, nhưng nay có hai lỗi thật để chứng minh cái giá của nó, không còn là suy đoán. Quyết định vẫn để mở cho bạn.
- **Quyền hủy hộ của quản trị sàn** dùng chung `RefundPolicy`/cổng `super_admin` chứ chưa có quyền riêng. Báo cáo cảnh báo đúng là không nên tái dùng policy buyer một cách máy móc khi đưa thêm API. Chưa cần trong vòng này; ghi lại để mốc 3 không làm máy móc.
- **Test Livewire end-to-end cho action Filament** — vẫn chưa có. Báo cáo đã tự kiểm vendor và không kết luận sai về chuỗi `isDisabled()`; tôi cũng không dựa vào đó để nhận là an toàn. Đây vẫn là khoảng trống.
- **Xác nhận/hoàn tiền đồng thời, hủy hai dòng đồng thời** — chưa đo.

## Điều kiện để chốt

Tôi **không** dùng số ca CI để thay cho các phép kiểm còn thiếu. Ba khối này nên coi là đã sửa sáu finding, không phải đã hoàn tất. Những chỗ tôi tự ghi là chưa đo ở trên là những chỗ vòng sau nên nhắm vào trước.
