<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenRateVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\CartService;
use App\Services\Pricing\DurationDiscount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Giai đoạn 1.6 — bảng giá có lịch sử, và chiết khấu theo thời lượng.
 *
 * Trước sửa: đổi giá là đổi thẳng, không ai trả lời được "ngày ấy màn hình này
 * niêm yết bao nhiêu"; và 12 kỳ đúng bằng 12 lần một kỳ, nên mọi thỏa thuận
 * giảm giá cho hợp đồng dài đều nằm ngoài hệ thống.
 */
class RateCardVersionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = User::factory()->create(['current_organization_id' => $this->org->id]);
        $this->buyer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->buyer->id,
            'role'            => 'admin',
        ]);
        $this->actingAs($this->buyer);
    }

    private function screen(?array $discounts = null): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenInventory::create([
            'screen_id'          => $screen->id,
            'pricing_model'      => 'io',
            'io_rate'            => 1_000_000,
            'io_rate_unit'       => 'month',
            'spot_length'        => 15,
            'duration_discounts' => $discounts,
        ]);

        return $screen->fresh('inventory');
    }

    private function cart(): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $this->buyer->id, 'status' => 'active'],
            ['organization_id' => $this->org->id, 'name' => 'Plan']
        );
    }

    /** Khoảng ngày tròn $months tháng lịch. */
    private function months(int $months): array
    {
        $start = now()->addYear()->startOfYear();

        return [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthsNoOverflow($months)->subDay()->toDateString(),
        ];
    }

    // ── Phiên bản bảng giá ───────────────────────────────────────────────────

    public function test_tao_kho_la_co_phien_ban_dau_tien(): void
    {
        $screen = $this->screen();

        $this->assertSame(1, ScreenRateVersion::where('screen_id', $screen->id)->count());
        $this->assertSame(1_000_000, (int) round((float) ScreenRateVersion::where('screen_id', $screen->id)->value('io_rate')));
    }

    public function test_doi_gia_thi_ghi_phien_ban_moi_va_giu_phien_ban_cu(): void
    {
        $screen = $this->screen();

        $this->asDataFixture(fn () => $screen->inventory->update(['io_rate' => 1_500_000]));

        $versions = ScreenRateVersion::where('screen_id', $screen->id)->orderBy('effective_from')->get();

        $this->assertCount(2, $versions, 'Đổi giá phải ghi thêm, không ghi đè.');
        $this->assertSame(1_000_000, (int) round((float) $versions[0]->io_rate));
        $this->assertSame(1_500_000, (int) round((float) $versions[1]->io_rate));
    }

    public function test_sua_thu_khong_lien_quan_den_gia_thi_khong_ghi_phien_ban(): void
    {
        $screen = $this->screen();

        $this->asDataFixture(fn () => $screen->inventory->update(['weekly_impressions' => 500_000]));

        $this->assertSame(
            1,
            ScreenRateVersion::where('screen_id', $screen->id)->count(),
            'Sửa số lượt hiển thị không phải đổi giá — ghi phiên bản cho mọi thay đổi thì lịch sử giá thành rác.'
        );
    }

    public function test_doi_bac_chiet_khau_cung_la_doi_gia(): void
    {
        $screen = $this->screen();

        $this->asDataFixture(fn () => $screen->inventory->update(['duration_discounts' => [['min_units' => 6, 'discount_pct' => 10]]]));

        $this->assertSame(2, ScreenRateVersion::where('screen_id', $screen->id)->count());
    }

    public function test_doc_duoc_bang_gia_co_hieu_luc_tai_mot_thoi_diem(): void
    {
        $screen = $this->screen();

        // Phiên bản cũ lùi về quá khứ để mô phỏng lịch sử thật.
        ScreenRateVersion::where('screen_id', $screen->id)->update(['effective_from' => now()->subDays(30)]);
        $this->asDataFixture(fn () => $screen->inventory->update(['io_rate' => 2_000_000]));

        $old = ScreenRateVersion::effectiveAt($screen->id, now()->subDays(10));
        $new = ScreenRateVersion::effectiveAt($screen->id, now());

        $this->assertSame(1_000_000, (int) round((float) $old->io_rate));
        $this->assertSame(2_000_000, (int) round((float) $new->io_rate));
    }

    // ── Chiết khấu theo thời lượng ───────────────────────────────────────────

    public function test_thue_dai_duoc_giam_gia(): void
    {
        $screen = $this->screen([
            ['min_units' => 3,  'discount_pct' => 5],
            ['min_units' => 12, 'discount_pct' => 15],
        ]);

        $item = app(CartService::class)->addItem($this->cart(), $screen->id, $this->months(12));

        $this->assertSame(12, (int) $item->duration_units);
        $this->assertSame(15, (int) $item->duration_discount_pct);
        $this->assertSame(
            10_200_000,
            (int) round((float) $item->estimated_cost),
            '12 kỳ × 1.000.000 giảm 15% = 10.200.000, không phải 12.000.000.'
        );
    }

    public function test_chua_du_bac_thi_khong_giam(): void
    {
        $screen = $this->screen([['min_units' => 3, 'discount_pct' => 5]]);

        $item = app(CartService::class)->addItem($this->cart(), $screen->id, $this->months(2));

        $this->assertSame(0, (int) $item->duration_discount_pct);
        $this->assertSame(2_000_000, (int) round((float) $item->estimated_cost));
    }

    public function test_ap_bac_cao_nhat_dat_toi_khong_cong_don(): void
    {
        $discounts = new DurationDiscount();
        $tiers = [
            ['min_units' => 3,  'discount_pct' => 5],
            ['min_units' => 6,  'discount_pct' => 10],
            ['min_units' => 12, 'discount_pct' => 15],
        ];

        $this->assertSame(0,  $discounts->pctFor($tiers, 2));
        $this->assertSame(5,  $discounts->pctFor($tiers, 3));
        $this->assertSame(10, $discounts->pctFor($tiers, 7));
        $this->assertSame(15, $discounts->pctFor($tiers, 24), 'Vượt bậc cuối thì vẫn là bậc cuối, không cộng thêm.');
    }

    public function test_bac_khai_sai_bi_bo_qua_khong_ra_gia_am(): void
    {
        $discounts = new DurationDiscount();

        $this->assertSame(0, $discounts->pctFor([['min_units' => 3, 'discount_pct' => 150]], 6), 'Gõ nhầm 150% không được thành hóa đơn âm.');
        $this->assertSame(0, $discounts->pctFor([['min_units' => 0, 'discount_pct' => 10]], 6));
        $this->assertSame(0, $discounts->pctFor('không phải mảng', 6));
        $this->assertSame(0, $discounts->pctFor(null, 6));
    }

    // ── Chiết khấu nằm trong ảnh chụp giá ────────────────────────────────────

    public function test_gio_hang_co_chiet_khau_van_chot_don_duoc(): void
    {
        $screen = $this->screen([['min_units' => 3, 'discount_pct' => 10]]);
        $cart   = $this->cart();

        app(CartService::class)->addItem($cart, $screen->id, $this->months(6));

        $campaign = app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);

        $line = $campaign->bookingLines()->firstOrFail();

        $this->assertSame(10, (int) $line->duration_discount_pct, 'Mức chiết khấu phải đi theo đơn để hóa đơn giải thích được con số.');
        $this->assertSame(5_400_000, (int) round((float) $line->estimated_cost));
    }

    public function test_owner_doi_bac_chiet_khau_trong_luc_gio_nam_do_thi_chan_chot_don(): void
    {
        $screen = $this->screen([['min_units' => 3, 'discount_pct' => 10]]);
        $cart   = $this->cart();

        app(CartService::class)->addItem($cart, $screen->id, $this->months(6));

        // Owner bỏ chiết khấu sau khi khách đã bỏ vào giỏ: tiền đổi, khách phải
        // được xem lại con số mới.
        $this->asDataFixture(fn () => $screen->inventory->update(['duration_discounts' => null]));

        try {
            app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);
            $this->fail('Đổi bậc chiết khấu là đổi giá, phải chặn chốt đơn.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }
}
