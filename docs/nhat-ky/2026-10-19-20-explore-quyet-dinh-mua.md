# Nhật ký 19–20/10/2026 — `/explore` thành trang ra quyết định

> `/explore` chuyển từ trang liệt kê sang trang ra quyết định (#126 → #134), rồi ba việc lấy ra khỏi hàng đợi, hai việc từ bảng "còn mở", và bốn lỗi mobile từ hai tấm ảnh.
>
> Tách ra từ `docs/LO-TRINH-SAU-GIAI-DOAN-0.md` ngày 23/10/2026, khi tệp đó
> chạm 2.988 dòng. Kế hoạch theo giai đoạn ở lại tệp gốc; mục này là nhật ký.

---

## Ngày 19–20/10/2026 — `/explore` thành trang ra quyết định (#126 → #134)

`main` ở `1f3253c`. CI **1.086 ca / 4.142 khẳng định** (chiều 18/10: 1.037 /
3.973), `test:js` **252** (207), `test:khoi` **369 khẳng định trên 11 trang**
(330 / 10), `test:seo` **16 trang**. Không còn PR nào mở.

Bảy lát của `docs/PLAN-EXPLORE-QUYET-DINH-MUA.md` đã xong, cộng ba lỗi có sẵn
mà chính việc làm bảy lát ấy làm lộ ra.

### Vì sao plan này tồn tại

Chủ dự án hỏi ngày 18/10: *"nếu chỉ listing thôi nghĩ rất khó để buyer quyết
định mua"*. Đo trên production cùng ngày, câu đó không phải cảm tính:

| Thứ người mua cần để quyết | Tỉ lệ bản ghi có |
|---|---|
| Giá | **1/50** (định nghĩa cũ, chỉ nhìn `floor_cpm`) |
| Ảnh thực địa riêng | **0/50** — cả 50 dùng chung hai tấm ảnh bìa của owner |
| Quận/huyện | 0/50 |
| Toạ độ | **50/50** |

Một trang danh mục thương mại điện tử giả định *giá có sẵn, ảnh có sẵn, người
mua tự so sánh rồi bấm mua*. Ở kho này không điều nào đúng. Việc người mua
**thật sự làm được** là khoanh một danh sách theo vị trí và loại hình rồi hỏi
giá — và bảy lát dựng đúng đường đó.

### Bảy lát

| PR | Lát | Việc | Đo được |
|---|---|---|---|
| #126 | 1 + 2 | `price_status` ở API, cột lọc trái | Thanh lọc giá cũ trả `{0,0}`; định nghĩa mới (nhận cả `io_rate`) tìm ra **19 vị trí có giá**, không phải 1 |
| #128 | 3 | Bản đồ cạnh danh sách, ba cột từ 1280px | Pin khớp `meta.total` tuyệt đối: 19/19 và 85/85 |
| #131 | 4 | Danh sách rút gọn trong `localStorage`, trần 50 | Tick ở thẻ và ở popup bản đồ là **một** trạng thái |
| #132 | 5 | Hai bảng, `POST /api/v2/quote-requests`, hai panel Filament | 24 ca test, trong đó 8 ca chỉ canh ranh giới dữ liệu |
| #133 | 6 | Màn hình gửi `/explore/yeu-cau-bao-gia` | Ba cú bấm → **một** POST |
| #134 | 7 | Tải CSV sinh ở trình duyệt | BOM `EF BB BF`, 6 cột, bọc ô theo RFC 4180 |

Bốn câu chủ dự án chốt ngày 19/10 quyết định hình dạng CSDL của lát 5: khách
ẩn danh **gửi được** · sàn **không cam kết** thời gian trả lời · liên hệ người
mua mở **sau khi quản trị duyệt** · tệp tải về là **CSV**.

### Ba lỗi có sẵn, lộ ra vì có người soát

Cả ba đều có **trước** plan. Cột lọc và các phép đối chiếu chỉ làm chúng đọc
được — và hai trong ba chỉ lộ ra khi tôi soát production sau khi merge, không
phải khi viết mã.

**1. Thẻ nói LCD, bộ lọc LCD trả 0 (#129).** Hai luật cho cùng một tên: nhãn
tính hai cạnh là 0 rồi trả "LCD", còn cả ba nhánh bộ lọc đều đòi
`whereHas('spec')`. 20/104 bản ghi không có dòng `screen_specs` rơi ra ngoài
cả ba nhóm, trong khi thẻ của chúng vẫn mang nhãn. Cột lọc in "LCD 0" ngay
cạnh hai chục thẻ LCD.

Chốt của #126 im lặng vì nó so nhóm đếm với `meta.total` của **cùng một hàm
sai** — hai con số lấy từ một chỗ thì luôn bằng nhau. Chốt mới so **nhãn người
dùng đọc trên thẻ** với nhóm mà bộ lọc xếp bản ghi đó vào.

**2. Lật sang trang 2 thấy lại 29 vị trí của trang 1 (#130).** Đọc hết ba
trang `/api/v2/screens`: 104 dòng trả về, **75 slug duy nhất**. 29 vị trí
không có đường nào để người mua tới. Với `sort=price_asc`: 54/104.

`LIMIT … OFFSET …` không cam kết thứ tự khi khoá sắp xếp có hàng bằng nhau, và
mọi danh sách công khai sắp theo **một** cột không duy nhất. Kho nhập theo lô
nên `created_at` trùng hàng loạt; 103/104 chưa có giá nên khoá sắp giá là NULL
cho gần hết. Sửa bằng trait `PhanTrangOnDinh` cho bốn service — chín chỗ gọi
`paginate()` là chín cơ hội quên.

**3. Bốn vùng chạm dưới 44px.** Nút nổi bộ lọc 38px và dòng tick cột lọc 34px
(hỏng từ #111, lúc đó không ai mở drawer `/products` ra đo) · ô tick trên thẻ
28px · nhãn ô đồng ý chính sách 38px. Cả bốn nay trong danh sách của
`class-css.test.mjs`.

### Năm lỗi của chính tôi, và cái bắt được chúng

| Lỗi | Thứ bắt được |
|---|---|
| Điều kiện "đã báo giá" viết **hai lần** — hằng số SQL cho nhóm đếm, Eloquent cho bộ lọc | Phép đột biến: 2 ca đỏ thay vì 4 |
| Dòng tick định nghĩa **trong thân** component → React tháo và gắn lại cả cây con, tick hai ô chỉ ăn một | Đo tay ở 1440px |
| `getOwnerScreens()` còn `return` ở dòng trên → `phanTrang()` thành mã chết, hàm trả Builder thay vì paginator | Soát lại diff trước khi chạy test |
| Chặn gửi trùng bằng **state** React: `setDangGui(true)` không đổi giá trị ngay, ba cú bấm → ba yêu cầu | Chính chốt mà plan đã nêu trước |
| Phép tính bề ngang lấy padding `.w` là 22px; từ 1024px nó là **52px** | Đo trong iframe |

### Hai lần suýt sửa thứ không hỏng

**`whereHas` và thứ tự `AND`/`OR`.** Tôi đọc
`where(w>=300)->orWhere(h>=300)` trong `whereHas` thành một lỗi ngoặc nuốt mất
ràng buộc khoá ngoại, và đã **báo với chủ dự án** rằng con số 84 trên
production là triệu chứng của nó. `toSql()` cho thấy Laravel tự bọc closure;
phép đột biến bỏ ngoặc không làm đỏ ca nào. 84 là số billboard thật.

**"CSV không có BOM".** `blob.text()` báo không có BOM. Đọc `arrayBuffer()`
thì ba byte đầu là `239 187 191` — `Blob.text()` giải mã UTF-8 và **bỏ BOM
theo spec**.

Cả hai cùng một hình dạng: một phép đo trả lời đúng câu hỏi của **nó**, không
phải câu hỏi của tôi.

### Hai lớp canh mới đáng ghi

**Chốt chạy trên chính mã sẽ lên production.** Node 24 bóc chú thích kiểu khi
nạp, nên `tests/js/*.test.mjs` import **thẳng** module TypeScript của Next —
không có bản sao nào để trôi. Ba tệp mới dùng cách này: danh sách rút gọn (13
ca), phần thuần của màn hình gửi (12), xuất CSV (14).

**Khẳng định phủ định.** `test:khoi` nay canh cả thứ **không được** có trong
HTML máy chủ render: bản đồ (để `/explore` và `/map` không cùng đem một tập
pin đi index), thanh danh sách rút gọn (trạng thái của riêng từng người), và
mọi câu hứa thời gian trả lời — "sẽ liên hệ sớm", "trong 24h", "ngày làm
việc".

### Còn mở sau ngày 20/10

| Việc | Chặn bởi |
|---|---|
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Bản ghi tạo ra không xoá được qua `/admin`: `QuoteRequestPolicy::delete()` đóng có chủ ý. Cần chủ dự án thử, hoặc mở quyền xoá cho `super_admin` trước |
| **Phân trang `/api/v1` mang đúng lỗi #130** | Bốn controller ở đó cũng sắp theo cột không duy nhất. Đổi thứ tự trả về là đổi hành vi hợp đồng đối tác (CLAUDE.md mục 1) — cần chủ dự án chốt |
| **Ghép sai vị trí** | "Aeon Mall Tân Phú" gắn site ở Bắc Kạn. Dữ liệu, không phải mã |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| **Thanh "trust"** | Vẫn in "Verified inventory", "Traffic real-time", "AI-powered search" |
| Mốc ba cột 1280px thay vì 1180px như plan viết | Đã ghi lại trong plan: `.w` lấy 52px mỗi bên, nên ở 1180px lưới còn 140px một thẻ |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

> **Bảng trên đã cũ tính từ chiều 20/10/2026.** Ba dòng đầu đã xử lý ở #136 →
> #138 (quyền xoá yêu cầu, soát lỗi ghép vị trí, khoá ghép khi nhập). Bảng còn
> hiệu lực ở cuối mục kế tiếp.

## Ngày 20/10/2026 — ba việc lấy ra khỏi hàng đợi (#136 → #138)

`main` ở `6a755da`. CI **1.139 ca / 4.306 khẳng định** (sáng 20/10: 1.086 /
4.142). Không còn PR nào mở, cả ba đã lên production.

| PR | Việc | Đo được |
|---|---|---|
| #136 | Mở quyền xoá yêu cầu báo giá cho quản trị sàn | 12 ca / 46 khẳng định |
| #137 | Báo cáo soát dữ liệu vị trí ở `/admin` | 9 phép đo, 30 ca |
| #138 | Lưu khoá ghép điểm đặt khi nhập | 11 ca, kèm một lỗi có sẵn |

### #136 — xoá được thì mới thử được

Lát 5 đóng cứng `QuoteRequestPolicy::delete()` để không ai tay trượt làm mất
một đầu mối của media owner. Hệ quả ngược lại: **không ai thử được đường gửi
thật trên production**, vì bản ghi thử nằm vĩnh viễn trong danh sách việc của
owner và trong mọi con số báo cáo. Một tính năng không ai thử được trên môi
trường thật là một tính năng chưa biết có chạy.

Ba quyết định bên trong:

| Quyết định | Vì sao |
|---|---|
| Chỉ `super_admin` | Owner chỉ thấy phần của mình, nhưng xoá thì xoá cả bản ghi — tức xoá luôn phần của owner khác |
| Xoá thật, không xoá mềm | Bản ghi chứa tên, email, điện thoại người gửi. Một cột `deleted_at` là **ẩn**, không phải **xoá**, và nó không trả lời được khi người mua đòi xoá dữ liệu của họ |
| Gõ lại mã + lý do bắt buộc | `requiresConfirmation()` một mình chỉ đòi một cú bấm nữa, và cú bấm nữa là thứ người ta làm theo quán tính |

Nhật ký ghi mã, trạng thái, mốc gửi, số mục, **owner nào mất đầu mối**, ai xoá
và lý do — **không** mang tên, email, điện thoại. Có một ca test đi tìm đúng ba
thứ đó trong nội dung đã ghi: xoá bản ghi vì dữ liệu cá nhân rồi chép chính dữ
liệu đó sang tệp log là xoá trên giấy.

Phép đột biến bắt được một thứ đáng ghi: **bỏ `items()->delete()` thì chốt vẫn
xanh** — khoá ngoại MySQL dọn hàng con. Dòng đó là lớp thứ hai, không phải thứ
làm việc; đã ghi lại ngay tại chỗ để không ai đọc nó như một dòng được canh.

### #137 — "ghép sai vị trí" là MỘT hàng

Soát 104 bản ghi công khai qua API công khai, không chạm CSDL production.

| Phát hiện | Số |
|---|---|
| **Thiếu tỉnh/thành** — `OOHX_LED_LCU_LAI_CHAU`, địa chỉ *"TP Lai Châu, Lai Châu"* và toạ độ đủ, ô tỉnh rỗng | 1 |
| **`Màn Hình Quảng Cáo Aeon Mall Tân Phú`** gắn "LED Bắc Kạn" | 1 |
| **`LED Yên Bái`: 6 màn hình một toạ độ**, tên chúng chỉ **ba** con đường | 6 |
| Ô tỉnh chứa hai cấp ("Thái Nguyên > Phường Bắc Kạn") | 21 |

Hàng đợi ghi việc này như thể một lỗi hệ thống. Nó không phải: 20/21 màn hình
của cùng owner theo đúng một quy ước (`OOHX_LED_<mã tỉnh>_<TỈNH>_<chi tiết>`)
và **tất cả** khớp điểm đặt. Đúng một hàng lệch — hình dạng của một bản ghi
nhập tay. Toạ độ của nó **là** ở Bắc Kạn, trùng khít `OOHX_LED_BKN_BAC_KAN`,
nên trường sai là **tên**, hoặc bản ghi gắn sai chỗ.

**Phép đo tôi thử rồi bỏ.** "Tên màn hình không có token nào chung với điểm
đặt / địa chỉ / tỉnh" — nghe hiển nhiên, cho **15 cảnh báo, 14 báo sai**: một
owner đặt tên viết tắt (`Billboard CV 23/9` ↔ *Công viên 23 Tháng 9*;
`Billboard LTT-SVH` ↔ *Giao lộ Lý Thái Tổ - Sư Vạn Hạnh*). Một bảng mà 14/15
dòng là rác thì không ai đọc lần thứ hai, và dòng thật chết cùng những dòng
kia. `test_ten_viet_tat_khong_bi_bao_sai` đóng băng quyết định ấy bằng bốn
hàng thật.

Phép đo **đã** ship và bắt được hàng Aeon lại rất hẹp: *20/21 màn hình của
owner này tên dạng mã, cái này thì không*. Bảy phép đo khác im lặng với nó —
toạ độ đúng, tỉnh đúng, nhóm đúng, không khoá nào để đối chiếu, trong tên
không có tên tỉnh nào.

**Cột "đối chiếu được".** Mỗi phép đo in kèm số bản ghi nó thật sự xét tới, vì
"0 lỗi" trên 0 bản ghi đọc giống hệt "0 lỗi" trên cả kho. Hai mức: `Lỗi` là
một khẳng định, `Cần đọc lại` thì không — badge trên menu chỉ đếm mức đầu,
gộp cả hai thì badge không bao giờ về 0 (21 ô tỉnh hai cấp, không cái nào là
lỗi) và một badge không bao giờ tắt thì không ai nhìn nữa.

### #138 — khoá ghép, và lưu nó TỪ NGUỒN

`ghepLechKhoa()` của #137 hiện `0/104`: cả ba lối nhập tra `site_external_id`
để tìm `site_id` rồi **bỏ mã đi**.

Điểm dễ làm sai nhất không phải việc lưu, mà là lưu **từ đâu**. Lấy mã từ
`$site->external_id` thì hai bên luôn bằng nhau, và một bất biến không bao giờ
vỡ được thì không canh gì — báo cáo sẽ in `104/104` và không bao giờ nói được
điều gì. Nên mã lấy từ ô trong tệp, ô người gọi gửi lên.

Hai hàng rào chống báo oan, vì một báo cáo báo oan sẽ bị tắt: `Screen::saving`
kéo cột theo khi `site_id` đổi mà không ai nói gì về mã nguồn (đúng việc biểu
mẫu Filament làm), và `Site::updated` kéo cột ở màn hình con khi mã địa điểm
đổi.

**Không điền ngược hàng cũ.** Cột nói "nguồn đã nói gì"; với hàng nhập trước
hôm nay thì không ai còn biết. Điền từ điểm đặt hiện tại là tự dựng bằng chứng
rằng chỗ gắn hôm nay đúng — kể cả khi nó sai, và hàng Aeon là đúng loại hàng
sẽ bị dựng bằng chứng cho. Độ phủ lớn dần theo từng lượt nhập lại.

**Lỗi có sẵn ca test tìm ra.** `RowValidator::resolveSiteId()` khai kiểu trả
về `?int` trong khi `sites.id` là ULID `char(26)`, và PHP 8 ném TypeError chứ
không ép. `createSite(): int` mang đúng lỗi đó. Nghĩa là
`ScreenWriter::write()` đổ ở **mọi** dòng — cả lối nhập màn hình ở `/admin`
không ghi được hàng nào. Im lặng tới hôm nay vì **không ca test nào gọi
`ScreenWriter`**.

### Ba lỗi của chính tôi trong ngày

| Lỗi | Thứ bắt được |
|---|---|
| In thẳng "cao" / "xem tay" vào Blade của panel | CI — `ChuBladePanelPhaiCoDauTest` đòi chữ hiển thị phải có dấu |
| Một mảnh chữ kẹp giữa hai biểu thức Blade thành chuỗi không dấu | CI, cùng chốt đó, **lần thứ hai trong một ngày** |
| Commit lên `main` vì quên tạo nhánh sau khi merge #137 | Tự soát trước khi push. Chưa push nên production không bị chạm |

Lỗi thứ ba đáng nhớ nhất: `merge = deploy`, nên một lần `git push` sai chỗ là
một lần deploy không ai duyệt. Việc cứu nó là `git switch -c` rồi
`git branch -f main origin/main` — không `reset --hard`, không mất gì.

### Hai chốt mới đáng ghi

**Trang Filament được dựng thật.** Hai ca nạp đúng URL `/admin/soat-vi-tri` và
đọc nội dung trả về, nên một lỗi Blade hay một biến thiếu trong
`getViewData()` làm chúng đỏ. Lần đầu chúng trả **404**: `AdminPanelProvider`
gắn `->domain(config('domains.dash'))` ở mọi môi trường trừ `local`, nên phải
gọi kèm tên miền của panel. Đó là lỗ hổng đã nêu ở #136 ("chưa có chốt nào
render thật bảng Filament") và nay đã bịt.

**Tệp Excel dựng trong ca test.** `KhoaGhepDiemDatTest` sinh workbook bằng
PhpSpreadsheet chứ không dùng tệp nhị phân cố định trong repo: khuôn tệp là
thứ chốt này canh, nên nó phải đọc được ngay trong mã test.

### Còn mở sau ngày 20/10 (bản cập nhật)

> **Bảng này hết hiệu lực chiều 20/10.** Hai dòng trong nó — phân trang
> `/api/v1` và thanh "trust" — đã làm xong ở #140 và #141. Bảng còn hiệu lực
> ở cuối tệp.

| Việc | Chặn bởi |
|---|---|
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Nay xoá được rồi (#136), nên không còn gì chặn. Cần chủ dự án bấm |
| **Chưa ai chạy thật lối nhập màn hình ở `/admin`** sau khi sửa kiểu ULID | Hai ca hồi quy đi qua `ScreenWriter` trực tiếp, không qua `ScreenImportService` và hàng đợi của nó |
| **Ba việc nhập liệu từ báo cáo soát** | Điền tỉnh cho LED Lai Châu · quyết định tên hay điểm đặt mới là cái sai ở hàng Aeon · cho sáu màn hình Yên Bái toạ độ riêng |
| **Độ phủ `ghepLechKhoa` vẫn 0/104** | Chờ lượt nhập lại đầu tiên. Không có cách trung thực nào làm nó nhảy lên hôm nay |
| **Phân trang `/api/v1` mang đúng lỗi #130** | Đổi thứ tự trả về là đổi hành vi hợp đồng đối tác (CLAUDE.md mục 1) — cần chủ dự án chốt |
| **Thanh "trust"** | Vẫn in "Verified inventory", "Traffic real-time", "AI-powered search" |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| Báo cáo soát chưa xét giá, ảnh thực địa, kích thước | Trang in câu đó ra để không ai đọc nó như một báo cáo đầy đủ |
| Mốc ba cột 1280px thay vì 1180px như plan viết | `.w` lấy 52px mỗi bên, nên ở 1180px lưới còn 140px một thẻ |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

## Ngày 20/10/2026, chiều — hai việc lấy từ bảng "còn mở" (#140, #141)

`main` ở `87af1d3`. CI **1.157 ca / 4.407 khẳng định** (sáng nay: 1.139 /
4.306). Không còn PR nào mở, cả hai đã lên production và đã đo lại trên đó.

| PR | Việc | Đo được |
|---|---|---|
| #140 | `/api/v1` lật trang không còn trùng và thiếu bản ghi | 18 ca / 101 khẳng định |
| #141 | Thanh "trust" nói việc làm được thay vì năng lực | 5 câu đổi, 1 lỗi bố cục đo trong trình duyệt |

### #140 — vì sao hôm nay sửa được cái #130 phải hoãn

#130 hoãn `/api/v1` vì nó là hợp đồng với đối tác (CLAUDE.md mục 1), và đổi
thứ tự trả về là đổi hành vi. Điều mở khoá cho hôm nay không phải một quyết
định nới tay, mà là một câu hỏi hẹp hơn: **hợp đồng đã công bố cái gì?**

`docs/tapon_api_list.md` và `docs/api_tapon_browse_filters_v1.md` công bố
**những khoá sắp xếp nào tồn tại**. Không chỗ nào công bố thứ tự **trong một
nhóm bằng nhau** — và đó là tất cả những gì khoá phụ chạm tới. Một đối tác
đang lật trang không thể đã dựa vào thứ tự cũ, vì thứ tự cũ không tồn tại:
nó đổi giữa các lần chạy.

Năm chỗ, và cột nào đang sắp trước khi sửa:

| Đường | Khoá sắp xếp cũ |
|---|---|
| `inventory/screens?sort=newest` | `updated_at` — trùng hàng loạt khi nhập theo lô |
| `inventory/screens?sort=price_asc\|price_desc` | `floor_cpm` — NULL cho 103/104 bản ghi thật |
| `inventory/owners` | `featured` (boolean) rồi `name` |
| `owners`, `sites`, `screens` | **không có `ORDER BY` nào** khi không gửi `sort` |

Ba endpoint quản lý là chỗ **duy nhất** đối tác có thể thấy khác trước: nay
chúng đi theo ULID (≈ cũ nhất trước). Cái họ thấy trước đó là thứ tự MySQL
thấy rẻ nhất hôm ấy.

**Phép đột biến nói ra một điều không đoán trước.** Gỡ khoá phụ khỏi
`phanTrang()` làm bốn ca kho hàng đỏ, nhưng **sáu ca endpoint quản lý vẫn
xanh**. Không còn `ORDER BY` nào thì MySQL quét 25 hàng theo khoá chính một
cách nhất quán giữa hai truy vấn, nên không bản ghi nào lặp. Thứ tự *không
xác định* không có nghĩa là *ngẫu nhiên*: nó đổi khi kế hoạch truy vấn đổi —
tức khi dữ liệu đủ nhiều — chứ không đổi ở cỡ một fixture.

Nên hai ca mới đi xuống **câu SQL** (`DB::listen`) và đòi khoá chính phải là
khoá sắp **cuối cùng** — vừa nói khoá phụ có mặt, vừa nói nó đứng sau mọi
khoá khác. Giới hạn của sáu ca kia ghi thẳng lên đầu chúng trong tệp test,
thay vì để người đọc tưởng chúng canh lỗi này.

Đột biến thứ hai — chèn `reorder()` để khoá phụ **giành chỗ** khoá chính —
làm đúng bốn ca "hợp đồng không đổi" đỏ. Bốn ca đó cần thiết vì
`allowedSorts()` của Spatie nạp sort vào query **ngay trong thân nó**: một
thứ tự gọi nằm trong thư viện bên ngoài, không có chốt thì không ai biết nó
đổi.

**Chưa đo được trên production.** `/api/v1` đòi Bearer token đối tác, không
có thì không gọi được; v2 đo được hôm 19/10 vì nó công khai. Đo thay thế:
v2 sau deploy vẫn 104/104 bản ghi duy nhất ở cả bốn cách sắp.

### #141 — chỗ cuối cùng còn những câu không kiểm được

Thanh năm mục dưới hero là chỗ **cuối cùng** còn chúng, sau khi khối AI bị gỡ
ở lát 3. Chủ dự án chốt dựng nguyên văn ngày 18/10 (#94, #97) rồi chốt sửa
ngày 20/10.

| Câu cũ | Câu mới | Mã đứng sau câu mới |
|---|---|---|
| Verified inventory | Vị trí trên bản đồ | `/api/v2/screens/pins`; 104/104 bản ghi công khai có toạ độ |
| Traffic real-time | Dữ liệu từ chủ màn hình | Ba lối nhập đều ghi dữ liệu của owner |
| AI-powered search | Lọc theo tỉnh, loại hình | `/api/v2/filters`: 17 tỉnh, 2 loại địa điểm, 3 loại màn hình |
| Secure payment | Gửi yêu cầu không cần đăng ký | `POST /api/v2/quote-requests` nằm ngoài nhóm Sanctum |
| Agency support 24/7 | Xuất danh sách CSV | `/explore` xuất giỏ ra CSV |

Tiêu chí: mỗi câu nói một **việc làm được**, không nói một **năng lực**. Đó là
khác biệt làm chúng kiểm được — "Vị trí trên bản đồ" sai khi bản đồ không có
pin, còn "Traffic real-time" thì không có phép thử nào.

Ba thứ **cố ý không nói ở đây** vì trang đã nói chỗ khác, và nhắc lại là lấp
chỗ: "Miễn phí đăng ký · Không phí môi giới" (hero), "Sàn không thu hộ" và
"Giá do chủ màn hình đặt" (khối ⑤). Một thứ **không nói vì chưa đo được**:
chất lượng toạ độ — báo cáo soát sáng nay tìm ra sáu màn hình Yên Bái chung
một toạ độ, nên câu là "vị trí trên bản đồ" (có pin), không phải "toạ độ
chính xác" (một khẳng định về chất lượng).

**Chữ mới tạo ra một lỗi bố cục.** Nhãn tiếng Việt dài hơn nhãn tiếng Anh, và
`.trust-in` ở desktop là `flex-wrap:nowrap` + `overflow:visible` — tràn ra
ngoài khung chứ không cuộn. Đo trong trình duyệt bằng iframe (media query
theo chiều rộng iframe, nên là phép đo thật):

| Rộng | Có `wrap` | Nếu tắt `wrap` |
|---|---|---|
| 1280px · 1180px | 1 dòng, không tràn | — |
| 1024px | 2 dòng, không tràn | mục cuối chạm 1052px — tràn 28px |
| 768px | 2 dòng, không tràn | mục cuối chạm 924px — tràn 156px |
| < 768px | cuộn ngang trong thanh (đúng thiết kế mobile), trang không cuộn ngang | — |

Nên thêm `flex-wrap:wrap; row-gap:10px` ở ≥768px và rút hai nhãn dài nhất.
Đánh đổi đã chọn: ở 768–1024px thanh nay hai dòng.

### Hai thứ đáng ghi về cách làm

**Đo trên production, không chỉ xem mã thoát.** Sau mỗi deploy hôm nay đều có
một phép đo thật, không chỉ smoke 200: #140 đếm bản ghi duy nhất khi lật hết
trang, #141 `grep` năm câu mới có mặt **và** năm câu cũ bằng 0 trong HTML
production, cộng một lần kiểm `flex-wrap:wrap` thật sự nằm trong tệp CSS đã
build.

**Đảo một quyết định thì ghi lại cả hai đầu.** Năm câu cũ chuyển từ danh sách
`co` sang `khong` của `khoi-giao-dien.mjs`, cùng kiểu với sáu luật mục AI hồi
lát 3 — mang lại một câu phải là một quyết định, không phải một lần sửa test
cho xanh.

### Còn mở sau chiều 20/10

| Việc | Chặn bởi |
|---|---|
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Nay xoá được rồi (#136), không còn gì chặn. Cần chủ dự án bấm |
| **Chưa ai chạy thật lối nhập màn hình ở `/admin`** sau khi sửa kiểu ULID | Hai ca hồi quy đi qua `ScreenWriter` trực tiếp, không qua `ScreenImportService` và hàng đợi của nó |
| **Chưa đo `/api/v1` trên production** | Cần một API client (scope `inventory`) và token của nó. Không có token thì không gọi được đường nào trong nhóm đó |
| **Ba việc nhập liệu từ báo cáo soát** | Điền tỉnh cho LED Lai Châu · quyết định tên hay điểm đặt mới là cái sai ở hàng Aeon · cho sáu màn hình Yên Bái toạ độ riêng |
| **Độ phủ `ghepLechKhoa` vẫn 0/104** | Chờ lượt nhập lại đầu tiên |
| **Đối tác chưa được báo** về thứ tự mặc định mới của `/owners`, `/sites`, `/screens` | `docs/tapon_api_list.md` đã ghi, nhưng tài liệu không tự đến tay ai — và STATUS.md vẫn đang chờ trả lời "đối tác nào đang dùng API" |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| `price_per_slot_vnd` của `/api/v1` mang giá trị `floor_cpm` | Ghi chú F09 tại chỗ: đổi là làm sai số tiền trong hệ thống đối tác mà họ không được báo trước |
| Báo cáo soát chưa xét giá, ảnh thực địa, kích thước | Trang in câu đó ra để không ai đọc nó như một báo cáo đầy đủ |
| Mốc ba cột 1280px thay vì 1180px như plan viết | `.w` lấy 52px mỗi bên, nên ở 1180px lưới còn 140px một thẻ |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

> **Bảng trên thiếu một dòng.** #143 (dưới đây) tìm ra rằng `/map` bỏ sót
> `OOHX_LED_LCU_LAI_CHAU` khỏi danh sách — dòng "ba việc nhập liệu" vì thế
> nặng hơn vẻ ngoài của nó. Bảng còn hiệu lực ở cuối mục sau.

## Ngày 20/10/2026, tối — bốn lỗi mobile từ hai tấm ảnh (#143)

Chủ dự án gửi hai ảnh chụp `/explore` và `/map` trên điện thoại, kèm đúng một
câu: *"Giao diện mobile của 2 trang này bị lỗi."* Đo lại ở khung **390×844**
trong trình duyệt (iframe, nên media query ăn theo chiều rộng khung) ra bốn
lỗi, cộng một chênh lệch số liệu hoá ra không phải lỗi giao diện.

### Một biến CSS sai phạm vi, bốn triệu chứng

`--nav: 60px` là chiều cao thanh điều hướng dưới của bản **Blade** (`.bnav`,
chỉ được `include` trong layout Blade). **Không trang Next nào render nó** —
kiểm cả `/`, `/explore`, `/map`: không có phần tử cố định nào ở đáy. Nhưng
`frontpage.css` dùng chung, và **15 quy tắc** trừ hao `var(--nav)` để né một
thanh không tồn tại:

| Đo ở 390px | Trước | Sau |
|---|---|---|
| "Bộ lọc" (`/explore`) cách đáy | 72px — lơ lửng giữa nội dung, đè lên thẻ | 12px |
| "Danh sách" (`/map`) cách đáy | 72px — đè lên dòng ghi công | 12px |
| `body` chừa cuối trang | 60px trống | 0 |
| Khung `.map-layout` | `100vh − hdr − 60px` | 668/720 |

Sửa bằng **một dòng** `--nav: 0px` trong `webapp/app/globals.css`, không đụng
`frontpage.css`: bên đó `var(--nav)` vẫn đúng cho bản Blade, nơi `.bnav` có
thật. Đây là lần thứ hai trong hai ngày mà cách đúng là **sửa ở lớp
`globals.css` của Next** thay vì sửa tệp dùng chung — lần trước là `--font`.

### Tên dạng mã bị cắt giữa chữ

Tên kho thật không có một khoảng trắng nào:
`OOHX_LED_YBI_YEN_BAI_NGUYEN_VAN_CU_1`. `.sc-title` có `overflow:hidden`
nhưng `overflow-wrap: normal`, nên chuỗi ấy không xuống dòng được — **307px
nội dung trong ô 197px**, cắt phũ, không dấu "…".

Tệ hơn chuyện thẩm mỹ: ở mobile ô này clamp **một** dòng, nên
`..._NGUYEN_VAN_CU_1` và `..._CU_2` cắt ra **giống hệt nhau**. Hai vị trí
khác nhau hiện ra như một. Sửa: `overflow-wrap:anywhere` + clamp hai dòng.

### Ô trắng rỗng trên ảnh

`.o-tick` (ô chọn vào danh sách rút gọn) có `color: transparent` khi chưa
chọn, dấu tick chỉ lộ ra khi **trỏ chuột vào**. Điện thoại không có hover,
nên nó hiện ra như một ô trắng 44×44 rỗng nằm trên ảnh. Thêm
`@media(hover:none){.o-tick{color:var(--t4)}}`.

Đây là loại lỗi chỉ tồn tại ở một nửa số thiết bị, và nửa không thấy nó là
nửa mà người viết mã ngồi.

### Dòng ghi công in chữ "true"

`L.control.attribution({ prefix: true })`. `prefix` của Leaflet là **chuỗi
HTML** in trước phần ghi công, không phải cờ bật/tắt — nên dòng dưới bản đồ
production đọc là `true | © MapTiler · © OpenStreetMap`. Nhìn thoáng qua
trông như tên một đơn vị bị cắt mất đầu, và đó đúng là cách nó bị đọc nhầm
khi soát. `false` mới là cách tắt.

### 103 ≠ 104: một vị trí không có đường nào để tới

`/map` hiện "Hiển thị **103** kết quả" trong khi kho có 104.
`/api/v2/screens/map` trả đủ 104, nhưng **đúng một** bản ghi không có tỉnh —
`OOHX_LED_LCU_LAI_CHAU` — nên nó rơi khỏi danh sách gom theo tỉnh.

Báo cáo soát sáng nay đã xếp bản ghi này là *"thiếu tỉnh, mức cao"*, nhưng
chưa ai biết **hệ quả**: nó biến mất khỏi `/map`. Một ô dữ liệu rỗng thành
một vị trí người mua không tìm thấy. Không sửa ở #143 vì có hai cách khác
nhau — điền tỉnh, hay cho danh sách một nhóm "chưa rõ tỉnh" — và đó là lựa
chọn của chủ dự án.

("94 vị trí đang hiển thị" thì đúng: đó là số pin trong khung nhìn.)

### Vì sao không test nào bắt được bốn lỗi này

jsdom không chạy layout, `khoi-giao-dien.mjs` đọc HTML đã render chứ không
chạy CSS, và không chốt nào đọc tới `dung-ban-do.ts`.
`tests/js/mobile-khong-che-khong-cat.test.mjs` **không** mở rộng tầm đó ra
tới cách trang hiện ra — điều ấy vẫn ngoài tầm. Nó đóng một khoảng hẹp hơn và
kiểm được bằng văn bản: bốn dòng đã sửa phải còn nguyên, mỗi chốt mang theo
số đo của lỗi nó chặn.

Một ca đi theo chiều ngược: `frontpage.css` **phải** giữ `--nav: 60px` và
`.bnav` **phải** còn là thanh cố định — nếu bản Blade bỏ thanh đó thì dòng
`--nav: 0px` của Next cũng hết lý do. Chốt nói ra quan hệ ấy thay vì để hai
tệp trôi khỏi nhau.

Đột biến: gỡ từng sửa một → đúng ca tương ứng đỏ, 5/5.

### Lỗi của chính tôi hôm nay, dòng thứ tư

| Lỗi | Thứ bắt được |
|---|---|
| Tạo nhánh `fix/mobile-explore-map` bằng `git switch -c` khi **đang đứng trên nhánh tài liệu**, không phải trên `main` | `git rebase main` của PR tài liệu — nó báo *"patch contents already upstream"* |

Hệ quả: PR #143 mang theo hai tệp `docs/` của PR #142, và **mô tả của #143
không nhắc tới chúng**. Nội dung không sai và không mất gì, nhưng một PR sửa
CSS lại chứa 145 dòng tài liệu mà người duyệt không được báo — nếu có người
duyệt, họ sẽ đọc một PR khác với PR họ nghĩ mình đang đọc.

Cùng họ với lỗi *"commit lên `main` vì quên tạo nhánh"* sáng nay: cả hai đều
là **không kiểm nhánh hiện tại trước khi tạo nhánh mới**. Cách tránh rẻ hơn
mọi cách sửa: `git switch -c <tên> main`, nêu rõ điểm xuất phát.

### Còn mở sau ngày 20/10 (bản cuối ngày)

| Việc | Chặn bởi |
|---|---|
| **`OOHX_LED_LCU_LAI_CHAU` không có đường nào để tới qua `/map`** | Hai cách sửa, cần chủ dự án chọn: điền tỉnh, hay cho `/map` nhóm "chưa rõ tỉnh" |
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Không còn gì chặn (#136). Cần chủ dự án bấm |
| **Chưa ai chạy thật lối nhập màn hình ở `/admin`** sau khi sửa kiểu ULID | Hai ca hồi quy đi qua `ScreenWriter` trực tiếp, không qua `ScreenImportService` và hàng đợi |
| **Chưa đo `/api/v1` trên production** | Cần một API client (scope `inventory`) và token của nó |
| **Hai việc nhập liệu còn lại** | Tên hay điểm đặt mới là cái sai ở hàng Aeon · sáu màn hình Yên Bái chung một toạ độ |
| **Độ phủ `ghepLechKhoa` vẫn 0/104** | Chờ lượt nhập lại đầu tiên |
| **Đối tác chưa được báo** về thứ tự mặc định mới của `/owners`, `/sites`, `/screens` | Tài liệu đã ghi, nhưng vẫn chưa biết đối tác nào đang dùng API |
| **Ô tick trên thiết bị cảm ứng chưa ai nhìn tận mắt** | `@media(hover:none)` không kiểm được từ máy tính; chốt chỉ canh quy tắc tồn tại |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| Bố cục `/map` mobile: bottom sheet chiếm phần lớn màn hình | #143 chỉ trả lại 60px chiều cao, không thiết kế lại tỷ lệ |
| `price_per_slot_vnd` của `/api/v1` mang giá trị `floor_cpm` | Ghi chú F09: đổi là làm sai số tiền trong hệ thống đối tác |
| Báo cáo soát chưa xét giá, ảnh thực địa, kích thước | Trang in câu đó ra để không ai đọc nó như báo cáo đầy đủ |
| Mốc ba cột 1280px thay vì 1180px như plan viết | `.w` lấy 52px mỗi bên |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

> **Bảng trên có một dòng đã đóng, và thiếu một dòng mới.** #144 và #145 đóng
> dòng "bottom sheet chiếm phần lớn màn hình" và trả nợ lời hứa của tay nắm.
> Chúng ở [`2026-10-21-22-gop-explore-ban-do.md`](2026-10-21-22-gop-explore-ban-do.md),
> và bảng còn hiệu lực ở cuối tệp đó. (Lúc viết, hai mục này ở cạnh nhau trong
> cùng một tệp; phép tách ngày 23/10/2026 chia chúng ra.)


---

← [18/10 — responsive và trang chủ](2026-10-18-responsive-va-trang-chu.md) · [Lộ trình](../LO-TRINH-SAU-GIAI-DOAN-0.md) · [21–22/10 — gộp /explore với /map](2026-10-21-22-gop-explore-ban-do.md) →
