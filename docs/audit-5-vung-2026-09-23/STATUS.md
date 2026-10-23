# Trạng thái công việc — cập nhật 13/10/2026

Một trang duy nhất trả lời: **đã làm gì, đang dở gì, chưa làm gì.** Chi tiết kỹ thuật ở `IMPLEMENTATION-P0-CLAUDE.md`; các vòng review ở `REVIEW-*.md`.

Baseline: `112e2aa`. **Giai đoạn 0 đã push** lên `feat/tmdt-review-1107` (11 commit, CI xanh). **Giai đoạn 1 đã code xong**, phần cuối còn ở working tree — xem mục 1b.

> **Mốc hiện tại (13/10/2026, cuối ngày).** `main` ở `0aed199`, CI xanh: **960
> test PHPUnit / 3.565 phép kiểm** (run `38412388041`), cộng **177 ca jsdom**
> chạy trên đúng khối script của trang Blade. Deploy production `38412388677`
> **success**. **Không còn PR nào mở.**
>
> Mười PR đã lên production trong ngày 13/10 (#74 → #83). Hai PR cuối cùng là
> phân quyền: **#83 thực thi giới hạn theo mạng lưới** — trước đó
> `allowed_network_ids` là ô cấu hình không gác gì — và #82 trả lại chữ cho hai
> quyền có hệ quả tiền trên bảng quyền.
>
> Giai đoạn 1 → 7 **đã xong và đã lên production**; giai đoạn 8 (Next.js cho khu
> người mua) hoãn có chủ ý. Mục 2 và 3 bên dưới viết từ 29/09 nên **đã lạc hậu
> một phần** — chỗ nào lạc hậu đã đánh dấu tại chỗ. Trạng thái theo giai đoạn,
> cập nhật liên tục, ở `docs/LO-TRINH-SAU-GIAI-DOAN-0.md`.

---

## 1. Đã xong

| # | Việc | Commit | Test |
|---|---|---|---|
| T1a | Phân quyền API: policy cho mọi action, tách `ability:manage`, `owner_id` suy từ người đăng nhập, DTO lọc trường nhạy cảm, ẩn `device_token` | `b9923a0` | 28 |
| T1a+ | Scope chặn mặc định khi mất tenant; quyền giá (`manage_pricing`) tách khỏi quyền kho; `TenantPermission` đòi owner còn hoạt động | `b9923a0` | ↑ |
| T2 | Máy chủ quyết định số kỳ và số CPM; `updateItem` tính lại từ ngày; đóng băng giá trong giỏ, chặn chốt đơn khi giá đổi | `eb1f32c` | 24 |
| T2+ | **Tháng lịch** thay quy ước 30 ngày; **VAT 8%** hợp nhất từ 13 chỗ về config | `38a97c9` | ↑ |
| T3 | Cổng bán hàng phủ cả sửa giỏ, chốt đơn, gửi booking | `7386fc0` | 4 |
| T4 | Giới hạn tần suất (api/login/token/player/geocode); chặn SSRF webhook hai lớp; webhook thử lại thật; `event_id` ổn định; cache proxy geocode | `66c6bc5` | 17 |
| CI | Workflow test trên MySQL có bước chặn an toàn; deploy **gọi lại** workflow test nên chỉ deploy khi xanh trên đúng SHA | `3a11989`, `9da1032` | — |
| Nhỏ | `fresh(["status"])` sai API; vai trò khi tự đăng ký; file import sang disk riêng | `6fc554b` | — |
| Baseline | Sửa 3 trong 10 ca hỏng baseline, gồm một lỗi sản phẩm: link mời hết hạn trả 500 thay vì 410 | `31218f8` | — |

## 1b. Giai đoạn 1 — đóng đường tiền (đã code xong 29/09)

| # | Việc | Test |
|---|---|---|
| 1.1 | Giữ chỗ nguyên tử: khóa hàng màn hình, hết hạn 30 phút, nhả khi bỏ giỏ hoặc hủy đơn | 17 |
| 1.2 | Mở gói thành N dòng đặt chỗ, chia tiền theo giá niêm yết, chụp thành phần gói | 17 |
| 1.3 | Cổng nội dung: duyệt mới được lên sóng, từ chối thì gỡ khỏi dòng đặt chỗ | 13 |
| 1.4 | Tiền theo từng owner: kích hoạt theo dòng, chống trùng thanh toán, số hóa đơn không trùng, số tiền do máy chủ tính | ↑ |
| 1.5 | Hủy và hoàn tiền bậc thang, nhả suất về kho, ghi nghĩa vụ hoàn tiền | 12 |
| 1.6 | Bảng giá có phiên bản; chiết khấu theo thời lượng | 11 |
| 1b | Bốn lỗ phân quyền: scope owner khi duyệt, quyền `manage_bookings`, quyền giá ép ở tầng lưu, `canAccessPanel` theo tenant đang chọn; thêm `CampaignPolicy` | 12 |

Chi tiết và những gì giai đoạn 1 **không** đóng: `REVIEW-REQUEST-GIAI-DOAN-1-CLAUDE.md`.

## 1c. Giai đoạn 2 — đường ghi bằng chứng phát sóng (xong 29/09)

Bốn lỗi trên cùng một đường, nên "bằng chứng phát sóng" của sàn cho tới nay **không tồn tại**:

| # | Lỗi | Sửa |
|---|---|---|
| 1 | **Không ghi được dòng nào** — khóa chính `char(26)` không có mặc định, model thiếu `HasUlids` → SQLSTATE 1364 ở mọi lần chèn | Dựng lại bảng; migration đếm lại trước khi xóa và dừng nếu có bản ghi |
| 2 | **Ai biết UUID cũng ghi được** — UUID nằm trong cấu hình thiết bị và log; `device_token` có sẵn từ đầu nhưng chưa từng dùng | Token băm, gửi qua `X-Device-Token`, cấp bằng `screens:issue-device-token`; chưa cấp token thì không cho qua |
| 3 | **Cộng trùng** khi thiết bị gửi lại sau mất mạng | Khóa `(screen_id, event_id, played_at)` + hỏi theo `event_id` trước khi ghi; `played_at` thành bắt buộc |
| 4 | **Không nối được với đơn hàng** — `campaign_id` khai `bigint` trong khi chiến dịch dùng ULID, nên báo cáo lọc theo nó vĩnh viễn không khớp | ULID + thêm `booking_line_id`, máy chủ tự xác minh dòng thuộc đúng màn hình và khoảng ngày |

Kèm theo: chặn biên đồng hồ thiết bị (muộn tối đa 7 ngày, tương lai kéo về hiện tại), bảng tổng hợp theo ngày và lệnh `impressions:rollup` chạy mỗi giờ để báo cáo không quét bảng thô. 23 ca test.

**Bốn quyết định nghiệp vụ chốt 29/09:** giữ chỗ 30 phút · hoàn tiền bậc thang 14/7 ngày · chiến dịch chạy theo từng dòng · gói được gồm nhiều owner, chia theo giá từng dòng.

---

## 1d. Giai đoạn 3 — hợp nhất quan hệ Màn hình ↔ Mạng lưới (xong 29/09)

Không phải hai mà **ba** đường cùng trả lời "màn hình thuộc mạng lưới nào": `sites.network_id` (trang công khai), `screen_inventory.network_id` (sáu chỗ trong Filament), `screens.network_code` (quan hệ trên model, không code nào ghi). Cùng một mạng lưới, trang quản trị và trang công khai báo hai con số khác nhau.

Chốt `sites.network_id` là nguồn sự thật duy nhất. Phần đối chiếu dữ liệu tách thành `NetworkRelationReconciler` + lệnh `networks:reconcile --dry-run` để chạy thử được trước khi chạy thật; chỉ điền ô trống, không ghi đè, mâu thuẫn thì báo cáo chứ không đoán.

**CI nay chạy toàn bộ** — đã gỡ `--exclude-group f12-network-relation` và bảy nhãn `#[Group]`. 13 ca test mới, cộng 7 ca cũ nay chạy lại.


## 1e. Lỗi do test bắt được, không nhìn ra khi đọc code

1. Đóng băng giá **chặn mọi đơn hàng** — MySQL sắp xếp lại khóa cột JSON, `===` báo khác nhau.
2. DNS trục trặc **tắt vĩnh viễn webhook** của đối tác — phải tách "cấm hẳn" khỏi "lỗi tạm thời".
3. Loopback IPv6 `[::1]` **lọt qua luật chặn SSRF** vì `parse_url` giữ dấu ngoặc vuông.
4. Gán vai trò khi đăng ký làm **đăng ký hỏng hoàn toàn** nếu bản ghi vai trò chưa có.
5. `store` của ScreenController **vẫn cho scheduler đặt giá** — tôi từng khẳng định sai rằng nó không nhận `inventory`.
6. Chia tiền gói lệch **16 đồng** vì quy giá về một ngày bằng phép chia làm tròn — tỉ lệ 1:3 hóa ra 1.999.984 với 6.000.016.
7. Cột `campaign_activities.action` bị tôi viết thành `type` trong test — lỗi test, nhưng nó chặn đúng lúc.
8. Kiểu hẹp `?Authenticatable` trên tham số của `GioiHanNetwork::cho()` làm **mọi lượt ghi `Site` bằng token đối tác thành 500 trên `/api/v1`** (13/10): `ApiClient extends Model` và **không** implement `Authenticatable`, nhưng `auth()->user()` vẫn trả về nó dưới token guard. Ba ca Api đỏ với `TypeError` — đọc code thì kiểu đó trông đúng hơn `mixed`.

---

## 2. Chưa làm — cần anh gật đầu trước

| Việc | Vì sao chờ |
|---|---|
| ~~**Gỡ số liệu bịa trên trang công khai** (F-15)~~ **XONG 03/10/2026** | Giai đoạn 4, hai đợt (29/09 và 03/10). Chi tiết ở `LO-TRINH-SAU-GIAI-DOAN-0.md` |
| ~~**Chạy đối chiếu mạng lưới trên production** (F-12)~~ **XONG 02/10/2026** | Đã chạy khi deploy: **2970 → 2970 địa điểm, 0 xung đột** |
| ~~**Xoay khóa deploy** đang nằm trong git~~ **XONG 01/10/2026** | Nhãn "F-07" ở đây **sai**: F07 trong `FINDINGS.md` là chuyện tổng tiền che khuất công nợ theo owner. Việc khóa deploy là **mục 0.2** của `IMPLEMENTATION-P0-CLAUDE.md`. Trình tự đã chạy ghi ở `docs/LO-TRINH-SAU-GIAI-DOAN-0.md`. |
| **Soát `allowed_network_ids` trên production** (mới 13/10) | Hàng rào mạng lưới nay **có hiệu lực thật** trên dữ liệu cũ: mọi hàng pivot `operator`/`read_only` đang mang danh sách khác rỗng vừa bị thu hẹp tầm nhìn kho. Câu truy vấn để biết hàng nào ở `docs/nhat-ky/2026-10-13-hang-rao-mang-luoi.md`. Nếu hàng nào là dữ liệu sót chứ không phải chủ ý, bỏ tích hết là trả lại "tất cả" |
| **Kiểm `MAIL_MAILER` trên VPS** và chạy `php artisan policies:consents-before-effective` | Hai việc cần quyền vào máy chủ, tôi không có. Nếu `MAIL_MAILER` vẫn là `log` thì thư nghĩa vụ hoàn tiền (#75) **và hai thông báo có từ trước** chỉ ghi vào log, không tới ai |

---

## 3. Chưa làm — thuộc các đợt sau

### Còn lại của T1b
- DTO lọc trường cho Site/Screen ở `/api/v2`. `price_per_slot_vnd` trả `floor_cpm` dưới cái tên sai (F09) — **cố ý chưa sửa** vì `/api/v1` là hợp đồng với đối tác; đã ghi chú tại chỗ trong code. Chờ trả lời câu hỏi 2.
- Quyền cho các action Filament còn lại (CRUD product, settings của owner).
- **Hàng rào theo mạng lưới đã thực thi 13/10** (#83, ba tầng — xem `docs/nhat-ky/2026-10-13-hang-rao-mang-luoi.md`). Còn hai khe đã biết: `ImportScreensJob` không có người dùng đăng nhập nên chặn-ghi không thấy giới hạn nào (hôm nay không ai tới được vì trang nhập kho chỉ có ở `/admin` cho `super_admin`, nhưng **phải truyền hàng rào vào job** trước khi panel publisher có trang nhập); và hai vai trò đọc báo cáo (`reporting_only`, `sales_manager`) **không** bị fence vì số liệu của chúng ở mức cả media owner, không tách được theo mạng lưới.

### Nợ giao dịch còn lại
- **Đường phát sóng cho thiết bị**: vẫn chưa tồn tại — không endpoint nào trả lịch phát. Giai đoạn 2 mới làm xong đường thiết bị **báo về**, chưa làm đường máy chủ **gửi xuống**. Cổng nội dung của 1.3 vì thế vẫn đặt ở bước kích hoạt.
- **Khiếu nại**: luồng hủy và hoàn tiền đã có (1.5), phần khiếu nại chưa.

### ~~Chuyển sang Next.js~~ — **trang công khai XONG 07/10/2026**

13 đường dẫn công khai chạy trên Next, đo từ ngoài qua Cloudflare (giai đoạn 6); Blade công khai đã dọn 08/10 theo nhánh B — bỏ đường lùi (giai đoạn 7).

**Khu người mua vẫn trên Blade** và vẫn hoãn (giai đoạn 8, chặn bởi "có thêm người") — nhưng nay rẻ hơn hẳn: **cả 11 trang** của khu người mua đọc toàn bộ dữ liệu qua `/api/v2` **từ trình duyệt**, không còn trang nào render dữ liệu nghiệp vụ ở máy chủ (đóng nốt 10/10/2026 với bốn trang luồng đặt chỗ). Việc còn lại là đổi tầng vẽ, không phải gỡ truy vấn ra khỏi view.

Và đó không chỉ là đổi hình dạng: tầng DTO có lưu lượng thật chạy qua đã **bắt bảy lỗi** mà test không bắt — trong đó VAT nhân hai lần ở trang giỏ, một thẻ trạng thái viết cứng trên trang xác nhận thanh toán, và VAT nhân lần nữa ngay trong view của trang xác nhận đặt chỗ.

---

## 4. Câu hỏi nghiệp vụ còn treo

| # | Câu hỏi | Chặn việc gì |
|---|---|---|
| ~~1~~ | ~~Khoảng ngày tối đa cho một dòng đặt chỗ — giữ 365, nới 730, hay bỏ?~~ | **Đã chốt 01/10/2026: giữ 365 ngày.** |
| 2 | Có đối tác nào đang **ghi** dữ liệu qua API không? | Giai đoạn 0 đã lên production 01–02/10 nên câu này **không còn chặn deploy**; nó còn chặn việc đặt tên lại F09 (`price_per_slot_vnd` đang trả `floor_cpm`) |
| 3 | Media owner có được **tự** xác nhận đã nhận tiền không? | Tôi đang giả định: owner chỉ ghi nhận, quản trị xác nhận mới đóng công nợ |
| ~~4~~ | ~~Số liệu bịa trên trang công khai: thay bằng số thật hay gỡ hẳn?~~ | **Đã xử xong 03/10/2026** (giai đoạn 4) |
| 5 | **Tỷ giá cho giá niêm yết ngoài VND** (mới 01/10): lấy từ đâu, chụp lại lúc nào, ai chịu rủi ro khi tỷ giá đổi | Đang **chặn đặt trực tuyến** với giá ngoài VND. Hiện không chặn việc gì có thật: production không có bản ghi USD nào |

Năm câu còn lại và thứ chúng chặn — gộp cả các câu phát sinh sau 29/09 — ở mục "Bảy câu hỏi nghiệp vụ đang chặn việc" của `LO-TRINH-SAU-GIAI-DOAN-0.md`. Đó là danh sách đầy đủ hơn bảng này.

**Đã chốt 29/09, không còn treo:** giữ chỗ 30 phút · hoàn tiền bậc thang · chiến dịch chạy theo từng dòng · gói nhiều owner chia theo giá từng dòng · **chưa có thiết bị player nào gửi dữ liệu thật**, nên giai đoạn 2 được làm lại schema `impression_logs` cho sạch thay vì migration bảo toàn dữ liệu.

---
## 5. Mốc số liệu test

| Thời điểm | Tổng | Pass | Fail |
|---|---|---|---|
| Baseline `112e2aa` | 229 | 219 | 10 |
| Sau T1a | 257 | 247 | 10 |
| Sau T2 | 281 | 271 | 10 |
| Sau tháng lịch + VAT | 286 | 276 | 10 |
| Sau T3 + T4 + việc nhỏ | 307 | 297 | 10 |
| Sau khi sửa 3 ca baseline | 307 | 300 | 7 |
| **Sau giai đoạn 1** (CI, run 36535168463) | 390 | 383 | 0 ngoài 7 ca F-12 bị loại |
| **Sau giai đoạn 2** (CI, run 36544802797) | 413 | 406 | 0 ngoài 7 ca F-12 bị loại |
| **Sau giai đoạn 3** (CI, run 36548265693) | **426** | **426** | **0 — không loại nhóm nào** |
| Sau khi hủy/hoàn tiền có lối vào thật (CI, run 36709847798) | 550 | 550 | 0 |
| Sau bốn trang luồng đặt chỗ — `a6c01c5`, 10/10/2026 (CI, run 38057005171) | 903 | 903 | 0 — 3.368 phép kiểm, 103s |
| Sau nhóm B — `2ceb100`, 13/10/2026 (CI, run 38360864450) | 922 | 922 | 0 — 3.434 phép kiểm |
| Sau gom phân quyền thành viên + "nghĩa vụ tiền" — `8ba965e`, 13/10/2026 (CI, run 38380434548) | 933 | 933 | 0 — 3.491 phép kiểm |
| Sau hàng rào mạng lưới — `2e68844`, 13/10/2026 (CI, run 38406729218) | 958 | 958 | 0 — 3.559 phép kiểm |
| **Hiện tại — `0aed199`, 13/10/2026** (CI, run 38412388041) | **960** | **960** | **0** — 3.565 phép kiểm |

Cộng thêm **177 ca jsdom** (`npm run test:js`) không nằm trong các con số trên: chúng trích **đúng khối `<script>` sẽ lên production** của 11 trang Blade khu người mua rồi chạy trong jsdom, nên một lỗi chỉ có ở phía trình duyệt vẫn đỏ được. Mười cái chốt "tự tìm phạm vi" — và **bảy lỗ** đã tìm thấy trong chính chúng — liệt kê ở `LO-TRINH-SAU-GIAI-DOAN-0.md`.

Trong 10 ca hỏng baseline, **3 ca đã sửa ngày 27/09**: 2 ca `InvitationFlowTest` — trong đó có một lỗi sản phẩm thật, `InvitationController::show` dùng sai tham số thứ ba của `view()` nên link mời hết hạn trả 500 thay vì 410 — và 1 ca `PanelAccessTest` (test dùng `status = inactive`, enum chỉ có `pending/active/suspended`).

**Bảy ca F-12 đã được thả ra ở giai đoạn 3** (29/09): quan hệ Màn hình ↔ Mạng lưới đã hợp nhất về `sites.network_id`, nhãn `#[Group]` và cờ `--exclude-group` đã gỡ. CI nay chạy **toàn bộ**, không loại nhóm nào.
