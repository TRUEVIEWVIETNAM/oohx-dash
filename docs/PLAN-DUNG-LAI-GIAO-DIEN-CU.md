# Dựng lại giao diện cũ trên API + Next.js

Viết 14/10/2026 · Claude Code · Đối chiếu bản Blade trước giai đoạn 7 với bản Next đang chạy

> **Tiến độ, cập nhật 17/10/2026.** **Cả bảy lát đã làm** (chủ dự án duyệt
> từng lát). Những gì còn thiếu so với bản Blade đều là quyết định về lời hứa
> sản phẩm, liệt kê ở cuối tài liệu.
>
> **Hai chỗ bản kế hoạch này đo sai, phát hiện khi làm:**
>
> 1. Bảng dưới nói lát 0 "không cần API". Sai: `cover_url` của owner trả
>    **đường dẫn trong disk** chứ không phải URL, nên mọi ảnh bìa owner trên
>    `/owners` và trang chủ là ảnh hỏng — và thẻ owner của bản Blade còn in
>    logo, số tỉnh thành, nhãn loại hình, dấu đã xác minh mà DTO không mang ra.
>    Bốn trường đó `loadOwnerExtras()` **đã tính sẵn**. Nên lát 0 có một lần sửa
>    DTO.
> 2. Bảng dưới nói lát 2 dựng ô tìm kiếm lớn được từ `locations` + `filters`
>    "phía client". Sai: cây *loại hình → network* không suy ra được từ hai danh
>    sách phẳng, vì `networks` không mang khóa loại hình nào. `/api/v2/filters`
>    nay trả thêm `categories_networks` — một trường **thêm vào endpoint có
>    sẵn**, không phải endpoint mới.
> 3. Bảng dưới nói lát 3 dùng được `filters` đã có, kèm dấu hỏi "phải kiểm đủ
>    trường cho sidebar gói". Đã kiểm: **không đủ**. `/api/v2/filters` đếm trên
>    bảng **màn hình** — `cities` ở đó là thành phố có màn hình, `venue_types`
>    là loại điểm đặt. Sidebar gói cần `categories` của gói, `types`
>    (đơn lẻ/gói/tùy chỉnh) và `cities` **của gói**: cùng tên khóa `cities`,
>    khác con số. Nên lát 3 có một endpoint mới,
>    `GET /api/v2/products/filters`, chứ không phải một trường thêm.
>
> **Ba việc cần chủ dự án quyết** (đều là lời hứa sản phẩm, không phải kỹ
> thuật): thanh `trust` năm mục, mục "Marketplace OOH đầu tiên có AI", và câu
> chữ của bốn thẻ WORKFLOW. Chi tiết ở mục "Ba khối chưa dựng vì chúng là lời
> hứa, không phải hình" ở cuối tài liệu.

## Vì sao tài liệu này tồn tại

Sở Thông tin sẽ vào chụp lại màn hình. Banner trên mọi trang công khai hiện nói
*"website đang chạy thử nghiệm, chưa hoàn tất đăng ký với Bộ Công Thương"*, nên
đợt chụp này gần như chắc chắn phục vụ hồ sơ đó — và ảnh chụp phải khớp với
giao diện đã đăng ký.

Trang công khai đã rời Blade ngày 07–08/10/2026 (giai đoạn 6 và 7). Câu hỏi phải
trả lời trước khi ai đó chụp màn hình: **bản Next có còn là cùng một giao diện?**

Câu trả lời ngắn: **đường dẫn thì đủ, giao diện thì không.**

| Nhóm trang | Tình trạng |
|---|---|
| 4 trang pháp lý (`quy-che-hoat-dong`, `chinh-sach-bao-mat`, `giai-quyet-tranh-chap`, `bang-phi`) | **Y NGUYÊN** — chuyển đúng từng tên lớp CSS |
| 2 trang phản ánh TCXH (`/phan-anh-to-chuc-xa-hoi`, `/danh-sach`) | **Y NGUYÊN** — đúng từng tên lớp |
| Khối pháp lý ở chân trang (tên pháp nhân, MSDN, địa chỉ, người đại diện, đầu mối liên hệ cơ quan nhà nước) | **Y NGUYÊN**, có trên mọi trang |
| `/login`, `/register` | Chuyển đủ |
| `/agency` | Chuyển gần đủ — dùng đúng bộ `oc-card-*` như bản cũ |
| **8 trang danh mục** (`/`, `/explore`, `/explore/{slug}`, `/owners`, `/owners/{slug}`, `/products`, `/products/{slug}`, `/map`) | **RÚT GỌN ĐÁNG KỂ** — mất phần lớn khối giao diện |

Nghĩa là: thứ cơ quan quản lý cần đọc thì còn nguyên văn và nguyên hình. Thứ
người mua nhìn thấy đầu tiên — trang chủ và trang kho — thì không còn giống bản
cũ.

## Cách đo, để ai cũng kiểm lại được

Markup cũ **không mất**, nó nằm trong git:

```bash
git show d7d3446^:resources/views/frontpage/index.blade.php   # trang chủ bản Blade
git show 10cbc86^:resources/views/frontpage/agency.blade.php  # /agency, gỡ ở commit sau
```

`d7d3446` là commit "gỡ trang công khai Blade đã được Next thay" (07/10),
`10cbc86` là lát cuối (`/agency`). Tổng cộng 15 view trang + 11 partial + 3
controller.

CSS cũng **không mất**. `resources/css/frontpage.css` (2.211 dòng) vẫn là
stylesheet duy nhất của cả hai bản — `webapp/app/globals.css` chỉ có một dòng
thật: `@import '../../resources/css/frontpage.css'`.

Đo mức độ giao diện cũ đang bị bỏ không, bằng cách đếm tên lớp:

```bash
# 1.094 tên lớp được định nghĩa trong frontpage.css
grep -ohE "\.[a-zA-Z][a-zA-Z0-9_-]*" resources/css/frontpage.css | sed 's/^\.//' | sort -u | wc -l
# rồi với mỗi tên: có trang nào trong webapp/ hoặc resources/views/ tham chiếu không
```

| Phép đếm | Kết quả |
|---|---|
| Tên lớp định nghĩa trong `frontpage.css` | **1.094** |
| Không trang nào hôm nay dùng | **803** (73%) |
| Trong 803 đó, thuộc chính các view đã gỡ — tức dựng lại là dùng lại được | **445** |
| Không ai dùng, kể cả view cũ — CSS chết từ các thiết kế trước (`about-*`, `ag-hero*`, `bk-layout`, `bar-chart`…) | **358** |

445 tên lớp đang chờ sẵn là tin tốt: **dựng lại giao diện cũ không phải thiết kế
lại.** Markup lấy từ git, CSS đã nằm trong repo, dữ liệu đã có endpoint hoặc đã
có service sẵn.

## Từng trang: cũ có gì, nay còn gì

### `/` — trang chủ

Bản Blade có **10 khối**; bản Next có **5**, và thứ tự khác.

| Khối bản cũ | Bản Next |
|---|---|
| Hero + **mega-search** (ô tìm kiếm lớn: tỉnh / loại điểm / loại màn hình, cây chọn địa điểm, chip đã chọn — `partials/mega-search.blade.php` 158 dòng + 187 dòng JS) | Hero có, **mega-search không có** |
| Duyệt theo địa điểm | ✔ |
| `{N}` Tỉnh thành (lưới liên kết theo vùng) | ✔ nhưng **dời xuống cuối trang** |
| Vị trí nổi bật (băng trượt + dots) | ✔ (lưới, không băng trượt) |
| **Live Map View** — bản đồ Leaflet nhúng, thanh thành phố, dropdown "thêm", hai nút zoom, popup, bộ đếm "N màn hình tại X" | **không có** |
| Trusted Media Owners | ✔ (đổi chữ: "Media owner trên sàn") |
| **4 bước đặt booking OOH** | **không có** |
| **Marketplace OOH đầu tiên có AI** | **không có** |
| **Gói chiến dịch** (`pkg-grid`, ba thẻ gói) | **không có** |
| **Owner CTA** ("Đưa inventory của bạn lên OOHX", `owner-cta` + ba thẻ tính năng) | **không có** |

### `/explore` — danh sách kho

| Bản cũ | Bản Next |
|---|---|
| mega-search ngay trên danh sách | không có |
| `results-header` + `rh-count` (đếm kết quả) + ô sắp xếp | chỉ một dòng đếm trong hero |
| Bộ lọc hiện ra cho người dùng | **không có** — trang đọc `city`, `screen_type`, `sort` từ URL rồi vẽ lưới. Nặng hơn: thông báo rỗng viết *"Không có vị trí nào khớp bộ lọc"* trong khi **không có bộ lọc nào để đổi** |
| `inv-grid` + `list-view` (đổi kiểu xem lưới / danh sách) | `oc-grid oc-grid--full` — lưới dành cho thẻ **chủ sở hữu**, không phải thẻ màn hình |
| Phân trang | ✔ |

### `/explore/{slug}` — chi tiết màn hình

Bản cũ 776 dòng; bản Next 189. Mất gần hết:

| Bản cũ | Bản Next |
|---|---|
| Thư viện ảnh + thumbnail + **lightbox** (`lb-overlay`), badge còn/hết suất 30 ngày, bộ đếm ảnh | **một ảnh** |
| `stats-row` (bốn ô số liệu) | không có |
| **Bốn tab**: Thông tin · Insights · Lịch trống · Đánh giá | không có |
| `specs-grid` (bảng thông số) | có, dạng `<dl>` đơn giản |
| **screen-insights** (315 dòng: vị trí đặt, lưu lượng, chân dung khán giả, hiệu suất theo giờ, bối cảnh quanh điểm, ghi chú phương pháp) | không có |
| **Lịch trống theo tháng** (`cal-grid` + chú giải màu) | một dòng chữ về SOV 30 ngày |
| **Panel giá** (`bp-*`): chọn kiểu bán I/O hay CPM, ngày bắt đầu, thời lượng, số màn hình, số CPM, tên brand, tính tiền ngay trên trang | không có |
| Thẻ chủ sở hữu, màn hình gần đó (`getSimilarScreens`), khối vị trí + bản đồ | không có |
| ~~Đánh giá 4.9 sao + ba nhận xét~~ | **không được dựng lại** — xem mục dưới |

### `/owners` và `/owners/{slug}`

| Bản cũ | Bản Next |
|---|---|
| `/owners`: `pg-hero` + **ô tìm kiếm** (`pg-hero-search`) + **chip lọc theo loại** (`cat-chips`) + lưới thẻ `oc-card-*` | `pg-hero` trơn + lưới thẻ dùng **năm tên lớp không tồn tại** (lỗi #2) |
| `/owners/{slug}`: bố cục trang hồ sơ — ảnh bìa, avatar, tagline, meta, lưới số liệu, tab, cột phải (thông tin liên hệ, panel), thẻ nổi bật, bộ lọc kho, băng trượt, **đánh giá của người mua**. CSS 98 dòng nằm ngay trong view | `pg-hero` + một tiêu đề + lưới màn hình |

### `/products` và `/products/{slug}`

| Bản cũ | Bản Next |
|---|---|
| `/products`: **sidebar lọc** (`sidebar`, `sb-group`, `sb-checkbox`, `sb-search-inp`, `sb-count`), results header, ô sắp xếp, **drawer lọc cho mobile** (`drawer-drag`, `filter-backdrop`, `filter-float`), `load-more`, thẻ gói riêng (`pd-card`) | lưới phẳng, thẻ dùng lớp của **thẻ màn hình** (`sc-*`), không bộ lọc nào |
| `/products/{slug}`: gallery + thumbnail, bố cục hai cột, **panel giá có tab** (`bp-tabs`), **danh sách màn hình trong gói** có checkbox và ảnh nhỏ | danh sách thông số + lưới màn hình |

### `/map`

| Bản cũ | Bản Next |
|---|---|
| Bản đồ + cluster + popup | ✔ (còn tốt hơn: thư viện nạp từ npm, không từ unpkg) |
| **smart-filter** (sidebar lọc: tỉnh, loại điểm, loại màn hình, network, owner — 101 dòng + 98 dòng JS) | **không có** — lọc chỉ qua tham số URL |
| Thanh thành phố, hai nút zoom, chú giải, bộ đếm kết quả | không có |

## Ba lỗi phải sửa dù không dựng lại gì

**1. Mọi thẻ màn hình trên bản Next đặt sai tên lớp.**

`webapp/components/ScreenCard.tsx:42` và `webapp/app/products/page.tsx:86` viết
`<article className="sc">`. Trong `frontpage.css`:

- `.sc` (dòng 1330) = **một ô trong bảng thông số**: `padding`, viền phải, viền
  dưới, kèm `:nth-child` để ghép thành bảng.
- `.sc-card` (dòng 1939) = **thẻ màn hình**: `border-radius:14px`, `box-shadow`,
  `flex-direction:column`, `overflow:hidden`, và `:hover` nâng thẻ 4px kèm phóng
  ảnh.

Bản cũ dùng `sc-card`. Nên thẻ màn hình trên production hôm nay không có bo góc,
không đổ bóng, không hiệu ứng hover, và mang viền phải/dưới của một ô bảng. Đây
là thẻ xuất hiện trên `/`, `/explore`, `/owners/{slug}`, `/products` — tức hầu
hết ảnh sẽ chụp.

**2. Thẻ chủ sở hữu dùng năm tên lớp không ai định nghĩa.**

`oc-cover`, `oc-body`, `oc-name`, `oc-stats`, `oc-type` — không có trong
`frontpage.css`, không có trong bất kỳ khối `<style>` nào. Dùng ở
`webapp/app/owners/page.tsx` và `webapp/app/page.tsx`. Vỏ ngoài `oc-card` thì có
kiểu, nên thẻ có khung nhưng **ruột không có kiểu**: ảnh bìa không bị giới hạn
chiều cao, tên không có cỡ chữ, số liệu không có bố cục.

Thứ đáng nói: bản cũ dùng `oc-card-cover` / `oc-card-name` / `oc-card-stats`, và
**`/agency` trong cùng repo vẫn đang dùng đúng bộ đó**. Bản mẫu đúng nằm cách đó
một tệp.

**3. Chốt canh tên lớp không soi `webapp/`.**

`tests/js/class-css.test.mjs` tồn tại chính vì hai lỗi cùng loại (`.b-gray` chưa
từng có trong CSS, `b-green` sai tên) đã lên production. Nhưng phạm vi của nó là
`resources/views` — Blade. Trang Next nằm ngoài. Nên hai lỗi trên sống sót qua
mọi lượt CI.

Đây là **lỗ thứ tám** cùng họ với bảy lỗ đã ghi ở `LO-TRINH-SAU-GIAI-DOAN-0.md`:
một chốt xanh chưa chứng minh nó đang canh thứ nó nói.

## Thứ KHÔNG dựng lại, và vì sao

Dựng lại giao diện cũ nghĩa là dựng lại **hình**, không phải dựng lại những con
số bịa mà giai đoạn 4 đã dọn (audit F-15). Năm thứ sau nằm trong markup cũ và
**phải để lại trong git**:

| Thứ bỏ lại | Vì sao |
|---|---|
| `4.9` sao + ba nhận xét có tên người ở `/explore/{slug}` | Không có model Review nào. Chữ viết cứng trong template |
| `"price": 0` trong JSON-LD khi màn hình chưa niêm yết giá | schema.org đọc `price: 0` là **miễn phí** |
| Ba nút gói "City Launch / Retail Activation / Event Blitz" | `<button>` trần, không hành vi, không có bản ghi tương ứng |
| Hai nút ở owner CTA + thẻ "Tăng fill rate lên 40%" | Không có trang đăng ký owner; 40% không tính được từ dữ liệu nào |
| Bốn liên kết `href="#"` ở chân trang | Liên kết không đi đâu |

Khối **hình** của chúng (`pkg-grid`, `owner-cta`, `oc-feat`) thì dựng lại được —
chỉ cần nội dung bên trong có nguồn thật, hoặc bỏ phần không có nguồn.

## Kế hoạch — bảy lát

Thứ tự theo *thứ sẽ bị chụp trước*, trong cùng mức ưu tiên thì *rẻ trước*. Mỗi
lát một PR, CI xanh mới merge.

| Lát | Việc | API cần thêm | Ước lượng |
|---|---|---|---|
| ~~**0**~~ **XONG 14/10** | Ba lỗi trên: `sc` → `sc-card`; thẻ owner về `oc-card-*`; mở rộng `class-css.test.mjs` sang `webapp/` | ~~không~~ — **có**: `logo_url`/`cover_url` phải là URL đầy đủ, thêm `city_count`, `venue_types`, `verified`, `featured` vào `OwnerSummary` | nửa ngày |
| ~~**1**~~ **XONG 14/10** | Thẻ và lưới: `inv-grid` cho lưới màn hình (thay `oc-grid`), đổi kiểu xem lưới/danh sách; `/owners` có lại ô tìm kiếm + `cat-chips` | không — `filters` đã có | 1 ngày |
| ~~**2**~~ **XONG 14/10** | **Trang chủ**: mega-search, Live Map View, 4 bước đặt booking, Gói chiến dịch, Owner CTA, và phần hero còn thiếu (badge, `hstat`, hint, ảnh mô phỏng) | `locations` ✔ `pins` ✔ `stats` ✔ — **thêm** `categories_networks` vào `/filters` | 2–3 ngày |
| ~~**3**~~ **XONG 14/10** | `/explore` + `/products`: sidebar lọc, results header, sắp xếp, drawer mobile | ~~`filters` ✔~~ — **có**: `filters` của màn hình không mang `categories`/`types` của gói, nên thêm `GET /api/v2/products/filters` | 2 ngày |
| ~~**4**~~ **XONG 14/10** | `/explore/{slug}`: gallery + lightbox, bốn tab, insights, lịch trống theo tháng, panel giá, khối vị trí + POI, màn hình tương tự | **bốn món, đúng như dự đoán**: `photo_urls[]`, insights, lịch trống theo ngày (đường riêng, trần 3 tháng), màn hình gần đó — cộng `ScreenDetailResource` mới | 3–4 ngày |
| ~~**5**~~ **XONG 14/10** | `/owners/{slug}`: bố cục hồ sơ `fp-*` (chuyển **96** dòng CSS nội tuyến vào `frontpage.css`), hai tab, băng trượt, đánh giá người mua | `owners/{slug}` ✔ cho hồ sơ — nhưng **đánh giá cần một DTO mới**: `OwnerReviewResource` viết cho tác giả nhận xét (có `status`, `id`), không dùng được cho trang công khai | 2 ngày |
| ~~**6**~~ **XONG 17/10** | `/map`: smart-filter, panel danh sách gom theo site, thanh thành phố + dropdown "Khác", ba nút thu phóng, bộ đếm, popup | ~~`filters` ✔ `map` ✔~~ — **có**: `MapPinResource` thiếu `site.name` (để gom theo toà nhà), `network.name` (chữ trên pin) và `venue_type` (màu pin) | 1–2 ngày |
| ~~**7**~~ **XONG 17/10** | `/products/{slug}`: gallery, bố cục hai cột, panel giá có tab, danh sách màn hình chọn được, gói tương tự | `products/{slug}` ✔ cho hồ sơ gói — **thêm `similar`** (`getSimilarProducts()` đã có từ giai đoạn 4, chưa endpoint nào mang ra) | 1–2 ngày |

**Nếu Sở vào trong tuần này:** lát 0 + lát 1 + lát 2. Trang chủ là ảnh đầu tiên
và là trang khác bản cũ nhiều nhất; thẻ màn hình thì có mặt trên gần hết các
trang còn lại. Ba lát đó không cần endpoint mới nào.

## API còn thiếu — chỉ ở lát 4

Dữ liệu đã có trong CSDL và đã có service đọc. Việc là **mở ra ở DTO v2**, không
phải tính mới.

| Món | Dữ liệu nằm ở đâu hôm nay | Việc cần làm |
|---|---|---|
| Thư viện ảnh của màn hình | `screen_specs.photos[]`, accessor `display_photo` | `ScreenSummaryResource` chỉ trả `photo_url`. Thêm `photo_urls[]` ở đường chi tiết |
| Insights | `screens.placement_zone`, `daily_footfall`, `monthly_reach`, `audience_profile`, `time_performance`, `nearby_context`, `traffic_methodology_note` — **`/api/v1` đã trả** qua `?include=intelligence` | Mở ở `/api/v2/screens/{slug}`. **Không đổi `/api/v1`** (CLAUDE.md mục 1) |
| Lịch trống theo tháng | `AvailabilityService::getRemainingSOV()` theo khoảng ngày | Thêm đường trả SOV còn lại theo từng ngày cho N tháng, có giới hạn cứng số tháng |
| Màn hình gần đó | `FrontpageService::getSimilarScreens()` — đã có, chưa có endpoint | Trả kèm trong `/api/v2/screens/{slug}` |

Panel giá **không** cần endpoint mới cho phần hiển thị, nhưng phải giữ đúng
CLAUDE.md mục 5: *số tiền do máy chủ tính*. Bản cũ tính tiền bằng JS ngay trên
trang. Dựng lại thì con số hiển thị chỉ là **ước tính** và phải nói rõ là ước
tính; số thật vẫn do `/api/v2/cart` trả về khi thêm vào giỏ.

## Khu người mua — không nằm trong việc này

11 trang Blade (`cart`, `dashboard/*`, `booking/*`) **vẫn đang mang giao diện
cũ** và vẫn chạy: chúng chưa chuyển sang Next (giai đoạn 8, hoãn có chủ ý). Cả
11 đã đọc dữ liệu qua `/api/v2` từ trình duyệt, nên nếu Sở cần chụp quy trình
đặt chỗ thì phần đó **không cần dựng lại gì**.

## Rủi ro

1. **Ảnh chụp trong hồ sơ cũ tôi chưa thấy.** Tài liệu này so bản Next với bản
   Blade *ở commit cuối trước khi gỡ*. Nếu hồ sơ đã nộp dùng ảnh chụp **trước**
   đợt dọn F-15 (giai đoạn 4, 29/09 và 03/10) thì bản cũ trong ảnh còn những con
   số đã bị gỡ vì chúng bịa. Khớp lại đúng những con số đó là việc không nên
   làm, và cần nói trước với cơ quan quản lý thay vì dựng lại.
2. **358 tên lớp CSS chết** vẫn còn sau khi làm hết bảy lát. Dọn chúng là việc
   khác, và chỉ nên làm sau khi giao diện đã chốt.
3. **Lát 4 là lát duy nhất kéo theo API mới**, nên cũng là lát duy nhất có thể
   trượt lịch vì lý do không nằm ở giao diện.
4. Banner *"đang chạy thử nghiệm, chưa hoàn tất đăng ký"* hiện trên mọi trang và
   tự ẩn sau 6,5 giây. Nó bật/tắt bằng `OOHX_TRIAL_MODE`. Giữ hay tắt trong lúc
   chụp là quyết định pháp lý, không phải quyết định kỹ thuật.

---


## Những gì lát 3 đã đổi, và hai thứ tìm thấy trong CSS

**`load-more` không được dựng lại, vì nó không là gì.** Bảng kế hoạch kể nó
như một khối. Trong `products/index.blade.php` nó là một `<div>` bọc
`$products->links()`, và `frontpage.css` **không có luật nào** cho
`.load-more` — nó không mang kiểu, không mang hành vi "tải thêm". Phân trang
ở đây dùng `Pagination` như `/explore`, cùng một thành phần cho hai trang.

**`.listing-layout` được dùng mà chưa bao giờ được định nghĩa.** Bản Blade
đặt class này trên thẻ bọc hai cột, nhưng không có luật CSS nào — nên trên
desktop sidebar nằm thành một khối chiếm hết chiều rộng **phía trên** lưới:
một bố cục hai cột thiếu cột. Lát này thêm định nghĩa
(`grid-template-columns:260px minmax(0,1fr)` từ 1024px). Đây là lần thêm CSS
thật sự, không phải dựng lại — và nó nằm trong 803 tên class mà bản khảo sát
đếm được, chỉ ở chiều ngược lại: class được **dùng** mà không được khai.

**Nút "danh sách" ở `/explore` không làm được việc nó hứa — và đã thế từ bản
Blade.** `.inv-grid.list-view` đặt lưới về một cột rồi nhắm tiếp vào `.ic-*`
— bộ class của `.inv-card`. Không view nào trong repo dùng `.inv-card`, kể cả
bản Blade trước khi gỡ: thẻ màn hình là `.sc-card`. Nên bật kiểu danh sách
chỉ làm mỗi thẻ rộng ra hết hàng, không xếp ảnh sang bên trái như thiết kế
của bộ `ic-*` mô tả. **Chưa sửa trong lát 3** vì sửa là viết kiểu mới, không
phải dựng lại kiểu cũ; vì cùng lý do, `/products` không có hai nút đó (bản
Blade cũng không).

**Ba tên class của bản Blade cố ý không mang sang:** `sb-search-inp` (không
có định nghĩa; `.sb-input` dòng 1179 đã có đúng bộ thuộc tính đó cộng
`:focus`, nên bản Blade bù bằng style nội tuyến là bù cho một tên viết sai),
`filter-backdrop` (`display:none`, rồi `!important` ở desktop, kèm chú thích
"removed, panel is opaque white" — và JS bản Blade vẫn gắn `click` lên nó để
đóng drawer, tức một trình xử lý không bao giờ chạy), và `ms-compact` (lớp
rỗng, đã bỏ từ lát 2).

**Một chỗ tốt hơn bản Blade, có chủ ý.** Bấm lại mục "Loại sản phẩm" đang
chọn thì bỏ chọn. Radio HTML không bỏ chọn được, nên ở bản Blade đó là một
lựa chọn vào rồi không ra — muốn bỏ phải bấm "Xoá bộ lọc" và mất cả các bộ
lọc khác.

**Stub API nói sai hợp đồng, và điều đó che một lỗi.** `webapp/test/stub-api.mjs`
trả `type: 'bundle'` cho gói, trong khi OpenAPI khai `enum: [single, package,
custom]`. `ProductCard` có `kieu?.nhan ?? product.type`, nên thẻ in **mã thô**
"bundle" ra trang trong mọi lượt build thử — và không chốt nào đỏ. Đã sửa
stub, và thêm vào `khoi-giao-dien.mjs` một khẳng định rằng nhãn trên thẻ là
chữ tiếng Việt, không phải mã.
## Những gì lát 4 đã đổi, và một thứ tìm thấy trong CSS

**Bốn món API mà bảng trên dự đoán — đúng cả bốn, và không thêm món nào.**
`photo_urls[]`, insights, lịch trống theo ngày, màn hình gần đó. Ba món đầu vào
`ScreenDetailResource` (một DTO **mới**, không phải thêm trường vào DTO danh
sách: `/api/v2/screens` trả 24 bản ghi mỗi trang, và nhồi insights vào đó là
nhân payload danh sách lên nhiều lần cho dữ liệu mà lưới thẻ không in ra). Lịch
trống thành một đường riêng.

**Lịch trống là một đường riêng vì nhịp đổi khác nhau.** Chỗ trống đổi theo
từng lượt đặt chỗ; thông số và ảnh đổi theo tháng. Gộp vào chi tiết là buộc cả
trang cache theo nhịp của phần động nhất — hoặc buộc lịch cache theo nhịp của
phần tĩnh nhất và hiện ngày đã bán là còn trống. `months` có **trần 3**, và quá
trần là 422 chứ không lặng lẽ cắt: endpoint trả một phần tử mỗi ngày, nên
`months=240` là 7.300 phần tử một lần gọi trên một đường không cần đăng nhập.

**Lịch của bản Blade bỏ qua giữ chỗ trong giỏ — và đó là một lỗi, không phải
một lựa chọn.** View cũ tự cộng `share_of_voice_pct` của `BookingLine` có
`status` trong `['approved','active']`, và không biết gì về `InventoryHold`. Nên
nó sơn **xanh** những ngày người khác đang thanh toán. Endpoint mới đi qua
`InventoryHoldService::remainingSovByDay()` — lớp duy nhất biết công thức sức
chứa — nên hai nơi không thể trôi khỏi nhau. Có một ca test cho đúng chuyện này
(`test_lich_trong_tru_ca_suat_dang_co_nguoi_giu_trong_gio`).

**Toàn bộ CSS của lightbox và khối POI sống trong view đã bị gỡ.** `.lb-*`,
`.loc-*`, `.poi-pin*`, `.screen-pin*`, `.gal-thumb-on` — 70 dòng nằm trong
`@push('head')` của `frontpage/detail.blade.php`, tức chúng biến mất cùng view ở
giai đoạn 7. Dựng lại thư viện ảnh mà không chuyển chúng vào `frontpage.css` thì
lightbox mở ra là một ảnh trần giữa trang, không có lớp nền đen. Lát này chuyển
cả 70 dòng, và đổi `#loc-map` thành `.loc-map`: một id trong CSS buộc mọi nơi
dùng lại khối này phải đặt đúng id đó, và hai bản đồ trên một trang thì id trùng.

**`.sc` trên trang chi tiết là HỢP LỆ** — và đó là chỗ phải cẩn thận khi đọc
lỗi của lát 0. `.sc` là một ô trong bảng thông số (`frontpage.css:1330`), và
trang chi tiết có đúng bảng đó trong `specs-grid`. Lỗi lát 0 là thẻ **màn hình**
mở bằng tên ấy. Nên `khoi-giao-dien.mjs` cấm `class="sc"` ở `/explore` và
`/products`, nhưng KHÔNG cấm ở `/explore/{slug}` — chỗ canh ở trang này là
`class="sc-card sc-compact"` của khối "Inventory tương tự".

### Ba thứ của bản Blade KHÔNG dựng lại, và một thứ dựng khác

| Thứ | Quyết định | Vì sao |
|---|---|---|
| Bốn thẻ "Điểm nổi bật" (`hl-card`) | **không dựng** | "AI Traffic Data · Cập nhật theo giờ thực" (không có AI trong mã), "Proof of Play · Video xác nhận hàng ngày" (`impression_logs` chưa có bản ghi nào), "Verified Location · Kiểm tra thực địa 2024" (khẳng định về quy trình), "Fast Booking · Xác nhận trong 48h" (cam kết vận hành). Cùng đường cắt với thanh `trust` ở trang chủ |
| Bốn thẻ đánh giá 4.9 sao | **không dựng** | Bản Blade đã bọc chúng trong `@if(false)` — chính tác giả đã tắt vì là dữ liệu bịa. Tab "Đánh giá" dựng lại đúng trạng thái đang bật: chưa có đánh giá nào |
| Dòng "Nguồn traffic: OOHX Data Engine · Method · Confidence" | **không dựng** | Nó đọc `$screen->traffic_estimate`, một accessor gọi HTTP sang tunnel nội bộ. `/api/v2/screens/{slug}` là đường công khai có giới hạn tần suất; để nó chờ một dịch vụ khác là đổi một trang danh mục thành một trang phụ thuộc tunnel còn sống. Số liệu trong Insights vì thế là cột do owner khai, và `insights.source` nói đúng thế |
| Panel giá: hàng VAT + "Tổng cộng" | **dựng khác** | Bản Blade tính cả ba bằng JS và trình bày như số tiền thật — chú thích trong chính view đó kể lại một lần nhãn (config 8%) và con số (nhân cứng 0,1) đã lệch nhau. CLAUDE.md mục 5: **số tiền do máy chủ tính**. Nên panel in **một** hàng tạm tính, gọi nó là ước tính, và nói số chính thức do hệ thống tính khi thêm vào kế hoạch. Thuế suất không có trong hợp đồng API công khai, và viết cứng 8% vào mã trình duyệt là dựng bản sao thứ hai của một chính sách |

Chân panel cũng đổi: bản Blade viết *"Thanh toán bảo mật bởi OOHX · Hoàn tiền
100% nếu hủy trước 48h"*. Sàn **không thu hộ** (người mua chuyển khoản trực tiếp
cho owner, hồ sơ Bộ Công Thương), nên câu đầu nói sai cách tiền chạy; và chính
sách hủy/hoàn tiền là một trong **sáu câu hỏi nghiệp vụ còn chờ chủ dự án chốt**,
nên câu sau là một cam kết chưa ai duyệt. Thay bằng liên kết tới `/bang-phi`.

**Nút "Thêm vào Plan" dẫn tới `/login`.** Khu người mua chưa chuyển sang Next,
nên nút gửi `POST` vào một đường chưa tồn tại là tệ hơn một nút nói thật là nó
cần đăng nhập. Nút "lưu" (trái tim) cũng không dựng: nó gọi `POST /save/{id}`.

## Những gì lát 5 đã đổi, và một quyết định tôi phải đảo

**96 dòng CSS `fp-*` sống trong view đã bị gỡ — lần thứ hai cùng một chuyện.**
Toàn bộ bố cục hồ sơ (`fp-cover`, `fp-avatar`, `fp-header`, `fp-tabs`,
`fp-panel`, `fp-overview`, `fp-card`, `fp-stat`, `fp-slider`…) nằm trong
`@push('head')` của `frontpage/owner-detail.blade.php`. Dựng lại bố cục mà
không mang chúng theo thì **không một tên class nào có định nghĩa**: ảnh bìa
không có chiều cao, avatar không tròn và không chồng lên bìa, hai cột thành
một cột, và cả hai `fp-panel` hiện cùng lúc vì không có luật `display:none`.
Lát 4 đã chuyển 70 dòng lightbox + POI từ `detail.blade.php` vì đúng lý do
này; `tests/js/class-css.test.mjs` bắt được cả hai lần.

**Một quyết định của bản Next trước đó phải đảo lại.** Trang `/owners/{slug}`
của bản Next ghi trong docblock: *"tôi không in `contact`, vì một trang công
khai in email và số điện thoại là một trang cho máy thu thập địa chỉ"* — kèm
một câu phụ, *"bản Blade cũng không in"*. Câu phụ đó **sai**:
`owner-detail.blade.php` in email, điện thoại, website và địa chỉ trong thẻ
"Giới thiệu", cộng hai nút "Liên hệ" / "Gửi email" ở hàng tiêu đề. Và lý do
chính không đứng được một mình: sàn **không thu hộ**, nên không thấy đường liên
hệ owner thì không ai đặt được gì — lát 4 đã mở đúng hai trường này ở
`/explore/{slug}` vì cùng lý do đó.

Hệ quả: `webapp/test/seo.mjs` có một luật cấm `email`/`phone`/`contact` xuất
hiện trên trang owner, và luật đó nay canh **đúng thứ phải chặn** — danh sách
cấm của CLAUDE.md mục 2 (`revenue_share_pct`, `bank_*`, `tax_code`,
`business_license_path`, `device_token`…). Nếu chủ dự án muốn ẩn liên hệ owner
khỏi trang công khai thì đó là một quyết định mới, và nó phải sửa **cả ba
chỗ**: DTO, trang hồ sơ, trang chi tiết màn hình.

**`og:type` trả lại `profile`.** Bản Blade phát `profile` cho trang này; bản
Next hạ xuống `website` khi chưa dựng bố cục hồ sơ. Mạng xã hội dựng thẻ xem
trước khác nhau cho hai giá trị.

**Đánh giá công khai có DTO RIÊNG, không dùng lại `OwnerReviewResource`.** DTO
kia viết cho **tác giả** nhận xét (đường `GET campaigns/{campaign}`) và trả
`id`, `status`, `status_label`, `owner.id`, `created_at`. Trên trang công khai
`status` luôn là một giá trị duy nhất, id là khóa nội bộ, và `created_at` là
thời điểm *viết* — có thể cách thời điểm *đăng* nhiều ngày vì đi qua kiểm
duyệt. `OwnerPublicReviewResource` trả đúng bốn trường: `organization`,
`rating`, `comment`, `published_at`.

**`rating.average` là `null` khi chưa có đánh giá, KHÔNG phải 0.** Điểm 0 đọc
ra là "bị đánh giá rất tệ"; sự thật là "chưa ai đánh giá". Trang dùng chính chỗ
phân biệt này để quyết định có khai `aggregateRating` trong JSON-LD hay không —
khai với `ratingValue: 0` là nói với công cụ tìm kiếm rằng owner bị 0/5, cùng
loại lỗi `price: 0` của audit F-15.

**Stub lại nói sai hợp đồng, và lại che một lỗi.** `OWNER_DETAIL.stats` trong
`stub-api.mjs` viết `{ screens: 2, cities: 1 }` — hai khóa **không có** trong
`OwnerDetail` (hợp đồng là `screen_count`, `city_count`, `venue_types`). Nên
trang in "0 màn hình · 0 tỉnh/thành" trong mọi lượt build thử và không chốt nào
đỏ. Đây là lần thứ hai: lát 3 đã sửa `type: 'bundle'` nằm ngoài enum. Cả hai
lần, lỗi nằm ở **dữ liệu giả**, không ở mã — và cả hai lần nó làm trang hiện
sai mà không ai thấy.

**Hai chỗ tốt hơn bản Blade, có chủ ý:**

- Tab mở sẵn do **máy chủ** quyết (`?tab=inventory`, có bộ lọc, hoặc `page>1`).
  Bản Blade mở "Tổng quan" rồi nhảy sang "Màn hình" trong một IIFE sau khi tải
  xong — người dùng thấy một nhịp nội dung sai trước khi nó biến mất.
- Hai nút băng trượt **vô hiệu ở hai đầu**. `frontpage.css` đã có
  `.fp-slider-btn:disabled` (opacity .3) nhưng không có JS nào đặt `disabled`,
  nên ở bản cũ chúng luôn trông bấm được kể cả khi không còn gì để cuộn.

## Những gì lát 6 đã đổi, và lần thứ ba CSS nằm trong view đã gỡ

**Kế hoạch lại đo thiếu một lần nữa — lần thứ ba.** Bảng bảy lát ghi lát 6 cần
`filters` ✔ `map` ✔, tức không có API mới. Thực tế `MapPinResource` thiếu **ba
trường** mà bản đồ bản Blade dựa vào:

- `site.name` — bản Blade gom pin theo `site_id` ở **máy chủ**, nên một marker
  là một toà nhà và badge trên pin là số màn hình bên trong. `/api/v2/screens/map`
  trả theo màn hình, và hợp đồng đó không nên đổi (nhiều bên dùng dữ liệu thô).
  Nên việc gom chuyển sang client — và không có tên site thì không gom được:
  hai màn hình cùng toà nhà trùng toạ độ, chồng lên nhau thành một chấm.
- `network.name` — chữ trên pin ở mức thu phóng gần.
- `venue_type` — **màu** pin đọc trường này (`OOHXMap.pinColor`). Thiếu nó thì
  mọi pin một màu.

Ba chuỗi ngắn, và DTO vẫn cố tình gọn: không thêm thông số, không thêm ảnh thứ
hai, không thêm logo owner (pin dùng chữ cái đầu khi thiếu logo). Gom theo khóa
`tên site + toạ độ làm tròn 5 chữ số`, **không** theo id — DTO bản đồ cố ý
không trả khóa nội bộ, và một ca test canh đúng chuyện đó.

**35 dòng CSS marker sống trong view đã bị gỡ.** `.oohx-rich*` (pin có tên site
+ badge) và `.oohx-cluster*` (vòng tròn gom cụm) nằm trong partial `map-styles`
mà chỉ `map.blade.php` và `index.blade.php` include. Phần pin cơ bản
(`.oohx-pin`, `.oohx-pin-box`) đã có sẵn trong `frontpage.css` từ trước, nên
nhìn qua tưởng đủ — nhưng bản đồ bản Next vẫn vẽ marker mặc định của Leaflet.
Lát 4 chuyển 70 dòng, lát 5 chuyển 96 dòng, lát này 35.

**`#leaflet-map` → `.map-canvas`.** Cùng lý do đã đổi `#loc-map` ở lát 4: một
id trong CSS buộc mọi nơi dùng lại khối này phải đặt đúng id đó, và hai bản đồ
trên một trang thì id trùng. Luật `#leaflet-map` cũ để nguyên — gỡ nó là một
lần dọn riêng.

**`region` không được gửi lên API, và đó là một khác biệt thật.** Chip vùng
miền của bản Blade sinh `region[]=north`, nhưng `FrontpageListingRequest` nhận
`city`, `venue_type`, `network`, `q`, `sort` — **không có** `region`. Tức ở bản
Blade, bấm "Miền Bắc" rồi "Tìm kiếm" là gửi một tham số máy chủ bỏ qua. Bản Next
dịch chip vùng thành danh sách `city` của vùng đó trước khi vào URL, nên bộ lọc
có tác dụng thật.

**`sft-chips-wrap` không mang sang.** Nó là móc `querySelectorAll` của bản
Blade (đi cùng `data-max`), không có định nghĩa CSS nào; `.sft-chips` đã
`flex-wrap: wrap`. `class-css.test.mjs` bắt — lần thứ hai sau `ms-drop-close`
ở lát 2.

**`hideFooter` không dựng lại.** Layout Blade ẩn chân trang cho trang này vì bố
cục chiếm trọn màn hình. Ở bản Next chân trang nằm trong `app/layout.tsx` dùng
chung; ẩn nó cho đúng một đường dẫn là thêm một nhánh điều kiện vào layout của
mọi trang. `.map-layout` có chiều cao riêng nên chân trang chỉ nằm bên dưới.

**Một chỗ tốt hơn bản Blade:** tỉnh **không có trong bảng toạ độ viết cứng**
vẫn bấm được — bản đồ hỏi pin của tỉnh đó rồi `fitBounds` theo chính chúng. Bản
Blade có `CITY_COORDS` 10 tỉnh và không có nhánh dự phòng, nên tỉnh thứ 11 bấm
vào thì bản đồ đứng yên.

## Những gì lát 7 đã đổi, và kế hoạch bảy lát kết thúc ở đâu

**Lát duy nhất không phải chuyển dòng CSS nào.** Mọi tên class của trang gói
(`pd-detail-layout`, `pd-detail-gallery`, `pd-screen-list`, `bp-tabs`,
`bp-tab-content`…) đã nằm sẵn trong `frontpage.css`. Ba lát trước phải chuyển
70 + 96 + 35 dòng từ `@push('head')` của các view đã gỡ; trang gói viết CSS
vào tệp chung ngay từ đầu.

**API thiếu đúng một món: `similar`.** `ProductService::getSimilarProducts()`
có từ giai đoạn 4 và chưa endpoint nào mang ra. Trả kèm trong
`/api/v2/products/{slug}`, ngoài `data` — cùng chỗ `reviews` của owner (lát 5)
nằm, và cùng lý do: `data` là một `ProductDetail` dùng ở nhiều nơi.

**Bốn id toàn cục của bản Blade biến mất, và một lỗi đi cùng chúng.** Panel giá
(cột phải) và danh sách màn hình (cột trái) nối với nhau bằng
`document.querySelectorAll` và `#selected-count`, `#selected-total`,
`#individual-summary`, `#selected-inputs`. Khi `listing_mode` là
`individual_only` thì **hai khối cùng khai ba id đầu** — `getElementById` chỉ
trả về cái đầu tiên, nên khối thứ hai không bao giờ cập nhật. Ở bản Next đó là
một `useState` trong component cha chung, nên không có khả năng lệch.

**Ba thứ in vô điều kiện không dựng lại:**

| Bản Blade in | Điều kiện thật |
|---|---|
| `bp-avail` "Còn trống" theo `status === 'active'` | `/api/v2/products/{slug}` chỉ trả gói **công khai**, nên điều kiện luôn đúng — một badge in vô điều kiện, đúng thứ audit F-15 đã dọn khỏi trang màn hình |
| "Verified Owner" dưới tên owner trong khối cột phải | Không kiểm gì. `ProductSummary.owner` chỉ có `slug` + `name`; thay bằng "Media owner của gói này" |
| "Thanh toán bảo mật bởi OOHX" ở chân panel | Sàn **không thu hộ** — thay bằng liên kết `/bang-phi`, cùng chỗ đã sửa ở `PanelGia` (lát 4) |

**Kích thước màn hình in bằng mét, không bằng pixel.** Bản Blade in
`spec.width_px × height_px`; `ScreenSummary` có `size.width_m`/`height_m` chứ
không có hai cột pixel. Mở thêm hai trường vào DTO **danh sách** chỉ để in một
dòng phụ là nhân payload của mọi danh sách màn hình lên — mét nói cùng một thứ
cho người đang chọn vị trí.

**Thư viện ảnh dùng bộ `pd-detail-*`, không dùng lại `ThuVienAnh` của lát 4.**
Hai bộ class có kích thước và bố cục khác nhau trong `frontpage.css`, và trang
gói ở bản Blade **không có lightbox**. Dựng lại đúng thứ đã có, không thêm một
tính năng chỉ vì component kia sẵn có nó.

**Stub nói sai hợp đồng lần thứ ba.** `PRODUCT.listing_mode` viết
`'package'` — một chuỗi — trong khi hợp đồng là
`{code, label, allows_package, allows_individual}`. Nên
`product.listing_mode?.allows_package` là `undefined`, và panel giá sẽ không
render nhánh nào trong lượt build thử. Lát 3 là `type: 'bundle'` ngoài enum,
lát 5 là `stats.screens`/`stats.cities`, lát này là `listing_mode`. **Ba lần
liên tiếp lỗi nằm ở dữ liệu giả, không ở mã** — và cả ba lần nó làm trang hiện
sai mà không chốt nào đỏ. Đó là lý do mỗi lát đều phải mở fixture ra đọc, chứ
không chỉ chạy test.

---

## Kế hoạch bảy lát: xong

| Lát | Trang | API phải thêm |
|---|---|---|
| 0 | thẻ màn hình + thẻ owner | URL ảnh owner, 4 trường `OwnerSummary` |
| 1 | lưới và kiểu xem | không |
| 2 | trang chủ | `categories_networks` |
| 3 | `/explore` + `/products` | `GET /products/filters` |
| 4 | `/explore/{slug}` | `ScreenDetailResource`, lịch trống theo ngày |
| 5 | `/owners/{slug}` | `OwnerPublicReviewResource`, `reviews` + `rating` |
| 6 | `/map` | `site.name`, `network`, `venue_type` cho pin |
| 7 | `/products/{slug}` | `similar` |

**Bảng kế hoạch đoán sai về API ở bốn trong bảy lát** (0, 3, 6, 7 — và lát 5
đoán đúng là "đã có" nhưng bỏ sót việc đánh giá cần DTO riêng). Mẫu chung:
trang Blade đọc thẳng model nên không ai thấy nó cần trường gì; chỉ khi dựng
lại trên API mới lộ ra. Lần sau lập kế hoạch kiểu này, bước "đối chiếu từng
trường view dùng với DTO đang có" phải nằm trong khảo sát, không để tới lúc
làm.

**Còn lại sau bảy lát** — không thuộc kế hoạch, cần chủ dự án quyết:

- Thanh `trust` 5 mục và mục "Marketplace OOH đầu tiên có AI" ở trang chủ.
- Bốn thẻ "Điểm nổi bật" ở `/explore/{slug}`.
- Kiểu xem danh sách ở `/explore` chỉ thu lưới về một cột (bộ `ic-*` là class
  chết).
- `/owners/{slug}` nhận `venue_type` để bộ lọc trong tab kho có tác dụng.

## CHỐT 18/10/2026 — chủ dự án quyết dựng lại nguyên văn cả ba khối

Ba khối trong mục trên (thanh `trust` năm mục, mục "Marketplace OOH đầu tiên
có AI", bốn thẻ "Điểm nổi bật" ở `/explore/{slug}`) **đã dựng lại, đúng câu
chữ bản Blade**. Chủ dự án chốt sau khi đọc danh sách những gì không kiểm
được.

Giữ nguyên mục trên không sửa — nó là bản ghi của lúc quyết định còn mở.
Mục này ghi kết quả.

### Những câu đang nằm trên trang mà hệ thống chưa làm được

Liệt kê một lần, đầy đủ, để lần sau ai đọc cũng thấy cùng một danh sách —
và để nếu có người hỏi thì không ai phải đi tìm:

| Câu trên trang | Trang | Thực tế trong mã |
|---|---|---|
| "Traffic real-time" | chủ | `impression_logs` chưa có bản ghi nào |
| "AI-powered search" | chủ | `MegaSearch` lọc theo thuộc tính; không có AI |
| "Secure payment" | chủ | sàn **không thu hộ** — người mua chuyển khoản trực tiếp cho từng owner |
| "Agency support 24/7" | chủ | cam kết vận hành, không phải thuộc tính hệ thống |
| "AI Campaign Match · GPT-powered" | chủ | không có tính năng nào như vậy |
| "Real-time Availability · Live sync <5min" | chủ | không có đồng bộ với owner; suất trống tính từ CSDL của chính sàn |
| "AI Audience Estimation · Data-driven ±8%" | chủ | ước tính là `daily_footfall × 0,6`, một hệ số kinh nghiệm |
| "Verified Data Network · 100% verified" | chủ | `owners.verified` là cờ thủ công |
| "3.2× nhanh hơn cách đặt truyền thống" | chủ | không có nguồn |
| "AI Traffic Data · Cập nhật theo giờ thực" | `/explore/{slug}` | không có AI; không có dữ liệu theo giờ |
| "Proof of Play · Video xác nhận hàng ngày" | `/explore/{slug}` | chưa có proof-of-play nào |
| "Verified Location · Kiểm tra thực địa 2024" | `/explore/{slug}` | khẳng định về quy trình, không phải cột trong CSDL |
| "Fast Booking · Xác nhận trong 48h" | `/explore/{slug}` | cam kết vận hành |

Bốn thẻ "Điểm nổi bật" in **vô điều kiện cho mọi màn hình** — chúng không
đọc trường nào của `screen`.

### Một chỗ KHÔNG nguyên văn, và vì sao

Ảnh nền ô "3.2×". Bản Blade hotlink
`images.unsplash.com/photo-1551836022-…`. Giữ nguyên là thêm một điểm hỏng
trên trang công khai — Unsplash chậm, đổi URL hay chặn hotlink thì ô ảnh
trống — cộng một câu hỏi bản quyền cho ảnh nền thương mại. Thay bằng SVG
dựng tại chỗ (`ANH_AI_MINH_HOA` trong `webapp/lib/anh-hero.ts`), cùng cách
`lib/logo.ts` và ảnh mô phỏng bảng điều khiển ở lát 2 đã làm. Ảnh không
mang số liệu nào.

Muốn ảnh chụp thật thì tải tệp về `public/` và trỏ tới nó — một lần thêm
tệp, không phải một lần sửa mã.

### Chốt đổi chiều

`webapp/test/khoi-giao-dien.mjs`: ba khối chuyển từ danh sách **`khong`**
sang danh sách **`co`**, và thêm một luật mới cấm `images.unsplash.com`
xuất hiện trong HTML trang chủ. 273 → **293 khẳng định**.

Nghĩa là từ nay nếu ai gỡ ba khối đó, chốt sẽ **đỏ** — đúng như trước đây
nó đỏ nếu ai thêm vào. Chiều của luật đi theo quyết định, không theo ý
người viết test.

## Ba khối chưa dựng vì chúng là lời hứa, không phải hình (ghi 14/10/2026)

Lát 2 dựng lại trang chủ và dừng trước ba khối. Không phải vì khó — cả ba là
HTML tĩnh, dựng trong mười phút — mà vì nội dung của chúng **nói những điều tôi
không kiểm được**, và trang này sắp được một cơ quan quản lý chụp màn hình.

| Khối | Nó nói gì | Kiểm được không |
|---|---|---|
| Thanh `trust` (5 mục, ngay dưới hero) | "Verified inventory" · "Traffic real-time" · "AI-powered search" · "Secure payment" · "Agency support 24/7" | **Không.** Cột `verified` có thật nhưng thanh này nói về cả sàn; `impression_logs` chưa có bản ghi nào nên không có "real-time"; không có tính năng AI nào trong mã; sàn **không thu hộ** (người mua chuyển khoản trực tiếp cho owner) nên "secure payment" nói sai cách tiền chạy; "24/7" là cam kết vận hành, không phải một thuộc tính của hệ thống |
| Mục "Marketplace OOH đầu tiên có AI" (`value-grid` + `ai-cards`) | Nhan đề khẳng định có AI; `vv-number` in **3.2×** "nhanh hơn cách đặt truyền thống"; ảnh nền hotlink từ `images.unsplash.com` | **Không.** 3.2× không có nguồn nào; không có tính năng AI; và một trang công khai hotlink ảnh của dịch vụ ngoài là thêm một điểm hỏng cùng một câu hỏi bản quyền |
| Bốn thẻ WORKFLOW | "AI search", "traffic data", "so sánh tối đa 5 vị trí", "proof-of-play report tự động" | **Một phần.** Bốn *bước* là thật và đã dựng lại; bốn *câu mô tả* thì không — không có chức năng so sánh, chưa có dữ liệu phát sóng |

Bốn thẻ WORKFLOW đã dựng với **chữ viết lại**, mô tả đúng những gì hôm nay làm
được. Hai khối còn lại **chưa có trên trang**.

Đây là ba quyết định về lời hứa sản phẩm, nên chúng thuộc chủ dự án, không
thuộc người sửa giao diện. Ba lựa chọn cho mỗi khối: dựng lại **đúng câu chữ
cũ** (và nhận rủi ro đứng trước cơ quan quản lý), dựng lại với **câu chữ chỉ
nói điều kiểm được**, hoặc **không dựng**.

Ghi lại vì nó là cùng một đường cắt mà giai đoạn 4 đã cắt một lần: audit F-15 gỡ
"30M+ impressions", bộ đếm chạy bằng `Math.random()`, "AI Match 94%", "tăng fill
rate 40%" — nhưng **để lại** thanh `trust` và mục AI. Chúng sống sót vì đợt đó
dọn theo *con số*, còn đây là *lời khẳng định năng lực*. Cùng một loại rủi ro,
khác hình dạng.

## Những gì lát 0–2 đã đổi, để lần sau không phải đọc lại git log

**Bốn lỗi, không phải ba.** Lỗi thứ tư tìm thấy khi sửa lỗi thẻ owner: hai
resource owner trả `cover_url`/`logo_url` là **đường dẫn trong disk**, nên trình
duyệt giải chúng tương đối với trang đang mở — `/owners/owners/cover/abc.jpg`.
Quy ước của repo (`AgencySummaryResource`, `Product::getPhotoUrlsAttribute()`)
là URL đầy đủ; hai resource đó là ngoại lệ duy nhất.

**Thẻ `.sc` → `.sc-card` không chỉ là một tên class.** Ba chỗ cấu trúc phải
trả lại đúng: `.sc-foot` là **con trực tiếp** của thẻ (nó có `border-top` và
`margin-top`; lồng trong `.sc-body` thì viền không chạy hết thẻ và lề đếm hai
lần); `.sc-price-sub` là **div em** của `.sc-price`, không phải `<span>` bên
trong (lề trên của inline span không có tác dụng); và cả thẻ là **một** liên
kết, nên nút "Chi tiết" là `<span class="btn">` chứ không phải một `<Link>`
lồng trong `<Link>`.

**Ba tên class của bản Blade cố ý KHÔNG mang sang.** Chốt mới
(`class-css.test.mjs` nay soi cả `webapp/`) báo `ms-drop-close`, `ms-compact`,
`has-children` không có định nghĩa nào — và đúng là không có, kể cả ở bản Blade:
chúng là **móc cho JS** (`querySelectorAll('.ms-drop-close')`) và một biến thể
rỗng. Ở React thì sự kiện gắn trực tiếp, nên ba cái móc đó không còn việc gì.
Chốt bắt đúng thứ nó sinh ra để bắt, ngay trên mã mới.

**Mục "Gói chiến dịch" đọc gói THẬT.** Bản Blade vẽ ba thẻ viết cứng ("City
Launch", "Retail Activation", "Event Blitz") và chú thích tại chỗ đã nêu nghi
vấn là chúng không ứng với bản ghi nào — ba nút của chúng đã bị gỡ vì không có
đích. Mục này nay đọc `/api/v2/products`, nên mỗi tên gói trên trang là một bản
ghi có thật và bấm vào thì tới đúng trang đó.

**Bản đồ trang chủ dùng `/screens/map`, không dùng `/screens/pins`.** Thanh chọn
thành phố của bản Blade có nút "Toàn quốc", mà `CityPinsRequest` chỉ nhận slug
tỉnh thành có thật. Endpoint bản đồ nhận khung nhìn + `city`, nên một đường phục
vụ cả hai nhánh, và bộ đếm dưới bản đồ là `meta.total` thật của vùng. Lần vẽ đầu
vẫn dùng pin mà `getHomeData()` đã lấy ở máy chủ, nên không có lời gọi nào ở
trình duyệt khi mới mở trang.

**Khung nhìn đi theo dữ liệu.** Chọn một thành phố thì bản đồ `fitBounds` theo
chính các pin trả về, không theo một bảng toạ độ viết cứng — thêm một tỉnh vào
`CITY_SLUG_MAP` mà quên bảng đó thì bản đồ mở ra ở giữa biển, và đó là lỗi khó
truy.

**Một chốt mới, và nó đã được thử bằng đột biến.**
`webapp/test/khoi-giao-dien.mjs` (`npm run test:khoi`, có bước riêng trong CI)
đọc HTML **đã render** của `/`, `/explore`, `/owners`, `/products` và đòi 49
khẳng định: khối nào phải có mặt, và tên class nào **không được** có. Đổi
`sc-card` về `sc` rồi build lại: ba lỗi đỏ, gọi đúng tên trang và tên class. Trả
lại: xanh.

Vì sao nó cần tồn tại bên cạnh hai chốt đã có: `class-css.test.mjs` hỏi "tên
class này có người định nghĩa không" — `.sc` **có**, nên nó không thấy gì sai;
`test:seo` chỉ đo thẻ `<meta>`. Không chốt nào trả lời được câu "khối này còn
trên trang không".
