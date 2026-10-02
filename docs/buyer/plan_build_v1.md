# OOHX Buyer System — Build Plan v1

## Architecture Overview

```
oohx.net (Frontpage — Blade + Alpine.js)
  ├── Public pages (browse, search, map)
  ├── Buyer auth (login, register)
  ├── Cart & Booking flow
  ├── /my/* — Buyer dashboard
  └── Payment

dash.oohx.net (Dashboard — Filament)
  ├── /admin — Platform admin
  │   └── Campaigns, Payments, Organizations management
  └── /publisher — Media owner
      └── Booking inbox (approve/reject)
```

### Design Principles

- Buyer flow lives entirely on **oohx.net** (frontpage) — NOT on Filament dashboard
- Blade + Alpine.js for all buyer UI (lightweight, fast, mobile-first)
- Controller thin, business logic in Service layer
- Multi-tenant: buyer scoped by `current_organization_id` on User model
- Seller approval flow via Filament Publisher panel

---

## Data Model

### New Tables

#### 1. `organizations` — Agency/Client/Brand entity

| Column | Type | Notes |
|--------|------|-------|
| id | ULID | PK |
| name | string | Company name |
| slug | string | Unique, URL-safe |
| type | enum | `agency`, `client`, `brand` |
| tax_id | string | nullable, MST |
| billing_address | text | nullable |
| billing_email | string | nullable |
| billing_phone | string(30) | nullable |
| logo_url | string | nullable |
| website | string | nullable |
| status | enum | `active`, `suspended` — default `active` |
| credit_limit | decimal(15,2) | nullable, for credit terms |
| payment_terms_days | unsigned int | default 30 |
| created_at, updated_at, deleted_at | timestamps | soft deletes |

#### 2. `organization_users` — Pivot: User <-> Organization

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | ULID FK | |
| user_id | bigint FK | |
| role | enum | `admin`, `planner`, `viewer` |
| created_at, updated_at | timestamps | |

Unique: `(organization_id, user_id)`

#### 3. `carts` — Screen selection plan

| Column | Type | Notes |
|--------|------|-------|
| id | ULID | PK |
| user_id | bigint FK | |
| organization_id | ULID FK | nullable (guest cart) |
| name | string | nullable, default "Untitled Plan" |
| status | enum | `active`, `converted`, `expired` |
| expires_at | timestamp | nullable |
| created_at, updated_at | timestamps | |

#### 4. `cart_items` — Screens in cart

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| cart_id | ULID FK | cascade delete |
| screen_id | ULID FK | |
| start_date | date | |
| end_date | date | |
| spot_length | unsigned int | seconds, default 15 |
| share_of_voice_pct | unsigned tinyint | 1-100, default 100 |
| estimated_impressions | unsigned int | computed |
| estimated_cost | decimal(15,2) | computed from CPM |
| notes | text | nullable |
| created_at, updated_at | timestamps | |

#### 5. `campaigns` — Campaign container

| Column | Type | Notes |
|--------|------|-------|
| id | ULID | PK |
| organization_id | ULID FK | |
| created_by | bigint FK (users) | |
| code | string | auto: `CPN-YYYYMM-XXXX`, unique |
| name | string | |
| brand_name | string | nullable |
| category | string | nullable (industry) |
| objectives | JSON | nullable, array of strings |
| start_date | date | |
| end_date | date | |
| total_budget | decimal(15,2) | nullable |
| currency | string(3) | default `VND` |
| total_screens | unsigned int | computed |
| total_impressions_estimated | unsigned int | computed |
| status | enum | see Status Flow below |
| submitted_at | timestamp | nullable |
| approved_at | timestamp | nullable |
| rejected_at | timestamp | nullable |
| rejection_reason | text | nullable |
| activated_at | timestamp | nullable |
| completed_at | timestamp | nullable |
| notes | text | nullable |
| created_at, updated_at, deleted_at | timestamps | |

**Campaign Status Flow:**

```
draft → pending_approval → approved → active → completed
                        ↘ rejected    ↗ paused ↗
                                    ↘ cancelled
```

#### 6. `booking_lines` — Per-screen booking within campaign

| Column | Type | Notes |
|--------|------|-------|
| id | ULID | PK |
| campaign_id | ULID FK | cascade delete |
| screen_id | ULID FK | |
| owner_id | ULID FK | denormalized for fast owner queries |
| start_date | date | |
| end_date | date | |
| spot_length | unsigned int | seconds |
| share_of_voice_pct | unsigned tinyint | |
| floor_cpm_at_booking | decimal(15,2) | snapshot price at booking time |
| negotiated_cpm | decimal(15,2) | nullable, if different from floor |
| estimated_impressions | unsigned int | |
| estimated_cost | decimal(15,2) | |
| actual_impressions | unsigned int | default 0, updated from ImpressionLog |
| actual_cost | decimal(15,2) | default 0 |
| status | enum | `pending`, `approved`, `rejected`, `active`, `paused`, `completed`, `cancelled` |
| approved_by | bigint FK (users) | nullable |
| approved_at | timestamp | nullable |
| rejected_reason | text | nullable |
| notes | text | nullable |
| created_at, updated_at | timestamps | |

Indexes: `(campaign_id, status)`, `(owner_id, status)`, `(screen_id, start_date, end_date)`

#### 7. `creatives` — Media assets

| Column | Type | Notes |
|--------|------|-------|
| id | ULID | PK |
| campaign_id | ULID FK | |
| organization_id | ULID FK | |
| name | string | |
| type | enum | `image`, `video`, `html5`, `vast_tag` |
| file_path | string | nullable (storage path) |
| file_size | unsigned int | bytes, nullable |
| width_px | unsigned int | nullable |
| height_px | unsigned int | nullable |
| duration_sec | unsigned int | nullable (video) |
| vast_tag_url | string | nullable |
| status | enum | `pending_review`, `approved`, `rejected` |
| reviewed_by | bigint FK | nullable |
| reviewed_at | timestamp | nullable |
| created_at, updated_at | timestamps | |

#### 8. `booking_line_creatives` — Pivot: booking_line <-> creative

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| booking_line_id | ULID FK | cascade |
| creative_id | ULID FK | cascade |
| weight | unsigned tinyint | 1-100, rotation weight |
| start_date | date | nullable (override) |
| end_date | date | nullable (override) |

#### 9. `payments` — Payment tracking

| Column | Type | Notes |
|--------|------|-------|
| id | ULID | PK |
| campaign_id | ULID FK | |
| organization_id | ULID FK | |
| amount | decimal(15,2) | |
| currency | string(3) | default `VND` |
| method | enum | `bank_transfer`, `vnpay`, `momo` |
| transaction_ref | string | nullable, internal ref |
| gateway_ref | string | nullable, payment gateway ref |
| status | enum | `pending`, `processing`, `completed`, `failed`, `refunded` |
| paid_at | timestamp | nullable |
| due_date | date | nullable |
| invoice_number | string | nullable |
| invoice_url | string | nullable |
| notes | text | nullable |
| metadata | JSON | nullable |
| created_at, updated_at | timestamps | |

#### 10. `campaign_activities` — Audit log

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| campaign_id | ULID FK | cascade |
| user_id | bigint FK | nullable (system actions) |
| action | string | `created`, `submitted`, `approved`, `rejected`, `activated`, `paused`, `completed`, `cancelled`, `payment_received`, `creative_uploaded` |
| description | string | human-readable |
| metadata | JSON | nullable, context data |
| created_at | timestamp | |

---

## Route Structure

### Frontpage — Buyer Routes

```php
// Public (no auth)
Route::get('/explore', ...);           // Browse screens
Route::get('/explore/{screen}', ...);  // Screen detail
Route::get('/map', ...);               // Map view

// Auth required — Buyer
Route::middleware('auth')->group(function () {

    // Cart
    Route::get('/cart', [CartController::class, 'index'])->name('buyer.cart');
    Route::post('/cart/add', [CartController::class, 'add'])->name('buyer.cart.add');
    Route::delete('/cart/{item}', [CartController::class, 'remove'])->name('buyer.cart.remove');
    Route::put('/cart/{item}', [CartController::class, 'update'])->name('buyer.cart.update');

    // Booking / Campaign creation
    Route::get('/booking/create', [BookingController::class, 'create'])->name('buyer.booking.create');
    Route::post('/booking/create', [BookingController::class, 'store'])->name('buyer.booking.store');
    Route::get('/booking/{campaign}/creative', [BookingController::class, 'creative'])->name('buyer.booking.creative');
    Route::post('/booking/{campaign}/creative', [BookingController::class, 'uploadCreative']);
    Route::get('/booking/{campaign}/review', [BookingController::class, 'review'])->name('buyer.booking.review');
    Route::post('/booking/{campaign}/submit', [BookingController::class, 'submit'])->name('buyer.booking.submit');
    Route::get('/booking/{campaign}/payment', [PaymentController::class, 'show'])->name('buyer.payment');
    Route::post('/booking/{campaign}/payment', [PaymentController::class, 'process']);

    // Buyer dashboard
    Route::prefix('my')->name('buyer.')->group(function () {
        Route::get('/', [BuyerDashboardController::class, 'index'])->name('dashboard');
        Route::get('/campaigns', [BuyerCampaignController::class, 'index'])->name('campaigns');
        Route::get('/campaigns/{campaign}', [BuyerCampaignController::class, 'show'])->name('campaigns.show');
        Route::get('/payments', [BuyerPaymentController::class, 'index'])->name('payments');
        Route::get('/creatives', [BuyerCreativeController::class, 'index'])->name('creatives');
        Route::get('/settings', [BuyerSettingsController::class, 'index'])->name('settings');
        Route::put('/settings', [BuyerSettingsController::class, 'update']);
    });
});

// Payment callbacks
Route::post('/payment/vnpay/callback', [VNPayController::class, 'callback'])->name('payment.vnpay.callback');
```

### Dashboard — Publisher Booking Inbox (Filament)

```
dash.oohx.net/publisher/booking-inbox     — List pending bookings
dash.oohx.net/publisher/booking-inbox/{id} — Review & approve/reject
```

### Dashboard — Admin Campaign Management (Filament)

```
dash.oohx.net/admin/campaigns       — All campaigns
dash.oohx.net/admin/organizations   — Manage buyer orgs
dash.oohx.net/admin/payments        — Payment overview
```

---

## Service Layer

### New Services

```
app/Services/
  ├── CartService.php
  │   ├── getOrCreateCart(User): Cart
  │   ├── addItem(Cart, Screen, dates, options): CartItem
  │   ├── removeItem(CartItem): void
  │   ├── updateItem(CartItem, data): CartItem
  │   ├── estimateCost(CartItem): decimal
  │   └── convertToBooking(Cart, campaignData): Campaign
  │
  ├── CampaignService.php
  │   ├── create(Organization, User, data): Campaign
  │   ├── submit(Campaign): Campaign  // draft → pending_approval
  │   ├── approve(Campaign, User): Campaign
  │   ├── approveLines(Campaign, lineIds, User): void
  │   ├── reject(Campaign, reason, User): Campaign
  │   ├── rejectLine(BookingLine, reason, User): void
  │   ├── activate(Campaign): Campaign
  │   ├── pause(Campaign): Campaign
  │   ├── complete(Campaign): Campaign
  │   ├── cancel(Campaign, reason): Campaign
  │   └── generateCode(): string  // CPN-YYYYMM-XXXX
  │
  ├── AvailabilityService.php
  │   ├── checkScreenAvailability(Screen, dates, sov): bool
  │   ├── getBookedSOV(Screen, dates): int  // total % booked
  │   ├── getRemainingSOV(Screen, dates): int
  │   └── validateBookingConflicts(Campaign): array
  │
  ├── PaymentService.php
  │   ├── createPayment(Campaign, method, amount): Payment
  │   ├── processVNPay(Payment): redirect_url
  │   ├── handleVNPayCallback(data): Payment
  │   ├── confirmBankTransfer(Payment, ref): Payment
  │   └── generateInvoice(Campaign): string  // PDF path
  │
  └── CreativeService.php
      ├── upload(Campaign, file, metadata): Creative
      ├── validateDimensions(Creative, Screen): bool
      ├── assignToLine(BookingLine, Creative, weight): void
      └── review(Creative, User, status): Creative
```

---

## New Models

```
app/Models/
  ├── Organization.php
  ├── OrganizationUser.php
  ├── Cart.php
  ├── CartItem.php
  ├── Campaign.php
  ├── BookingLine.php
  ├── Creative.php
  ├── BookingLineCreative.php  (pivot)
  ├── Payment.php
  └── CampaignActivity.php
```

### Key Relationships

```php
// Organization
hasMany: campaigns, carts, creatives, payments
belongsToMany: users (via organization_users)

// Campaign
belongsTo: organization, createdBy (User)
hasMany: bookingLines, creatives, payments, activities
// Computed: total_screens, total_cost, delivery_rate

// BookingLine
belongsTo: campaign, screen, owner
belongsToMany: creatives (via booking_line_creatives)

// Cart
belongsTo: user, organization
hasMany: items (CartItem)

// CartItem
belongsTo: cart, screen
```

---

## Buyer Auth Flow

### Registration

```
/auth/register → Choose: Agency | Brand | Client
  → Name, email, password
  → Organization name, type
  → Auto-create: User + Organization + OrganizationUser(role=admin)
  → Redirect: /my (buyer dashboard)
```

### Login

```
/auth/login → Email + password
  → If user has organizations → set current_organization_id → /my
  → If user has owners → set current_owner_id → dash.oohx.net/publisher
  → If super_admin → dash.oohx.net/admin
```

### User Model Changes

```php
// Migration: add current_organization_id to users
$table->ulid('current_organization_id')->nullable();
$table->foreign('current_organization_id')->references('id')->on('organizations')->nullOnDelete();
```

---

## Phase Breakdown

### Phase 1: Data Foundation
- [x] Plan document
- [ ] Migration: organizations
- [ ] Migration: organization_users
- [ ] Migration: add current_organization_id to users
- [ ] Migration: carts + cart_items
- [ ] Migration: campaigns
- [ ] Migration: booking_lines
- [ ] Migration: creatives
- [ ] Migration: booking_line_creatives
- [ ] Migration: payments
- [ ] Migration: campaign_activities
- [ ] Models with relationships + fillable + casts
- [ ] Run migrations

### Phase 2: Auth & Organization
- [ ] Buyer registration page (Blade)
- [ ] Login page updates (redirect logic)
- [ ] Middleware: EnsureBuyerAuth
- [ ] Organization setup flow
- [ ] OrganizationUser roles & permissions

### Phase 3: Cart & Screen Selection
- [ ] "Thêm vào plan" button on screen cards
- [ ] Cart page /cart (Blade)
- [ ] CartController + CartService
- [ ] Date picker, SOV slider, cost estimate
- [ ] Cart badge in header
- [ ] localStorage sync for guest → convert on login

### Phase 4: Campaign Wizard
- [ ] Step 1: Campaign info form
- [ ] Step 2: Screen selection (import from cart)
- [ ] Step 3: Creative upload
- [ ] Step 4: Review & submit
- [ ] CampaignService
- [ ] AvailabilityService (SOV conflict check)
- [ ] Email notification to owner on submit

### Phase 5: Approval Workflow
- [ ] Publisher Filament: BookingInboxResource
- [ ] Per-line approve/reject
- [ ] Auto-reminder (48h SLA)
- [ ] Email notification to buyer on approve/reject
- [ ] Campaign status transitions

### Phase 6: Payment
- [ ] Bank transfer flow (invoice + manual confirm)
- [ ] VNPay integration
- [ ] Payment page /booking/{id}/payment
- [ ] PaymentService
- [ ] Auto-activate campaign on payment confirmed

### Phase 7: On Air & Reporting
- [ ] Sync booking_lines to ad server / player
- [ ] ImpressionLog → actual_impressions update
- [ ] /my/campaigns/{id} — report page (Chart.js)
- [ ] Proof of play gallery
- [ ] Campaign completion auto-detection

---

## File Structure (New Files)

```
app/
  Http/Controllers/
    Buyer/
      CartController.php
      BookingController.php
      PaymentController.php
      BuyerDashboardController.php
      BuyerCampaignController.php
      BuyerPaymentController.php
      BuyerCreativeController.php
      BuyerSettingsController.php
    Payment/
      VNPayController.php
  Services/
    CartService.php
    CampaignService.php
    AvailabilityService.php
    PaymentService.php
    CreativeService.php
  Models/
    Organization.php
    OrganizationUser.php
    Cart.php
    CartItem.php
    Campaign.php
    BookingLine.php
    Creative.php
    BookingLineCreative.php
    Payment.php
    CampaignActivity.php
  Policies/
    CampaignPolicy.php
    CartPolicy.php
    OrganizationPolicy.php

database/migrations/
    2026_04_12_000001_create_organizations_table.php
    2026_04_12_000002_create_organization_users_table.php
    2026_04_12_000003_add_current_organization_id_to_users.php
    2026_04_12_000004_create_carts_table.php
    2026_04_12_000005_create_cart_items_table.php
    2026_04_12_000006_create_campaigns_table.php
    2026_04_12_000007_create_booking_lines_table.php
    2026_04_12_000008_create_creatives_table.php
    2026_04_12_000009_create_booking_line_creatives_table.php
    2026_04_12_000010_create_payments_table.php
    2026_04_12_000011_create_campaign_activities_table.php

resources/views/
    buyer/
      cart.blade.php
      booking/
        create.blade.php
        creative.blade.php
        review.blade.php
        payment.blade.php
      dashboard/
        index.blade.php
        campaigns.blade.php
        campaign-detail.blade.php
        payments.blade.php
        creatives.blade.php
        settings.blade.php
    auth/
      login.blade.php
      register.blade.php

app/Filament/Publisher/Resources/
    BookingInboxResource.php

app/Filament/Resources/
    CampaignResource.php
    OrganizationResource.php
```

---

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Concurrent booking (2 buyers book same screen) | SOV > 100% | AvailabilityService lock check before confirm |
| Payment failure mid-flow | Campaign stuck | Grace period, retry flow, manual admin override |
| Large creative files | Server storage | Size limits (50MB video, 5MB image), S3 later |
| Approval SLA breach | Buyer frustration | Auto-reminder 24h, escalation 48h, admin override 72h |
| Multi-org user (agency managing multiple brands) | Context confusion | Organization switcher (similar to owner switcher) |

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| v1 | 2026-04-12 | Initial plan — frontpage-first buyer architecture |
