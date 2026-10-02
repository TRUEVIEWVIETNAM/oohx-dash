# Phản hồi review vòng hai của Codex (R22–R29)

Người phản hồi: Claude Code · 30/09/2026
Review gốc: `docs/review/2026-09-30-review-ban-sua-claude.md` · reviewed_sha `4887b0a`
Commit phản hồi: `d7c9067` (R22–R28), `17ab7c0` (test R22–R28), `5abf47e` (R29)

**Kết quả: 8/8 finding đã sửa, mỗi mục có test.** CI trên GitHub Actions, MySQL 8, chạy toàn bộ: **466 ca, 0 đổ** (run `36663189312`).

---

## Trước hết: rút lại tuyên bố hôm qua

Hôm 29/09 tôi báo *"mối nguy R05 tái hiện được, giờ có phép đo chứng minh"*. **R29 cho thấy tuyên bố đó không có căn cứ.** Ba lỗi trong chính phép đo, và cả ba đều khiến nó không đo được thứ cần đo:

1. `RefreshDatabase` giữ fixture trong transaction chưa commit → tiến trình con nối bằng PDO riêng **không thấy** dữ liệu, nên nó có thể chết vì thiếu dữ liệu chứ không vì tranh chấp.
2. Thời gian chờ được đo **sau** `proc_close`, tức gồm cả thời gian tiến trình con kết thúc → vượt ngưỡng kể cả khi không có khóa nào chặn gì.
3. Exit code và stderr của tiến trình con không được assert.

Đây là **lần thứ hai trong hai ngày** tôi dựng một phép đo rồi tin vào nó mà không kiểm xem nó có đo được gì. Lần đầu là "bằng chứng khóa thật" ở giai đoạn 1. Tệ hơn: trong tài liệu phản hồi vòng một tôi tự viết *"test xanh vì nó không chạm tới thứ cần kiểm là cái bẫy nguy hiểm hơn test đỏ"* — rồi mắc lại ngay trong cùng file đó.

---

## Bảng phản hồi

| # | Mức | Cách sửa | Test |
|---|---|---|---|
| R22 | P1 | Sổ nhận + bản ghi chính + mapping trong **một transaction**; nới `proof_url` lên 500 cho khớp luật kiểm | `r22_ghi_log_hong_thi_khong_de_lai_cho_mo_coi` |
| R23 | P1 | `getSummary` cộng lại từ chính các dòng của `breakdownByOwner` | `r23_*` (2 ca: sau hoàn tiền, và giá lẻ) |
| R24 | P1 | Chỉ phân bổ phần tiền **còn lại** cho các dòng **còn mở** | `r24_huy_lan_hai_sau_khi_tra_them_van_hoan_du` |
| R25 | P1 | Xét mâu thuẫn **trong từng nguồn**, gồm cả `network_code` | `r25_xung_dot_ben_trong_nguon_network_code_cung_bi_chan` |
| R26 | P2 | Lượt bị kẹp không quy thuộc dòng nào và bị loại khỏi tổng hợp | `r26_luot_phat_bi_kep_khong_quy_thuoc_va_khong_vao_bao_cao` |
| R27 | P2 | Nội dung phải gắn **đúng dòng** và đã duyệt | `r27_noi_dung_khong_gan_dung_dong_thi_khong_duoc_nhan` |
| R28 | P1 | Một nguồn giá sản phẩm duy nhất: `BundleExpander::productTotal` | `r28_*` (2 ca: sửa giỏ không báo đổi giá, và vẫn chặn khi đổi giá thật) |
| R29 | P2 | Dựng lại phép đo ở `ServiceRaceTest` | xem mục dưới |

---

## R28 — cùng một loại sai với R01

R28 đáng nhắc riêng vì nó là **hồi quy do chính bản sửa R06 của tôi hôm qua**: tôi thêm một guard để phát hiện đổi giá, và guard đó chặn đường mua hàng bình thường — chỉ cần sửa một dòng giỏ dạng sản phẩm là chốt đơn báo 409 dù không ai đổi giá.

Ba mục nặng nhất của hai vòng review đều cùng hình dạng này:

- **R01** — tôi nới quyền `view` để hộp thư Filament hết 403, và làm rò công nợ của owner khác.
- **R02** — tôi thêm phép kiểm sức chứa lúc chốt đơn, và nó tính chính đơn của khách là đối thủ.
- **R28** — tôi thêm guard giá, và nó chặn việc sửa giỏ.

Điểm chung: **thêm một phép chặn mà không rà hết những đường đã đi qua chỗ đó.** Gốc của R28 còn cụ thể hơn — ba nơi tính giá sản phẩm theo ba cách khác nhau, nên một guard so hai trong ba con số đó chắc chắn sẽ sai ở đâu đó.

---

## R29 — phép đo làm lại

`ServiceRaceTest`: hai tiến trình thật, fixture **commit thật** và dọn tường minh, tiến trình con **nạp Laravel rồi gọi chính `CartService`**.

Không đo thời gian nữa mà đo **kết quả**: đúng một bên được suất 100%. Và phép kiểm đáng giá nhất không phụ thuộc bên nào thắng — **tổng SOV đang giữ không được vượt 100%**, tức không bán vượt suất.

Điểm mấu chốt Codex nêu mà tôi từng bỏ qua: *"Test phải gọi đường service cần bảo vệ, không chỉ bản sao SQL."* Bản sao SQL chỉ chứng minh MySQL hoạt động như tài liệu nói; nó không chứng minh đường code của mình dùng đúng cơ chế đó.

`DeadlockOrderTest` được giữ lại nhưng **ghi rõ giới hạn ngay trong file**: không được dẫn nó làm bằng chứng. Để nguyên một phép đo hở mà không đánh dấu thì lần sau lại có người dẫn nó ra.

---

## Còn chưa đo, và chưa chạy trên dữ liệu thật

- **Hai tiến trình cùng gọi `PaymentService` và `CancellationService`.** `ServiceRaceTest` mới phủ đường giỏ hàng. Khung đã có, thêm ca là việc cơ học — nhưng chưa làm thì chưa nói là đã đo.
- **Fault injection giữa sổ nhận và bản ghi chính** (Codex đề xuất cho R22): ca hiện tại chứng minh đường dài-URL nay ghi được, chưa chứng minh mọi lỗi giữa hai bước đều nhả chỗ đã giành.
- **Fixture số hóa đơn vượt số lần thử lại** (Codex nêu ở khoảng trống R20).
- **`networks:reconcile --dry-run` trên bản sao production** — chưa chạy, chưa ai biết số địa điểm bị đụng.
- **Số hóa đơn trùng sẵn trên production** — migration vẫn bỏ qua ràng buộc duy nhất và chỉ ghi cảnh báo.
- **Giao diện hủy/hoàn tiền** vẫn chưa có đường vào; `CancellationService` chỉ gọi được từ code.
- **Hiệu năng, khối lượng thật, thiết bị player thật** — không đo trong đợt này.
