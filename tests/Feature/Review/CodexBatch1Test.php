<?php

namespace Tests\Feature\Review;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Cart;
use App\Models\Creative;
use App\Models\InventoryHold;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\CancellationService;
use App\Services\Booking\CreativeGate;
use App\Services\CampaignService;
use App\Services\CartService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Chống hồi quy cho nhóm 1 phản hồi review của Codex (29/09/2026).
 *
 * Mỗi ca dưới đây tương ứng một finding đã kiểm chứng trong code. Test viết
 * SAU khi sửa, nên giá trị của chúng nằm ở chỗ: bỏ bản sửa ra thì ca tương ứng
 * phải đổ. Ghi rõ finding nào ở từng ca để lần sau ai đọc cũng biết nó canh gì.
 */
class CodexBatch1Test extends TestCase
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
    }

    private function screen(?Owner $owner = null): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'io',
            'io_rate'       => 1_000_000,
            'io_rate_unit'  => 'month',
            'spot_length'   => 15,
        ]);

        return $screen->fresh('inventory');
    }

    private function publisherOf(Owner $owner): User
    {
        $user = User::factory()->create(['current_owner_id' => $owner->id]);
        $user->assignRole('publisher');
        OwnerUser::create(['owner_id' => $owner->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $user->fresh();
    }

    /** @param array<int, array{screen: Screen, cost: int}> $lines */
    private function campaign(array $lines, string $status = Campaign::STATUS_APPROVED, string $lineStatus = 'approved'): Campaign
    {
        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch',
            'start_date'      => now()->addMonth()->toDateString(),
            'end_date'        => now()->addMonths(2)->toDateString(),
            'currency'        => 'VND',
            'status'          => $status,
        ]);

        foreach ($lines as $l) {
            BookingLine::create([
                'campaign_id'        => $campaign->id,
                'screen_id'          => $l['screen']->id,
                'owner_id'           => $l['screen']->owner_id,
                'start_date'         => now()->addMonth()->toDateString(),
                'end_date'           => now()->addMonths(2)->toDateString(),
                'spot_length'        => 15,
                'share_of_voice_pct' => 100,
                'estimated_cost'     => $l['cost'],
                'status'             => $lineStatus,
                'pricing_model'      => 'io',
            ]);
        }

        return $campaign->fresh();
    }

    private function approvedCreative(Campaign $campaign): Creative
    {
        $creative = Creative::create([
            'campaign_id'     => $campaign->id,
            'organization_id' => $this->org->id,
            'name'            => 'Mẫu',
            'type'            => 'image',
            'file_path'       => 'creatives/x.jpg',
            'status'          => 'pending_review',
        ]);

        return app(CreativeGate::class)->approve($creative, $this->buyer->id);
    }

    private function payInFull(Campaign $campaign, string $ownerId): void
    {
        $payment = app(PaymentService::class)->createPayment($campaign->fresh(), 'bank_transfer', null, $ownerId);
        app(PaymentService::class)->confirmBankTransfer($payment);
    }

    // ── R01 ─────────────────────────────────────────────────────────────────

    public function test_r01_publisher_khong_mo_duoc_trang_thanh_toan_cua_nguoi_mua(): void
    {
        $a = $this->screen();
        $b = $this->screen();
        $campaign = $this->campaign([
            ['screen' => $a, 'cost' => 1_000_000],
            ['screen' => $b, 'cost' => 2_000_000],
        ]);

        $publisherA = $this->publisherOf($a->owner);

        $url = 'http://' . config('domains.frontpage', 'oohx.net') . '/booking/' . $campaign->id . '/payment';

        $this->actingAs($publisherA)->get($url)->assertForbidden();
    }

    public function test_r01_publisher_van_xem_duoc_hop_thu_dat_cho_cua_minh(): void
    {
        $a = $this->screen();
        $campaign = $this->campaign([['screen' => $a, 'cost' => 1_000_000]], Campaign::STATUS_PENDING, 'pending');

        $publisherA = $this->publisherOf($a->owner);

        $this->assertTrue(
            $publisherA->can('viewAsOwner', $campaign),
            'Tách quyền không được làm hỏng hộp thư đặt chỗ — đó là lý do quyền bị nới ra lúc đầu.'
        );
        $this->assertFalse($publisherA->can('view', $campaign));
    }

    // ── R02 ─────────────────────────────────────────────────────────────────

    public function test_r02_giu_cho_het_han_tren_man_hinh_trong_van_chot_don_duoc(): void
    {
        $screen = $this->screen();
        $cart   = Cart::create([
            'user_id'         => $this->buyer->id,
            'organization_id' => $this->org->id,
            'status'          => 'active',
            'name'            => 'P',
        ]);

        $start = now()->addYear()->startOfYear();
        $dates = [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];

        $this->actingAs($this->buyer);
        $item = app(CartService::class)->addItem($cart, $screen->id, $dates + ['share_of_voice_pct' => 100]);

        // Giỏ nằm quá hạn, nhưng KHÔNG ai khác lấy suất.
        InventoryHold::where('cart_item_id', $item->id)->update(['expires_at' => now()->subMinute()]);

        $campaign = app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);

        $this->assertSame(
            1,
            $campaign->bookingLines()->count(),
            'Màn hình trống mà vẫn 422 nghĩa là đơn của chính mình bị tính là đối thủ.'
        );
    }

    // ── R09 ─────────────────────────────────────────────────────────────────

    public function test_r09_gia_le_van_tra_du_va_kich_hoat_duoc(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_001]]);
        $this->approvedCreative($campaign);

        $this->payInFull($campaign, $screen->owner_id);

        $row = app(PaymentService::class)->breakdownByOwner($campaign->fresh())->first();

        $this->assertTrue($row['is_paid'], 'Trả đúng số máy chủ đòi thì phải được coi là đã trả đủ.');
        $this->assertSame(0.0, $row['remaining']);
        $this->assertSame('active', $campaign->fresh()->bookingLines()->first()->status);
    }

    // ── R10 ─────────────────────────────────────────────────────────────────

    public function test_r10_tra_tien_truoc_duyet_noi_dung_sau_van_kich_hoat(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_000]]);

        Creative::create([
            'campaign_id'     => $campaign->id,
            'organization_id' => $this->org->id,
            'name'            => 'Mẫu chờ duyệt',
            'type'            => 'image',
            'file_path'       => 'creatives/x.jpg',
            'status'          => 'pending_review',
        ]);

        $this->payInFull($campaign, $screen->owner_id);
        $this->assertSame('approved', $campaign->fresh()->bookingLines()->first()->status);

        // Duyệt nội dung SAU khi đã trả tiền: điều kiện cuối cùng được gỡ.
        app(CreativeGate::class)->approve(Creative::firstOrFail(), $this->buyer->id);

        $this->assertSame(
            'active',
            $campaign->fresh()->bookingLines()->first()->status,
            'Điều kiện nào được gỡ sau cùng thì chính nó phải xét lại việc lên sóng.'
        );
    }

    // ── R11 ─────────────────────────────────────────────────────────────────

    public function test_r11_owner_du_dieu_kien_khong_bi_owner_khac_chan(): void
    {
        $a = $this->screen();
        $b = $this->screen();
        $campaign = $this->campaign([
            ['screen' => $a, 'cost' => 1_000_000],
            ['screen' => $b, 'cost' => 1_000_000],
        ]);

        // Một mẫu đã duyệt, gắn cho dòng của A; dòng của B chưa có nội dung.
        $creative = $this->approvedCreative($campaign);
        $lineB    = $campaign->bookingLines()->where('owner_id', $b->owner_id)->firstOrFail();
        $lineB->creatives()->detach($creative->id);

        $this->payInFull($campaign, $a->owner_id);

        $lines = $campaign->fresh()->bookingLines()->get()->keyBy('owner_id');

        $this->assertSame('active', $lines[$a->owner_id]->status, 'Owner A đủ tiền và đủ nội dung thì phải chạy.');
        $this->assertSame('approved', $lines[$b->owner_id]->status);
    }

    // ── R12 ─────────────────────────────────────────────────────────────────

    public function test_r12_tien_da_hoan_khong_con_tinh_la_da_tra(): void
    {
        $owner    = Owner::factory()->create(['status' => 'active']);
        $one      = $this->screen($owner);
        $two      = $this->screen($owner);
        $campaign = $this->campaign([
            ['screen' => $one, 'cost' => 1_000_000],
            ['screen' => $two, 'cost' => 1_000_000],
        ]);
        $this->approvedCreative($campaign);

        // Trả MỘT PHẦN: 1.080.000 trên tổng nợ 2.160.000 của owner này.
        // Trả đủ cả hai dòng thì hoàn một dòng xong vẫn còn đủ cho dòng kia —
        // lúc đó `is_paid = true` là đúng, không lộ được lỗi.
        $partial = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', 1_080_000, $owner->id);
        app(PaymentService::class)->confirmBankTransfer($partial);

        // Hủy sớm một dòng → hoàn phần đã trả phân bổ cho dòng đó (540.000).
        $line = $campaign->fresh()->bookingLines()->first();
        $line->update(['start_date' => now()->addDays(30)->toDateString()]);
        app(CancellationService::class)->cancelLine($line->fresh(), $this->buyer, 'đổi kế hoạch');

        $row = app(PaymentService::class)->breakdownByOwner($campaign->fresh())->first();

        $this->assertFalse(
            $row['is_paid'],
            'Tiền đã hoàn không còn là tiền owner giữ — dòng còn lại không thể coi là đã trả đủ.'
        );
        $this->assertGreaterThan(0, $row['remaining']);
    }

    // ── R13 ─────────────────────────────────────────────────────────────────

    public function test_r13_hai_yeu_cau_huy_chi_sinh_mot_nghia_vu_hoan_tien(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_000]]);

        // Hai đối tượng cùng một dòng, cùng mang trạng thái cũ — đúng hình
        // dạng của hai tab hoặc hai yêu cầu song song.
        $lineId = $campaign->bookingLines()->first()->id;
        $first  = BookingLine::findOrFail($lineId);
        $second = BookingLine::findOrFail($lineId);

        app(CancellationService::class)->cancelLine($first, $this->buyer);

        try {
            app(CancellationService::class)->cancelLine($second, $this->buyer);
            $this->fail('Đối tượng thứ hai mang trạng thái cũ, phải bị chặn khi đọc lại trong transaction.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(1, \App\Models\Refund::count());
    }

    // ── R14 ─────────────────────────────────────────────────────────────────

    public function test_r14_huy_dong_active_cuoi_cung_thi_chien_dich_khong_con_dang_chay(): void
    {
        $a = $this->screen();
        $b = $this->screen();
        $campaign = $this->campaign([
            ['screen' => $a, 'cost' => 1_000_000],
            ['screen' => $b, 'cost' => 1_000_000],
        ], Campaign::STATUS_ACTIVE);

        // A đang chạy, B mới duyệt chưa trả tiền.
        $lineA = $campaign->bookingLines()->where('owner_id', $a->owner_id)->firstOrFail();
        $lineA->update(['status' => 'active']);

        app(CancellationService::class)->cancelLine($lineA->fresh(), $this->buyer);

        $this->assertSame(
            Campaign::STATUS_APPROVED,
            $campaign->fresh()->status,
            'Không còn màn hình nào phát thì chiến dịch không thể vẫn là "đang chạy".'
        );
    }
}
