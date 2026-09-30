<?php

namespace Tests\Feature\Review;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Cart;
use App\Models\Creative;
use App\Models\ImpressionLog;
use App\Models\Network;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\CancellationService;
use App\Services\Booking\CreativeGate;
use App\Services\CampaignService;
use App\Services\CartService;
use App\Services\Network\NetworkRelationReconciler;
use App\Services\PaymentService;
use App\Services\Player\DeviceAuthenticator;
use App\Services\Player\ImpressionRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chống hồi quy cho review vòng hai của Codex (R22–R28).
 *
 * Mỗi ca dựng theo đúng kịch bản trong báo cáo, kể cả các con số. R28 đáng
 * chú ý riêng: nó là hồi quy do chính bản sửa R06 của tôi gây ra — tôi thêm
 * một guard để bảo vệ và guard đó chặn đường mua hàng bình thường.
 */
class CodexRound2Test extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['buyer', 'publisher', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = User::factory()->create(['current_organization_id' => $this->org->id]);
        $this->buyer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $this->actingAs($this->buyer);
    }

    private function screen(?Owner $owner = null, int $ioRate = 1_000_000): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'io',
            'io_rate'       => $ioRate,
            'io_rate_unit'  => 'month',
            'spot_length'   => 15,
        ]);

        return $screen->fresh('inventory');
    }

    private function cart(): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $this->buyer->id, 'status' => 'active'],
            ['organization_id' => $this->org->id, 'name' => 'P']
        );
    }

    private function dates(): array
    {
        $start = now()->addYear()->startOfYear();

        return [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];
    }

    // ── R28 ─────────────────────────────────────────────────────────────────

    public function test_r28_sua_gio_san_pham_roi_chot_don_khong_bao_doi_gia(): void
    {
        // Đúng kịch bản Codex: sản phẩm mua lẻ 1.500.000/màn, chọn hai màn,
        // giỏ ghi 3.000.000; màn hình đầu có giá kho 1.000.000 nên nếu sửa giỏ
        // tính theo giá kho thì con số lệch và chốt đơn báo 409.
        $a = $this->screen(null, 1_000_000);
        $b = $this->screen(null, 1_000_000);

        $product = Product::create([
            'owner_id'         => $a->owner_id,
            'name'             => 'Sản phẩm mua lẻ',
            'slug'             => 'sp-le-' . Str::random(6),
            'type'             => 'package',
            'category'         => 'led',
            'listing_mode'     => 'both',
            'floor_price'      => 9_000_000,
            'individual_price' => 1_500_000,
            'min_quantity'     => 1,
            'total_units'      => 2,
            'status'           => 'active',
        ]);
        $product->screens()->attach([$a->id, $b->id]);

        $cart = $this->cart();
        $item = app(CartService::class)->addProduct($cart, $product->id, $this->dates() + [
            'buy_mode'            => 'individual',
            'selected_screen_ids' => [$a->id, $b->id],
        ]);

        $this->assertSame(3_000_000, (int) round((float) $item->estimated_cost));

        // Sửa giỏ: thao tác bình thường, không đổi giá gì.
        $updated = app(CartService::class)->updateItem($item, ['spot_length' => 20]);

        $this->assertSame(
            3_000_000,
            (int) round((float) $updated->estimated_cost),
            'Sửa giỏ không được tính lại tiền theo giá kho của màn hình đầu.'
        );

        // Chốt đơn phải đi qua, không 409.
        $campaign = app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);

        $this->assertSame(2, $campaign->bookingLines()->count());
        $this->assertSame(3_000_000, (int) round((float) $campaign->bookingLines()->sum('estimated_cost')));
    }

    public function test_r28_van_chan_khi_owner_that_su_doi_gia(): void
    {
        $a = $this->screen();
        $product = Product::create([
            'owner_id'         => $a->owner_id,
            'name'             => 'Sản phẩm',
            'slug'             => 'sp-' . Str::random(6),
            'type'             => 'package',
            'category'         => 'led',
            'listing_mode'     => 'both',
            'floor_price'      => 2_000_000,
            'individual_price' => 1_500_000,
            'min_quantity'     => 1,
            'total_units'      => 1,
            'status'           => 'active',
        ]);
        $product->screens()->attach([$a->id]);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + [
            'buy_mode'            => 'individual',
            'selected_screen_ids' => [$a->id],
        ]);

        // Owner đổi giá thật sau khi khách đã bỏ vào giỏ.
        $this->asDataFixture(fn () => $product->update(['individual_price' => 2_500_000]));

        try {
            app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);
            $this->fail('Đổi giá thật thì vẫn phải chặn — sửa R28 không được làm mất guard R06.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // ── R23 ─────────────────────────────────────────────────────────────────

    /** @param array<int, array{screen: Screen, cost: int}> $lines */
    private function campaignWith(array $lines): Campaign
    {
        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch',
            'start_date'      => now()->addDays(30)->toDateString(),
            'end_date'        => now()->addDays(60)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        foreach ($lines as $l) {
            BookingLine::create([
                'campaign_id'        => $campaign->id,
                'screen_id'          => $l['screen']->id,
                'owner_id'           => $l['screen']->owner_id,
                'start_date'         => now()->addDays(30)->toDateString(),
                'end_date'           => now()->addDays(60)->toDateString(),
                'spot_length'        => 15,
                'share_of_voice_pct' => 100,
                'estimated_cost'     => $l['cost'],
                'status'             => 'approved',
                'pricing_model'      => 'io',
            ]);
        }

        return $campaign->fresh();
    }

    public function test_r23_sau_khi_hoan_tien_tong_ket_khong_bao_da_tra_du(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $one   = $this->screen($owner);
        $two   = $this->screen($owner);

        $campaign = $this->campaignWith([
            ['screen' => $one, 'cost' => 1_000_000],
            ['screen' => $two, 'cost' => 1_000_000],
        ]);

        $svc = app(PaymentService::class);
        $payment = $svc->createPayment($campaign, 'bank_transfer', 1_080_000, $owner->id);
        $svc->confirmBankTransfer($payment);

        // Hủy sớm một dòng → hoàn 540.000.
        $line = $campaign->fresh()->bookingLines()->first();
        app(CancellationService::class)->cancelLine($line, $this->buyer);

        $summary = $svc->getSummary($campaign->fresh());

        $this->assertFalse(
            $summary['is_fully_paid'],
            'Giao diện dùng cờ này để quyết định hiện biểu mẫu thanh toán — báo "đã trả đủ" là khóa luôn đường trả phần còn thiếu.'
        );
        $this->assertGreaterThan(0, $summary['remaining']);
    }

    public function test_r23_gia_le_tra_du_thi_tong_ket_khong_bao_thieu(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWith([['screen' => $screen, 'cost' => 1_000_001]]);

        $svc = app(PaymentService::class);
        $svc->confirmBankTransfer($svc->createPayment($campaign, 'bank_transfer', null, $screen->owner_id));

        $summary = $svc->getSummary($campaign->fresh());

        $this->assertSame(0.0, $summary['remaining'], 'Tổng kết và công nợ phải dùng cùng một phép làm tròn.');
        $this->assertTrue($summary['is_fully_paid']);
    }

    // ── R24 ─────────────────────────────────────────────────────────────────

    public function test_r24_huy_lan_hai_sau_khi_tra_them_van_hoan_du(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $a = $this->screen($owner);
        $b = $this->screen($owner);

        $campaign = $this->campaignWith([
            ['screen' => $a, 'cost' => 1_000_000],
            ['screen' => $b, 'cost' => 1_000_000],
        ]);

        $svc = app(PaymentService::class);
        $cancel = app(CancellationService::class);

        // Trả 1.080.000 rồi hủy A → hoàn 540.000.
        $svc->confirmBankTransfer($svc->createPayment($campaign, 'bank_transfer', 1_080_000, $owner->id));

        $lineA = $campaign->fresh()->bookingLines()->where('screen_id', $a->id)->firstOrFail();
        $refundA = $cancel->cancelLine($lineA, $this->buyer);

        $this->assertSame(540_000, (int) round((float) $refundA->amount));

        // Trả thêm 540.000 cho B → B đủ 1.080.000.
        $svc->confirmBankTransfer($svc->createPayment($campaign->fresh(), 'bank_transfer', 540_000, $owner->id, 'top-up'));

        // Hủy B, vẫn trong kỳ hoàn 100%.
        $lineB = $campaign->fresh()->bookingLines()->where('screen_id', $b->id)->firstOrFail();
        $refundB = $cancel->cancelLine($lineB, $this->buyer);

        $this->assertSame(
            1_080_000,
            (int) round((float) $refundB->amount),
            'Tiền trả thêm cho dòng còn sống không được chia lại vào dòng đã hủy — cách cũ ra 810.000.'
        );
        $this->assertSame(
            1_620_000,
            (int) round((float) Refund::sum('amount')),
            'Hai lần hủy đều trong kỳ hoàn 100% thì tổng hoàn phải bằng tổng đã trả.'
        );
    }

    // ── R22 ─────────────────────────────────────────────────────────────────

    public function test_r22_ghi_log_hong_thi_khong_de_lai_cho_mo_coi(): void
    {
        $screen = $this->screen();
        $token  = app(DeviceAuthenticator::class)->issueToken($screen);
        $screen->refresh();

        $eventId = (string) Str::ulid();

        // Đường lỗi Codex chỉ ra: URL dài hơn cột. Nay cột đã nới bằng luật
        // kiểm nên ca này phải ghi được — và đó chính là điều cần chứng minh.
        $longUrl = 'https://proof.example.com/' . str_repeat('a', 260);
        $this->assertGreaterThan(255, strlen($longUrl));

        $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/v1/player/impression', [
                'screen_uuid'  => $screen->uuid,
                'event_id'     => $eventId,
                'duration_sec' => 15,
                'played_at'    => now()->toIso8601String(),
                'proof_url'    => $longUrl,
            ])
            ->assertStatus(201);

        $this->assertSame(1, ImpressionLog::count());
        $this->assertSame(
            1,
            (int) DB::table('impression_events')->whereNotNull('impression_log_id')->count(),
            'Chỗ đã giành phải được nối với bản ghi chính, không để mồ côi.'
        );
    }

    // ── R26 ─────────────────────────────────────────────────────────────────

    public function test_r26_luot_phat_bi_kep_khong_quy_thuoc_va_khong_vao_bao_cao(): void
    {
        $screen = $this->screen();
        $token  = app(DeviceAuthenticator::class)->issueToken($screen);
        $screen->refresh();

        // Dòng đặt chỗ đang chạy, phủ hôm nay.
        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'CD',
            'start_date'      => now()->subDays(5)->toDateString(),
            'end_date'        => now()->addDays(25)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_ACTIVE,
        ]);
        BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => now()->subDays(5)->toDateString(),
            'end_date'           => now()->addDays(25)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'active',
            'pricing_model'      => 'io',
        ]);

        // Sự kiện 60 ngày trước: mốc bị kẹp về biên 7 ngày.
        $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/v1/player/impression', [
                'screen_uuid'  => $screen->uuid,
                'event_id'     => (string) Str::ulid(),
                'duration_sec' => 15,
                'played_at'    => now()->subDays(60)->toIso8601String(),
                'campaign_id'  => $campaign->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('clamped', true);

        $log = ImpressionLog::firstOrFail();

        $this->assertNull(
            $log->booking_line_id,
            'Mốc đã bị dịch thì không được dùng để tìm hợp đồng — có thể gắn vào dòng chưa tồn tại lúc phát thật.'
        );

        app(ImpressionRollupService::class)->rollupRange(now()->subDays(10), now());

        $this->assertSame(
            0,
            DB::table('impression_daily_rollups')->count(),
            'Đưa lượt bị kẹp vào báo cáo là cộng nó vào một ngày mà nó không thuộc về.'
        );
    }

    // ── R27 ─────────────────────────────────────────────────────────────────

    public function test_r27_noi_dung_khong_gan_dung_dong_thi_khong_duoc_nhan(): void
    {
        $screen = $this->screen();
        $token  = app(DeviceAuthenticator::class)->issueToken($screen);
        $screen->refresh();

        $other = $this->screen();

        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'CD',
            'start_date'      => now()->subDays(5)->toDateString(),
            'end_date'        => now()->addDays(25)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_ACTIVE,
        ]);

        foreach ([$screen, $other] as $s) {
            BookingLine::create([
                'campaign_id'        => $campaign->id,
                'screen_id'          => $s->id,
                'owner_id'           => $s->owner_id,
                'start_date'         => now()->subDays(5)->toDateString(),
                'end_date'           => now()->addDays(25)->toDateString(),
                'spot_length'        => 15,
                'share_of_voice_pct' => 100,
                'estimated_cost'     => 1_000_000,
                'status'             => 'active',
                'pricing_model'      => 'io',
            ]);
        }

        // Mẫu C2 chỉ gắn cho dòng của màn hình KHÁC.
        $c2 = Creative::create([
            'campaign_id'     => $campaign->id,
            'organization_id' => $this->org->id,
            'name'            => 'C2',
            'type'            => 'image',
            'file_path'       => 'x.jpg',
            'status'          => 'approved',
        ]);
        $lineOther = $campaign->bookingLines()->where('screen_id', $other->id)->firstOrFail();
        $lineOther->creatives()->attach($c2->id, ['weight' => 100]);

        $lineMine = $campaign->bookingLines()->where('screen_id', $screen->id)->firstOrFail();

        $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/v1/player/impression', [
                'screen_uuid'     => $screen->uuid,
                'event_id'        => (string) Str::ulid(),
                'duration_sec'    => 15,
                'played_at'       => now()->toIso8601String(),
                'booking_line_id' => $lineMine->id,
                'creative_id'     => $c2->id,
            ])
            ->assertStatus(201);

        $this->assertNull(
            ImpressionLog::firstOrFail()->creative_id,
            'Cùng chiến dịch không có nghĩa mọi mẫu đều được phát trên mọi màn hình.'
        );
    }

    // ── R25 ─────────────────────────────────────────────────────────────────

    public function test_r25_xung_dot_ben_trong_nguon_network_code_cung_bi_chan(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $a = Network::factory()->create(['code' => 'net-a', 'name' => 'A', 'owner_id' => $owner->id]);
        Network::factory()->create(['code' => 'net-b', 'name' => 'B', 'owner_id' => $owner->id]);
        Network::factory()->create(['code' => 'net-c', 'name' => 'C', 'owner_id' => $owner->id]);

        $site = Site::factory()->create(['owner_id' => $owner->id, 'network_id' => null]);

        // Kho của cả hai màn hình đều trỏ A (không mâu thuẫn), nhưng
        // network_code lần lượt trỏ B và C.
        foreach (['net-b', 'net-c'] as $code) {
            $screen = Screen::factory()->create([
                'owner_id'     => $owner->id,
                'site_id'      => $site->id,
                'active'       => true,
                'network_code' => $code,
            ]);
            ScreenInventory::factory()->create(['screen_id' => $screen->id, 'network_id' => $a->id]);
        }

        $result = app(NetworkRelationReconciler::class)->run();

        $this->assertNull(
            DB::table('sites')->where('id', $site->id)->value('network_id'),
            'Nguồn network_code tự mâu thuẫn thì không được lấy đáp án từ nguồn còn lại.'
        );
        $this->assertNotEmpty($result['conflicts']);
    }
}
