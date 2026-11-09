# Máy đếm lượt xem vị trí

## Vì sao plan này tồn tại

Mockup sàn có ba chỗ cần biết một vị trí được xem nhiều hay ít:

| Chỗ | Cần gì |
|---|---|
| `/explore` — tuỳ chọn sắp xếp **"Phổ biến nhất"** | thứ tự theo lượt xem |
| Trang chi tiết — một dòng dưới tên vị trí | số lượt xem của chính vị trí đó |
| Khu media owner | *"kho của Quý đối tác được xem bao nhiêu lượt"* |

Hôm nay cả ba đều **không dựng được**, vì sàn không có một bản ghi lượt xem
nào. Trong mockup chúng là ba ô xám ghi "chưa có dữ liệu", và tuỳ chọn
`Phổ biến nhất` được vẽ ra nhưng **khoá lại** (`<option disabled>`) kèm chú
thích *"chưa có dữ liệu lượt xem"*.

Ba ô đó sẽ không bao giờ bật được nếu không có đường đo này, và cái thay thế
duy nhất là một con số bịa. Đã có tiền lệ đúng loại ấy trên chính sàn này: bốn
thẻ "Điểm nổi bật" của trang chi tiết in *"AI Traffic Data · cập nhật theo giờ
thực"* vô điều kiện cho mọi màn hình trong khi cả chín trường `insights` là
`null` — xem docblock `webapp/app/explore/[slug]/page.tsx`.

## Nguyên tắc: đếm, không ghi nhật ký

Cùng ranh giới, cùng lý do và cùng hình dạng như `do_dac_ngay`
(`docs/PLAN-DO-DAC-TOI-THIEU.md`). Bảng gộp theo `(ngày, màn hình)`, không theo
người.

| | Bảng này | Nhật ký lượt xem (KHÔNG làm) |
|---|---|---|
| Lưu gì | `(ngày, màn hình) → số lượt` | một hàng mỗi lần ai đó mở trang |
| Định danh ai | **không** — không `user_id`, không id phiên, không IP | có, kể cả khi "ẩn danh" |
| Trả lời được | "vị trí nào được mở nhiều" | "người này đã xem những vị trí nào" |
| Lớn theo | số **màn hình** — tối đa 104 hàng mỗi ngày | số **lượt truy cập** |

Khác biệt đó quyết định bảng này có phải dữ liệu cá nhân hay không, tức quyết
định Nghị định 13/2023/NĐ-CP có áp vào hay không. Migration
`create_do_dac_ngay_table` đã viết ra ranh giới ấy và nói trước đúng điều đang
xảy ra ở đây:

> *"ngày nào có người muốn biết **người dùng X đã xem những màn hình nào**, đó
> là một bảng khác, một plan khác, có đồng ý của người dùng và có mục trong
> chính sách bảo mật. Đừng thêm cột vào đây để làm việc đó."*

Bảng `luot_xem_ngay` **không phải** bảng đó, và lát 4 dưới đây cho thấy nó
không cần phải là bảng đó: ô *"Vị trí đã xem gần đây"* — thứ duy nhất trong
mockup thật sự cần biết **một người** đã xem gì — giải được hoàn toàn trong
`localStorage` của từng trình duyệt, không có gì rời khỏi máy người dùng.

## `so_luot` là SỐ LƯỢT MỞ TRANG, không phải SỐ NGƯỜI

Đây là chỗ dễ nói sai nhất của cả plan, nên nó phải nằm ngay đầu.

Máy chủ không biết người gửi là ai — đó là cả điểm của thiết kế trên — nên nó
**không thể** gộp hai lượt của cùng một người. Phép gộp duy nhất nằm ở client:
`webapp/lib/luot-xem.ts` gửi nhiều nhất một lần cho mỗi màn hình trong mỗi
phiên trình duyệt (`sessionStorage`). Phép gộp ấy biến con số từ *"số lần tải
trang"* thành *"số phiên đã mở trang"* — gần với cái đang cần hơn nhiều — nhưng
nó **thiện chí, phá được bằng tay**, không phải một bảo đảm.

Hệ quả, và nó quyết định cách gắn nhãn trên giao diện:

- gọi nó là **"lượt xem"** thì đúng;
- gọi nó là *"người quan tâm"*, *"khách đã xem"*, *"số người tìm vị trí này"*
  hay bất cứ gì ngụ ý một con người là **sai**, và sai theo chiều thổi phồng.

Lát 3 phải giữ đúng chữ này.

## Lát cắt

### Lát 1 — bảng đếm, service và đường nhận — **XONG 08/11/2026**

| Tệp | Việc |
|---|---|
| `database/migrations/2026_11_08_000001_create_luot_xem_ngay_table.php` | ba cột, khoá duy nhất `(ngay, screen_id)`, hai chỉ mục, khoá ngoại xoá kèm |
| `app/Services/LuotXemService.php` | `ghiNhan()` — một câu `INSERT … ON DUPLICATE KEY UPDATE`; `homNay()` theo giờ Việt Nam |
| `app/Services/FrontpageService.php` | `timIdCongKhai()` — tra id của một màn hình **công khai** theo bất cứ định danh nào |
| `app/Models/Screen.php` | `scopeTheoDinhDanhCongKhai()` — phép khớp bốn định danh, nay dùng chung với trang chi tiết |
| `app/Http/Controllers/Api/V2/LuotXemController.php` | 204 thân rỗng; 404 nếu không phải màn hình công khai |
| `routes/api.php` | `POST /api/v2/screens/{slug}/luot-xem`, `throttle:60,60` |
| `tests/Feature/Api/V2/LuotXemApiTest.php` | 13 ca |

**Bốn quyết định của lát này, và lý do từng cái:**

**1. Một `POST` từ trình duyệt, KHÔNG đếm trong `GET /screens/{slug}`.** Đếm
trong phép đọc là cách viết ít mã nhất và là cách sai. `/explore/{slug}` là
trang Next render ở máy chủ, nên mỗi lượt gọi `GET /api/v2/screens/{slug}` là
một trong bốn thứ: một người thật mở trang, một lượt render lại khi cache hết
hạn, một con bot đọc `sitemap.xml` (sàn có 104 URL chi tiết trong đó), hoặc một
lượt `next build` prerender. Chỉ dạng đầu là một lượt xem; ba dạng còn lại là
máy chủ tự nói chuyện với chính nó, và chúng **đông hơn người thật** ở một sàn
mới. Con số sẽ đo lưu lượng crawler, và sai theo đúng chiều tệ nhất: vị trí nào
Google ghé nhiều thì trông "phổ biến" nhất.

Còn một cái bẫy thứ hai, độc lập: `getScreenDetail()` bọc trong
`Cache::remember` 300 giây. Một phép cộng đặt **bên trong** closure đó chỉ chạy
khi cache hết hạn — tối đa 12 lượt mỗi giờ cho mỗi màn hình, bất kể bao nhiêu
người mở. Đặt **bên ngoài** thì nó đếm cả bốn dạng trên. Không có chỗ nào trong
phép đọc ấy là chỗ đúng.

**2. 204 thân rỗng.** Một phản hồi mang `{"so_luot": 318}` biến đường công khai
này thành chỗ đọc số liệu kinh doanh của sàn — vị trí nào đang được quan tâm —
bằng cách gửi vào một lượt. Có công bố con số đó hay không là một quyết định
của giao diện (lát 3), không phải của một đường ghi.

**3. 404 khi slug không phải màn hình công khai.** Không lộ gì hơn
`GET /screens/{slug}` đã lộ — cùng một phép kiểm `publiclyVisible()`, cùng một
câu trả lời. Nhưng nó chặn một thứ thật: không có nó thì ai cũng dựng được số
lượt xem cho những màn hình **đang tắt** hoặc của owner **chưa được sàn
duyệt**, và những con số ấy nằm sẵn trong bảng chờ ngày màn hình được bật lại.

**4. Phép khớp định danh thành một scope dùng chung.** Trang chi tiết nhận cả
`slug`, `id`, `uuid` và `external_id`. Nếu đường đếm chỉ nhận `slug` thì những
vị trí người ta vào bằng hai dạng sau có con số **vĩnh viễn bằng 0** trong khi
trang vẫn mở bình thường. Hai bản viết rời sẽ lệch đúng vào ngày đó, nên phép
khớp sống ở một chỗ: `Screen::scopeTheoDinhDanhCongKhai()`.

### Lát 2 — client gửi từ trang chi tiết — **XONG 08/11/2026**

| Tệp | Việc |
|---|---|
| `webapp/lib/luot-xem.ts` | `ghiLuotXem(slug)` — không chặn luồng, không cookie, gộp theo phiên |
| `webapp/components/GhiLuotXem.tsx` | `useEffect` chạy trong trình duyệt; không vẽ gì |
| `webapp/app/explore/[slug]/page.tsx` | nối dây một dòng |
| `tests/js/luot-xem.test.mjs` | 14 ca |

**Ba điều kiện bắt buộc, giống `do-dac.ts`:**

1. **Không bao giờ chặn luồng.** `fetch` không `await`, lỗi nuốt im lặng. Một
   phép đo làm chậm tính năng nó đo là một phép đo tự huỷ.
2. **Không gửi ở máy phát triển.** Dùng chung `nenGuiDoDac()` của `do-dac.ts`,
   **không chép lại** — phép kiểm đó đã có bốn ca chốt riêng, kể cả hai ca tên
   miền giả mạo (`oohx.net.evil.com`, `notoohx.net`). Thiếu nó thì mỗi lần
   `next start` ở máy lại bơm số rác vào bảng production, và rác đó **không gỡ
   ra được**: bảng chỉ có số cộng dồn, không cột nào nói lượt nào từ đâu tới.
3. **Không gửi cookie phiên.** `credentials: 'omit'`. Để `fetch` kèm cookie
   theo mặc định cùng nguồn gốc thì mỗi lượt xem mang theo danh tính người dùng
   tới máy chủ — không vào bảng, nhưng vào nhật ký truy cập của OpenLiteSpeed,
   nơi nó sống ngoài mọi lời hứa của plan này.

**Hai lỗi im lặng mà `tests/js/luot-xem.test.mjs` canh riêng:**

- **Mảng phụ thuộc của `useEffect` thiếu `slug`.** Next điều hướng phía client
  giữa hai trang `/explore/{slug}` không dựng lại component — nó chỉ đổi props.
  Để `[]` thì effect chạy đúng một lần cho cả chuỗi vị trí người dùng xem trong
  một phiên duyệt, và **mọi vị trí sau vị trí đầu tiên không được đếm**.
- **Đường dẫn client lệch route Laravel.** Mọi lượt gửi nhận 404, bảng vĩnh
  viễn rỗng, trang chi tiết vẫn chạy bình thường. Ca `đường dẫn client khớp
  route Laravel` đọc thẳng `routes/api.php` và so, nên hai bên không lệch được.

### Lát 3 — ba chỗ đọc — **chỉ làm khi bảng đã có số liệu**

Đây là lát làm ba ô xám trong mockup bật lên. Nó **chưa làm**, và không nên làm
trước khi bảng có số thật: một phép xếp hạng tính trên một bảng gần rỗng vẫn
**trông như một phép xếp hạng**, nên nó sẽ được mang đi quyết định vị trí nào
lên đầu trang chủ.

| Chỗ | Việc |
|---|---|
| `/explore` | mở khoá `sort=pho-bien`; bỏ `disabled` và bỏ chú thích "chưa có dữ liệu" |
| Trang chi tiết | một dòng *"N lượt xem trong 30 ngày"* — thay một trong ba ô `chưa có` |
| Khu media owner | lượt xem từng vị trí của owner đó, cùng cửa sổ 30 ngày |

**Ba điều lát 3 phải làm đúng:**

**1. Ngưỡng.** Dưới ngưỡng thì **không in thứ tự nào** và giữ tuỳ chọn khoá,
thay vì in một thứ tự tính trên vài chục lượt. Đề xuất: cửa sổ 30 ngày phải có
tối thiểu **1.000 lượt** và tối thiểu **30 vị trí có số** — con số cần chủ dự
án chốt, xem mục dưới. Cùng tinh thần `DoDacService::NGUONG_DU_LIEU`.

**2. Chặn một ngày bơm số đè cả bảng.** `so_luot` phá được bằng tay, nên một
owner tự bơm số cho vị trí của mình là một ca thật, không phải một ca lý
thuyết. Phép xếp hạng nên cộng `LEAST(so_luot, <trần ngày>)` thay vì `so_luot`
trần: khi đó một ngày bơm số đóng góp nhiều nhất bằng một ngày đông khách bình
thường, và muốn lên đầu bảng thì phải bơm đều suốt 30 ngày. Nó không chặn hẳn,
nhưng nó đổi chi phí của việc gian lận từ "một buổi chiều" thành "một tháng".

**3. Lọc lại `publiclyVisible()` khi đọc.** Bảng giữ nguyên số của một màn hình
đã bị tắt sau đó. Phép xếp hạng tin vào bảng thôi là xếp cả những vị trí không
còn mở — và liên kết sẽ trả 404.

### Lát 4 — "Vị trí đã xem gần đây" — **không chạm bảng này**

Ô này trong mockup trang chủ hiện mang chip `cần bảng lượt xem`. Chip đó **sai**
— nó không cần bảng này, và không nên dùng bảng này.

"Đã xem gần đây" là một tiện ích **của một trình duyệt**, không phải một con số
của sàn. Nó thuộc `localStorage`, đúng như danh sách rút gọn đã làm
(`webapp/lib/danh-sach-rut-gon.ts`): lưu vài slug gần nhất ở máy người dùng,
không gửi gì đi, không có bảng nào, không có câu hỏi pháp lý nào phát sinh.

Làm nó bằng cách đọc bảng lượt xem thì phải biết *người nào* đã xem *vị trí
nào* — tức đúng cái bảng mà `create_do_dac_ngay_table` nói là một plan khác.
Không có lý do nào để trả giá đó cho một ô tiện ích.

## Hai việc chủ dự án cần chốt

| Việc | Vì sao cần chốt |
|---|---|
| **Số lượt xem có cho người mua thấy không** | Hôm nay đường ghi trả 204 rỗng nên chưa ai thấy gì, và quyết định còn mở. Công bố nó là nói cho cả thị trường biết vị trí nào đang được quan tâm — có lợi cho người mua (tín hiệu thật), và là thông tin kinh doanh của media owner. Ba khả năng: công bố cho mọi người; chỉ cho media owner thấy kho của mình; hoặc chỉ dùng nội bộ để sắp thứ tự mà không in con số. **Khuyến nghị của tôi:** dùng để sắp thứ tự + cho owner thấy kho của mình, chưa in con số cho người mua, cho tới khi số liệu đủ dày để một con số nhỏ không làm một vị trí trông chết |
| **Ngưỡng bật phép xếp hạng** | Đề xuất 1.000 lượt / 30 vị trí có số trong cửa sổ 30 ngày. Thấp hơn thì "Phổ biến nhất" là một thứ tự gần như tuỳ ý mà trông có cơ sở |
| **Có ghi mục vào chính sách bảo mật không** | Ngày 23/10/2026 chủ dự án chốt **không** cho `do_dac_ngay`, và lý do đứng được là bảng đó không thu dữ liệu cá nhân. Bảng lượt xem có **cùng hình dạng** và lát 4 cố ý không thu gì ở máy chủ, nên cùng lập luận áp vào. **Khuyến nghị của tôi vẫn là *có*** — một dòng nói sàn đếm lượt xem theo vị trí chứ không theo người thì rõ với người đọc hơn là một lời hứa chỉ nằm trong mã. Nhưng nó là quyết định của chủ dự án, và nó đứng được cả hai cách |

Tôi **không** khuyến nghị gì về mặt pháp lý — tôi không đủ thẩm quyền. Thiết kế
trên cố ý tránh dữ liệu cá nhân để câu hỏi pháp lý không phát sinh.

## Rủi ro

| Rủi ro | Chặn bằng |
|---|---|
| Đường ghi công khai bị bơm số | `throttle:60,60` + slug phải là màn hình công khai thật + bảng đếm (không phình theo lượt). Bơm số **không** làm bảng lớn, chỉ làm vài con số cao hơn sự thật |
| Owner tự bơm số cho vị trí của mình | Chưa chặn. Lát 3 phải cộng `LEAST(so_luot, <trần ngày>)` — xem trên |
| `so_luot` bị đọc thành "số người" | Nhãn giao diện phải là "lượt xem"; docblock của migration và service nói thẳng điều này |
| Bot chạy JS làm lệch số | Chưa chặn, giống `do_dac_ngay`. Nếu số liệu trông sai thì đó là nghi can đầu tiên |
| Số liệu lẫn lượt chạy ở máy phát triển | Chỉ gửi khi hostname là `oohx.net` hoặc tên miền con của nó |
| Phép xếp hạng đọc cả vị trí đã tắt | Lát 3 phải lọc lại `publiclyVisible()` khi đọc, không tin bảng |
| Bảng này lớn dần thành bảng hành vi | Ca `bảng không có cột nào định danh người xem` trong `LuotXemApiTest`; ranh giới ghi ở mục "Nguyên tắc" |

## Cái plan này cố ý KHÔNG làm

- **Không** đếm theo người, theo phiên hay theo thiết bị ở máy chủ.
- **Không** lưu IP, user agent, đường dẫn giới thiệu.
- **Không** đếm lượt hiện trong danh sách `/explore` — chỉ lượt **mở trang chi
  tiết**. Một lượt cuộn qua một thẻ không phải một lượt xem, và đếm nó là làm
  con số lớn gấp vài chục lần mà không mang thêm thông tin nào.
- **Không** dùng dịch vụ phân tích bên thứ ba: nó đẩy dữ liệu người dùng Việt
  Nam ra khỏi hạ tầng của sàn, một quyết định lớn hơn nhiều so với ba ô đang
  cần bật.
- **Không** đọc gì từ bảng cho tới khi có số liệu thật (lát 3).

## Đứng ở đâu — 08/11/2026

| Lát | Trạng thái |
|---|---|
| 1 — bảng, service, đường nhận | **xong** · 13/13 ca xanh trên MySQL 8.0.46 |
| 2 — client gửi từ trang chi tiết | **xong** · 14/14 ca xanh |
| 3 — ba chỗ đọc | chưa, và **chỉ làm khi bảng có số liệu** |
| 4 — "đã xem gần đây" | chưa, và **không dùng bảng này** |

Đường ghi **nối liền**: trang chi tiết mở → client gửi một lượt → bảng cộng
dồn. Nhưng chưa có gì đọc nó, và bảng sẽ rỗng cho tới lần deploy tiếp theo.

**Ba ô xám trong mockup chỉ bật được sau khi đủ số liệu.** Đừng mở khoá
`Phổ biến nhất` sớm hơn thế, và đừng hạ ngưỡng để mở sớm hơn — plan này tồn tại
vì cái thay thế duy nhất cho một con số không có là một con số bịa, và một thứ
tự tính trên bảng gần rỗng chính là một con số bịa có vẻ ngoài của một phép đo.
