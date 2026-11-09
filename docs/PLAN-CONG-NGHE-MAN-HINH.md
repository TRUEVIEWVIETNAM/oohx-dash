# Công nghệ màn hình — trường đầu tiên ghi thay vì đoán

## Vì sao plan này tồn tại

Người mua OOH hỏi *"LED hay in bạt"* trước cả khi hỏi giá. Trước 08/11/2026 sàn
**không có một trường nào** trả lời câu đó.

Câu trả lời duy nhất là `screen_type`, và nó trả lời một câu khác:

| Câu hỏi | Trường trả lời được |
|---|---|
| Biển lớn hay màn hình nhỏ? | kích thước — **đo được**, đã có |
| Ở ngoài đường hay trong nhà? | `vn_category_id` → `venue_categories` — **khai**, đã có |
| LED, LCD hay in bạt? | **không có gì** |

`screen_type` gộp hai câu đầu rồi gọi kết quả là "loại hình", nên nó không thể
đúng cho một màn LED ngoài trời cỡ lớn. Hai hệ quả đo được trên production
ngày 08/11/2026:

- `OOHX_LED_CTO_CAN_THO` là màn LED nhưng khai 23,04 × 28,8 m nên vượt ngưỡng
  và mang nhãn **"Billboard"** — nó không tìm được bằng bộ lọc LED;
- 19 vị trí `OOHX_LED_<tỉnh>` ra **"LED"** *chỉ vì chúng chưa khai kích thước*.
  Khai đúng kích thước thật thì chúng cũng thành "Billboard" và loại hình LED
  về 0 lần nữa.

Nói cách khác: con số LED hôm nay đúng **vì một chỗ thiếu dữ liệu**, không vì
sàn biết. Đó là thứ không sửa được bằng cách sửa phép suy — nó cần một trường.

## Hai trường, và chúng KHÔNG được gộp

Đây là nguyên tắc của cả plan.

| | `screen_type` | `cong_nghe` |
|---|---|---|
| Trả lời | **hình thức** | **công nghệ** |
| Nguồn | suy ra từ kích thước | media owner **khai** |
| Từ vựng | `billboard` `led` `lcd` | `led` `lcd` `in_bat` |
| Một màn LED 6m | `billboard` | `led` |

Một màn LED cỡ lớn thuộc **cả hai** nhóm, và lọc theo nhóm nào cũng phải thấy
nó. Nên bộ lọc công nghệ là một tham số riêng (`?cong_nghe=`), không phải thêm
mã vào `screen_type`: nhồi `in_bat` vào đó là dựng lại đúng một trường gộp hai
câu hỏi, tức dựng lại chỗ sai đang sửa.

`CongNgheManHinhTest::test_man_led_co_lon_thuoc_ca_hai_nhom` là ca canh điều
này.

## `null` là "chưa khai", và nó không có mặc định

Cột **nullable, không `default`**. Cả 104 bản ghi hiện có bắt đầu từ `null`, và
đó là câu trả lời đúng cho chúng: sàn chưa hỏi media owner câu này bao giờ.

Đặt một mặc định — kể cả `'led'`, kể cả vì "phần lớn là LED" — là biến một
khoảng trống thành một lời khẳng định về 104 vị trí mà không ai kiểm. Đó đúng
là lỗi `screen_type` đang mắc, và cả cột này tồn tại để thôi mắc nó.

`chua_khai` **không** là một giá trị của enum. Nó là một **câu hỏi lọc**: *"vị
trí nào chưa trả lời câu này"*. Để nó vào enum là mời media owner chọn "chưa
khai" như một câu trả lời, và khi đó không phân biệt được *chưa trả lời* với
*đã trả lời rằng chưa biết*.

"Chưa khai" có **hai hình dạng** trong CSDL và cả hai đều có thật: không có
dòng `screen_specs` nào (20/104 bản ghi), hoặc có dòng mà cột rỗng (84 bản ghi
còn lại). Bộ lọc phải bắt cả hai — hỏi `whereNull` thôi là bỏ sót nhóm thứ
nhất, đúng cái bẫy bộ lọc loại hình đã sập ngày 19/10/2026.

## Lát cắt

### Lát 1 — cột, đường khai, đường đọc — **XONG 08/11/2026**

| Tệp | Việc |
|---|---|
| `database/migrations/2026_11_08_000002_add_cong_nghe_to_screen_specs.php` | `enum('led','lcd','in_bat')` nullable, không mặc định |
| `app/Models/ScreenSpec.php` | `CONG_NGHE_LABELS` + fillable |
| `app/Http/Resources/V2/Concerns/SuyRaLoaiManHinh.php` | `resolveScreenType()` đọc khai báo trước khi đoán |
| `app/Http/Resources/V2/ScreenSummaryResource.php` | `cong_nghe` + `cong_nghe_label` trên thẻ |
| `app/Http/Resources/V2/ScreenDetailResource.php` | hai khoá đó trong `specs` |
| `app/Services/FrontpageService.php` | `applyCongNgheFilter()`, ô đếm bốn nhóm, `cong_nghe` vào 7 `with(['spec:…'])` |
| `app/Http/Controllers/Api/V2/CatalogController.php` | ô đếm ở `/filters`, kèm nhóm `chua_khai` |
| `app/Filament/Shared/Resources/BaseScreenResource.php` | ô chọn trong tab "Kỹ thuật" |
| `app/Services/ScreenImport/{FieldCatalog,ScreenWriter}.php` | cột `spec.cong_nghe` của đường nhập kho |
| `docs/openapi/v2.yaml` | hai schema, hai tham số truy vấn, một ô đếm |
| `tests/Feature/Api/V2/CongNgheManHinhTest.php` | 15 ca |

**Thứ tự tin cậy của `resolveScreenType()`**, và mỗi bậc một lý do:

1. **`in_bat` đã khai ⇒ `billboard`.** Một tấm in bạt không phải màn hình, bất
   kể cỡ nào. Bậc này đứng **trước** ngưỡng kích thước vì nó sửa một ca sai
   thật: một pa-nô in bạt nhỏ hơn 3m, nếu rơi xuống phép đoán, sẽ ra `led` hoặc
   `lcd` — tức sàn gọi một tấm bạt là một màn hình.
2. **Cạnh ≥ 3m ⇒ `billboard`.** Giữ nguyên, và vẫn trên hai bậc dưới: nhãn
   `billboard` nói về hình thức, nên một màn LED 6m vẫn là một billboard. Công
   nghệ của nó đọc ở `cong_nghe`.
3. **`led` / `lcd` đã khai ⇒ đúng thứ đã khai.** Đây là cả điểm của cột mới:
   với màn hình không đạt ngưỡng, `screen_type` **thôi đoán**.
4. **Chưa khai ⇒ đoán theo loại điểm đặt.** Nhánh cũ, giữ cho 104 bản ghi hiện
   có. Nó teo dần theo số vị trí được khai, và đó là hình dạng đúng: một phép
   đoán chỉ chạy ở chỗ chưa có câu trả lời.

**Một quyết định tôi tự chốt, vì chưa có ai chốt:** ô chọn **không bắt buộc**
trong biểu mẫu media owner, và cột nhập kho cũng không. Đặt bắt buộc là chặn
mọi lần lưu một vị trí cũ và làm mọi tệp nhập cũ đỏ toàn bộ. Siết lại về sau là
một thay đổi một dòng; nới ra sau khi đã chặn là một lần sửa dữ liệu. Nếu chủ
dự án muốn bắt buộc cho **vị trí mới** thì nói, đó là một luật theo ngày tạo.

### Lát 2 — khai cho 104 vị trí hiện có — **chưa làm**

Cột rỗng thì bộ lọc in bốn số, ba số 0 và một số 104. Không ai hưởng lợi từ
lát 1 cho tới khi dữ liệu vào.

Đường khai đã mở sẵn hai lối: ô chọn trên Filament (từng vị trí) và cột
`spec.cong_nghe` của tệp nhập kho (hàng loạt). Việc còn lại là **hỏi hai media
owner** — Kim Ngân ADV (83 vị trí) và AdTRUE Platform (21 vị trí).

Tên vị trí đã gợi câu trả lời cho phần lớn: 19 bản ghi `OOHX_LED_<tỉnh>` và
`OOHX_LED_CTO_CAN_THO` gần như chắc chắn là LED. Nhưng **tên không phải một
trường**, và điền cột theo tên là đoán lần nữa, chỉ bằng một nguồn khác. Nên
plan này không làm thế: nó đưa danh sách cho owner xác nhận.

### Lát 3 — hàng chip trên `/explore` — **chưa làm**

API đã đủ: `/api/v2/filters` trả `cong_nghe` bốn nhóm có nhãn và số đếm,
`/api/v2/screens?cong_nghe=` lọc, và mỗi thẻ mang `cong_nghe_label`. Việc còn
lại là Next.js vẽ hàng chip trong cột lọc, đúng chỗ mockup đã đặt.

Làm sau lát 2, cùng lý do như mọi ô khác: một hàng chip bốn nhóm với ba số 0 là
một hàng chip không ai bấm.

## Cái plan này cố ý KHÔNG làm

- **Không** đổi từ vựng của `screen_type`. Nó vẫn ba mã `billboard` / `led` /
  `lcd`, nên nó vẫn **không** diễn đạt được `in_bat`, và một màn LED cỡ lớn vẫn
  mang nhãn `billboard`. Đổi nó là đổi thứ người dùng đang thấy trên trang tìm
  kiếm và trong mockup đang chờ duyệt — xem mục dưới.
- **Không** điền cột theo tên vị trí. Xem lát 2.
- **Không** bắt buộc khai. Xem lát 1.
- **Không** chạm `/api/v1`. Nó có bản `applyScreenTypeFilter()` riêng mang cả
  hai lỗi cũ, và `LoaiHinhNhanKhopBoLocTest::test_api_v1_giu_nguyen_hanh_vi_cu`
  ghim nó nguyên trạng.
- **Không** thêm giá trị thứ tư (`hop_den` chẳng hạn). Thêm là một migration
  một dòng, nên không cần đoán trước.

## Việc chủ dự án cần chốt

| Việc | Vì sao cần chốt |
|---|---|
| **`screen_type` có nên thành thuần hình thức không** | Hôm nay nó vừa nói hình thức (`billboard`) vừa nói công nghệ (`led`, `lcd`), nên nó không bao giờ hết nhập nhằng: một màn LED 6m phải chọn một trong hai. Sạch nhất là đổi nó thành hai mã — `billboard` (biển lớn) và `man_hinh` (màn hình nhỏ) — rồi để `cong_nghe` lo phần công nghệ. **Khuyến nghị của tôi: có, nhưng sau lát 2.** Nó đổi từ vựng bộ lọc mà người dùng đang thấy và đổi cả cột lọc trong mockup, nên nó phải là một quyết định được nhìn thấy trước khi làm, không phải hệ quả của một lần sửa |
| **Có bắt buộc khai cho vị trí mới không** | Hôm nay không bắt buộc (xem lát 1). Bắt buộc theo ngày tạo là một luật thêm vào biểu mẫu; bắt buộc cho tất cả là chặn mọi lần lưu vị trí cũ |
| **Có cần giá trị thứ tư không** | `hop_den` (đèn hộp) phổ biến ở nhà chờ xe buýt và trong TTTM. Nếu kho sắp có loại đó thì thêm ngay sẽ đỡ một lần migration |

## Rủi ro

| Rủi ro | Chặn bằng |
|---|---|
| Cột rỗng mãi, và bộ lọc thành bốn số vô nghĩa | Nhóm `chua_khai` in đúng con số 104 thay vì ba số 0 — nó tự nói ra việc còn phải làm. Lát 2 |
| Ai đó đặt mặc định cho cột "cho đỡ trống" | `CongNgheManHinhTest::test_cot_la_enum_ba_gia_tri_va_nullable` đỏ nếu cột có `DEFAULT` |
| Ai đó nhồi `in_bat` vào `SCREEN_TYPE_LABELS` | `NhanLoaiManHinhTest` đòi mọi mã của bảng chữ phải là một nhánh `resolveScreenType()` trả được |
| Bộ lọc "chưa khai" bỏ sót nhóm không có dòng spec | `test_chua_khai_bat_duoc_CA_HAI_hinh_dang` |
| Ô đếm lệch khỏi bộ lọc | `test_o_dem_khop_tung_bo_loc_va_co_du_bon_nhom` so từng nhóm với đúng bộ lọc của nó |
| Một lần nhập lại xoá công nghệ owner đã khai | `array_filter` của `ScreenWriter` bỏ khoá `null`, nên cột trống trong tệp nhập không ghi đè |

## Đứng ở đâu — 08/11/2026

| Lát | Trạng thái |
|---|---|
| 1 — cột, đường khai, đường đọc | **xong về mã**, chưa chạy được test — xem dưới |
| 2 — khai cho 104 vị trí | chưa; cần hỏi hai media owner |
| 3 — hàng chip trên `/explore` | chưa; API đã đủ, làm sau lát 2 |

**Chưa chạy được bộ test.** Máy còn 0,6 GB RAM trống trên 15,4 GB, và lượt chạy
trước đã bị hệ thống dừng giữa đường vì thiếu bộ nhớ. Đã chạy được: `php -l`
sạch trên cả 20 tệp PHP đã sửa, và `docs/openapi/v2.yaml` đọc được bằng trình
phân tích YAML.

Những bộ cần chạy khi máy rảnh:

```
CongNgheManHinhTest|LoaiHinhNhanKhopBoLocTest|NhanhLedToiDuocTest
|NhanLoaiManHinhTest|NhanEnumMotNoiTest|CatalogPriceStatusApiTest
|ScreenDetailApiTest|OpenApiContractTest
```

`NhanEnumMotNoiTest` nằm trong danh sách vì nó **tự tìm** hằng `CONG_NGHE_LABELS`
mới và đối chiếu hai chiều với định nghĩa enum trong CSDL — nên nó là chốt canh
cột này mà không ai phải đăng ký gì.
