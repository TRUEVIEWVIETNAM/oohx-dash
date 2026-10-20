# TapON SSP — Danh sách API

> **Cập nhật lần cuối:** 2026-10-20
> **Base URL:** `https://ssp.tapon.vn/api/v1`
> **Auth:** Sanctum Bearer Token (trừ `POST /auth/token` và `/player/*`)

---

## Mục lục

1. [Auth](#1-auth)
2. [Inventory — Public API (OOHX)](#2-inventory--public-api-oohx) *(stats, owners, owner detail, screens, map, screen detail)*
3. [Webhook](#3-webhook)
4. [Player](#4-player)
5. [Owners](#5-owners)
6. [Sites](#6-sites)
7. [Screens](#7-screens)
8. [Import](#8-import)

---

## 1. Auth

### `POST /auth/token`

Lấy access token theo chuẩn OAuth2 `client_credentials`.

**Auth:** Không cần

**Body (JSON):**

| Field | Type | Required | Ghi chú |
|---|---|---|---|
| `client_id` | string | ✅ | |
| `client_secret` | string | ✅ | |
| `grant_type` | string | ✅ | Phải là `client_credentials` |
| `scope` | string | — | Space-separated, vd: `inventory booking` |

**Response 200:**
```json
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "inventory"
}
```

**Response 401:**
```json
{ "error": "invalid_client", "error_description": "Client authentication failed" }
```

---

## 2. Inventory — Public API (OOHX)

**Auth:** Bearer token, scope `inventory`

---

### `GET /inventory/stats`

Thống kê tổng hợp inventory cho homepage. Kết quả được cache 30 phút.

**Query params:** Không có

**Response 200:**
```json
{
  "total_screens": 3644,
  "total_cities": 5,
  "total_owners": 42,
  "venues": [
    { "type": "mall", "label": "Retail", "count": 1200 },
    { "type": "outdoor", "label": "Outdoor", "count": 800 }
  ],
  "cities": [
    { "code": "hanoi",    "name": "Hà Nội",           "count": 1500 },
    { "code": "hcm",      "name": "TP. Hồ Chí Minh",  "count": 1200 },
    { "code": "danang",   "name": "Đà Nẵng",           "count": 400 },
    { "code": "haiphong", "name": "Hải Phòng",         "count": 300 },
    { "code": "cantho",   "name": "Cần Thơ",           "count": 244 }
  ]
}
```

> `cities` luôn trả về đúng 5 thành phố lớn cố định dù count = 0. `venues` chỉ trả về loại có tồn tại.

---

### `GET /inventory/owners`

Danh sách media owners công khai (có phân trang). Chỉ trả về owner có `status = active`.

**Query params:**

| Param | Type | Default | Ghi chú |
|---|---|---|---|
| `page` | integer | 1 | |
| `limit` | integer | 20 | Max: 100 |
| `featured` | boolean | — | Nếu `true`, chỉ trả về featured owners |

**Response 200:**
```json
{
  "total": 42,
  "page": 1,
  "limit": 20,
  "data": [
    {
      "owner_id": "vingroup-media",
      "name": "Vingroup Media",
      "tagline": "Vietnam's leading OOH network",
      "logo_url": "https://...",
      "cover_url": "https://...",
      "screen_count": 120,
      "city_count": 5,
      "venue_types": ["mall", "outdoor"],
      "website": "https://...",
      "email": "contact@...",
      "phone": "+84901234567",
      "founded": 2015,
      "featured": true,
      "headquarters": { "lat": 21.028, "lng": 105.834 }
    }
  ]
}
```

> `owner_id` = `slug` của owner. `about` không có trong list, chỉ trong detail.

---

### `GET /inventory/owners/{slug}`

Chi tiết một media owner theo slug. Thêm field `about` so với list.

**Path params:**

| Param | Ghi chú |
|---|---|
| `slug` | Slug của owner (= `owner_id` trong list) |

**Response 200:** Giống 1 item trong list + thêm field `about` (string).

**Response 404:**
```json
{ "error": "Owner not found" }
```

> Trả về 404 nếu owner không tồn tại hoặc không có `status = active`.

---

### `GET /inventory/venue-types`

Danh sách venue types có màn hình, cấu trúc phân cấp (depth 0→1→2). Cache 30 phút.

**Query params:** Không có

**Response 200:**
```json
{
  "data": [
    {
      "type": "outdoor",
      "label": "Outdoor",
      "count": 800,
      "children": [
        {
          "type": "outdoor.billboards",
          "label": "Outdoor : Billboards",
          "count": 600,
          "children": [
            {
              "type": "outdoor.billboards.roadside",
              "label": "Outdoor : Billboards : Roadside",
              "count": 400,
              "children": []
            }
          ]
        }
      ]
    }
  ]
}
```

> `type` = `string_value` từ bảng `venue_types` (OpenOOH taxonomy). `count` đã cộng dồn từ tất cả con cháu. Nếu `string_value` không có trong taxonomy, trả về flat với `children: []`.

---

### `GET /inventory/screens`

Danh sách màn hình có phân trang. Dùng cho chế độ List trên Browse page.

**Query params:**

| Param | Type | Default | Ghi chú |
|---|---|---|---|
| `page` | integer | 1 | |
| `limit` | integer | 50 | Max: 100 |
| `status` | string | — | `active` \| `inactive` |
| `city` | string \| string[] | — | `hanoi` \| `hcm` \| `danang` \| `haiphong` \| `cantho` — hỗ trợ `city[]=hanoi&city[]=hcm` và `city=hanoi\|hcm` (legacy) |
| `venue_type` | string \| string[] | — | `mall` \| `outdoor` \| `fnb` \| `transit` \| `office` — hỗ trợ multi-value |
| `screen_type` | string \| string[] | — | `lcd` \| `led` \| `billboard` — derived field, hỗ trợ multi-value |
| `orientation` | string \| string[] | — | `landscape` \| `portrait` \| `square` — hỗ trợ multi-value |
| `q` | string | — | Text search tự do trên tên màn hình và địa chỉ. Max 100 ký tự |
| `sort` | string | — | `price_asc` \| `price_desc` \| `newest` |
| `updated_after` | string (ISO8601) | — | Lọc theo `updated_at >` (dùng cho webhook sync) |

**Ví dụ:**
```
GET /inventory/screens?status=active&city[]=hanoi&city[]=danang&venue_type[]=mall&sort=price_asc&page=1&limit=24
GET /inventory/screens?screen_type[]=lcd&screen_type[]=led&orientation=landscape&q=Vincom
```

**Response 200:**
```json
{
  "total": 3644,
  "page": 1,
  "limit": 24,
  "data": [
    {
      "screen_id": "SCR-001",
      "name": "Billboard Cầu Giấy",
      "owner_id": "OWN-abc123",
      "owner_name": "Media Owner A",
      "screen_type": "billboard",
      "venue_type": "outdoor",
      "location": {
        "address": "Xuân Thủy, Cầu Giấy",
        "city": "Hà Nội",
        "district": "Cầu Giấy",
        "lat": 21.038,
        "lng": 105.7992
      },
      "specs": {
        "width_px": 3840,
        "height_px": 1080,
        "width_m": 14.0,
        "height_m": 6.0,
        "orientation": "landscape",
        "resolution": null
      },
      "slot_duration_sec": 15,
      "slots_per_loop": 8,
      "operating_hours": { "open": "08:00", "close": "22:00", "days": ["mon","tue","wed","thu","fri","sat","sun"] },
      "price_per_slot_vnd": 500000,
      "min_booking_days": 7,
      "photos": ["https://..."],
      "status": "active",
      "updated_at": "2026-03-24T10:00:00+07:00"
    }
  ]
}
```

---

### `GET /inventory/screens/map`

Danh sách tất cả màn hình (không phân trang) với payload tối thiểu. Dùng cho chế độ Map — render markers.

> ⚠️ Route này phải đứng **trước** `GET /inventory/screens/{screen_id}` trong router.

**Query params:** Giống `GET /inventory/screens` nhưng **không có** `page`, `limit`, `sort`.

**Ví dụ:**
```
GET /inventory/screens/map?status=active
GET /inventory/screens/map?city[]=hanoi&venue_type[]=mall&venue_type[]=outdoor
```

**Response 200:**
```json
{
  "total": 3644,
  "data": [
    {
      "screen_id": "SCR-001",
      "name": "Billboard Cầu Giấy",
      "venue_type": "outdoor",
      "screen_type": "billboard",
      "size": "14×6m",
      "location": {
        "address": "Xuân Thủy, Cầu Giấy",
        "lat": 21.038,
        "lng": 105.7992
      }
    }
  ]
}
```

**So sánh kích thước:** ~400 KB vs ~4.5 MB (paginated ×36 pages).

---

### `GET /inventory/screens/{screen_id}`

Chi tiết một màn hình theo `external_id` hoặc `uuid`.

**Path params:**

| Param | Ghi chú |
|---|---|
| `screen_id` | external_id hoặc uuid của màn hình |

**Response 200:** Cùng schema với 1 item trong `GET /inventory/screens`.

**Response 404:**
```json
{ "error": "screen_not_found", "message": "Screen SCR-001 does not exist" }
```

---

## 3. Webhook

**Auth:** Bearer token, scope `inventory`

### `POST /inventory/webhook/register`

Đăng ký webhook nhận sự kiện thay đổi inventory.

**Body (JSON):**

| Field | Type | Required | Ghi chú |
|---|---|---|---|
| `url` | string (URL) | ✅ | Max 500 ký tự |
| `events` | string[] | ✅ | Min 1 item. Xem bảng events bên dưới |
| `secret` | string | ✅ | Min 16 ký tự, dùng để verify signature |

**Events hợp lệ:** `screen.created` \| `screen.updated` \| `screen.deactivated`

**Response 201:**
```json
{ "webhook_id": "WH-AB12CD", "status": "active" }
```

---

## 4. Player

**Auth:** Không cần Sanctum token (screen dùng UUID tự xác thực)

### `POST /player/heartbeat`

Screen báo hiệu đang hoạt động, cập nhật `last_heartbeat_at`.

**Body (JSON):**

| Field | Type | Required | Ghi chú |
|---|---|---|---|
| `screen_uuid` | uuid | ✅ | UUID của màn hình |
| `player_version` | string | — | Phiên bản player |

**Response 200:**
```json
{
  "status": "ok",
  "server_time": "2026-03-24T10:00:00+07:00",
  "operating": true,
  "playlist_version": 1711245600
}
```

**Response 404:**
```json
{ "message": "Screen not found" }
```

---

### `POST /player/impression`

Ghi log lần hiển thị quảng cáo.

**Body (JSON):**

| Field | Type | Required | Ghi chú |
|---|---|---|---|
| `screen_uuid` | uuid | ✅ | |
| `campaign_id` | integer | — | |
| `creative_id` | integer | — | |
| `duration_sec` | integer | ✅ | Min: 1 |
| `played_at` | date | — | ISO8601. Mặc định: now |
| `deal_type` | string | — | `direct` \| `rtb` \| `pmp` |
| `proof_url` | string (URL) | — | URL proof of play |

**Response 201:**
```json
{ "id": 1234, "imp_count": 1, "multiplier": 1.2 }
```

---

## 5. Owners

**Auth:** Bearer token (Sanctum), role `super_admin`

### `GET /owners`

Danh sách tất cả owners (phân trang, Spatie QueryBuilder).

**Query params:**

| Param | Ghi chú |
|---|---|
| `filter[status]` | `pending` \| `active` \| `suspended` |
| `filter[type]` | `retailer` \| `media_owner` \| `self` |
| `filter[onboard_method]` | `cms` \| `api` \| `vast` \| `hardware` |
| `sort` | `name` \| `created_at` \| `-name` (prefix `-` = DESC) |
| `page` | mặc định 20/page |

**Response 200:** Paginated collection với `sites_count`, `screens_count`.

---

### `POST /owners`

Tạo owner mới.

**Body (JSON):**

| Field | Type | Required | Ghi chú |
|---|---|---|---|
| `name` | string | ✅ | Max 255 |
| `slug` | string | ✅ | Unique |
| `type` | string | ✅ | `retailer` \| `media_owner` \| `self` |
| `onboard_method` | string | ✅ | `cms` \| `api` \| `vast` \| `hardware` |
| `revenue_share_pct` | numeric | — | 0–100 |
| `billing_info` | object | — | |
| `notes` | string | — | |

**Response 201:** Owner object.

---

### `GET /owners/{owner}`

Chi tiết owner. Load: `sites`, `networks`. Append: `total_screens`, `programmatic_screens`.

---

### `PUT /owners/{owner}`

Cập nhật owner (partial).

**Body (JSON, all optional):** `name`, `status`, `revenue_share_pct`, `onboard_method`, `billing_info`.

**Response 200:** Owner object.

---

### `DELETE /owners/{owner}`

Xoá owner. **Response 204.**

---

### `POST /owners/{owner}/switch`

Chuyển context sang owner khác (dùng trong CMS multi-tenant).

**Response 200:**
```json
{ "message": "Switched", "owner": { ... } }
```
**Response 403:** `{ "message": "Access denied" }`

---

### `GET /owners/{owner}/stats`

Thống kê 30 ngày của owner.

**Response 200:**
```json
{
  "total_screens": 120,
  "active_screens": 100,
  "online_screens": 85,
  "programmatic_screens": 60,
  "total_sites": 40,
  "revenue_last_30d": "12500000.00",
  "impressions_last_30d": 980000
}
```

---

## 6. Sites

**Auth:** Bearer token (Sanctum), scoped theo owner hiện tại.

### `GET /sites`

Danh sách sites (Spatie QueryBuilder).

**Query params:** `filter[status]`, `filter[city]`, `filter[region]`, `filter[country]`, `sort` (`name` \| `created_at` \| `city`).

**Response 200:** Paginated, include `screens_count`.

---

### `POST /sites`

Tạo site mới.

**Body (JSON):**

| Field | Type | Required | Ghi chú |
|---|---|---|---|
| `owner_id` | ulid | ✅ | |
| `external_id` | string | ✅ | Max 75, unique per owner |
| `name` | string | ✅ | Max 255 |
| `description` | string | — | |
| `lat` | numeric | — | -90 đến 90 |
| `lon` | numeric | — | -180 đến 180 |
| `address` | string | — | |
| `city` | string | — | Tên tỉnh/thành (vd: "Hà Nội") |
| `region` | string | — | |
| `country` | string | — | 2 ký tự, vd: `VN` |

**Response 201:** Site object.

---

### `GET /sites/{site}`

Chi tiết site. Load: `screens.spec`, `screens.inventory`. Append: `screen_count`.

---

### `PUT /sites/{site}`

Cập nhật site (partial). Fields: `name`, `description`, `lat`, `lon`, `address`, `city`, `region`, `status` (`active` \| `paused` \| `closed`).

**Response 200:** Site object.

---

### `DELETE /sites/{site}`

Xoá site. **Response 204.**

---

## 7. Screens

**Auth:** Bearer token (Sanctum), scoped theo owner hiện tại.

### `GET /screens`

Danh sách screens (Spatie QueryBuilder).

**Query params:**

| Param | Ghi chú |
|---|---|
| `filter[active]` | boolean |
| `filter[status]` | `online` \| `offline` \| `maintenance` |
| `filter[player_type]` | `adtrue_android` \| `adtrue_webview` \| `third_party` \| `vast_only` |
| `filter[site_id]` | ULID |
| `filter[owner_id]` | ULID |
| `filter[venue_type]` | string |
| `filter[programmatic]` | boolean |
| `sort` | `name` \| `created_at` \| `last_heartbeat_at` |
| `include` | `spec` \| `inventory` \| `site` \| `multipliers` (comma-separated) |

---

### `POST /screens`

Tạo/đăng ký màn hình mới.

**Body (JSON):**

| Field | Type | Required | Ghi chú |
|---|---|---|---|
| `owner_id` | ulid | ✅ | |
| `site_external_id` | string | ✅ | |
| `external_id` | string | ✅ | Max 75 |
| `name` | string | ✅ | Max 199 |
| `description` | string | — | |
| `internal_notes` | string | — | |
| `player_type` | string | — | `adtrue_android` \| `adtrue_webview` \| `third_party` \| `vast_only` |
| `uuid` | uuid | — | Auto-generated nếu bỏ trống |
| `spec` | object | — | Thông số kỹ thuật màn hình |
| `spec.width_px` | integer | ✅ (nếu có spec) | |
| `spec.height_px` | integer | ✅ (nếu có spec) | |
| `inventory` | object | — | Cài đặt inventory (venue_type, floor_cpm, v.v.) |
| `multiplier_string` | string | — | Chuỗi multiplier format Hivestack |

**Response 201:** Screen object.

---

### `GET /screens/{screen}`

Chi tiết screen. Load: `spec`, `inventory`, `site`, `externalIds`. Append: `is_online`.

---

### `PUT /screens/{screen}`

Cập nhật screen (partial).

**Body (JSON, all optional):** `name`, `description`, `internal_notes`, `active`, `player_type`, `player_version`, `spec` (object), `inventory` (object).

**Response 200:** Screen object.

---

### `DELETE /screens/{screen}`

Xoá screen. **Response 204.**

---

### `GET /screens/{screen}/multipliers`

Lấy impression multipliers theo giờ/ngày.

**Response 200:** Array grouped by `day_of_week` (0=Mon … 6=Sun), mỗi day có 24 giờ.

---

### `PUT /screens/{screen}/multipliers`

Cập nhật impression multipliers.

**Body (JSON):** Một trong hai:
- `multiplier_string` (string) — format Hivestack comma-separated
- `multipliers` (array) — `[{day_of_week, hour_of_day, multiplier}]`

**Response 200:** `{ "message": "Multipliers updated" }`

---

### `POST /screens/{screen}/toggle-programmatic`

Bật/tắt programmatic cho màn hình.

**Response 200:** `{ "programmatic_enabled": true }`

**Response 422:** `{ "message": "No inventory settings" }` (nếu chưa có inventory)

---

## 8. Import

**Auth:** Bearer token (Sanctum), super_admin.

### `POST /owners/{owner}/import/hivestack`

Import hàng loạt màn hình từ file Excel Hivestack.

**Body:** `multipart/form-data`

| Field | Type | Required | Ghi chú |
|---|---|---|---|
| `file` | file | ✅ | Chỉ `.xlsx`, max 10 MB |

**Response 200:**
```json
{
  "message": "Import completed",
  "results": {
    "sites": 12,
    "screens": 48,
    "errors": []
  }
}
```

---

## Ghi chú chung

### City slug mapping (Inventory API)

Filter `city` của `GET /inventory/screens` và `/map` nhận slug tiếng Anh và tự động map sang tên tiếng Việt trong DB:

| Slug (API) | Giá trị trong DB (`sites.city`) |
|---|---|
| `hanoi` | `Hà Nội` |
| `hcm` | `Hồ Chí Minh` |
| `danang` | `Đà Nẵng` |
| `haiphong` | `Hải Phòng` |
| `cantho` | `Cần Thơ` |

### Multi-value filter format

```
city[]=hanoi&city[]=danang        ← PHP array (chuẩn, khuyên dùng)
city=hanoi|danang                 ← pipe-separated (legacy, vẫn hoạt động)
city=hanoi                        ← single value (vẫn hoạt động)
```

### Thứ tự khi lật trang (từ 20/10/2026)

Mọi danh sách phân trang nay sắp theo khoá sắp xếp đã công bố, **rồi theo
khoá chính của bảng** làm khoá phụ. Lý do: `LIMIT ... OFFSET ...` không cam
kết thứ tự khi khoá sắp xếp có hàng bằng nhau, nên cơ sở dữ liệu được tự do
trả thứ tự nào rẻ nhất cho từng lần chạy — và khi đó lật trang 1 → 2 vừa trả
lặp một số bản ghi vừa bỏ sót số khác.

Đo trên `/api/v2` ngày 19/10/2026 (cùng lớp lỗi, cùng dữ liệu): đọc hết ba
trang của 104 bản ghi được 104 dòng nhưng chỉ **75 bản ghi khác nhau**.

Những gì **không** đổi: các giá trị `sort` được nhận, và khoá sắp xếp chính
vẫn thắng. `sort=newest` vẫn trả mới nhất trước, `sort=price_asc` vẫn trả rẻ
nhất trước, `GET /inventory/owners` vẫn đưa owner `featured` lên đầu. Khoá
phụ chỉ phá thế bằng.

Một chỗ đối tác có thể thấy khác trước: `GET /owners`, `GET /sites` và
`GET /screens` **không có thứ tự nào** khi gọi mà không kèm `sort`. Nay chúng
đi theo khoá chính (ULID, nên ≈ cũ nhất trước). Thứ tự cũ ở ba endpoint đó
không phải một thứ tự khác — nó đổi giữa các lần chạy.
