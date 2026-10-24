# Nhật ký 13/10/2026 — hàng rào mạng lưới nay có thực thi

> Hàng rào theo mạng lưới (#83, ba tầng) bắt đầu có hiệu lực thật trên dữ liệu cũ, và câu truy vấn để biết hàng pivot nào vừa bị thu hẹp tầm nhìn kho.
>
> Tách ra từ `docs/LO-TRINH-SAU-GIAI-DOAN-0.md` ngày 23/10/2026, khi tệp đó
> chạm 2.988 dòng. Kế hoạch theo giai đoạn ở lại tệp gốc; mục này là nhật ký.

---

## Mục mới cuối ngày 13/10/2026 — hàng rào mạng lưới nay có thực thi

Món số 1 của "hai món mới" bên trên đã đóng, nhưng **không theo cách nó được
mô tả**. Ghi lại ở đây vì chỗ lệch mới là phần đáng đọc.

Mục đó nói: `canAccessNetwork()` so vai trò bằng tên, nên hãy cho nó một quyền.
PR #82 mở ra thì thấy hàm đó **không có chỗ gọi nào** — nên một quyền thêm vào
đó sẽ gác hư không. Và phát hiện lớn hơn: `allowed_network_ids` được bốn biểu
mẫu Filament thu thập, bảng in ra *"N restricted"*, lời mời mang theo — **và
không chỗ nào thực thi**. Owner giới hạn một thành viên vào một mạng lưới thì
thấy "1 restricted", còn thành viên đó vẫn thấy tất cả. Lựa chọn là **thực thi
thật** hay **gỡ cái UI đang hứa hẹn**; chủ dự án chọn thực thi, và PR #83 làm.

### Ba tầng, vì một tầng không đủ

| Tầng | Ở đâu | Canh cái gì |
|---|---|---|
| Đọc theo danh sách | `HasOwnerScope` → `locTheoNetwork()` của **5** model | mọi truy vấn kho đi qua scope: bảng, ô chọn, đếm, resolve bản ghi |
| Đọc theo bản ghi | `NetworkPolicy` · `SitePolicy` · `ScreenPolicy` | bản ghi lấy bằng `withoutGlobalScope` — repo này dùng ở hơn 30 chỗ, nên scope một mình là lời hứa hở |
| Ghi | `Site::saving()` | scope nói được "thấy gì", không nói được "đặt kho vào đâu". Đặt ở model vì `sites.network_id` được ghi từ **bốn** đường: form Filament, `/api/v1/sites`, `SiteImportService`, `ScreenWriter` |

Câu trả lời "ai bị giới hạn" gom về **một chỗ**: `OwnerUser::networkDuocPhep()`,
và lời giải bộ nhớ theo người ở `App\Services\GioiHanNetwork` (bind `scoped()`,
không `singleton()` — một thực thể dùng chung sẽ trả lời bằng giới hạn của
người trước). `['operator','read_only']` từng có **mười** bản chép trong mã nay
còn đúng một: hằng `OwnerUser::ROLES_GIOI_HAN_NETWORK`.

Hình dạng hàng rào **khác nhau theo bảng**, và mỗi chỗ lệch là một quyết định:

| Model | Lọc theo | Khi không biết |
|---|---|---|
| `Network` | chính `id` | — |
| `Site` | `network_id` | `network_id` null → **ngoài** hàng rào |
| `Screen` | `site_id` thuộc địa điểm trong hàng rào (truy vấn con, **không** `whereHas('site')` — nó lồng hàng rào vào trong chính nó) | theo địa điểm |
| `Product` | `network_id` trong danh sách, **hoặc** `network_id` null + `site_id` thuộc địa điểm trong hàng rào | cả hai null → **ngoài** |
| `ScreenImport` | bảng không có đường nối tới mạng lưới → đổi hình dạng: chỉ lượt nhập **của chính mình** | — |

Nguyên tắc đã ghi vào docblock: **hàng rào phải đóng khi không biết, không mở.**

### Phạm vi: chỉ kho, và vì sao chỉ kho là đủ

Không đoán, mà đọc ra từ dữ liệu: ô `allowed_network_ids` **chỉ** hiện cho hai
vai trò `operator` và `read_only`, và `PERMISSIONS` của đúng hai vai trò đó chỉ
có `manage_inventory`, `import_inventory`, `view_inventory`. Nên hàng rào quanh
kho phủ hết những gì họ chạm tới được.

`reporting_only` và `sales_manager` đọc số doanh thu / báo cáo ở mức **cả media
owner**, không tách được theo mạng lưới — fence hai vai trò đó sẽ là một lời hứa
nửa vời, nên **không làm**, và ghi ở đây thay vì làm im lặng.

### Hai lỗi kề bên, sửa cùng PR vì hàng rào thật làm chúng thành lỗi

- **Thẻ "N restricted" nói sai.** Cả ba chỗ đọc thẳng cột thô, nên một hàng
  `manager` còn dữ liệu cũ trong `allowed_network_ids` bị in là "bị giới hạn"
  trong khi nó **không** bị fence. Trước đây vô hại; nay là một câu sai về một
  chốt đang có hiệu lực. Ba thẻ nay hỏi `networkDuocPhep()`.
- **`UserInvitationService`** — một trong mười bản chép, và là đường **ghi** hàng
  pivot thật khi người ta nhận lời mời.

### Hai cái chốt mới, và một ca test từng chỉ sai thủ phạm

`abstract protected static function locTheoNetwork()` trên trait làm PHP từ chối
nạp class nếu một model dùng `HasOwnerScope` mà quên khai — một bảo đảm ở thời
điểm nạp, không phải ở thời điểm chạy. Nhưng nó **không** bắt được một thân hàm
**rỗng**, nên có thêm ca tự tìm phạm vi: duyệt mọi model dùng trait và đòi SQL
sinh ra phải **khác** giữa người bị giới hạn và người không.

Bản đầu của ca đó dò trait bằng cách tìm chuỗi `"HasOwnerScope"` trong tệp, nên
nó khớp cả docblock của `OwnerUser` **bàn về** trait — và đi đỏ với tên một model
không liên quan. Đã đổi sang `class_uses_recursive()`. Bài học cùng họ với sáu lỗ
bên trên: **một phép dò nhận cả lời bàn về mã sẽ gọi tên sai thủ phạm.**

### Một lỗi tôi tự gây và CI bắt được

Kiểu hẹp `?Authenticatable` trên tham số đầu của `GioiHanNetwork::cho()` làm **ba
ca Api đỏ** với `TypeError`: `ApiClient extends Model` và **không** implement
`Authenticatable`, nhưng `auth()->user()` vẫn trả về nó dưới token guard. Tức mọi
lượt ghi `Site` bằng token đối tác sẽ thành 500 trên `/api/v1` — đúng thứ CLAUDE.md
mục 1 cấm. Đã đổi thành `mixed`, ghi lý do tại chỗ, và thêm ca
`test_token_doi_tac_khong_lam_no_hang_rao`.

### Còn mở, có chủ ý

1. **`ImportScreensJob` không chạm được hàng rào ghi.** Job không có người dùng
   đăng nhập nên `Site::saving` không thấy giới hạn nào. Hôm nay **không ai tới
   được**: trang nhập kho chỉ có ở panel `/admin`, mà `canAccessPanel` giới hạn
   cho `super_admin`. Nhưng trước khi panel publisher có trang nhập, hàng rào
   phải được **truyền tường minh** vào job.
2. **`current_owner_id` cũ** — lỗ có từ trước, không do PR này: người bị gỡ khỏi
   tenant mà phiên còn giữ `current_owner_id` cũ.
3. **Vai trò đọc báo cáo không fence** — xem mục phạm vi bên trên.

### Dữ liệu production đã đổi hành vi ngay khi deploy

Đây là một chốt **đang có hiệu lực** trên dữ liệu cũ: mọi hàng pivot
`operator`/`read_only` đang mang `allowed_network_ids` khác rỗng vừa bị thu hẹp
tầm nhìn kho — trước đây ô đó không làm gì. Câu truy vấn để biết hàng nào:

```sql
SELECT ou.owner_id, ou.user_id, ou.role, ou.allowed_network_ids
FROM owner_users ou
WHERE ou.allowed_network_ids IS NOT NULL
  AND JSON_LENGTH(ou.allowed_network_ids) > 0
  AND ou.role IN ('operator', 'read_only');
```

Nếu có hàng nào là dữ liệu cũ bỏ sót chứ không phải chủ ý, bỏ tích hết ô đó là
trả lại "tất cả" — biểu mẫu ghi `[]`, và `[]` nghĩa là tất cả, đúng như nhãn
*"để trống = tất cả"* nói.

---


---

[Lộ trình](../LO-TRINH-SAU-GIAI-DOAN-0.md) · [18/10 — responsive và trang chủ](2026-10-18-responsive-va-trang-chu.md) →
