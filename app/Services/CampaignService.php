<?php

namespace App\Services;

use App\Models\BookingLine;
use App\Models\BookingLineBundle;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\User;
use App\Services\Booking\BundleExpander;
use App\Services\InventoryHoldService;
use App\Services\PurchaseEligibilityService;
use App\Notifications\BookingResolvedNotification;
use App\Notifications\BookingSubmittedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CampaignService
{
    /**
     * Danh sách chiến dịch của một người, trong **tổ chức đang chọn**.
     *
     * ══ Vì sao scope ở service, không ở controller ══
     *
     * CLAUDE.md mục 1: controller nhận request, gọi service, trả response.
     * Nhưng lý do thật nằm ở chỗ khác — phép scope này là một **phép phân
     * quyền**, và trang Blade cùng `/api/v2` phải dùng đúng một bản. Hai bản
     * là cách để một ngày nào đó danh sách trên trang và danh sách qua API
     * khác nhau, mà không bên nào biết bên nào đúng.
     *
     * ══ Kiểm tư cách, không tin `current_organization_id` ══
     *
     * Cột đó do client đổi được (có bộ chuyển tổ chức), và nó có thể trỏ vào
     * một tổ chức người này đã bị gỡ khỏi, hoặc một tổ chức đã bị tạm ngưng.
     * Nên không truy vấn theo nó trực tiếp: tìm **tư cách thành viên còn hiệu
     * lực** đúng ba điều kiện mà `CampaignPolicy::membership()` dùng — thành
     * viên của tổ chức đó, tổ chức còn `active`, và vai trò có quyền
     * `view_campaigns`.
     *
     * Không có tư cách thì trả về một trang **rỗng**, không phải mọi chiến
     * dịch của sàn. Mặc định phải là chặn, như `HasOwnerScope`.
     *
     * @param  array{status?: string|null, q?: string|null}  $loc
     */
    public function listForUser(User $user, array $loc = [], int $perPage = 20)
    {
        $membership = $this->tuCachXemChienDich($user);

        if (! $membership) {
            // `whereRaw('1 = 0')` thay vì trả `collect()`: người gọi cần một
            // paginator thật để `meta` vẫn đúng hình dạng, chứ không phải một
            // nhánh đặc biệt ở mọi bên tiêu thụ.
            return Campaign::whereRaw('1 = 0')->paginate($perPage);
        }

        $query = Campaign::where('organization_id', $membership->organization_id);

        if (! empty($loc['status'])) {
            $query->where('status', $loc['status']);
        }

        if (! empty($loc['q'])) {
            // Tìm theo tên hoặc mã. `LIKE` có ký tự đại diện ở hai đầu nên
            // không dùng được index — chấp nhận được vì phạm vi đã hẹp về một
            // tổ chức, và một tổ chức không có hàng triệu chiến dịch.
            //
            // Ngoặc quanh nhóm `orWhere` là bắt buộc: thiếu nó thì
            // `organization_id = X AND name LIKE … OR code LIKE …` đọc thành
            // `(… AND …) OR (code LIKE …)`, tức mã trùng ở TỔ CHỨC KHÁC cũng
            // ra — một rò rỉ theo đúng kiểu `InventoryHold` từng mắc.
            $tu = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $loc['q']) . '%';

            $query->where(function ($q) use ($tu) {
                $q->where('name', 'like', $tu)->orWhere('code', 'like', $tu);
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Tư cách xem chiến dịch trong **tổ chức đang chọn**, hoặc `null`.
     *
     * Tách ra vì danh sách và bảng đếm phải dùng **đúng một** phép phân quyền.
     * Hai bản của cùng một cổng là cách để một ngày nào đó trang đếm được 12
     * chiến dịch mà danh sách chỉ ra 0 — hoặc tệ hơn, ngược lại.
     */
    private function tuCachXemChienDich(User $user): ?OrganizationUser
    {
        $membership = OrganizationUser::where('user_id', $user->id)
            ->where('organization_id', $user->current_organization_id)
            ->whereHas('organization', fn ($q) => $q->where('status', 'active'))
            ->first();

        return $membership?->can('view_campaigns') ? $membership : null;
    }

    /**
     * Số chiến dịch theo từng trạng thái, cho trang đầu khu người mua.
     *
     * ══ Lỗ hổng việc này đóng ══
     *
     * `BuyerDashboardController` trước đây đếm bằng `$org->campaigns()` với
     * `$org = $user->currentOrganization` — một `belongsTo` thuần, **không**
     * kiểm tư cách thành viên. Ba tình huống đọc được dữ liệu của tổ chức khác:
     *
     *  1. Người bị **gỡ khỏi tổ chức** nhưng `current_organization_id` vẫn trỏ
     *     ở đó: vẫn đếm và vẫn thấy năm chiến dịch gần nhất kèm tên, mã, kỳ
     *     chạy. Đây là tình huống có thật — khu quản trị tổ chức xoá được thành
     *     viên, và không chỗ nào dọn cột đó.
     *  2. Tổ chức bị **tạm ngưng**: vẫn đọc.
     *  3. Thành viên có vai trò **không có quyền `view_campaigns`**: vẫn đọc.
     *
     * `listForUser()` đã đóng cả ba cho `/my/campaigns` từ PR #40. Trang đầu
     * thì chưa, nên cùng một người thấy số 0 ở danh sách và số 12 ở ô thống kê.
     *
     * ══ Một truy vấn, không tám ══
     *
     * Bản cũ chạy bốn `COUNT(*)` cho bốn ô. Trả đủ tám trạng thái bằng cách đó
     * là tám lượt đi về. `GROUP BY` cho cùng câu trả lời trong một lượt.
     *
     * Trả về **đủ mọi mã** trong `Campaign::STATUS_LABELS`, kể cả mã đếm được
     * 0: thiếu khoá thì mỗi bên tiêu thụ phải tự nhớ `?? 0`, và chỗ nào quên
     * thì hiện ra `undefined` thay vì số không.
     *
     * @return array{total: int, by_status: array<string, int>}
     */
    public function statusCountsForUser(User $user): array
    {
        $khong = array_fill_keys(array_keys(Campaign::STATUS_LABELS), 0);

        $membership = $this->tuCachXemChienDich($user);

        if (! $membership) {
            return ['total' => 0, 'by_status' => $khong];
        }

        $dem = Campaign::where('organization_id', $membership->organization_id)
            ->groupBy('status')
            ->selectRaw('status, count(*) as so')
            ->pluck('so', 'status');

        // Ghi lên bảng toàn-số-không chứ không lấy thẳng kết quả truy vấn: giữ
        // đúng thứ tự của `STATUS_LABELS` (nháp → chờ duyệt → … → đã hủy), và
        // một mã lạ trong CSDL không lọt ra ngoài thành một khoá không có chữ.
        $theoTrangThai = $khong;

        foreach ($theoTrangThai as $ma => $_) {
            $theoTrangThai[$ma] = (int) ($dem[$ma] ?? 0);
        }

        // Tổng từ **cùng** truy vấn, không phải một `count()` thứ hai: hai lượt
        // đọc cách nhau một nhịp có thể lệch nhau, và lúc đó tổng không bằng
        // tổng các phần — đúng con số người dùng cộng nhẩm được.
        //
        // Và tổng của **mọi** mã trong CSDL, không phải tổng của `by_status`:
        // một mã lạ phải được đếm vào tổng, vì nó là một chiến dịch thật. Nếu
        // hai số đó lệch nhau thì CSDL có mã không có chữ, và
        // `NhanTrangThaiMotNoiTest` đỏ ngay ở chỗ nói đúng nguyên nhân — tốt
        // hơn là lấy tổng của `by_status` rồi che mất chiến dịch đó đi.
        return [
            'total'     => (int) $dem->sum(),
            'by_status' => $theoTrangThai,
        ];
    }

    /**
     * Create campaign from cart items.
     */
    public function createFromCart(Organization $org, User $user, Cart $cart, array $data): Campaign
    {
        return DB::transaction(function () use ($org, $user, $cart, $data) {
            $items = $cart->items()->with(['screen.inventory', 'screen.owner'])->get();

            // Giá trong giỏ phải còn khớp giá hiện hành. Nếu media owner vừa đổi giá,
            // dừng lại để người mua xem con số mới rồi tự quyết — không im lặng lấy
            // giá mới, cũng không giữ giá cũ đã hết hiệu lực (audit F-01, Codex R03).
            $this->assertCartRatesUnchanged($items);

            // Owner có thể bị tạm ngưng trong lúc giỏ nằm đó — kiểm lại trước khi ghi.
            $eligibility = app(PurchaseEligibilityService::class);
            foreach ($items as $cartItem) {
                if ($cartItem->screen) {
                    $eligibility->assertScreenPurchasable($cartItem->screen);
                }
            }

            $campaign = Campaign::create([
                'organization_id'             => $org->id,
                'created_by'                  => $user->id,
                'code'                        => $this->generateCode(),
                'name'                        => $data['name'],
                'brand_name'                  => $data['brand_name'] ?? null,
                'category'                    => $data['category'] ?? null,
                'objectives'                  => $data['objectives'] ?? null,
                'start_date'                  => $items->min('start_date'),
                'end_date'                    => $items->max('end_date'),
                'total_budget'                => $data['total_budget'] ?? null,
                'currency'                    => 'VND',
                // Điền lại sau khi mở gói: một dòng giỏ thuộc gói sinh ra nhiều
                // dòng đặt chỗ, nên đếm số dòng giỏ là đếm sai số màn hình.
                'total_screens'               => 0,
                'total_impressions_estimated' => 0,
                'status'                      => Campaign::STATUS_DRAFT,
                'notes'                       => $data['notes'] ?? null,
            ]);

            // Khóa mọi màn hình liên quan TRƯỚC khi ghi dòng nào.
            //
            // `booking_lines` có khóa ngoại tới `screens`: chèn trước là nhận
            // shared lock, xin exclusive sau là nâng cấp khóa, và hai đơn có
            // màn hình chung sẽ khóa chéo nhau (Codex R05). Sắp theo id để mọi
            // giao dịch trong hệ thống luôn khóa cùng một thứ tự.
            $holdService = app(InventoryHoldService::class);

            foreach ($this->screenIdsInCart($items)->sort()->values() as $screenId) {
                $holdService->lockScreen($screenId);
            }

            // Convert cart items → booking lines (freeze pricing at booking time)
            //
            // Hai bước tách rời có lý do: tạo dòng trước, giành suất sau. Giành
            // suất phải KHÓA hàng màn hình, và một đơn nhiều màn hình sẽ khóa
            // nhiều hàng. Khóa theo thứ tự tùy ý thì hai đơn có màn hình chung
            // nhưng thứ tự khác nhau sẽ khóa chéo và MySQL hủy một trong hai vì
            // deadlock. Gom lại rồi khóa theo thứ tự id là cách rẻ nhất để mọi
            // giao dịch trong hệ thống luôn khóa cùng một thứ tự.
            /** @var array<int, array{line: BookingLine, item: CartItem}> $pairs */
            $pairs = [];

            foreach ($items as $item) {
                $lines = $item->product_id
                    ? $this->createBundleLines($campaign, $item)
                    : array_filter([$this->createScreenLine($campaign, $item)]);

                foreach ($lines as $line) {
                    $pairs[] = ['line' => $line, 'item' => $item];
                }
            }

            $holds = app(InventoryHoldService::class);

            foreach (collect($pairs)->sortBy(fn ($p) => $p['line']->screen_id)->values() as $pair) {
                $holds->consumeForBookingLine($pair['line'], $pair['item']);
            }

            $lineCount = count($pairs);

            $campaign->update([
                'total_screens'               => $lineCount,
                'total_impressions_estimated' => (int) $campaign->bookingLines()->sum('estimated_impressions'),
            ]);

            // Mark cart as converted
            $cart->update(['status' => 'converted']);

            CampaignActivity::log($campaign, 'created', 'Campaign được tạo từ plan với ' . $lineCount . ' màn hình', $user->id);

            return $campaign;
        });
    }

    /**
     * Mọi màn hình mà các dòng giỏ này sẽ chạm tới, kể cả màn hình trong gói.
     *
     * @param  Collection<int, CartItem>  $items
     * @return Collection<int, string>
     */
    private function screenIdsInCart(Collection $items): Collection
    {
        $expander    = app(BundleExpander::class);
        $eligibility = app(PurchaseEligibilityService::class);

        return $items->flatMap(function (CartItem $item) use ($expander, $eligibility) {
            if (! $item->product_id) {
                return $item->screen_id ? [$item->screen_id] : [];
            }

            $product = $eligibility->findPurchasableProduct($item->product_id);

            return $expander->resolveScreens($item, $product)->pluck('id')->all();
        })->unique()->values();
    }

    /**
     * Một dòng giỏ mua màn hình lẻ → một dòng đặt chỗ.
     */
    private function createScreenLine(Campaign $campaign, CartItem $item): ?BookingLine
    {
        if (! $item->screen) {
            return null;
        }

        // Giữ chỗ được giành ở bước sau, trong `createFromCart`, theo thứ tự id
        // màn hình — xem chú thích ở đó về deadlock.
        return BookingLine::create($this->linePayload($campaign, $item, $item->screen, [
            'estimated_cost'        => (int) round((float) $item->estimated_cost),
            'estimated_impressions' => (int) $item->estimated_impressions,
            'booked_cpms'           => $item->booked_cpms,
            'screen_count'          => $item->screen_count ?? 1,
        ]));
    }

    /**
     * Một dòng giỏ thuộc sản phẩm → N dòng đặt chỗ, mỗi màn hình một dòng.
     *
     * Trước đây gói thu về **một** dòng trỏ vào màn hình đầu tiên: SOV chỉ bị
     * trừ ở một chỗ nên các màn hình còn lại vẫn bán tiếp, và toàn bộ tiền ghi
     * cho owner của màn hình đầu. Xem `BundleExpander` để biết cách chia tiền.
     *
     * @return array<int, BookingLine>
     */
    private function createBundleLines(Campaign $campaign, CartItem $item): array
    {
        $expander = app(BundleExpander::class);

        // Đọc lại thành phần gói từ sản phẩm, không tin danh sách đã lưu trong
        // giỏ: owner có thể đã gỡ một màn hình khỏi gói từ lúc khách thêm giỏ.
        // Màn hình không còn bán được thì cổng bán hàng ném 422 ở đây.
        $product = app(PurchaseEligibilityService::class)->findPurchasableProduct($item->product_id);
        $screens = $expander->resolveScreens($item, $product);
        $buyMode = $expander->buyModeOf($item);

        $totalVnd  = (int) round((float) $item->estimated_cost);
        $unitPrice = (int) round((float) ($product->individual_price ?: $product->floor_price));

        // Giá sản phẩm đổi giữa lúc thêm giỏ và lúc chốt đơn thì DỪNG LẠI.
        //
        // Owner đổi giá là tổng tiền đơn hàng đổi theo mà khách không hề xác
        // nhận, và bản chụp gói vẫn ghi con số cũ — hai con số mâu thuẫn trong
        // cùng một đơn (Codex R06). `assertCartRatesUnchanged` không bắt được
        // vì nó chỉ so giá kho của màn hình, không so giá sản phẩm.
        //
        // Dùng CHUNG `productTotal` với lúc thêm giỏ và sửa giỏ: ba nơi tính
        // ba kiểu là lý do guard này từng báo đổi giá khi không ai đổi gì
        // (Codex R28).
        $expected = $expander->productTotal($product, $buyMode, $screens);

        if ($expected !== $totalVnd) {
            throw new HttpException(409, sprintf(
                'Giá của "%s" vừa thay đổi (%s ₫ → %s ₫). Vui lòng xem lại giỏ hàng trước khi gửi booking.',
                $product->name,
                number_format($totalVnd, 0, ',', '.'),
                number_format($expected, 0, ',', '.'),
            ));
        }

        $split = $expander->splitCost($totalVnd, $screens, $buyMode, $unitPrice);

        $snapshotScreens = [];
        $lines = [];

        foreach ($screens->values() as $i => $screen) {
            $amount      = (int) ($split['amounts'][$i] ?? 0);
            $impressions = (int) ($screen->inventory?->weekly_impressions ?? 0);

            $snapshotScreens[] = [
                'screen_id'   => $screen->id,
                'screen_name' => $screen->name,
                'owner_id'    => $screen->owner_id,
                'weight'      => (int) ($split['weights'][$i] ?? 0),
                'amount_vnd'  => $amount,
            ];

            $lines[] = [
                'screen'      => $screen,
                'amount'      => $amount,
                'impressions' => $impressions,
            ];
        }

        $bundle = BookingLineBundle::create([
            'campaign_id' => $campaign->id,
            'product_id'  => $product->id,
            'buy_mode'    => $buyMode,
            'price_total' => $totalVnd,
            'snapshot'    => [
                'product_id'      => $product->id,
                'product_name'    => $product->name,
                'listing_mode'    => $product->listing_mode,
                'buy_mode'        => $buyMode,
                'price_total_vnd' => $totalVnd,
                'split_method'    => $split['method'],
                'screens'         => $snapshotScreens,
                'captured_at'     => now()->toIso8601String(),
            ],
        ]);

        $created = [];
        foreach ($lines as $line) {
            $bookingLine = BookingLine::create($this->linePayload($campaign, $item, $line['screen'], [
                'product_id'            => $product->id,
                'bundle_id'             => $bundle->id,
                'estimated_cost'        => $line['amount'],
                'estimated_impressions' => $line['impressions'],
                // Gói tính theo giá gói, không theo số CPM của từng màn hình.
                'booked_cpms'           => null,
                'screen_count'          => 1,
            ]));

            $created[] = $bookingLine;
        }

        return $created;
    }

    /**
     * Phần chung của một dòng đặt chỗ: ngày, SOV, và ảnh chụp giá lúc đặt.
     *
     * Giá được đóng băng ở đây theo kho của **chính màn hình đó**, không phải
     * màn hình đầu tiên của gói.
     */
    private function linePayload(Campaign $campaign, CartItem $item, Screen $screen, array $overrides): array
    {
        $inv = $screen->inventory;
        $pricingModel = $item->pricing_model ?? $inv?->pricing_model ?? 'io';

        if ($pricingModel === 'both') {
            $pricingModel = 'io';
        }

        return array_merge([
            'campaign_id'           => $campaign->id,
            'screen_id'             => $screen->id,
            'owner_id'              => $screen->owner_id,
            'start_date'            => $item->start_date,
            'end_date'              => $item->end_date,
            'spot_length'           => $item->spot_length,
            'share_of_voice_pct'    => $item->share_of_voice_pct,
            'floor_cpm_at_booking'  => $inv?->floor_cpm ?? 0,
            'status'                => 'pending',
            'pricing_model'         => $pricingModel,
            // Mức chiết khấu đã áp đi theo đơn: hóa đơn phải giải thích được vì
            // sao tiền không bằng đơn giá nhân số kỳ.
            'duration_discount_pct' => (int) ($item->duration_discount_pct ?? 0),
            'io_rate_at_booking'    => $pricingModel === 'io' ? ($inv?->io_rate ?? 0) : null,
            'io_rate_unit'          => $pricingModel === 'io' ? ($inv?->io_rate_unit ?? 'month') : null,
            'kpi_spots_per_day'     => $pricingModel === 'io' ? $inv?->io_kpi_spots_per_day : null,
        ], $overrides);
    }

    /**
     * Submit campaign for owner approval.
     */
    public function submit(Campaign $campaign, User $user): Campaign
    {
        abort_unless($campaign->isDraft(), 422, 'Campaign không ở trạng thái nháp');

        // Kiểm lại lần cuối trước khi gửi cho media owner: giữa lúc tạo nháp và
        // lúc gửi, owner có thể đã bị tạm ngưng hoặc màn hình đã bị gỡ bán.
        $this->assertLinesStillPurchasable($campaign);

        $campaign->update([
            'status'       => Campaign::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        CampaignActivity::log($campaign, 'submitted', 'Campaign được gửi chờ duyệt', $user->id);

        // Notify each owner who has booking lines
        $ownerIds = $campaign->bookingLines()->distinct()->pluck('owner_id');
        foreach ($ownerIds as $ownerId) {
            $lineCount = $campaign->bookingLines()->where('owner_id', $ownerId)->count();
            $ownerUsers = User::whereHas('ownerUsers', fn ($q) => $q->where('owner_id', $ownerId))
                ->get();
            foreach ($ownerUsers as $ownerUser) {
                $ownerUser->notify(new BookingSubmittedNotification($campaign, $lineCount));
            }
        }

        return $campaign->fresh();
    }

    /**
     * Mọi màn hình trong chiến dịch còn bán được không.
     *
     * Cổng bán hàng trước đây chỉ chặn ở bước thêm giỏ. Giữa thêm giỏ và gửi
     * booking có thể cách nhau nhiều ngày — đủ để owner bị tạm ngưng hoặc màn
     * hình bị tắt (audit F10, Codex R05: eligibility phải kiểm ở mọi chuyển
     * trạng thái, không chỉ lúc thêm).
     */
    private function assertLinesStillPurchasable(Campaign $campaign): void
    {
        $eligibility = app(PurchaseEligibilityService::class);

        $lines = $campaign->bookingLines()->with('screen')->get();

        foreach ($lines as $line) {
            if ($line->screen) {
                $eligibility->assertScreenPurchasable($line->screen);
            }
        }
    }

    /**
     * Giá đã chụp lúc thêm vào giỏ phải còn khớp giá hiện hành của kho.
     *
     * Dòng giỏ cũ chưa có ảnh chụp (tạo trước đợt này) thì bỏ qua kiểm — không
     * hợp thức hoá chúng bằng cách coi như đã khớp, mà chỉ không chặn; chúng sẽ
     * có ảnh chụp ngay lần cập nhật kế tiếp.
     */
    private function assertCartRatesUnchanged(Collection $items): void
    {
        $cart = app(CartService::class);
        $changed = [];

        foreach ($items as $item) {
            if (empty($item->rate_snapshot)) {
                continue;
            }

            // So sánh không phụ thuộc thứ tự khóa: MySQL lưu cột JSON dưới dạng đã
            // chuẩn hoá và trả về với thứ tự khóa khác lúc ghi. Dùng === trực tiếp
            // sẽ báo "giá đã đổi" cho mọi đơn hàng.
            // Sắp khóa ở MỌI tầng, không chỉ tầng ngoài: ảnh chụp giá nay có
            // `duration_discounts` là một mảng lồng, và MySQL chuẩn hoá thứ tự
            // khóa cả bên trong. Chỉ ksort tầng ngoài thì mọi giỏ hàng có khai
            // chiết khấu đều bị báo "giá đã đổi" — đúng lỗi đã mắc một lần với
            // tầng ngoài.
            $current  = self::normalizeKeys($cart->rateSnapshot($item->screen?->inventory));
            $snapshot = self::normalizeKeys($item->rate_snapshot);

            if ($current !== $snapshot) {
                $changed[] = $item->screen?->name ?? $item->screen_id;
            }
        }

        if ($changed !== []) {
            throw new HttpException(409, sprintf(
                'Giá của %s vừa thay đổi. Vui lòng xem lại giỏ hàng trước khi gửi booking.',
                implode(', ', array_map(fn ($n) => "\"{$n}\"", $changed))
            ));
        }
    }

    /**
     * Các owner mà người này được thay mặt quyết định duyệt / từ chối.
     *
     * Quyền đọc từ cùng một bảng với Filament và API (`OwnerUser::PERMISSIONS`
     * qua `TenantPermission`) — không viết bộ luật thứ hai ở đây.
     *
     * @return array<int, string>
     */
    private function ownerIdsUserCanDecideFor(User $user): array
    {
        if ($user->hasRole('super_admin')) {
            return Owner::query()->pluck('id')->all();
        }

        return $user->owners()
            ->get()
            ->filter(fn ($owner) => TenantPermission::for($user, $owner->id)->can('manage_bookings'))
            ->pluck('id')
            ->all();
    }

    private function assertCanDecideForOwner(User $user, string $ownerId): void
    {
        if (! in_array($ownerId, $this->ownerIdsUserCanDecideFor($user), true)) {
            throw new HttpException(403, 'Bạn không có quyền duyệt hoặc từ chối đặt chỗ cho media owner này.');
        }
    }

    /**
     * Sắp thứ tự khóa của mảng ở mọi tầng, để so sánh không phụ thuộc thứ tự.
     *
     * Danh sách (khóa 0,1,2…) giữ nguyên thứ tự vì với bậc chiết khấu thì thứ
     * tự phần tử là dữ liệu, không phải chuyện trình bày.
     */
    private static function normalizeKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($v) => self::normalizeKeys($v), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Approve specific booking lines by owner.
     */
    public function approveLines(Campaign $campaign, array $lineIds, User $user): void
    {
        // Lọc theo owner mà người này thực sự được duyệt thay. Trước đây chỉ lọc
        // theo id dòng trong cùng chiến dịch, nên thành viên của owner A duyệt
        // được dòng của owner B miễn là biết id — một chiến dịch gồm màn hình
        // của nhiều owner thì id của họ nằm ngay trên cùng một trang.
        $affected = BookingLine::where('campaign_id', $campaign->id)
            ->whereIn('id', $lineIds)
            ->whereIn('owner_id', $this->ownerIdsUserCanDecideFor($user))
            ->where('status', 'pending')
            ->update([
                'status'      => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

        if ($affected === 0) {
            throw new HttpException(403, 'Bạn không có quyền duyệt các dòng đặt chỗ này.');
        }

        CampaignActivity::log($campaign, 'approved', "$affected màn hình được duyệt bởi " . $user->name, $user->id);

        $this->checkAllLinesResolved($campaign);
    }

    /**
     * Reject specific booking lines by owner.
     */
    public function rejectLines(Campaign $campaign, array $lineIds, string $reason, User $user): void
    {
        $affected = BookingLine::where('campaign_id', $campaign->id)
            ->whereIn('id', $lineIds)
            ->whereIn('owner_id', $this->ownerIdsUserCanDecideFor($user))
            ->where('status', 'pending')
            ->update([
                'status'          => 'rejected',
                'rejected_reason' => $reason,
            ]);

        if ($affected === 0) {
            throw new HttpException(403, 'Bạn không có quyền từ chối các dòng đặt chỗ này.');
        }

        CampaignActivity::log($campaign, 'rejected', "$affected màn hình bị từ chối: $reason", $user->id);

        $this->checkAllLinesResolved($campaign);
    }

    /**
     * Approve ALL pending lines for this owner in one action.
     */
    public function approveAllForOwner(Campaign $campaign, string $ownerId, User $user): int
    {
        $this->assertCanDecideForOwner($user, $ownerId);

        $lines = BookingLine::where('campaign_id', $campaign->id)
            ->where('owner_id', $ownerId)
            ->where('status', 'pending')
            ->get();

        if ($lines->isEmpty()) return 0;

        BookingLine::whereIn('id', $lines->pluck('id'))
            ->update([
                'status'      => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

        CampaignActivity::log($campaign, 'approved', $lines->count() . " màn hình được duyệt bởi " . $user->name, $user->id);

        $this->checkAllLinesResolved($campaign);

        return $lines->count();
    }

    /**
     * Reject ALL pending lines for this owner in one action.
     */
    public function rejectAllForOwner(Campaign $campaign, string $ownerId, string $reason, User $user): int
    {
        $this->assertCanDecideForOwner($user, $ownerId);

        $lines = BookingLine::where('campaign_id', $campaign->id)
            ->where('owner_id', $ownerId)
            ->where('status', 'pending')
            ->get();

        if ($lines->isEmpty()) return 0;

        BookingLine::whereIn('id', $lines->pluck('id'))
            ->update([
                'status'          => 'rejected',
                'rejected_reason' => $reason,
            ]);

        CampaignActivity::log($campaign, 'rejected', $lines->count() . " màn hình bị từ chối: $reason", $user->id);

        $this->checkAllLinesResolved($campaign);

        return $lines->count();
    }

    /**
     * Check if all lines are resolved (approved/rejected) and update campaign status.
     */
    private function checkAllLinesResolved(Campaign $campaign): void
    {
        $pending = $campaign->bookingLines()->where('status', 'pending')->count();
        if ($pending > 0) return;

        $approved = $campaign->bookingLines()->where('status', 'approved')->count();
        $total = $campaign->bookingLines()->count();

        if ($approved > 0) {
            // At least some approved → campaign approved
            $campaign->update([
                'status'      => Campaign::STATUS_APPROVED,
                'approved_at' => now(),
            ]);
            CampaignActivity::log($campaign, 'approved', "Campaign được duyệt ($approved/$total màn hình)");
            $this->notifyBuyer($campaign, 'approved');
        } else {
            // All rejected
            $campaign->update([
                'status'      => Campaign::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => 'Tất cả màn hình bị từ chối bởi media owner',
            ]);
            CampaignActivity::log($campaign, 'rejected', 'Campaign bị từ chối — tất cả màn hình bị từ chối');
            $this->notifyBuyer($campaign, 'rejected');
        }
    }

    /**
     * Notify campaign creator about approval/rejection.
     */
    private function notifyBuyer(Campaign $campaign, string $action): void
    {
        $creator = $campaign->createdBy;
        if ($creator) {
            $creator->notify(new BookingResolvedNotification($campaign->fresh(), $action));
        }
    }

    /**
     * Generate unique campaign code: CPN-YYYYMM-XXXX
     */
    public function generateCode(): string
    {
        $prefix = 'CPN-' . now()->format('Ym') . '-';
        $last = Campaign::where('code', 'like', $prefix . '%')
            ->orderByDesc('code')
            ->value('code');

        if ($last) {
            $num = (int) substr($last, -4) + 1;
        } else {
            $num = 1;
        }

        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
