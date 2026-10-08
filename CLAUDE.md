# OOHX — Quy tắc dự án

Cập nhật 26/09/2026: chuyển sang **kiến trúc API-first**. Kế hoạch đầy đủ ở `docs/PLAN-API-FIRST-NEXTJS.md`; các lỗi nền tảng phải sửa trước ở `docs/audit-5-vung-2026-09-23/`.

## 1. Kiến trúc

```
Laravel  = miền nghiệp vụ + API (nguồn sự thật duy nhất)
Next.js  = trang công khai + khu người mua (khách hàng nhìn thấy)
Filament = khu quản trị nội bộ /admin và /publisher
```

- **Mọi nghiệp vụ nằm ở Service/Action.** Filament, API, lệnh artisan, job đều gọi **cùng một** service. Không nhân đôi logic truy vấn — `InventoryController` và `FrontpageService` từng trôi khỏi nhau và gây rò rỉ dữ liệu chưa duyệt; đừng lặp lại.
- **Controller chỉ nhận request, gọi service, trả response.** Không viết truy vấn phức tạp trong controller.
- **Multi-tenant:** mọi truy vấn phải scope theo tenant. `HasOwnerScope` **chặn mặc định** khi không xác định được tenant, không được mở toang.
- **Không đổi hành vi `/api/v1`** — đó là hợp đồng với đối tác. Tính năng mới cho ứng dụng nội bộ đi vào `/api/v2`.

## 2. Viết API (v2)

- Mỗi action **phải** gọi policy/gate. Không dựa vào việc giao diện ẩn nút.
- **DTO danh sách trắng**, không trả thẳng model. Không bao giờ lộ: `revenue_share_pct`, `billing_info`, `bank_*`, `tax_code`, `business_license_path`, `device_token`, giá sàn nội bộ.
- **Một ngoại lệ, đã duyệt 08/10/2026:** `bank_*` + `tax_code` của media owner ra ngoài qua **đúng một** đường — `GET /api/v2/campaigns/{campaign}/payment-recipients`. Lý do: sàn không thu hộ, người mua chuyển khoản trực tiếp cho từng owner (hồ sơ Bộ Công Thương), nên không thấy nơi nhận tiền thì không trả được. Đường đó cần quyền `manage_payments`, không nhận tham số owner nào, chặn cache và ghi nhật ký truy cập. Mọi phản hồi khác — kể cả `GET campaigns/{campaign}/payments` — vẫn chỉ có `owner.id` + `owner.name`. Thêm đường thứ hai là một quyết định mới, không phải một lần sửa.
- Định dạng lỗi thống nhất: `{error, message, code, details[]}`.
- Danh sách phải phân trang và có giới hạn cứng. Endpoint bản đồ bắt buộc có khung nhìn.
- Mọi endpoint có giới hạn tần suất. Endpoint đăng nhập, cấp token và player có giới hạn riêng.
- **OpenAPI là nguồn sự thật** cho kiểu dữ liệu; Next.js sinh TypeScript từ đó, không chép tay.
- Xác thực: người dùng trình duyệt dùng Sanctum dạng SPA (cookie phiên); đối tác dùng token; Next.js phía máy chủ dùng token dịch vụ.

## 3. Viết Next.js

- App Router + TypeScript. Kiểu dữ liệu sinh từ OpenAPI.
- **Không truy cập CSDL trực tiếp.** Mọi dữ liệu qua API.
- Server Component gọi API; phần cần bí mật đi qua Route Handler, không lộ token ra trình duyệt.
- **Giữ nguyên đường dẫn cũ** khi thay trang Blade: `/explore/{slug}`, `/owners/{slug}`, `/products/{slug}`, các trang chính sách. Chuyển hướng 301 nếu buộc phải đổi.
- Giữ nguyên `sitemap.xml`, `robots.txt`, thẻ canonical và dữ liệu có cấu trúc JSON-LD đang có.
- Tiếng Việt là mặc định trên toàn bộ trang công khai.
- **Không thêm tính năng mới vào Blade** trong giai đoạn chuyển đổi; Blade chỉ được sửa lỗi.

## 4. Viết Filament (vẫn áp dụng cho /admin và /publisher)

- Dùng thành phần gốc: Resource, Page, Form, Table, Action, Infolist, Widget, RelationManager. Không viết Livewire component rời cho CRUD quản trị.
- Logic dùng chung giữa hai panel đặt ở `app/Filament/Shared/Resources/Base*Resource`; panel con chỉ override phần khác biệt (quyền, scope, route).
- Dùng `getRelations()`, không dùng `getRelationManagers()`.
- Tham số closure của filter phải đặt tên `$query`, nếu đặt tên khác Filament tạo Builder rỗng và filter mất tác dụng.
- Quyền trong panel publisher kiểm qua `TenantPermission::check()`. **Quyền phải được định nghĩa một chỗ** và dùng chung cho cả Filament lẫn API — không viết hai bộ luật.

## 5. Bảo mật

- Mọi input phải validate. Số lượng và số tiền **do máy chủ tính**, không nhận từ client.
- Không dùng raw SQL nếu không thực sự cần.
- Không commit secret. Khóa riêng, token, mật khẩu không được nằm trong repo.
- Upload: kiểm mime, phần mở rộng và dung lượng. Tệp nhạy cảm (giấy phép kinh doanh, nội dung quảng cáo) để disk riêng, truy cập qua URL ký hạn — không để trên disk công khai.
- URL do người dùng cung cấp (webhook) phải chặn địa chỉ nội bộ, loopback và link-local; kiểm lại ngay trước mỗi lần gửi.
- Hành động quan trọng phải ghi nhật ký: ai, lúc nào, trước sau, lý do.

## 6. Cơ sở dữ liệu

- Migration phải chạy lại được an toàn: kiểm `Schema::hasTable` / `hasColumn` trước khi tạo.
- Khóa ngoại đúng kiểu: Owner/Site/Screen/Campaign dùng `char(26)` (ULID); Network dùng `unsignedBigInteger`.
- Model có khóa chính ULID phải dùng trait `HasUlids` — thiếu trait thì insert hỏng.
- Bảng `impression_logs` đang phân vùng theo `played_at`: mọi khóa unique phải chứa cột phân vùng, nếu không MySQL từ chối.
- Tiền tính bằng VND số nguyên, làm tròn ở bước cuối.
- Không sửa dữ liệu lịch sử. Booking đã xác nhận giữ nguyên giá và điều khoản.

## 7. Kiểm thử

- Mọi thay đổi logic phải kèm test. Sửa lỗi phải có test chống hồi quy đúng lỗi đó.
- **Chạy test trên MySQL**, không dùng SQLite: migration của dự án dùng cú pháp riêng của MySQL. Công thức chạy an toàn ở `docs/audit-5-vung-2026-09-23/REPRODUCE-CLAUDE.md`.
- **`.env` đang trỏ vào CSDL production.** Không bao giờ chạy test mà chưa ghi đè toàn bộ biến CSDL. Kiểm tra `bootstrap/cache/config.php` không tồn tại trước khi chạy.
- Test tranh chấp đồng thời phải dùng nhiều kết nối thật, không chạy trên SQLite.
- Không kết thúc task khi test liên quan chưa pass.

## 8. Quy tắc thay đổi

- Không refactor lan man ngoài phạm vi task.
- Không gọi một tính năng là hoàn chỉnh chỉ vì có route, menu, enum hay model. Phải có đường chạy thật từ giao diện tới CSDL.
- Khi xong phải báo cáo:
  - files changed
  - tests added/updated
  - risks remaining

## 9. Thứ tự ưu tiên hiện tại

Sửa lõi trước, đổi giao diện sau. Cụ thể: phân quyền API, giá do máy chủ quyết định, cổng bán hàng, giới hạn tần suất, CI chạy test — xong nhóm này mới bắt đầu Next.js. Chi tiết ở `docs/audit-5-vung-2026-09-23/IMPLEMENTATION-P0-CLAUDE.md`.
