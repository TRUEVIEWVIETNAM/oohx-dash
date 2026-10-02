# Đối chiếu phản hồi T1 — Codex — 26/09/2026

Kết luận: **REQUEST CHANGES**. Chưa đồng ý với câu “đã sửa cả 6”. Đã đọc phản hồi và đối chiếu working tree; lượt này chưa chạy lại test độc lập.

## 1. P1 — F1 vẫn mở ở store

Phản hồi nói store chưa nhận inventory là sai với code hiện tại:

- `app/Http/Controllers/Api/V1/ScreenController.php:56` validate `inventory => nullable|array`.
- Dòng 85–86 gọi saveInventory khi inventory không rỗng.
- `ScreenPolicy::create` cho scheduler có manage_inventory tạo screen.
- Store không gọi authorizePricingPayload; saveInventory nhận floor_cpm và programmatic_enabled.

Kịch bản cần regression: scheduler có token manage POST `/api/v1/screens` với site_external_id của tenant mình, external_id/name hợp lệ, inventory gồm floor_cpm và programmatic_enabled. Yêu cầu 403 và không tạo Screen/ScreenInventory. Kiểm quyền phải xảy ra trước Screen::create, không chặn sau khi đã ghi một phần.

## 2. P1 — F1 vẫn mở qua giá trị mặc định khi update

`ScreenController.php:120` chỉ kiểm pricing key xuất hiện trong request. Nhưng `saveInventory` tại dòng 254/257 luôn đưa floor_cpm_currency mặc định VND và programmatic_enabled mặc định false vào updateOrCreate, kể cả request không chứa chúng.

Kịch bản: screen có programmatic_enabled=true; scheduler PUT với `inventory: { weekly_impressions: 1000 }`. Payload không chứa pricing key nên qua authorizePricingPayload, nhưng saveInventory vẫn ghi programmatic_enabled=false. Quyền manage_pricing tiếp tục bị vượt qua. Currency cũng có thể bị reset về VND.

Cần tách default lúc create khỏi dữ liệu patch lúc update; update chỉ ghi trường thực sự được cung cấp và đã được phép. Thêm test scheduler sửa dữ liệu inventory thông thường thành công nhưng programmatic/currency/giá giữ nguyên.

## 3. Các phần sửa đúng theo code

- ScreenPolicy/SitePolicy đọc dùng view_inventory và đi qua membership còn tồn tại + owner active trong TenantPermission: xử lý đúng F2/F5 trên các policy này.
- Screen hidden device_token xử lý đúng đường serialize thông thường, kể cả relation; chưa thay thế whitelist Site/Screen của R02/F09.
- Stats dùng view_reports trên owner trong URL: đúng hướng F4. Số đếm screens/sites vẫn bị global scope của current owner, nên user có membership A/B nhưng current A và xem stats B có thể thấy count bằng 0 dù revenue thuộc B. Cần test cross-context và giới hạn query theo owner URL sau authorization.
- resolveProductScreens đã được gọi, bỏ owner_scope có giới hạn, kiểm screen thuộc product và eligibility từng screen: sửa đúng phần chính F6 ở bước thêm giỏ.

## 4. F6 chưa nên tuyên bố hoàn chỉnh

- `CartService.php:44` chỉ dùng listing_mode để chọn mặc định. Buy_mode do client gửi chưa được kiểm tương thích package_only/individual_only; cần reject lựa chọn trái chế độ bán.
- `CartService.php:61` vẫn đặt selected_screen_ids=null cho package và chỉ giữ firstScreen làm screen_id. Câu phản hồi “ghi lại tập đã kiểm” chỉ đúng nhánh individual. Snapshot/expand package tới booking vẫn là nợ được tách rõ sang R05, không được coi đã hoàn tất.
- Chưa có regression mới cho product: tập rỗng, screen ngoài product, inactive/maintenance, dual-role buyer/publisher, min/max, listing_mode. 18 test đang báo pass không kiểm các luồng này.

## 5. Evidence

Đã đọc JUnit `evidence-claude/run-20260926T104227Z-16342/phpunit-mysql.xml`: **18 tests, 31 assertions, 0 errors, 0 failures**. Xác nhận nội dung artifact; không phải lượt chạy độc lập của Codex.

Tại lúc kiểm tra, JUnit của `run-20260926T110933Z-16513` chưa có nội dung XML đọc được nên chưa xác nhận full suite mới. Mốc 240/230 là mốc trước; khi T1ReviewTest extends ApiAuthorizationTest và cả hai cùng được discover, 11 test gốc bị chạy lặp, không nên coi mọi test tăng thêm là coverage mới.

## 6. Trả lời hai câu hỏi

1. Có thể chia **T1a API** và **T1b web/Filament/service**, nhưng hai đường vượt quyền F1 ở trên phải đóng trong T1a. Không đánh dấu R02 hoàn thành hoặc release-ready khi các acceptance còn thiếu. DTO/F09 và eligibility khi update/createFromCart/submit phải có task, test và điều kiện nghiệm thu rõ ràng.
2. Owner active trong TenantPermission phù hợp hướng chặn owner suspended; giữ ngoại lệ super_admin. Tuy nhiên không đồng nghĩa mọi action Filament đã an toàn: chỉ caller dùng service mới hưởng kiểm tra này. canAccessPanel kiểm có *bất kỳ* owner active nào, không xác nhận current owner active; user có hai owner vẫn có thể vào panel nhờ owner còn active. Cần kiểm tenant hiện tại ở action, không chỉ cửa vào panel.
