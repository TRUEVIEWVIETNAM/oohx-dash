# OOHX.net — Architecture Audit Report

**Date:** 2026-04-08
**Project:** OOHX.net — DOOH Marketplace Platform
**Status:** ~60% built
**Stack:** Laravel 12 + Filament 3.3 + Sanctum + Spatie Permission

---

## 1. Current Module Map

| Module | Status | Description |
|--------|--------|-------------|
| **Owner Management** | Complete | CRUD owners, team members, roles (6 roles), revenue share |
| **Site Management** | Complete | CRUD sites, geocoding, province/commune linking, Excel import |
| **Screen Management** | Complete | CRUD screens, specs, inventory, multipliers, 40+ form fields |
| **Network Management** | Complete | Group screens by network, floor CPM, programmatic toggles |
| **Inventory API (Public)** | Complete | Public read-only API for inventory, stats, filtering, search |
| **OAuth2 / API Auth** | Complete | Client credentials flow, scoped tokens via Sanctum |
| **Webhook System** | Complete | Subscribe to screen events, HMAC-signed payloads, retry logic |
| **Player Integration** | 80% | Heartbeat + impression logging. Missing ownership verification |
| **Import System** | Complete | Hivestack bulk xlsx + AdTRUE format, 4-step wizard |
| **Vietnam Geo Data** | Complete | Regions -> provinces -> communes, geocoding service |
| **Venue Types (OpenOOH)** | Complete | Hierarchical taxonomy, reseed from OpenOOH standard |
| **Frontpage (oohx.net)** | New/Blade only | 8 static pages, no API integration yet |
| **Campaign Management** | Not started | No campaign model/CRUD/booking flow |
| **Booking System** | Not started | No booking model, only static HTML |
| **Reporting/Analytics** | Not started | ImpressionLog exists but no dashboards/reports |
| **Billing/Payment** | Not started | Revenue share configured but no invoicing |

---

## 2. Architecture Assessment

### Strengths

- **Excellent base class pattern** — `BaseScreenResource`, `BaseSiteResource`, `BaseNetworkResource` shared between Admin & Publisher panels. Minimal duplication.
- **Proper multi-tenant scoping** — `HasOwnerScope` trait on Network/Site/Screen. Publisher panel filters by `current_owner_id`.
- **Service layer exists** — `ScreenRegistryService`, `SiteImportService`, `GeocodingService`, `TenantPermission`.
- **Clean separation Admin vs Publisher** — Different permissions, field visibility (pricing), query scoping.
- **Robust file handling** — Import wizard handles multiple disk configurations and Livewire temp dirs.
- **Good caching** — `ScreenStatsWidget` and `SiteStatsWidget` properly cached (60s). Filter options cached per owner (300s).

### Issues

| Problem | Severity | Location |
|---------|----------|----------|
| Business logic in Filament resources | Medium | `UserResource` (impersonation, password reset), `OwnerUsersRelationManager` (user creation/invitation) |
| Business logic in models | Medium | `Screen.getCurrentMultiplier()`, `Screen.isOperating()`, `Owner.getTotalScreensAttribute()` — complex query logic |
| No authorization in API controllers | **Critical** | `ScreenController`, `SiteController`, `ImportController` — any authenticated user can CRUD anything |
| Missing FormRequest classes | Medium | All API controllers use inline `$request->validate()` |
| Raw SQL in controllers | Low | `InventoryController`, `OwnerController.stats()` use `DB::table()` directly |
| `ScreenRegistryService` too many responsibilities | Low | Registration + import + heartbeat + impressions in one service |

---

## 3. Risk Assessment

### Critical Risks

1. **API authorization gap** — Authenticated API users can modify any owner's screens/sites. `HasOwnerScope` only works for web auth, not API tokens.
2. **Impression fraud** — `PlayerController.impression()` accepts any `screen_uuid` without verifying caller owns it. Can inject fake impressions.
3. **No rate limiting on `/auth/token`** — Brute force vulnerable.

### Fragile Areas

4. **Network->Screen relationship via string FK** (`network_code`) — Unusual, fragile, code is nullable.
5. **Screen.owner_id FK missing cascadeOnDelete** — Owner deletion orphans screens.
6. **Config cache invalidation** — Domain routing depends on `config('domains.*')`, production needs explicit cache management.

### Regression Hotspots

7. **BaseScreenResource (628 lines)** — Any change risks both Admin & Publisher panels.
8. **Import wizard (425 lines)** — Complex state machine, multiple file resolution strategies.
9. **HasOwnerScope trait** — Changes affect all tenant-scoped queries globally.

---

## 4. Data Model Assessment

### Entity Relationship Summary

```
Owner (ULID) --+-- Sites (ULID) --- Screens (ULID) --+-- ScreenSpec
               |                                       +-- ScreenInventory --- Network
               +-- Networks                            +-- ScreenExternalId
               +-- OwnerUsers --- User                 +-- ScreenImpressionMultiplier
               +-- ImpressionLogs                      +-- ImpressionLogs

VietnamRegion -> VietnamProvince -> VietnamCommune -> Site
VenueType (self-referencing) -> ScreenInventory.venue_type (string, no FK!)
ApiClient -> WebhookSubscription
ApiClient -> PersonalAccessToken (Sanctum)
```

### Models Inventory (17 total)

#### Owner (`app/Models/Owner.php`)
- **ID:** ULID
- **Uses:** HasFactory, HasUlids, SoftDeletes
- **Fillable:** name, slug, type, onboard_method, revenue_share_pct, status, billing_info, notes, tagline, about, logo_url, cover_url, website, email, phone, founded, featured, headquarters_lat, headquarters_lng
- **Casts:** billing_info (array), revenue_share_pct (decimal:2), featured (boolean), founded (integer), headquarters_lat/lng (decimal:7)
- **Relationships:** sites, networks, screens, users (via OwnerUser), impressionLogs
- **Scopes:** active()
- **Issues:** `getTotalScreensAttribute()` and `getProgrammaticScreensAttribute()` contain query logic (should be in service)

#### User (`app/Models/User.php`)
- **Uses:** HasFactory, Notifiable, HasApiTokens, HasRoles (Spatie)
- **Fillable:** name, email, password, current_owner_id
- **Relationships:** ownerUsers, owners (BelongsToMany via pivot), currentOwner
- **Methods:** switchOwner(), getRoleInOwner(), canAccessPanel()
- **No Soft Deletes**

#### OwnerUser (`app/Models/OwnerUser.php`)
- **Table:** owner_users (pivot)
- **Roles:** owner, manager, operator, read_only, reporting_only, sales_manager
- **Permissions:** Maps actions to allowed roles (6 permission types)
- **Methods:** can(), canAccessNetwork(), isOwner()

#### Network (`app/Models/Network.php`)
- **Uses:** HasFactory, HasOwnerScope, SoftDeletes
- **Fillable:** owner_id, code, name, description, default_floor_cpm, default_floor_cpm_currency, status
- **Relationships:** owner, screens (via network_code string FK), inventoryScreens (HasManyThrough)
- **Issue:** Screen relationship uses string FK (`network_code`) which is unusual and fragile

#### Site (`app/Models/Site.php`)
- **ID:** ULID
- **Uses:** HasFactory, HasUlids, HasOwnerScope, SoftDeletes
- **Fillable:** owner_id, external_id, hivestack_site_id, name, description, lat, lon, address, city, region, country, status, province_id, commune_id
- **Relationships:** owner, province, commune, screens
- **Issue:** `getScreenCountAttribute()` contains query logic

#### Screen (`app/Models/Screen.php`)
- **ID:** ULID
- **Uses:** HasFactory, HasUlids, HasOwnerScope, SoftDeletes
- **Fillable:** site_id, owner_id, external_id, uuid, unit_id, name, description, internal_notes, site_external_id, network_code, location_district, location_district_code, active, status, player_type, player_version, last_heartbeat_at, device_token
- **Relationships:** owner, site, network (via network_code), spec, inventory, multipliers, externalIds, impressionLogs
- **Scopes:** active(), online(), programmatic(), byVenueType()
- **Observer:** ScreenObserver (fires webhooks)
- **Issues:** `getCurrentMultiplier()`, `isOperating()`, `getIsOnlineAttribute()` contain complex business logic

#### ScreenSpec (`app/Models/ScreenSpec.php`)
- One-to-one with Screen. Resolution, physical size, facing direction, allowed formats.
- Computed: orientation, aspectRatio

#### ScreenInventory (`app/Models/ScreenInventory.php`)
- One-to-one with Screen. Network, venue type, spot/loop lengths, impressions, CPM, programmatic settings.
- Methods: computeFloorCpmUsd(), getDailyImpressionsAttribute()

#### ScreenExternalId (`app/Models/ScreenExternalId.php`)
- Platforms: hivestack, vistar, broadsign, oohmedia, place_exchange, viooh
- Tracks sync status per platform

#### ScreenImpressionMultiplier (`app/Models/ScreenImpressionMultiplier.php`)
- Day/hour grid (168 slots = 7 days x 24 hours)
- Static method: parseHivestackString()

#### ImpressionLog (`app/Models/ImpressionLog.php`)
- **Table:** impression_logs (partitioned by played_at RANGE, quarterly)
- Timestamps disabled, uses created_at only
- Tracks: screen_id, owner_id, campaign_id, creative_id, played_at, duration_sec, multiplier, impressions, revenue
- **No Soft Deletes** (correct for time-series data)

#### VenueType (`app/Models/VenueType.php`)
- Self-referencing hierarchy (parent_id)
- OpenOOH taxonomy standard
- Methods: groupedOptions(), flatOptions()

#### VietnamProvince, VietnamCommune, VietnamRegion
- Geographic hierarchy for Vietnam
- Linked to Site for location data

#### ApiClient (`app/Models/ApiClient.php`)
- Uses Sanctum for API token management
- Scoped access (inventory, booking, reporting)

#### WebhookSubscription (`app/Models/WebhookSubscription.php`)
- Events: screen.created, screen.updated, screen.deactivated
- Linked to ApiClient

### Relationship Problems

| Issue | Impact |
|-------|--------|
| `Screen.owner_id` FK **missing cascadeOnDelete** | Owner deletion orphans screens |
| `ScreenInventory.venue_type` is **string, no FK** to `venue_types` | Data integrity risk |
| `ScreenInventory.network_name` **denormalized** | Stale data if network renamed |
| `Network.code` is **nullable unique string** used as FK in Screen | Fragile join, NULL issues |
| `ImpressionLog` has **no FK to campaigns** (doesn't exist yet) | Future migration needed |

### Missing Indexes

- `impression_logs`: missing `(owner_id, screen_id, played_at)` composite for owner-screen reports
- `screen_inventory`: missing `(network_id, programmatic_enabled)` composite
- `screens`: could benefit from `(owner_id, network_code)` composite

---

## 5. Security & Permission Assessment

| Finding | Severity | Fix |
|---------|----------|-----|
| **No authorization in API mutating endpoints** | CRITICAL | Add `$this->authorize()` or policy middleware to ScreenController, SiteController, OwnerController, ImportController |
| **Import endpoint: no owner verification** | CRITICAL | Verify user owns target owner before import |
| **Impression logging: no caller verification** | CRITICAL | Verify screen belongs to caller in PlayerController |
| **No rate limiting on `/auth/token`** | CRITICAL | Add `throttle:5,1` middleware |
| No Policy classes for Screen/Site/Network | HIGH | Create policies, enforce in controllers |
| Admin Resources lack authorization methods | HIGH | Add `canViewAny/canCreate` etc. |
| Webhook secret stored in plaintext | MEDIUM | Hash like passwords |
| File uploads: no content sanitization | MEDIUM | Validate xlsx contents before processing |
| Mass assignment: fillable broader than validated | LOW | Align fillable arrays with validation rules |

### Multi-Tenant Isolation

- **Mechanism:** `HasOwnerScope` trait (global scope on Network, Site, Screen)
- **Bypass:** super_admin role and ApiClient instances see all records
- **User context:** `User.current_owner_id` determines active owner
- **Role-based access:** OwnerUser pivot with 6 roles and permission matrix
- **Network-level restriction:** `allowed_network_ids` JSON array on OwnerUser

### Authorization Gaps

- API controllers have NO authorization checks (any authenticated user can CRUD anything)
- Policies exist for Owner and OwnerUser but are NOT enforced in API routes
- No policies exist for Screen, Site, or Network
- Admin Filament Resources have hardcoded `canViewAny = true` (acceptable for admin, but no policy backup)

---

## 6. Performance Assessment

| Issue | Severity | Location | Solution |
|-------|----------|----------|----------|
| `RegistryStatsWidget` — no caching, 5+ queries per load | HIGH | Admin dashboard | Add 60s cache per owner |
| `NetworkStatsWidget` — no caching, 4 queries | HIGH | Publisher dashboard | Add 60s cache per owner |
| `Owner.getTotalScreensAttribute()` — query in accessor | HIGH | Any page showing owner | Move to service or use withCount |
| `NetworkDetailStats` / `SiteDetailStats` — multiple separate count queries | MEDIUM | Detail pages | Single aggregate selectRaw + cache |
| `BaseNetworkResource` table — `screens_count` subquery with whereHas | MEDIUM | Network list | Use withCount or subquery |
| `InventoryController` — complex raw SQL joins | MEDIUM | Public API | Already has 1800s cache, acceptable |
| `ScreensRelationManager` — filter queries with whereHas + distinct | LOW | Network detail | Optimize filter queries |

### Well-Optimized Areas
- `ScreenStatsWidget` — properly cached (60s) with single aggregate query
- `SiteStatsWidget` — properly cached (60s)
- `InventoryController` — 1800s cache on expensive stats operations
- Publisher filter options — cached per owner (300s)
- `impression_logs` — partitioned by played_at (quarterly) for time-series performance

---

## 7. Filament Layer Assessment

### Resources (Admin Panel)

| Resource | Model | Form Fields | Table Columns | Complete? |
|----------|-------|-------------|---------------|-----------|
| OwnerResource | Owner | 11 | 7 | Yes |
| UserResource | User | 14 | 6 | Yes |
| NetworkResource | Network | 5 (base) + owner | 6 + owner | Yes |
| ScreenResource | Screen | 40+ (base) | 30+ + owner | Yes |
| SiteResource | Site | 8 (base) + owner | 12 + owner | Yes |
| VenueTypeResource | VenueType | 9 | 7 | Yes |
| VietnamProvinceResource | VietnamProvince | 5 | 6 | Yes |
| VietnamRegionResource | VietnamRegion | 4 | 4 | Yes |
| VietnamCommuneResource | VietnamCommune | 5 | 5 | Yes |

### Resources (Publisher Panel)

| Resource | Model | Authorization | Scoping | Complete? |
|----------|-------|---------------|---------|-----------|
| NetworkResource | Network | TenantPermission | current_owner_id | Yes |
| ScreenResource | Screen | TenantPermission + canPricing | current_owner_id + eager loads | Yes |
| SiteResource | Site | TenantPermission | current_owner_id | Yes |
| OwnerUserResource | OwnerUser | Custom (manage_users) | current owner | Yes |

### Code Reuse
- `BaseScreenResource` (628 lines) — Shared form (9 sections, 40+ fields) and table (30+ columns)
- `BaseSiteResource` (381 lines) — Shared form (3 sections) and table with geocoding
- `BaseNetworkResource` (163 lines) — Shared form and table with CPM display
- Duplication is minimal — only owner-specific fields differ between panels

### Business Logic in Resources (Should Extract)

1. `UserResource` — impersonation logic (auth()->login()), password reset (Password::broker())
2. `OwnerUsersRelationManager` — user creation (firstOrCreate), invitation (sendResetLink)
3. `VenueTypeResource` — Artisan::call('db:seed') for reseed action
4. `OwnerUserResource` — Complex canCreate() logic with DB queries

### Widgets Performance

| Widget | Queries | Cached? | Issue |
|--------|---------|---------|-------|
| ScreenStatsWidget | 2 | Yes (60s) | OK |
| SiteStatsWidget | 2 | Yes (60s) | OK |
| NetworkStatsWidget | 4 | No | Needs caching |
| RegistryStatsWidget | 5+ | No | Needs caching |
| NetworkDetailStats | 5 | No | Multiple separate counts, needs aggregate |
| SiteDetailStats | 4 | No | Multiple separate counts, needs aggregate |

---

## 8. API Layer Assessment

### Endpoints

**Public OAuth2:**
- `POST /api/v1/auth/token` — Client credentials flow

**Public Inventory (scope: inventory):**
- `GET /api/v1/inventory/stats` — Aggregate statistics
- `GET /api/v1/inventory/venue-types` — OpenOOH taxonomy
- `GET /api/v1/inventory/networks` — Available networks
- `GET /api/v1/inventory/locations` — Cities and regions
- `GET /api/v1/inventory/owners` — Media owner list
- `GET /api/v1/inventory/owners/{id}` — Owner detail
- `GET /api/v1/inventory/screens` — Screen listing with filters
- `GET /api/v1/inventory/screens/map` — Map view data
- `POST /api/v1/inventory/webhook/register` — Subscribe to events

**Player:**
- `POST /api/v1/player/heartbeat` — Device heartbeat
- `POST /api/v1/player/impression` — Log impression

**Authenticated CRUD:**
- `GET/POST /api/v1/owners` — Owner management (super_admin)
- `GET/PUT/DELETE /api/v1/owners/{id}` — Owner CRUD
- `GET/POST /api/v1/owners/{owner}/sites` — Site management
- `GET/PUT/DELETE /api/v1/sites/{id}` — Site CRUD
- `GET/POST /api/v1/screens` — Screen management
- `GET/PUT/DELETE /api/v1/screens/{id}` — Screen CRUD
- `POST /api/v1/owners/{owner}/import/hivestack` — Bulk import

### Controllers Assessment

| Controller | Validation | Authorization | Service Usage | Issues |
|------------|-----------|---------------|---------------|--------|
| AuthController | FormRequest | N/A | Direct | No rate limiting |
| ScreenController | Inline | NONE | ScreenRegistryService | Missing authorization |
| SiteController | Inline | NONE | Direct | Missing authorization, inconsistent lat/lon validation |
| OwnerController | Inline | NONE | Direct | Raw SQL in stats(), missing authorization |
| InventoryController | Inline | Scope-based | Direct | Complex filtering in controller, magic numbers |
| PlayerController | Inline | UUID-based | ScreenRegistryService | No ownership verification |
| ImportController | Inline | NONE | ScreenRegistryService | No owner verification |
| WebhookController | Inline | ApiClient | Direct | No webhook list/delete endpoints |

### Response Format Inconsistencies

- `OwnerController.index()` uses Laravel default pagination format
- `InventoryController.owners()` uses custom `{ total, page, limit, data }` format
- Some endpoints return null body on success
- Error responses not standardized

---

## 9. Services Layer Assessment

### Existing Services

#### ScreenRegistryService
- **Responsibilities:** Screen registration, spec/inventory/multiplier management, bulk import (Hivestack & AdTRUE), heartbeat tracking, impression logging
- **Strength:** Consolidates complex screen management logic
- **Issue:** Too many responsibilities — could split into ScreenRegistrationService, ScreenImportService, HeartbeatService, ImpressionService
- **Issue:** Impression logging doesn't verify screen ownership or validate campaign data

#### SiteImportService
- **Responsibilities:** Excel import orchestration, column mapping, preview/dry-run, actual import
- **Strength:** Thorough column mapping for both formats, provides preview capability
- **Issue:** Doesn't validate owner ownership, no file cleanup after import

#### GeocodingService
- **Responsibilities:** Reverse geocoding via OpenStreetMap Nominatim
- **Strength:** Clean abstraction, proper error handling, flexible name matching
- **No issues found**

#### TenantPermission
- **Responsibilities:** Permission checking helper for Publisher panel
- **Strength:** Clean interface for checking OwnerUser permissions
- **Issue:** Only used in Publisher Filament panel, not in API controllers

### Missing Services (Recommended)

- `UserService` — impersonation, password reset (currently in Filament resources)
- `UserInvitationService` — team invite flow (currently in OwnerUsersRelationManager)
- `ScreenPolicy`, `SitePolicy`, `NetworkPolicy` — authorization (no policies for core models)
- `ReportingService` — aggregation on ImpressionLog (not started)
- `BookingService` — booking workflow (not started)

---

## 10. Middleware Assessment

### Existing Custom Middleware

- **CheckTokenAbility** — Verifies Sanctum token has required scopes/abilities

### Missing Middleware

- No rate limiting on auth endpoint
- No request logging for audit trail
- No ownership validation middleware to scope API requests by owner

---

## 11. Observer & Job Assessment

### ScreenObserver
- Fires webhooks on: screen.created, screen.updated, screen.deactivated
- Dispatches `SendWebhookJob` asynchronously
- Properly tracks dirty fields for update events

### SendWebhookJob
- Queued on `webhooks` queue
- 3 retries with backoff [30s, 5m, 30m]
- 15 second timeout
- HMAC-SHA256 signatures on payloads
- Auto-deactivates webhook after 3 failures

### Console Commands
- `FetchVietnamAdminDivisions` — Fetch admin divisions from external source
- `GeocodeSitesCommand` — Batch geocode sites
- `ImportVietnamAdminDivisions` — Import admin data from file

---

## 12. Refactor Priority Map

### CRITICAL — Must fix before building more

1. **Add API authorization** — Create `ScreenPolicy`, `SitePolicy`, `NetworkPolicy`. Add `$this->authorize()` to all API controller methods.
2. **Rate limit auth endpoint** — Add `throttle:5,1` to `POST /auth/token`.
3. **Verify player ownership** — `PlayerController.impression()` must validate screen belongs to caller.
4. **Fix Screen.owner_id FK** — Add `cascadeOnDelete` via migration.

### IMPORTANT — Should fix soon

5. **Add caching to admin widgets** — `RegistryStatsWidget`, `NetworkStatsWidget` need 60s cache.
6. **Extract business logic from resources** — Create `UserService` (impersonation), `UserInvitationService` (team invite).
7. **Create FormRequest classes** for API endpoints (ScreenStoreRequest, SiteStoreRequest, etc.).
8. **Move model query logic to services** — `Screen.getCurrentMultiplier()`, `Screen.isOperating()`, `Owner.getTotalScreensAttribute()`.
9. **Standardize API response format** — Consistent pagination and error structures.
10. **Create missing policies** — Screen, Site, Network policies for both Filament and API.

### SAFE TO POSTPONE

11. Split `ScreenRegistryService` into smaller services.
12. Convert `ScreenInventory.venue_type` to FK.
13. Add API documentation (OpenAPI/Swagger).
14. Add request logging middleware.
15. Refactor `Network->Screen` string FK to integer FK.
16. Standardize `ScreenInventory.network_name` denormalization.

---

## 13. Safe Continuation Plan

### Principle: Build forward, don't rewrite backward

```
Phase 0 (Now): Security hardening
  +-- Add API authorization (policies + middleware)
  +-- Rate limit auth endpoint
  +-- Fix player verification
  Impact: Only touches middleware/policies, zero risk to existing features

Phase 1: Campaign & Booking module (NEW)
  +-- Campaign model + migration
  +-- Booking model + migration
  +-- BookingResource (Filament, Publisher panel)
  +-- CampaignResource (Filament, Admin panel)
  +-- Booking API endpoints
  Impact: New code only, no changes to existing modules

Phase 2: Reporting module (NEW)
  +-- Build on existing ImpressionLog table
  +-- ReportingService for aggregation queries
  +-- Dashboard widgets for Publisher panel
  +-- Report export (CSV/PDF)
  Impact: Read-only on existing data, no schema changes

Phase 3: Frontpage API integration
  +-- Connect Blade views to existing Inventory API
  +-- Add server-side rendering via FrontpageController
  +-- Search/filter with Eloquent (SSR for SEO)
  Impact: Only touches FrontpageController, no existing module changes
```

### Rules for safe development

1. **Never modify `HasOwnerScope`** without testing all 3 panels (Admin, Publisher, API)
2. **Never modify `BaseScreenResource`** without testing both Admin & Publisher screen CRUD
3. **New features = new files** — Add new Resources/Services/Models, don't refactor existing ones
4. **Add tests before touching existing services** — Especially `ScreenRegistryService`
5. **Cache clear after every deploy** — `php artisan config:clear && route:clear && view:clear`

---

## 14. File Structure Reference

```
app/
  Filament/
    Pages/                        # Admin custom pages (Import)
    Resources/                    # Admin resources (Owner, User, Screen, Site, Network, VenueType, Vietnam*)
    Widgets/                      # Admin widgets (RegistryStatsWidget)
    Publisher/
      Pages/                      # Publisher custom pages (ImportSites)
      Resources/                  # Publisher resources (Network, Screen, Site, OwnerUser)
      Widgets/                    # Publisher widgets (NetworkStats, ScreenStats, SiteStats)
    Shared/
      Resources/                  # Base resources (BaseScreen, BaseSite, BaseNetwork)
      Pages/                      # Shared pages (BaseViewNetwork, BaseViewSite)
      Widgets/                    # Shared widgets (NetworkDetailStats, SiteDetailStats)
  Http/
    Controllers/
      Api/V1/                     # API controllers (Auth, Screen, Site, Owner, Inventory, Player, Import, Webhook)
      FrontpageController.php     # Public frontpage (8 routes)
    Middleware/
      CheckTokenAbility.php       # Sanctum scope verification
    Requests/
      AuthTokenRequest.php        # Only FormRequest class
  Models/                         # 17 models
  Observers/
    ScreenObserver.php            # Webhook dispatch on screen events
  Policies/
    OwnerPolicy.php               # Owner authorization (not enforced in API)
    OwnerUserPolicy.php           # OwnerUser authorization (not enforced in API)
  Providers/
    Filament/
      AdminPanelProvider.php      # Admin panel config (dash.oohx.net/admin)
      PublisherPanelProvider.php   # Publisher panel config (dash.oohx.net/publisher)
    AppServiceProvider.php        # Observer registration, login response binding
  Services/
    GeocodingService.php          # Nominatim reverse geocoding
    ScreenRegistryService.php     # Screen CRUD, import, heartbeat, impressions
    SiteImportService.php         # Excel import orchestration
    TenantPermission.php          # Publisher permission helper
  Traits/
    HasOwnerScope.php             # Global scope for multi-tenant isolation
  Jobs/
    SendWebhookJob.php            # Async webhook delivery with HMAC signing
config/
  domains.php                     # Domain routing config (frontpage, dash)
  tapon.php                       # Token/API config
  regions.php                     # Vietnam region mapping
routes/
  web.php                         # Domain-based routing (oohx.net + dash.oohx.net)
  api.php                         # API v1 routes
```

---

## 15. Summary Statistics

- **Models:** 17 total
- **Tables:** 25+ (including Spatie permission, Sanctum)
- **Filament Resources:** 9 admin + 4 publisher (13 total, 3 shared base)
- **Filament Widgets:** 6 (2 admin, 3 publisher, 2 shared)
- **API Controllers:** 8
- **Services:** 4
- **Policies:** 2 (Owner, OwnerUser) — missing Screen, Site, Network
- **FormRequests:** 1 (AuthTokenRequest) — missing for all CRUD endpoints
- **Observers:** 1 (ScreenObserver)
- **Jobs:** 1 (SendWebhookJob)
- **Console Commands:** 3
- **Core entities with soft deletes:** 4 (Owner, Network, Site, Screen)
- **Models using HasOwnerScope:** 3 (Network, Site, Screen)
- **Partitioned tables:** 1 (impression_logs by played_at)
