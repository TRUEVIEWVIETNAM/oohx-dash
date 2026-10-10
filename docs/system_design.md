# AdTRUE SSP — System Design Reference

> **Mục đích:** Tài liệu chuẩn hoá kiến trúc và quy tắc phát triển.
> Đọc trước khi thêm model, migration, resource, API, hoặc permission mới.
> **Cập nhật file này** mỗi khi có thay đổi cấu trúc quan trọng.

---

## 1. Tổng quan stack

| Thành phần | Version | Ghi chú |
|---|---|---|
| Laravel | 12 | PHP 8.2+ |
| Filament | 3.3 | Admin UI |
| Spatie Permission | 6.24 | Chỉ dùng cho `super_admin` global role |
| Laravel Sanctum | 4.3 | API token cho external clients |
| PhpSpreadsheet | 5.5 | Import Excel (Hivestack) |

---

## 2. Multi-tenant — Cơ chế & Quy tắc

### Cơ chế

```
User ──pivot(owner_users)──► Owner (tenant)
              ↑
         role + allowed_network_ids

User.current_owner_id  →  context đang hoạt động
```

- Mỗi **Owner** = 1 tenant (media owner / publisher).
- User có thể thuộc **nhiều** Owner, mỗi quan hệ có role riêng.
- `users.current_owner_id` xác định tenant context hiện tại cho session.
- Switching tenant: `User::switchOwner($ownerId)` — kiểm tra quyền trước khi gán.

### Trait `HasOwnerScope`

Áp dụng cho: **Screen, Site, Network**.

```php
// Tự động thêm WHERE owner_id = current_owner_id vào mọi query
// Ngoại lệ: super_admin thấy tất cả, ApiClient token thấy tất cả
```

**Quy tắc bắt buộc:**

> ✅ Mọi model liên quan đến inventory (`Site`, `Screen`, `Network`) **phải** có `owner_id` column và sử dụng trait `HasOwnerScope`.
> ✅ Mọi migration thêm bảng inventory phải có `owner_id` FK → `owners.id`.
> ❌ Không query trực tiếp `Site::all()` / `Screen::all()` trong controller hoặc service — HasOwnerScope sẽ tự scope nhưng phải đảm bảo user đã authenticate.
> ✅ Filament Resources trong Publisher panel phải override `getEloquentQuery()` scope theo `current_owner_id` (không dựa hoàn toàn vào trait vì Filament chạy trong context khác).

---

## 3. User Roles & Permissions

### Hai tầng phân quyền

```
Tầng 1 — Global (Spatie)
  super_admin  →  vào được Admin panel, thấy tất cả dữ liệu

Tầng 2 — Tenant (OwnerUser custom)
  owner_users.role  →  quyền trong phạm vi 1 Owner
```

### Tenant Roles (OwnerUser::ROLE_LABELS)

| Role | Mô tả |
|---|---|
| `owner` | Toàn quyền trong tenant |
| `manager` | Quản lý inventory + pricing + edit owner |
| `operator` | Quản lý inventory, import |
| `read_only` | Chỉ xem inventory |
| `reporting_only` | Chỉ xem báo cáo |
| `sales_manager` | Xem sales + báo cáo |

### Permission Matrix (OwnerUser::PERMISSIONS)

| Permission key | Roles được phép |
|---|---|
| `manage_users` | owner |
| `edit_owner` | owner, manager |
| `manage_inventory` | owner, manager, operator |
| `manage_pricing` | owner, manager |
| `view_inventory` | owner, manager, operator, read_only, sales_manager |
| `import_inventory` | owner, manager, operator |
| `view_reports` | owner, manager, reporting_only, sales_manager |
| `export_reports` | owner, manager, reporting_only |
| `view_sales` | owner, manager, sales_manager |
| `manage_bookings` | owner, manager, sales_manager |
| `settle_refunds` | owner, manager |

### Cách check permission

```php
// Trong Filament Resource (Publisher panel)
TenantPermission::check('manage_inventory')   // bool

// Trong controller/service
TenantPermission::for($user, $ownerId)->can('view_reports')

// Network-level restriction
$ownerUser->canAccessNetwork($networkId)
// owner/manager: thấy tất cả; các role khác: chỉ trong allowed_network_ids
```

**Quy tắc:**

> ✅ Filament Publisher resources **phải** implement `canViewAny/canCreate/canEdit/canDelete` bằng `TenantPermission::check()`.
> ✅ Admin resources **phải** return `true` cho tất cả can* methods (không dùng TenantPermission).
> ❌ Không hardcode role name trong logic nghiệp vụ — dùng permission key thông qua `TenantPermission`.
> ❌ Không thêm Spatie roles mới cho tenant-level permission — hệ thống đã có custom OwnerUser roles.

---

## 4. Database — Quy tắc & Cấu trúc

### Primary Keys

| Model | PK type | Ghi chú |
|---|---|---|
| Owner | ULID | `HasUlids` trait |
| Site | ULID | `HasUlids` trait |
| Screen | ULID | `HasUlids` trait |
| Network | bigint auto | Không dùng ULID |
| User | bigint auto | Standard Laravel |
| OwnerUser | bigint auto | Pivot table |
| *_inventory, *_spec | bigint auto | Child tables |

> ✅ FK đến Owner → dùng `string` / `ulid()` type.
> ✅ FK đến Screen/Site → dùng `string` / `ulid()` type.
> ✅ FK đến Network → dùng `unsignedBigInteger`.

### Soft Deletes

Áp dụng cho: **Owner, Site, Screen, Network**.

> ✅ Mọi query trên các model này phải aware soft delete.
> ✅ Filament tables đã xử lý tự động qua Eloquent; raw queries trên `DB::table()` phải thêm `->whereNull('deleted_at')` thủ công.

### Idempotent Migrations

> ✅ **Mọi migration** phải idempotent — dùng `Schema::hasTable()` / `Schema::hasColumn()` / `SHOW INDEX FROM` để check trước khi tạo.
> ❌ Không dùng `Schema::createOrFirst()` — chỉ có trong Laravel 11+, dự án đang chạy Laravel 12 nhưng pattern cũ trên production có thể conflict.

```php
// Pattern chuẩn cho create table
if (Schema::hasTable('table_name')) return;
Schema::create('table_name', ...);

// Pattern chuẩn cho add column
if (! Schema::hasColumn('table', 'column')) {
    $table->string('column');
}

// Pattern chuẩn cho add index
$indexes = collect(DB::select("SHOW INDEX FROM `table`"))->pluck('Key_name');
if (! $indexes->contains('index_name')) {
    $table->index('column', 'index_name');
}
```

### Quan hệ chính

```
Owner
 ├── hasMany Sites       (sites.owner_id)
 ├── hasMany Networks    (networks.owner_id)
 ├── hasMany Screens     (screens.owner_id)
 └── hasMany OwnerUsers  (owner_users.owner_id)

Site
 ├── belongsTo Owner
 ├── belongsTo VietnamProvince  (province_id)
 ├── belongsTo VietnamCommune   (commune_id)
 └── hasMany Screens

Screen
 ├── belongsTo Owner
 ├── belongsTo Site
 ├── hasOne ScreenSpec
 ├── hasOne ScreenInventory
 └── [Network via network_code ↔ networks.code — string join, không phải FK]

ScreenInventory
 └── belongsTo Network  (network_id → networks.id)

VietnamRegion → VietnamProvince → VietnamCommune → Site
```

> ⚠️ **Screen–Network relationship:** Screen có `network_code` (string), không phải `network_id` FK thông thường.
> Khi join: `networks.code = screens.network_code`.
> ScreenInventory có `network_id` FK thực (bigint → networks.id).

---

## 5. Filament Panels — Kiến trúc

### Hai panel song song

```
/admin      →  AdminPanelProvider    →  super_admin only
/publisher  →  PublisherPanelProvider →  owner_users với active owner
```

### Thư mục discovery

```
app/Filament/
├── Shared/
│   └── Resources/
│       ├── BaseSiteResource.php      ← canonical UI
│       ├── BaseNetworkResource.php   ← canonical UI
│       └── BaseScreenResource.php   ← canonical UI
├── Resources/           ← Admin panel (extends Base)
│   ├── SiteResource.php
│   ├── NetworkResource.php
│   ├── ScreenResource.php
│   ├── OwnerResource.php
│   ├── UserResource.php
│   └── Vietnam*/VenueType* (admin-only)
└── Publisher/
    └── Resources/       ← Publisher panel (extends Base)
        ├── SiteResource.php
        ├── NetworkResource.php
        ├── ScreenResource.php
        └── OwnerUserResource.php
```

### Quy tắc Base Resource

Resources được chia sẻ giữa Admin và Publisher đều extends `Base*Resource` trong `Shared/`:

| Thứ gì | Ở đâu |
|---|---|
| Form schema, table columns, filters, bulk actions | `Shared/Resources/Base*Resource` |
| Phân quyền (`canViewAny`, `can*`) | Subclass (Admin/Publisher) |
| Tenant scoping (`getEloquentQuery`) | Subclass Publisher (Admin không scope) |
| Owner field trong form | Admin override `ownerFormField()` hook |
| Owner column/filter trong table | Admin override `additionalTableColumns/Filters()` |
| Pricing-gated content | Publisher override `canPricing()` |
| Dropdown options (site/network) | Publisher override với scoped options |

> ✅ Khi thêm feature vào form/table của Site/Screen/Network → sửa trong `Base*Resource`.
> ❌ Không sửa trực tiếp Publisher hay Admin resource cho shared UI — sẽ tạo drift.
> ✅ Feature chỉ dành riêng cho 1 panel (ví dụ: geocoding action ở EditSite publisher) → để trong page class, không vào base.

### Hooks của Base Resources

**BaseSiteResource:**
```php
ownerFormField(): ?Component        // Admin: Select owner_id; Publisher: null
additionalTableColumns(): array     // Admin: [owner.name column]
additionalFilters(): array          // Admin: [owner SelectFilter]
```

**BaseNetworkResource:**
```php
ownerFormField(): ?Component        // Admin: Select owner_id; Publisher: null
additionalTableColumns(): array
additionalFilters(): array
hasViewPage(): bool                 // Publisher: true (ViewNetwork); Admin: false
```

**BaseScreenResource:**
```php
canPricing(): bool                  // Admin: true; Publisher: TenantPermission
siteFormOptions(): array            // Admin: all sites; Publisher: scoped
networkFormOptions(): array         // Admin: all networks; Publisher: scoped
siteFilterOptions(): array
networkFilterOptions(): array
additionalTableColumns(): array     // Admin: [owner.name column]
additionalFilters(): array          // Admin: [owner SelectFilter]
```

---

## 6. API External (Sanctum + ApiClient)

```
POST /api/v1/auth/token
  body: client_id, client_secret, grant_type=client_credentials
  returns: access_token (Bearer, TTL 1h)

Authorization: Bearer {token}  →  CheckTokenAbility middleware
```

- `ApiClient` model có `HasApiTokens` — dùng Sanctum personal tokens.
- Scope/ability được lưu trong token, check bằng `ability:inventory,booking`.
- ApiClient token **bypass** `HasOwnerScope` — thấy tất cả dữ liệu.

> ✅ Route API mới phải thêm `middleware('ability:scope_name')`.
> ✅ Endpoints trả dữ liệu inventory phải scope theo `owner_id` nếu token có owner context.

---

## 7. Services — Quy tắc

| Service | Nhiệm vụ |
|---|---|
| `TenantPermission` | Permission check — dùng mọi nơi, không bypass |
| `GeocodingService` | Nominatim reverse geocoding, rate limit 1 req/s |
| `ScreenRegistryService` | Import screens từ Excel (Hivestack format) |
| `SiteImportService` | Import sites từ Excel |

> ✅ Business logic phức tạp → vào Service/Action, không viết trong Controller/Resource.
> ✅ GeocodingService: luôn thêm `sleep(1)` giữa các request Nominatim khi batch.

---

## 8. Filament Form — Quy tắc Filter

> ⚠️ **Lỗi hay gặp:** Filter closure parameter phải đặt tên `$query` (không phải `$q`).
> Filament `EvaluatesClosures` resolve bằng **tên parameter** trước. `$q` không match key `'query'` → Filament tạo Builder mới rỗng → filter không có tác dụng.

```php
// ✅ Đúng
->query(fn(Builder $query, array $data) => $query->when(...))

// ❌ Sai — filter không hoạt động
->query(fn(Builder $q, array $data) => $q->when(...))
```

---

## 9. Địa lý Việt Nam

```
VietnamRegion (7 vùng kinh tế)
  └── VietnamProvince (63 tỉnh/thành)
       └── VietnamCommune (phường/xã/thị trấn)
            └── Site.province_id / commune_id
```

- `province.type`: `tinh` | `thanh_pho`
- `province.region`: tên vùng (chuỗi, redundant với region FK)
- Reverse geocoding: `GeocodingService::resolveLocation(lat, lon)` → trả `province_id`, `commune_id`, `address`, `city`, `region`

---

## 10. Checklist khi thêm tính năng mới

### Thêm Model / Table mới

- [ ] Model có `owner_id`? → thêm `HasOwnerScope` trait
- [ ] FK đến Owner/Site/Screen dùng đúng kiểu (ULID = string, Network = bigint)
- [ ] Migration idempotent (hasTable/hasColumn checks)
- [ ] SoftDeletes nếu là entity quan trọng

### Thêm Filament Resource cho shared entity

- [ ] Logic UI → `Shared/Resources/Base*Resource`
- [ ] Admin subclass: can* = true, không scope
- [ ] Publisher subclass: can* = TenantPermission, scope theo owner_id
- [ ] Không duplicate form/table code

### Thêm Permission mới

- [ ] Thêm vào `OwnerUser::PERMISSIONS` constant
- [ ] Cập nhật matrix trong file này (Section 3)
- [ ] Check bằng `TenantPermission::check('new_permission')`

### Thêm API endpoint mới

- [ ] Route có `middleware('ability:scope')`
- [ ] Response scope theo owner nếu cần
- [ ] Cập nhật docs/tapon_api_list.md

---

## 11. Lịch sử thay đổi cấu trúc

| Ngày | Thay đổi | Lý do |
|---|---|---|
| 2026-03-20 | Thêm VietnamProvince/Commune/Region tables + FK vào sites | Geocoding & địa chỉ chuẩn hoá |
| 2026-03-21 | Thêm ApiClient + WebhookSubscription tables | OAuth2 cho external API (OOHX) |
| 2026-03-24 | Thêm public profile fields vào owners, code vào networks | Publisher profile page |
| 2026-03-26 | Refactor Base*Resource pattern (Site/Screen/Network) | Đồng bộ Admin–Publisher UI |
| 2026-03-26 | Thêm map picker (Leaflet) vào Site form | UX nhập toạ độ GPS |
