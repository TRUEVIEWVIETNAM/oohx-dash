# Nhật ký 23/10/2026 — đo trước khi sửa, và một đường đo đạc

> Lát 0 của plan 10k: ba giả thuyết của tôi sai, và đường dốc nhất không nằm trong số đó (#163, #165). Rồi `filters` cache nguội, gộp menu, và đường đo đạc ba lát (#166 → #171).
>
> Tách ra từ `docs/LO-TRINH-SAU-GIAI-DOAN-0.md` ngày 23/10/2026, khi tệp đó
> chạm 2.988 dòng. Kế hoạch theo giai đoạn ở lại tệp gốc; mục này là nhật ký.

---

## Ngày 23/10/2026 — đo trước khi sửa, và ba giả thuyết sai (#163, #165)

| PR | Việc | Đổi |
|---|---|---|
| #163 | Plan cho quy mô 10.000 vị trí + lát 0 (dựng kho giả và bấm giờ) | +446/−8 |
| #165 | Lát A — bản đồ gom cụm ở máy chủ | +1.003/−81 |

Hai quyết định chủ dự án chốt sáng 23/10: **`sort=distance` giữ nguyên ngữ
nghĩa** (không bán kính, danh sách không bao giờ rỗng) và **làm lát 0 trước**.

### Vì sao lát 0 không sửa gì cả

Kho thật có hơn 10.000 billboard; mọi con số của plan gộp đo trên **104 bản
ghi** production. Tôi mang vào ba giả thuyết đọc từ mã nguồn — và nói thẳng
trong plan rằng đó là giả thuyết, không phải phép đo, nên lát đầu tiên dựng
chỗ để đo chứ không tối ưu.

Hai lệnh mới: `oohx:gia-lap-kho` gieo kho **dồn cục theo đô thị** (30% Hà Nội,
28% TP.HCM), `oohx:bam-gio-kho` bấm giờ sáu đường kèm `EXPLAIN`.

Phân bố dồn cục không phải chi tiết trang trí. Toạ độ rải đều cho số đo **lạc
quan sai**: mọi ô lưới có số đếm gần như nhau nên gom cụm trông dễ, và mọi
khung nhìn cắt theo tỉ lệ diện tích nên lọc trông chọn lọc tốt. Có một ca chốt
canh điều đó, và nó đỏ nếu ai đó làm phẳng bảng trọng số.

### Số đo

| Đường | 10.000 | 50.000 | Dốc |
|---|---|---|---|
| `map` cả nước | 97ms | 474ms | 4,9× |
| `map` một quận | **10ms** | 27ms | 2,7× |
| `sort=distance` trang 1 | 68ms | 361ms | 5,3× |
| `sort=distance` trang cuối (417) | 66ms | 388ms | 5,9× |
| `q` khớp mọi hàng | 42ms | 258ms | 6,1× |
| `filters` cache nguội | 100ms | **717ms** | **7,2×** |

**Ở 10.000 không đường nào vượt 300ms.** Khoảng dư chừng 3 lần; đọc là 2 lần
cho an toàn, tức quanh 20.000 thì đo lại.

### Ba giả thuyết của tôi sai

**`whereHas` chặn chỉ mục `(lat, lon)` — SAI.** `EXPLAIN` cho thấy optimizer
viết lại `EXISTS` thành semi-join, chọn `sites` làm **bảng dẫn**, quét khoảng
trên đúng chỉ mục đó và phủ được truy vấn bằng nó (`Using index`). Từ 298 site
nó mới dò sang `screens`. Đường tôi nghi nhất lại là đường **co giãn tốt nhất**
cả bảng. Lát C xoá khỏi plan, không hoãn.

**"Phân trang sâu là chỗ chậm nhất" — SAI.** Trang 417 mất 66ms, không chậm
hơn trang 1: `OFFSET 9.984` không thêm gì đáng kể vì phép sắp toàn tập đã phải
chạy dù trang nào.

**"Thiếu chỉ mục" — SAI.** Soát migration trước khi đoán: `sites` đã có
`(lat, lon)` và `city`, `screens` đã có `(active, site_id)`,
`screen_inventory` đã có `(screen_id, floor_cpm)`. Chỗ trống đúng là ba chỗ,
không phải "thiếu index".

Và một đường **không có trong ba giả thuyết** lại dốc nhất: `filters` cache
nguội, 7,2×. Nó lộ ra chỉ vì bảng đo có cột cho nó.

> Đó là toàn bộ lý do lát 0 tồn tại. Nếu làm thẳng lát B/C/D theo trực giác thì
> ba trong bốn lát sửa thứ không đau, và thứ sẽ đau trước tiên thì không ai
> nhìn.

### Cái sai NẶNG nhất không phải chậm, mà là sai (lát A)

`getMapPins()` cắt bằng `orderBy('screens.id')->limit()`. ULID tăng theo thời
gian tạo, nên "500 pin" là **500 màn hình nhập vào sớm nhất**:

```
trả về 500 / tổng 10000 · bị cắt 9500
id lớn nhất trong tập trả về đứng thứ 500 / 10.000 theo thứ tự nhập
→ bản đồ KHÔNG thấy 9.500 màn hình nhập sau đó
```

Ranh giới nằm **chính xác** ở vị trí thứ 500 theo thứ tự nhập. `meta.truncated`
có nói là đã cắt, nhưng không nói rằng phần bị cắt có hệ thống. Người mua rà cả
nước nhận một câu trả lời nhanh (97ms), gọn, và sai — không log, không gì đỏ.

**Lát A là lát duy nhất trong plan đáng làm kể cả khi số đo nói mọi thứ đủ
nhanh**, vì nó không sửa tốc độ.

Cách sửa: hai chế độ, `meta.mode` nói rõ chế độ nào. Trong ngân sách pin thì
trả pin (không giấu gì), trên ngân sách thì trả ô lưới có số đếm (không cắt
gì). **Ngưỡng là mật độ thật trong khung nhìn, không phải một mức thu phóng** —
zoom là một phỏng đoán về mật độ, đếm thì không.

`data` và `clusters` là hai khoá riêng, có chủ ý: một client cũ chưa biết
`clusters` vẽ **bản đồ trống** — hỏng thấy được ngay — thay vì vẽ 500 pin thiên
lệch và trông như đã xong.

| Đường | 10.000 | 50.000 | Truy vấn |
|---|---|---|---|
| `map` cả nước — pin (trước) | 143ms | 548ms | 7 |
| `map` cả nước — **cụm** (sau) | **24ms** | **151ms** | **1** |

Một `GROUP BY` thay cho `COUNT(*)` + 500 pin + 8 lượt nạp quan hệ. Và vì tổng
`count` của các ô *chính là* con số `countMapPins()` trả về, hai truy vấn thành
một — quan trọng hơn: hai con số không thể trôi khỏi nhau nữa vì chỉ còn một.

### Bốn chỗ suýt sập, không chỗ nào thấy bằng mắt

**1. Phóng to vào giữa vùng đã nạp không nạp lại.** Luật `canNapLai` là "chứa
trong", và khung mới nằm gọn trong khung cũ — nên bản đồ **kẹt ở cụm mãi mãi**
dù người dùng phóng tới sát mặt đường. Cờ `daCat` (bật khi chạm trần 500) đổi
thành `chuaDayDu` (bật ở chế độ cụm): cùng một lưới an toàn, đổi điều kiện bật.

**2. Một ca chốt xanh một cách rỗng.** `test_cat_co_thu_tu_xac_dinh` so hai tập
pin bị cắt. Ở chế độ cụm `data` rỗng nên phép so thành `[] === []` — nó **ngừng
canh gì cả mà không đỏ**. Chuyển sang so thứ tự cụm, và thêm `ORDER BY` xác
định vào truy vấn gom.

**3. Thang ô lưới cạn ở đầu mịn.** Dừng ở 0,002° nên khung 0,0045° (≈500m) chỉ
ra **4,5 ô ngang**. Ca chốt bắt, không phải mắt tôi. Nối tới 0,0001° (≈11m).

**4. Đột biến `AVG` → `MIN` cho tâm cụm: cả 30 ca vẫn xanh.** Tâm cụm là trung
bình toạ độ chứ không phải tâm ô — một lựa chọn đã ghi trong docblock mà
**không có gì giữ**. Thêm ca, đột biến lại thì đúng một ca đỏ.

### Hai lần đột biến cho tín hiệu RỖNG

Đáng ghi riêng, vì cả hai lần tôi suýt báo cáo "ca này có canh":

- Làm phẳng bảng trọng số đô thị làm ca phân bố đỏ — nhưng **vì một ngoại
  lệ**: `bangTrongSo()` rút `random_int(0, 99)` vì bảng tình cờ tổng đúng 100,
  nên bảng co lại 16 ô và lệnh chết giữa đường sau khi đã ghi vài nghìn hàng.
  Sửa (rút theo `count()`) rồi đột biến lại mới có tín hiệu thật.
- Đổi tâm cụm sang tâm ô làm sai số binding và **vỡ SQL**, nên 25 ca đỏ vì
  truy vấn crash chứ không vì ngữ nghĩa. Bản đột biến hợp lệ (`AVG`→`MIN`) mới
  lộ ra lỗ ở mục 4 trên.

Bài học: một đột biến làm đỏ **không** đủ. Phải đọc *vì sao* nó đỏ.

### Số đo trên production sau deploy

104 bản ghi, ngân sách 300, nên chế độ mặc định **không bao giờ vào nhánh
cụm**. Buộc bằng `limit` thấp để chạy thật nhánh đó:

| | Mặc định | `limit=10` |
|---|---|---|
| `mode` | `pin` | `cluster` |
| `data` | 104 pin | 0 |
| `clusters` | 0 | **16 cụm, tổng `count` = 104** |
| `cell_deg` | `null` | `1` |

Tổng `count` bằng đúng 104 — không mất một màn hình nào, đúng tính chất mà
đường pin cũ vi phạm khi kho lớn. Cụm dày nhất **55 ở `10.785, 106.680`**
(TP.HCM), rồi Hà Nội 13, Đà Nẵng 9 — khớp phân bố thật, và tâm cụm chỉ vào chỗ
dày chứ không vào tâm ô. Khoá của một cụm đúng là `["lat","lng","count"]`.

### Còn mở sau 23/10

| Việc | Chặn bởi |
|---|---|
| **`filters` cache nguội** — dốc nhất bảng (7,2×) | Chưa làm. Sẽ chạm 300ms **trước** mọi lát còn lại, và cách sửa rẻ hơn lát A nhiều |
| **Cú bấm vào vòng tròn cụm chưa ai bấm bằng chuột** | Production không vào nhánh cụm ở chế độ mặc định; chỉ có chốt tĩnh và chốt API canh |
| **Lát B** (khung nới dần) | Không làm — 68ms ở 10.000. Đo lại quanh 20.000 |
| **Lát D** (`FULLTEXT`) | Không làm — 42ms ở ca xấu nhất của `LIKE` |
| **Ba lớp chặn production của `oohx:gia-lap-kho` chưa đột biến được** | Auto mode classifier chặn mọi lần sửa dòng chốt chặn, cả chiều nới lẫn chiều siết |
| **`oohx:bam-gio-kho` chưa chạy trên production** | Lượt đo duy nhất không mang mấy cái lệch của Docker cùng máy |
| **`meta.truncated` là khoá luôn `false`** | Đã đánh `deprecated` trong OpenAPI, chưa gỡ vì là hợp đồng cũ |

Mọi việc còn mở của 22/10 vẫn nguyên, trừ **Haversine chạy trên từng hàng** —
nay đã đo: 68ms ở 10.000, nên không còn là việc phải làm, chỉ là việc phải đo
lại khi kho lớn hơn.

## Ngày 23/10/2026, chiều — đường dốc nhất, một mục menu chưa bao giờ sáng, và một lời hứa trả sau một ngày (#166 → #171)

| PR | Việc | Đổi |
|---|---|---|
| #166 | Cập nhật lộ trình (sáng 23/10) | +167/−0 |
| #167 | `filters` cache nguội — đường dốc nhất mà lát 0 tìm ra | +487/−11 |
| #168 | Gộp menu "Khám phá" với "Bản đồ", và chân trang dùng hết chiều rộng | +249/−39 |
| #169 | Đo đạc lát 1 — bảng đếm và đường nhận, chưa ai gọi | +899/−1 |
| #170 | Đo đạc lát 3 — một ô trên `/admin` | +909/−1 |
| #171 | Đo đạc lát 2 — client gửi năm kết quả và mẫu số | +573/−12 |

### #167 — "cứ 30 phút một người dùng thật là người tính"

Lát 0 đo ra đường dốc nhất **không nằm trong ba giả thuyết** của tôi:
`filters` cache nguội, 7,2× từ 10.000 lên 50.000 bản ghi. Nó lộ ra chỉ vì
bảng đo có cột cho nó.

Và chỗ đau không phải thuật toán. `Cache::remember` với thời hạn 1.800 giây
nghĩa là **người đầu tiên tới sau khi hết hạn trả toàn bộ phép tính** — cứ
nửa giờ một lần, mãi mãi. Không phải sự cố, đó là cách `remember` hoạt động.

Đo trên 50.000 bản ghi giả, cùng máy đo cho cả hai bản:

| Chế độ nguội | Trước | Sau | Truy vấn |
|---|---|---|---|
| tất cả nguội | 1.178ms | 659ms | 11 → 10 |
| chỉ `fp:filters` nguội | 987ms | 654ms | 7 → 6 |

**Hai chế độ nguội đó khác nhau hẳn, và lẫn chúng là cách tự lừa mình.** Ba
hàm mà `tinhBoLoc()` gọi có cache riêng với thời hạn riêng, nên `fp:filters`
hết hạn **không** có nghĩa là mọi thứ tính lại. Ca thường xuyên là chế độ thứ
hai, và nếu chỉ đo chế độ thứ nhất thì mọi con số đều lạc quan sai.

Ba việc, theo đúng thứ tự số đo chỉ ra:

1. **Lệnh `oohx:ham-nong-bo-loc`, chạy mỗi 15 phút.** Đổi "người tính" từ một
   người mua thành một tiến trình nền. Lệnh tính **trước** rồi `Cache::put`
   **sau** — không xoá khoá rồi tính, vì giữa hai lời gọi đó có khe hở hơn
   một giây với cache rỗng, tức lệnh **tự tạo ra chính thứ nó sinh ra để
   tránh**, đều đặn theo lịch. Cũng không dùng `remember`: khoá còn hạn thì
   nó không tính gì cả.

   15 phút là **một nửa** thời hạn, có chủ ý: chạy đúng 30 phút thì một lượt
   trượt để lại một khoảng nguội.

2. **Ba câu đếm loại màn hình còn hai** (284 + 216 + 17 = 517ms, hơn nửa thời
   gian SQL). Nhóm cuối suy ra bằng phép trừ, và phép trừ đúng **theo cấu
   trúc** chứ không theo kinh nghiệm: `billboard = P`, `led = ¬P∧Q`,
   `lcd = ¬P∧¬Q`, nên ba nhánh loại trừ nhau và phủ kín vì `P ∨ ¬P`.
   `whereHas` và `whereDoesntHave` là `EXISTS` và `NOT EXISTS` của cùng một
   điều kiện.

   Vẫn gọi `applyScreenTypeFilter()` cho hai nhóm đầu, không viết lại điều
   kiện: `screen_type` là giá trị **suy ra** chứ không phải một cột.

3. **`owners` bỏ `COUNT(DISTINCT)`** (96ms). `owners → screens` là một-nhiều
   nên mỗi màn hình xuất hiện đúng một lần trong nhóm của chủ nó; `DISTINCT`
   không loại được gì, chỉ bắt MySQL dựng thêm bảng tạm.

### #168 — một mục menu chưa bao giờ sáng lên

Chủ dự án gộp hai mục điều hướng thành một. Trong lúc gỡ, một phát hiện đáng
ghi lại hơn chính việc gỡ:

> Mục "Bản đồ" ở thanh đầu **đã là một khoá chết từ lát 2 (22/10/2026)**:
> `/explore?view=map` render `<SiteHeader active="explore">`, nên **không
> trang nào** từng truyền `'map'`. Mục đó chưa bao giờ sáng lên.

Tức việc gộp menu không chỉ bớt một mục — nó bỏ một mục **đã hỏng âm thầm
suốt một ngày** mà không ai thấy, vì cái hỏng là *thiếu* một dấu hiệu chứ
không phải *có* một lỗi.

Chân trang thì sai đo được, và tôi đo trên production trước khi sửa:

| | Trước | Sau |
|---|---|---|
| logo chân trang | `128×22`, tỉ lệ **5,82** | `104×26`, tỉ lệ **4,00** |
| logo thanh đầu | `104×26`, tỉ lệ 4,00 | không đổi |
| bốn khối trên lưới 1176px | dùng **716px**, trống **460px** | `428·217·217·217`, chia hết |

Logo giãn ngang vì `<img width="128" height="32">` gặp CSS chỉ đặt `height`:
trình duyệt giữ `width` của thuộc tính HTML. Thêm `width:auto` là xong, và
đó là một ca chốt mới.

Lớp `ft-tu-chia` phải là lớp **riêng**, không sửa `.ft-g`: `.ft-g` dùng chung
với Blade, nơi có **sáu** khối, và flex cùng `basis` làm khối thứ sáu rớt
hàng. Đã đo trước khi tách.

### #169 → #171 — đường đo đạc, làm theo thứ tự 1 → 3 → 2

Ngày 22/10 `/explore` bắt đầu xin quyền vị trí **khi mở trang** (#159). Khi
thuyết phục rằng cách hỏi đó chấp nhận được, tôi hứa ba lưới an toàn; lưới
thứ ba là *"đếm tỉ lệ bấm Chặn"*. Hai lưới đầu đã làm cùng ngày. **Lưới thứ
ba trả sau một ngày.**

Vì sao thứ tự là 1 → 3 → 2: lát 2 là lát duy nhất **gửi dữ liệu thật**, nên
nó chờ hai quyết định của chủ dự án. Lát 1 và lát 3 không gửi gì và không đụng
giao diện, nên chúng chạy trước.

**Hai quyết định, chốt 23/10:** bật đo đạc · **không** ghi mục vào chính sách
bảo mật.

Quyết định thứ hai đứng được vì thiết kế cố ý không thu dữ liệu cá nhân — thân
request có đúng ba trường, bảng đích không có cột nào nhận một định danh.
Nhưng đó là một lời hứa về **hình dạng**, không phải một ghi chép ai cũng đọc
được, nên plan giờ ghi rõ **ba ca test đang canh nó** và nói rằng ngày nào một
trong ba ca ấy bị sửa cho qua thì quyết định cần được xem lại — không vì nó
sai, mà vì tiền đề đã đổi.

#### Một máy đếm, không phải một nhật ký sự kiện

| | Bảng này | Nhật ký sự kiện (không làm) |
|---|---|---|
| Lưu gì | `(ngày, sự kiện, giá trị, thiết bị) → số lượt` | một hàng mỗi lần xảy ra |
| Định danh ai | **không** — không id, không IP, không user agent | có, kể cả khi "ẩn danh" |
| Lớn ra sao | ≤12 hàng/ngày hôm nay | một hàng mỗi lượt truy cập |

Khoá duy nhất `(ngay, su_kien, gia_tri, thiet_bi)` là thứ làm bảng **không
thể** lớn theo số lượt truy cập. Đó là lớp chặn quan trọng nhất của một đường
ghi mở cho người lạ: hai lớp kia (`throttle:30,60`, danh sách trắng theo cặp)
làm việc bơm rác *khó hơn*, lớp này làm nó *vô ích*.

#### Năm chỗ sai im lặng, và không chỗ nào ném lỗi

| Chỗ | Hậu quả nếu sai |
|---|---|
| `upsert()` của Laravel sinh `so_luot = VALUES(so_luot)` | **ghi đè bằng 1** thay vì cộng thêm 1. Mỗi ô vĩnh viễn bằng 1 và trông hoàn toàn bình thường |
| `config('app.timezone')` là `UTC` | bảy giờ đầu mỗi ngày VN dồn vào hôm trước — widget thiếu hẳn buổi sáng, đều đặn, mãi mãi |
| `da_chan_tu_truoc` chia cho `hoi` | nhánh đó không gọi `getCurrentPosition` nên không có lượt `hoi` nào đi kèm; phân số **vượt 100% được** |
| đếm cả nhánh `granted` | tỉ lệ cho phép **tiến dần tới 100%** theo số lượt quay lại của chính người đã đồng ý — nó *xác nhận* đúng cái quyết định đang cần kiểm chứng |
| gửi `'tu-choi'` thay vì `'tu_choi'` | 422, `fetch` nuốt lỗi, ô đó **vĩnh viễn bằng 0** — và `dung-vi-tri.ts` có cả hai quy ước nằm cạnh nhau |

Chỗ cuối có một ca đọc `DoDacService::CHO_PHEP` **thẳng từ PHP** rồi so với
mọi giá trị client gửi, cả hai chiều: gửi lạ → 422 im lặng; nhận mà không ai
gửi → ô trống mãi.

#### Lát 1 lộ ra một lỗi thật, và đột biến là thứ chỉ ra nó

Nới phép kiểm từ theo-cặp sang gộp mọi giá trị: **không ca nào đỏ**, 12/12 vẫn
xanh. Lý do — danh sách thật mới có *một* sự kiện, nên không ca nào dựng nổi
bộ ba *(sự kiện hợp lệ, giá trị thuộc sự kiện khác)*. Ca mang tên
`gia_tri_dung_nhung_thuoc_su_kien_khac` thật ra gửi một sự kiện **không** hợp
lệ, nên nó trùng việc với ca trước. **Tên ca hứa nhiều hơn nó làm.**

Lỗi thật hơn mà nó lộ ra: `GhiDoDacRequest` tự dựng lại cả ba phép kiểm thay
vì gọi service, làm `DoDacService::hopLe()` thành **mã chết** — hai bản luật
cho một thứ, đúng loại trôi khỏi nhau mà CLAUDE.md mục 1 cấm. Docblock của
FormRequest còn nói nó gọi `hopLe()` trong khi không gọi.

Sửa: luật về một chỗ, `loiDauTien()` trả `[trường, thông báo]` thay vì `bool`
(phía gọi cần biết trường nào sai; `false` trơ không nói được, và FormRequest
sẽ lại phải tra danh sách để đoán — tức lại hai bản luật). Đột biến lại:
**đúng một ca đỏ.**

#### Ô trên `/admin` được phép nói "chưa đủ số liệu"

Dưới 100 lượt hỏi thì không in tỉ lệ nào, và ngưỡng áp **theo từng thiết bị**:

| | mobile | desktop |
|---|---|---|
| lượt hỏi | 500 | 7 |
| áp theo tổng (507) | 60% | **43%** ← ba trên bảy |
| áp theo thiết bị | 60% | *chưa đủ số liệu* |

Thêm một lưới plan chưa đòi: `tong_ket_qua` so với `hoi`. Nó canh đúng lỗi
plan lát 2 đã gọi tên — hẹn giờ `chua_tra_loi` không huỷ được thì một callback
về chậm ghi hai kết quả cho một lượt. Khi đó **từng tỉ lệ vẫn trông hợp lý**,
chỉ tổng của chúng là vô lý, và không màn hình nào khác nhìn ra được.

#### Số đã đi qua đường mạng thật

Soát bundle production sau deploy: `quyen_vi_tri`, `api/v2/do-dac`,
`pointer: coarse`, `keepalive:!0`, `credentials:"omit"` — cả năm sống sót qua
minify trong `chunks/3cjdxn6q0152m.js`.

`credentials: 'omit'` không phải chi tiết nhỏ: mặc định của `fetch` cùng nguồn
gốc là **gửi cookie phiên**, nên mỗi lượt đếm sẽ mang danh tính người dùng vào
nhật ký truy cập của OpenLiteSpeed — không vào bảng, nhưng vào chỗ nằm ngoài
mọi lời hứa của plan.

### Trạng thái đường đo đạc

```
lát 2 ✓ client gửi  →  lát 1 ✓ bảng cộng dồn  →  lát 3 ✓ ô trên /admin
```

Nối liền trên production. **Nhưng chưa trả lời gì:** ngưỡng là 100 lượt hỏi
cho mỗi loại thiết bị, và bảng mới có những lượt đầu tiên. Ô in "Chưa đủ số
liệu" là nó đang làm đúng việc.

**Đừng nới ngưỡng để đọc sớm hơn.** Plan tồn tại vì một tỉ lệ tính trên quá ít
lượt là một con số sẽ bị mang đi quyết định.

Khi đủ số, ba kết quả dẫn tới ba việc khác nhau:

| Thấy gì | Việc |
|---|---|
| cho phép cao (~70%) | quyết định 22/10 đúng, không bàn lại. Sang lát 4 |
| `tu_choi` cao | trình duyệt đã khoá những người đó **vĩnh viễn** — bàn lại cách hỏi với người mới, không cứu được người cũ |
| `chua_tra_loi` cao | ô **duy nhất còn cứu được**: câu trả lời không phải "bỏ tính năng" mà là "hỏi ở thời điểm khác" |

### Lát 4: chưa làm, có chủ ý

Plan nói **không làm trước khi lát 3 trả lời được câu hỏi ban đầu**. Thêm sự
kiện vào một đường đo đạc chưa ai đọc chỉ là thêm dữ liệu không ai đọc.

### Còn mở sau hôm nay

| Việc | Ghi chú |
|---|---|
| Hai việc về log | hạ `LOG_LEVEL=debug` → **`info`** (không phải `warning`: `Log::info` là nhật ký kiểm toán ở `QuoteRequestService:94/167/218`); tách kênh kiểm toán **trước** khi bật xoay vòng, vì `LOG_STACK=daily` giữ 14 ngày sẽ **xoá** hồ sơ. Chưa rõ vì sao `laravel.log` (494MB) ngừng được ghi lúc 22/10 09:36 |
| Nhập 10.000 vị trí từ API đối tác | cần biết đối tác nào, tài liệu API, kéo hay đẩy |
| `TRIM(SUBSTRING_INDEX(sites.city,'>',1))` còn 164ms | cần cột tỉnh tách riêng (migration), quyết định riêng |
| Lát B (khung nới dần) và lát D (`FULLTEXT`) | **không làm** — đo lại quanh 20.000 |
| `oohx:bam-gio-kho` chưa chạy trên production | |
| Ba lớp chặn production của `oohx:gia-lap-kho` chưa đột biến được | |
| `meta.truncated` là khoá luôn `false` | đã đánh `deprecated`, chưa gỡ |
| Chân trang bản **Blade** vẫn sáu cột | và còn bốn liên kết `href="#"` không đi đâu |
| Hơn mười nhánh cũ đã merge còn treo trên remote | đã đề nghị dọn, chưa được chốt |
| ~~Lộ trình này đã hơn 2.900 dòng~~ | **đã tách 23/10/2026**: kế hoạch ở lại `LO-TRINH-SAU-GIAI-DOAN-0.md` (1.180 dòng), nhật ký từng ngày sang `docs/nhat-ky/` |

---

← [21–22/10 — gộp /explore với /map](2026-10-21-22-gop-explore-ban-do.md) · [Lộ trình](../LO-TRINH-SAU-GIAI-DOAN-0.md)
