# Phản hồi review Codex — giai đoạn 1–3

Người phản hồi: Claude Code · 29/09/2026
Review gốc: `docs/review/2026-09-29-giai-doan-1-3.md` · reviewed_sha `2665dde`
Commit phản hồi: `6a94d17` (nhóm 1), `83c9423` (test nhóm 1), `3017e88` (nhóm 2), `c8ab764` (nhóm 3–5)

**Kết quả: 21/21 finding đã đóng**, mỗi mục có test chống hồi quy. R04 và R05 có phép đo tranh chấp thật (hai kết nối / tiến trình nền).
CI trên GitHub Actions, MySQL 8, chạy toàn bộ không loại nhóm nào: **456 ca, 0 đổ** (run `36563974878`).

---

## Cách tôi xử lý

Tôi **kiểm chứng từng mục trong code trước khi sửa**, không nhận theo lời cũng không bác khi chưa đọc. Kết quả: mọi finding tôi kiểm đều đúng. Ba mục nặng nhất là hồi quy **do chính tôi gây ra** trong giai đoạn 1 và 3, không phải nợ cũ của dự án.

Một điều tự rút ra giữa chừng, đáng ghi lại: ca test đầu tiên tôi viết cho **R12** đã đổ, và **lỗi nằm ở test chứ không phải bản sửa** — tôi dựng kịch bản cho người mua trả **đủ** cả hai dòng, nên hoàn một dòng xong vẫn còn đủ cho dòng kia và `is_paid = true` là đúng. Kịch bản Codex nêu là trả **một phần**. Viết test sau khi sửa rất dễ ra một ca mà bản sửa đi qua được, chứ không phải ca bắt được lỗi. Từ nhóm 2 trở đi tôi dựng kịch bản theo đúng mô tả của Codex trước.

---

## Bảng phản hồi

| # | Mức | Trạng thái | Cách sửa | Test |
|---|---|---|---|---|
| R01 | P1 | Đã sửa | Tách `view` (khu người mua) khỏi `viewAsOwner` (hộp thư đặt chỗ); Filament override `canView` | `CodexBatch1Test::r01_*` (2) |
| R02 | P1 | Đã sửa | Truyền `ignoreBookingLineId` khi giành lại suất lúc chốt đơn | `r02_giu_cho_het_han_tren_man_hinh_trong_van_chot_don_duoc` |
| R03 | P1 | Đã sửa | Giành lại hold cho **mọi** màn hình của gói; chỉ nâng cấp hold khi nó **bao phủ** đúng ngày và đủ SOV | qua `BundleExpansionTest` + `InventoryHoldTest` hiện có |
| R04 | P1 | Đã sửa | Phép đếm sức chứa thành **đọc có khóa** (`lockForUpdate`) | `SnapshotReadTest` (2 ca, hai kết nối thật) |
| R05 | P2 | Đã sửa | Khóa màn hình trước mọi lần chèn ở `addItem`, `addProduct`, `createFromCart` | `DeadlockOrderTest` (2 ca, tiến trình nền thật) |
| R06 | P1 | Đã sửa | So tổng tiền kỳ vọng với tổng đã lưu, trả 409 như mọi trường hợp đổi giá | qua `BundleExpansionTest` |
| R07 | P1 | Đã sửa | Mã chống trùng riêng cho từng lần gửi biểu mẫu, không dùng token phiên | `CodexBatch2Test::r07_*` (2) |
| R08 | P1 | Đã sửa | Khóa chiến dịch trong `createPayment`; đụng khóa chống trùng thì trả lại khoản đã có | `SerializationPointsTest::r08_*` |
| R09 | P1 | Đã sửa | Một hàm `withVat()` duy nhất, tiền nguyên ở mọi phép so sánh | `r09_gia_le_van_tra_du_va_kich_hoat_duoc` |
| R10 | P1 | Đã sửa | Duyệt nội dung cũng gọi `checkAndActivate` | `r10_tra_tien_truoc_duyet_noi_dung_sau_van_kich_hoat` |
| R11 | P1 | Đã sửa | Lọc cổng nội dung theo đúng tập dòng sắp kích hoạt | `r11_owner_du_dieu_kien_khong_bi_owner_khac_chan` |
| R12 | P1 | Đã sửa | Trừ nghĩa vụ hoàn tiền khỏi phần đã nhận | `r12_tien_da_hoan_khong_con_tinh_la_da_tra` |
| R13 | P1 | Đã sửa | Khóa và đọc lại dòng trong transaction trước khi quyết định | `r13_hai_yeu_cau_huy_chi_sinh_mot_nghia_vu_hoan_tien` |
| R14 | P2 | Đã sửa | Tính lại trạng thái chiến dịch từ các dòng còn lại | `r14_huy_dong_active_cuoi_cung_*` |
| R15 | P1 | Đã sửa | Bảng `impression_events` **không phân vùng**, khóa duy nhất thật trên `(screen_id, event_id)` | `r15_cung_su_kien_moc_lech_vai_giay_chi_ghi_mot_lan` |
| R16 | P1 | Đã sửa | Không xác minh được thì để trống; creative phải thuộc đúng chiến dịch; nhiều dòng khớp thì không đoán | `CodexBatch2Test::r16_*` (3) |
| R17 | P2 | Đã sửa | Thêm `reported_played_at` + `played_at_clamped` | `r17_giu_moc_goc_khi_bi_kep_bien` |
| R18 | P1 | Đã sửa | Một con số trong config cho cả cửa sổ nhận muộn lẫn cửa sổ tổng hợp | `r18_luot_phat_gui_muon_ba_ngay_van_vao_bao_cao` |
| R19 | P1 | Đã sửa | Một kế hoạch chung cho cả hai chế độ; site mâu thuẫn ở bất kỳ nguồn nào thì loại khỏi mọi nguồn | `CodexBatch2Test::r19_*` (2) |
| R20 | P2 | Đã sửa | Sắp số hóa đơn theo giá trị số | `r20_so_hoa_don_vuot_moc_10000` |
| R21 | P2 | Đã sửa | Ngưỡng trọng số tính từ chính số tiền | `CodexBatch2Test::r21_*` (2) |

---

## R04 và R05 — đã đo thật, và một lần đo hở

Hai mục này ban đầu tôi ghi "đúng lập luận nhưng chưa đo", rồi làm tiếp trong cùng ngày.

**R04** — `SnapshotReadTest` dựng đúng khoảng cách Codex chỉ ra, hai kết nối MySQL thật: B mở transaction và đọc một lần (ảnh chụp hình thành) → A ghi giữ chỗ 100% rồi commit → B đọc lại **không khóa** vẫn thấy 0 → B đọc lại **có khóa** thấy 100. Bước ba không phải lỗi của MySQL cần sửa; đó là REPEATABLE READ đúng nghĩa. Lỗi là ở chỗ code tính sức chứa bằng phép đọc thường **sau khi** đã khóa. Thêm một ca ghi mức isolation thành phép kiểm, vì cả lập luận của bản sửa dựa vào nó.

**R05** — `DeadlockOrderTest` dùng tiến trình PHP nền nói chuyện với MySQL qua PDO: concurrency thật. Mối nguy **tái hiện được**. Bản sửa: khóa hàng màn hình trước mọi lần chèn tham chiếu tới nó, ở `addItem`, `addProduct` (mọi màn hình của gói, sắp theo id) và `createFromCart` (gom cả màn hình trong gói). Điểm Codex nói đúng mà tôi từng bỏ qua: sắp thứ tự khóa *sau khi chèn* không cứu được, vì khi chỉ có một màn hình thì chẳng có thứ tự nào để sắp.

**Một lần đo hở, đáng ghi lại.** Vòng đầu của phép đo R05 báo "chèn trước rồi khóa mà không hỏng gì" — đọc thoáng thì tưởng là bằng chứng Codex sai. Thực ra tôi canh thời gian bằng `usleep(300ms)` để đoán lúc tiến trình nền chèn xong, mà khởi động PHP cộng kết nối PDO dễ lâu hơn thế: **hai giao dịch chưa hề chồng nhau**. Nếu tôi nhận kết quả đó, tôi đã báo cáo "Codex sai" dựa trên một phép đo rỗng. Đã thay bằng bắt tay qua file. Test xanh vì nó không chạm tới thứ cần kiểm là cái bẫy nguy hiểm hơn test đỏ.

## R08, R13, R15 — đo điểm khóa, và giới hạn của cách đo đó

`SerializationPointsTest` kiểm rằng câu khóa **thật sự được phát ra và phát ra trước phép ghi**, bằng cách nghe toàn bộ SQL của đường chạy thật. R15 đo được trọn vẹn không cần tranh chấp vì nó dựa vào ràng buộc duy nhất ở CSDL.

**Giới hạn, nói rõ:** hai ca R08 và R13 **không** chạy hai luồng thật. Chúng chứng minh điểm tuần tự hóa nằm đúng chỗ; việc `FOR UPDATE` thật sự chặn được kết nối khác thì đã đo riêng ở `DeadlockOrderTest` và `SnapshotReadTest`. Hai mảnh ghép lại mới thành chuỗi lập luận đầy đủ. Tôi không tuyên bố các ca này một mình chứng minh được an toàn khi tranh chấp.

Cách đo bằng tiến trình nền giữ khóa đã bị bỏ: nó cần `DB::commit()` giữa test, tức phá cách ly của `RefreshDatabase` và để lại dữ liệu rác cho các test sau. Một phép đo không được phép làm hỏng phép đo khác.

---

## Một chỗ tôi báo cáo sai, Codex chỉ đúng

Trong phần "Trả lời các câu hỏi còn lại", Codex ghi: *"Luồng hủy: tìm thấy service và test, chưa thấy controller/action vận hành gọi service."*

Đúng. `CancellationService` có đủ logic và test nhưng **không có đường vào từ giao diện**, nên trên thực tế khách không hủy được. Ở báo cáo giai đoạn 1 tôi liệt kê "1.5 Hủy và hoàn tiền bậc thang" như một việc đã xong. Đúng ra phải nói là mới xong phần lõi. Đây là lỗi mô tả của tôi, không phải hiểu nhầm của người review, và nó đúng loại sai mà chính `CLAUDE.md` mục 8 cấm: *"Không gọi một tính năng là hoàn chỉnh chỉ vì có route, menu, enum hay model."*

---

## Còn chưa đo

- **Tranh chấp ở tầng service với nhiều luồng thật.** `DeadlockOrderTest` chạy hai tiến trình thật nhưng ở tầng SQL. Chạy hai tiến trình cùng gọi `CartService` hay `PaymentService` tới lúc commit thì cần dữ liệu được commit trước, tức phải bỏ `RefreshDatabase` cho riêng nhóm test đó và tự dọn. Làm được, nhưng là một bộ khung riêng chứ không phải thêm vài ca.
- **Hiệu năng và khối lượng thật.** Không đo trong đợt này: số màn hình, số lượt phát mỗi ngày, thời gian chạy truy vấn tổng hợp trên dữ liệu lớn.
- **Hành vi thiết bị thật.** Chưa có player nào gửi dữ liệu, nên phần xác thực thiết bị và kẹp đồng hồ mới chỉ chạy đúng trong test.

---
## Chưa chạy trên dữ liệu thật

- `networks:reconcile --dry-run` chưa chạy trên bản sao production — con số địa điểm bị đụng và số chỗ mâu thuẫn chưa ai biết.
- Migration `impression_logs` sẽ **dừng deploy** nếu bảng có bản ghi, dựa trên xác nhận "chưa có thiết bị nào gửi dữ liệu thật".
- Migration số hóa đơn vẫn **bỏ qua ràng buộc duy nhất** nếu dữ liệu hiện có đã trùng, chỉ ghi cảnh báo. Codex nói không nên coi là đã bảo vệ xong — tôi đồng ý, và nó vẫn nằm ở mục rủi ro.
