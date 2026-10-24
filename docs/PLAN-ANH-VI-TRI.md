# Plan — ảnh vị trí: từ hai tấm cho cả kho tới ảnh của từng biển

Chốt 24/10/2026, sau khi lát 5 của `docs/PLAN-CHI-TIET-VI-TRI.md` lên production
và làm lộ ra chỗ này.

Đối tượng: `screen_specs.photos` · `Screen::displayPhotoWithSource()` ·
`app/Filament/Shared/Resources/BaseScreenResource.php` (đường tải ảnh) ·
`app/Services/ScreenImport/` (đường nhập kho) · `webapp/components/ThuVienAnh.tsx`
và `ScreenCard.tsx` (hai chỗ ảnh ra tới người mua).

---

## Vì sao plan này tồn tại

Đo trên production ngày 24/10/2026, sau khi `photo_source` lên:

```
GET /api/v2/screens?per_page=50 · ba trang · 104/104 vị trí
  83  owners/covers/01KP5QP03FDDC0HJGWG2FP2T42.jpg   ← Kim Ngân ADV
  21  owners/covers/01KP5944Y5JW12MC2JJKYH2767.webp  ← AdTRUE Platform
   0  screen-photos/…
```

**Cả kho 104 vị trí hiện ra bằng đúng hai tấm ảnh.** Mỗi chủ sở hữu có một ảnh
bìa, và mọi vị trí của họ dùng lại nó. Trang `/explore` in cùng một tệp **42
lần** trong một khung nhìn.

Và chuỗi `screen-photos/` — thư mục mà biểu mẫu tải ảnh của Filament ghi vào —
xuất hiện **0 lần** trên cả đường danh sách lẫn trang chi tiết. Tức đường tải
ảnh **chưa từng được dùng cho một màn hình nào** trên production, dù nó có đủ:
`FileUpload::make('spec.photos')`, nhiều ảnh, kéo thả xếp lại, tối đa 10 tệp,
ở cả `/admin` và `/publisher`.

Đây đúng là điều `CLAUDE.md` mục 8 cảnh báo: *"Không gọi một tính năng là hoàn
chỉnh chỉ vì có route, menu, enum hay model."* Biểu mẫu có, nút có, cột CSDL có
— và 104 vị trí không có ảnh nào.

Lát 5 làm trang **nói ra** chuyện đó (`Đây là ảnh bìa của chủ vị trí, chưa có
ảnh chụp vị trí`). Nói ra là đúng, nhưng nó cũng nghĩa là dải cảnh báo ấy nay
xuất hiện trên **mọi** trang chi tiết, và sẽ còn ở đó tới khi có ảnh thật. Plan
này lo phần "có ảnh thật".

Và nó phải lo trước khi kho lên 10.000 vị trí: đổ 10.000 vị trí không ảnh vào
một trang danh sách là 10.000 thẻ giống nhau.

---

## Đo được gì, và đo bằng cách nào

| Số | Cách đo |
|---|---|
| 104 vị trí, **2** tấm ảnh phân biệt | `GET /api/v2/screens?per_page=50`, ba trang, đếm `photo_url` phân biệt |
| 83 của Kim Ngân ADV · 21 của AdTRUE Platform | đếm `owner.slug` trên cùng tập |
| `screen-photos/` **0 lần** | `curl /explore` + `curl /explore/{slug}`, grep đường dẫn |
| 3/3 trang chi tiết mở thử: `photo_source = owner_cover` | `GET /api/v2/screens/{slug}` |
| Biểu mẫu tải ảnh **không** có `maxSize`, `acceptedFileTypes`, hay resize | `BaseScreenResource.php:236–245` |
| Ba resource khác **có** resize | `VenueCategoryResource:63` (800×450), `VietnamProvinceResource:70` (800×600), `BaseNetworkResource:127` (400) |
| Cột nhập kho `spec.photo_url` là **string không luật nào** | `FieldCatalog.php:57`; `RowValidator` không có dòng nào cho `photo` |
| Không có đường API nào ghi ảnh | `routes/api.php` — v1 chỉ có `multipliers` và `toggle-programmatic` |

**Chưa đo được, cần anh chạy một lệnh:** giới hạn tải tệp và chỗ trống trên
production. `docker/php/php.ini` ghi `upload_max_filesize = 50M` nhưng đó là
container ở máy; production là OpenLiteSpeed sau aaPanel với ini riêng.

```
! ssh -i ~/.ssh/oohx_gha_deploy root@172.104.188.62 'php -i | grep -E "^(upload_max_filesize|post_max_size|memory_limit)" ; df -h /www ; du -sh /www/wwwroot/dash.oohx.net/storage/app/public'
```

---

## Nguyên tắc

1. **Không hotlink.** `ScreenDetailResource` đã ghi lý do từ trước: *một URL ảnh
   ngoài trong payload API là bắt mọi client phải tin một dịch vụ thứ ba mà
   không ai khai*. Ảnh đối tác đưa thì **tải về**, không trỏ sang máy chủ họ.
2. **Một đường đọc ảnh, không hai.** Hôm nay có hai và chúng đã lệch — xem lát
   1. Mỗi chỗ thêm vào là một chỗ nữa để lệch.
3. **Ảnh gốc không ra đường công khai.** Một ảnh điện thoại 4MB × 10.000 thẻ là
   một trang không mở được trên 3G.
4. **URL do đối tác đưa là URL không tin được.** `CLAUDE.md` mục 5: chặn địa chỉ
   nội bộ, loopback, link-local, và **kiểm lại ngay trước mỗi lần gửi**.
5. Mỗi lát là **một PR, deploy được một mình**, có chốt test riêng.
6. Lát nào **không** phụ thuộc câu trả lời của đối tác thì làm trước.

---

## Lát 0 — đo bằng lệnh, không bằng mắt

Số ở trên đo từ đường công khai, và đường công khai chỉ trả **ảnh đầu tiên**.
CSDL biết phần còn lại: vị trí nào có 1 ảnh, vị trí nào có 10, ảnh nào là đường
dẫn trong disk và ảnh nào là URL ngoài. Ở 104 vị trí còn đếm tay được; ở 10.000
thì không.

### Việc

`php artisan oohx:soat-anh-kho` — chỉ đọc, in một bảng:

- theo từng chủ sở hữu: số vị trí **có ảnh thật** / **dùng ảnh thay thế** /
  **không ảnh nào**, và `photo_source` nào đang đỡ cho nhóm thứ hai;
- số ảnh trung vị trên một vị trí có ảnh (trung bình bị một vị trí 10 ảnh kéo
  lệch);
- bao nhiêu phần tử trong `photos[]` là **URL đầy đủ** thay vì đường dẫn trong
  disk — con số này là đầu vào của lát 1;
- dung lượng `storage/app/public/screen-photos` và ảnh lớn nhất;
- cờ `--json` để lát 3 và các lượt đo sau dùng lại mà không phải phân tích bảng.

### Chốt

- Dựng kho giả ba trạng thái (ảnh thật · chỉ bìa owner · không gì) rồi khẳng
  định từng con số.
- Lệnh **không** in `device_token`, `revenue_share_pct`, hay bất cứ thứ gì
  trong danh sách cấm của `CLAUDE.md` mục 2 — lệnh này sẽ được dán vào chat.
- Đột biến: đổi một vị trí từ "có ảnh thật" sang "chỉ bìa owner" → ít nhất một
  ca đỏ.

---

## Lát 1 — ảnh URL đầy đủ thôi vỡ ở trang chi tiết

Một lỗi **đang sống**, chưa ai thấy vì chưa có ảnh nào để nó làm vỡ.

`ScreenSpec` có hai accessor xử lý đúng URL đầy đủ:

```php
if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
    return $path;
}
return asset('storage/' . $path);
```

`ScreenDetailResource::anhVaNguon()` thì **không**:

```php
->map(fn ($p) => asset('storage/' . $p))
```

Nên một hàng `photos: ["https://doitac.example/a.jpg"]` cho ra:

| Đường đọc | Kết quả |
|---|---|
| Thẻ danh sách, pin bản đồ (`display_photo` → `spec->photo`) | `https://doitac.example/a.jpg` — hiện được |
| **Trang chi tiết** (`photo_urls`) | `https://oohx.net/storage/https://doitac.example/a.jpg` — **ảnh vỡ** |

Hai đường đọc cho cùng một màn hình trả dữ liệu khác nhau: đúng hình dạng lỗi
R38 mà `CLAUDE.md` mục 1 dặn đừng lặp lại. Và cột `spec.photo_url` của đường
nhập kho là **string không luật nào** — hàng đầu tiên đối tác gửi kèm URL sẽ
tạo ra đúng cảnh trên.

### Việc

- `ScreenDetailResource` thôi tự dựng URL; nó gọi `spec->photoUrls` — accessor
  đã có, đã xử lý đúng, và đã được hai đường đọc khác dùng.
- `photo_source` vẫn do `Screen::displayPhotoWithSource()` quyết; lát này không
  đổi nghĩa trường đó.
- `RowValidator`: `spec.photo_url` nhận **hoặc** URL `https` **hoặc** đường dẫn
  tương đối không có `..`; mọi thứ khác là lỗi hàng, báo tại dòng đó.

### Chốt

- `photos[]` chứa URL đầy đủ → `photo_urls` của trang chi tiết trả **nguyên
  văn**, không bọc `asset()`.
- Ca **hai đường đọc phải khớp**: với cùng một màn hình, `photo_url` của DTO
  danh sách phải là phần tử đầu của `photo_urls` của DTO chi tiết. Ca này là
  cái chốt thật của lát — nó đỏ với bất cứ đường thứ ba nào mọc lên sau.
- Nhập một hàng có `photo_url = "../../etc/passwd"` → lỗi hàng, không ghi.

**Lát này phải lên trước mọi đường nhập ảnh.** Sửa sau nghĩa là sửa khi đã có
dữ liệu hỏng trong CSDL.

---

## Lát 2 — đường tải ảnh chịu được 10.000 vị trí

Biểu mẫu hiện tại:

```php
FileUpload::make('spec.photos')->image()->multiple()->reorderable()
    ->maxFiles(10)->disk('public')->directory('screen-photos')
```

Không `maxSize`, không `acceptedFileTypes`, không resize. `CLAUDE.md` mục 5 đòi
**kiểm mime, phần mở rộng và dung lượng**; `->image()` chỉ đặt `accept` của
`<input>`, tức một gợi ý cho trình duyệt, không phải một phép kiểm ở máy chủ. Ba
resource khác trong cùng repo đã resize đúng cách — biểu mẫu màn hình là chỗ
duy nhất không.

Và `screen-photos/` phẳng: 10.000 vị trí × tới 10 ảnh là 100.000 tệp trong một
thư mục.

### Việc

- `maxSize` + `acceptedFileTypes` tường minh (jpeg, png, webp) + `imageResize…`
  theo cạnh dài — ba con số chờ anh chốt, xem bảng quyết định.
- `directory` theo chủ sở hữu: `screen-photos/{owner_id}/` — để lát 0 đo được
  theo chủ sở hữu, và để một chủ sở hữu rời sàn thì dọn được một thư mục.
- Giữ `maxFiles(10)`.

### Chốt

- Ca đọc **khai báo** của biểu mẫu: bốn luật phải có mặt với đúng giá trị đã
  chốt. Cấu hình form Filament không có lớp test nào khác, nên một `maxSize` bị
  ai gỡ sẽ đi im lặng.
- Ca đường dẫn: ảnh của hai chủ sở hữu khác nhau không nằm cùng thư mục.
- Đột biến: gỡ `acceptedFileTypes` → ca đỏ.

---

## Lát 3 — chủ vị trí thấy mình thiếu ảnh ở đâu

104 vị trí thì gửi một email là xong. 10.000 thì chủ sở hữu cần một danh sách
rút dần được.

### Việc

Ở `/publisher`, bảng màn hình:

- một cột **Ảnh**: `Thật (n)` / `Dùng ảnh thay thế` / `Không có` — đọc
  `spec.photos` và `displayPhotoWithSource()`, không tính lại gì;
- một bộ lọc **"chỉ vị trí thiếu ảnh"**, mặc định tắt;
- sắp được theo cột đó, để chủ sở hữu mở đúng nhóm tệ nhất trước.

**Phạm vi hẹp có chủ ý.** Docblock của `danh-gia-ho-so.ts` nói khối 18 trường
"`/publisher` dùng lại được" — nhưng nó là **TypeScript**, còn `/publisher` là
Filament/PHP. Dựng lại cả 18 trường bằng PHP là tạo bản thứ hai của một phép
đánh giá, và hai bản sẽ lệch. Lát này chỉ mang sang **một** điều, cái điều
chính nó đo được từ CSDL: có ảnh thật hay không. Khối 18 trường cho `/publisher`
là một plan riêng, và nó phải bắt đầu bằng câu hỏi "một bản ở đâu" chứ không
bằng giao diện.

### Chốt

- Ba trạng thái cột, ba ca.
- Bộ lọc dùng tham số closure tên `$query` (`CLAUDE.md` mục 4: tên khác thì
  Filament tạo Builder rỗng và bộ lọc mất tác dụng — lỗi im lặng).
- Cột **scope theo tenant**: chủ sở hữu A không đếm được vị trí của B.

---

## Lát 4 — ảnh ra đường công khai không phải bản gốc

`ScreenCard` và `ThuVienAnh` đều trỏ `<img src>` thẳng vào tệp đã tải lên,
không `srcset`, không bản nhỏ. Hôm nay vô hại vì hai ảnh bìa đã nhẹ và
Cloudflare cache chúng. Sau lát 2 thì mỗi thẻ là một ảnh khác nhau, và một
trang 24 thẻ là 24 ảnh gốc.

Lưu ý: thẻ dùng `<img>` chứ không `next/image` **có lý do đã ghi** trong
`ScreenCard.tsx` — không mở `remotePatterns` để khỏi thành đường tải ảnh hộ.
Lát này không đảo quyết định đó; bản nhỏ sinh ở phía Laravel lúc tải lên, và
`srcset` chỉ trỏ vào các bản đã có.

### Việc

- Hai bản dẫn xuất lúc tải lên: `w480` cho thẻ, `w1280` cho ảnh lớn; bản gốc
  giữ lại cho lightbox và cho lượt sinh lại sau này.
- `srcset` ở hai chỗ render.
- Lệnh `oohx:sinh-anh-dan-xuat` cho ảnh đã có (chạy lại được, bỏ qua ảnh đã có
  bản dẫn xuất).

### Chốt

- Tải một ảnh 3000px → có ba tệp, và `w480` đúng là 480px.
- Lệnh chạy hai lần không sinh thêm tệp nào ở lượt hai.
- `srcset` có mặt trong HTML đã render (`webapp/test/khoi-giao-dien.mjs`).

---

## Lát 5 — ảnh về cùng lúc kho về (chờ câu trả lời đối tác)

**Phụ thuộc `plan nhập kho`**, và nó phụ thuộc đúng ba câu chưa có đáp án:
đối tác nào, tài liệu API ra sao, **kéo hay đẩy**. Không chọn hình dạng trước
khi biết — chọn sai ở đây là chọn sai cho 10.000 hàng.

Hai hình dạng, ghi lại để khi có đáp án là làm được ngay:

| | Khi nào dùng |
|---|---|
| **Bảng tính mang N URL + job tải về** | đối tác chỉ có danh sách; mở rộng `spec.photo_url` thành nhiều cột hoặc một cột nhiều URL, job tải về, đặt `photo_source = 'screen'` |
| **Đường `/api/v2` đối tác đẩy vào** | đối tác có hệ thống; cần token, giới hạn tần suất riêng, policy theo owner, và ghi nhật ký ai đẩy gì |

Cả hai đều cần: chặn SSRF (nội bộ/loopback/link-local, kiểm lại ngay trước mỗi
lần gửi), giới hạn kích thước **trước khi** tải hết tệp, kiểm mime theo nội dung
thật chứ không theo phần mở rộng trong URL, và một hàng nhật ký cho mỗi ảnh vào.

---

## Bốn quyết định cần chủ dự án chốt

| Việc | Text để duyệt |
|---|---|
| **Hotlink hay tải về.** Đề nghị **tải về**: repo đã ghi lý do ở `ScreenDetailResource` — một URL ngoài trong payload là bắt mọi client tin một dịch vụ thứ ba. Giá phải trả là chỗ trên đĩa: 10.000 vị trí × 3 ảnh × ~300KB sau resize ≈ **9GB**, và cần biết `/www` còn bao nhiêu (lệnh ở trên) | `tải ảnh về` |
| **Trong lúc chưa có ảnh thật: giữ ảnh bìa owner, hay thay bằng khung "chưa có ảnh"?** Hôm nay cả kho 104 vị trí hiện ra bằng hai tấm. Đề nghị **thôi rơi về bìa owner** và vẽ một khung trung tính có loại địa điểm + tỉnh. Đây là thay đổi **thấy ngay trên mọi trang**, nên nó là quyết định của anh, không phải của tôi — và `ScreenCard` hiện không có khung nào cho nhánh không ảnh, nên lát này kéo theo một mẩu thiết kế | `bỏ rơi về bìa owner` |
| **Vị trí không ảnh có bị xếp sau trong `/explore`?** Đề nghị **có, nhưng sau khi ảnh bắt đầu về** — xếp theo một trường mà cả 104 vị trí đều trống thì không đổi thứ tự nào, chỉ thêm một mệnh đề vào truy vấn nóng nhất của trang | `xếp sau vị trí không ảnh` |
| **Hạn mức ảnh.** Đề nghị: tối đa **10 ảnh**/vị trí, mỗi tệp **≤ 8MB**, cạnh dài resize về **2000px**, nhận jpeg/png/webp. Ba số này đi vào lát 2 | `hạn mức 10 · 8MB · 2000px` |

---

## Đứng ở đâu

| Lát | Nội dung | Đổi API? | Chặn bởi | Trạng thái |
|---|---|---|---|---|
| 0 | Lệnh soát ảnh kho | không | — | chưa làm |
| 1 | URL đầy đủ thôi vỡ · một đường đọc | không (sửa **giá trị** sai, không đổi hình dạng) | — | chưa làm |
| 2 | Đường tải ảnh: mime · dung lượng · resize | không | hạn mức chờ chốt | chưa làm |
| 3 | `/publisher` thấy vị trí thiếu ảnh | không | — | chưa làm |
| 4 | Bản dẫn xuất + `srcset` | không | lát 2 | chưa làm |
| 5 | Ảnh về cùng lúc nhập kho | **có thể** | `plan nhập kho` | chưa làm |

Thứ tự là rủi ro tăng dần. Lát 1 đứng sớm không vì nó dễ mà vì **mọi lát sau
ghi dữ liệu vào đúng chỗ nó đang hỏng**.

---

## Ngoài phạm vi plan này

- **Chất lượng ảnh** (ảnh chụp đúng mặt biển, đúng ban ngày, có bối cảnh) là
  việc của quy trình nghiệm thu, không phải của mã. Lát 3 nói "có ảnh thật";
  nó không nói ảnh đó có dùng được không.
- **Ảnh chứng minh phát sóng** (proof-of-play) là một đường khác hẳn, đi cùng
  `impression_logs`, và lát 5 của plan trang chi tiết vừa gỡ đúng lời hứa đó
  khỏi giao diện vì chưa có bản ghi nào.
- **Ảnh bìa owner** vẫn giữ nguyên vai trò của nó ở `/owners`; plan này chỉ
  thôi dùng nó làm ảnh biển.
