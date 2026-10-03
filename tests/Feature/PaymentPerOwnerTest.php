<?php

namespace Tests\Feature;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Thanh toán trực tiếp cho từng media owner (review mục 7).
 *
 * Hồ sơ đăng ký với Bộ Công Thương khai: "thanh toán trực tiếp giữa khách hàng
 * và nhà cung cấp dịch vụ quảng cáo; OOHX.NET hỗ trợ ghi nhận giao dịch và đối
 * soát". Nhưng trang thanh toán lại hiện tài khoản "CONG TY OOHX VIETNAM" với số
 * tài khoản giả 1234 5678 9012 cứng trong Blade — tức sàn đứng ra thu tiền, ngược
 * với những gì đã khai.
 *
 * Sau sửa: mỗi media owner một khối chuyển khoản riêng, số tiền tính theo phần
 * màn hình của owner đó.
 */
class PaymentPerOwnerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org  = Organization::create([
            'name' => 'Agency X', 'slug' => 'agency-x-' . uniqid(), 'type' => 'agency',
        ]);
        $this->user = User::factory()->create(['current_organization_id' => $this->org->id]);
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->user->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);
    }

    private function makeOwner(string $name, bool $withBank = true): Owner
    {
        return Owner::factory()->create([
            'name'   => $name,
            'slug'   => Str::slug($name) . '-' . uniqid(),
            'status' => 'active',
            'bank_name'           => $withBank ? 'Vietcombank (VCB)' : null,
            'bank_account_number' => $withBank ? '0011001234567' : null,
            'bank_account_name'   => $withBank ? mb_strtoupper($name) : null,
        ]);
    }

    private function makeCampaign(array $ownerCosts): Campaign
    {
        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->user->id,
            'code'            => 'CPN-' . uniqid(),
            'name'            => 'Chiến dịch thử',
            'start_date'      => now(),
            'end_date'        => now()->addMonth(),
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        foreach ($ownerCosts as $ownerId => $cost) {
            $site   = Site::factory()->create(['owner_id' => $ownerId]);
            $screen = Screen::factory()->create(['owner_id' => $ownerId, 'site_id' => $site->id]);

            BookingLine::create([
                'campaign_id'    => $campaign->id,
                'screen_id'      => $screen->id,
                'owner_id'       => $ownerId,
                'start_date'     => now(),
                'end_date'       => now()->addMonth(),
                'status'         => 'approved',
                'estimated_cost' => $cost,
            ]);
        }

        return $campaign;
    }

    // ── Chia tiền theo owner ─────────────────────────────────────────────────

    public function test_chia_so_tien_theo_tung_media_owner(): void
    {
        $a = $this->makeOwner('Kim Ngân ADV');
        $b = $this->makeOwner('Đại Phát Media');
        $campaign = $this->makeCampaign([$a->id => 100_000_000, $b->id => 50_000_000]);

        $rows = app(PaymentService::class)->breakdownByOwner($campaign);

        $this->assertCount(2, $rows);

        // Thuế suất lấy từ config, không mã hoá cứng: mức áp dụng do nghiệp vụ
        // chốt và đã đổi 10% → 8% ngày 27/09/2026.
        $vatRate = (float) config('pricing.vat_rate');

        $rowA = $rows->firstWhere(fn ($r) => $r['owner']->id === $a->id);
        $this->assertSame(100_000_000.0, $rowA['cost']);
        $this->assertSame(100_000_000.0 * $vatRate, $rowA['vat']);
        $this->assertSame(100_000_000.0 * (1 + $vatRate), $rowA['total']);
        $this->assertSame(100_000_000.0 * (1 + $vatRate), $rowA['remaining']);
        $this->assertFalse($rowA['is_paid']);
    }

    public function test_tong_cac_phan_bang_tong_campaign(): void
    {
        $a = $this->makeOwner('A');
        $b = $this->makeOwner('B');
        $campaign = $this->makeCampaign([$a->id => 30_000_000, $b->id => 70_000_000]);

        $svc  = app(PaymentService::class);
        $rows = $svc->breakdownByOwner($campaign);
        $summary = $svc->getSummary($campaign);

        $this->assertEqualsWithDelta(
            $summary['total_cost_vat'],
            $rows->sum('total'),
            0.01,
            'tổng chia theo owner phải khớp tổng campaign, nếu không đối soát sẽ lệch'
        );
    }

    public function test_thanh_toan_cho_owner_nay_khong_lam_owner_kia_thanh_da_tra(): void
    {
        $a = $this->makeOwner('A');
        $b = $this->makeOwner('B');
        $campaign = $this->makeCampaign([$a->id => 10_000_000, $b->id => 20_000_000]);

        // Hai điều đã đổi so với lúc viết test này, đều là đổi có chủ ý:
        //
        // 1. Số tiền do MÁY CHỦ tính. Không truyền gì thì nó lấy đúng phần còn
        //    nợ. Con số 11.000.000 cũ là 10 triệu + VAT 10% — mức thuế đã đổi
        //    thành 8% ngày 27/09, nên số cũ nay vượt phần còn nợ và bị chặn.
        // 2. Tạo khoản thanh toán KHÔNG còn nghĩa là đã trả. Phải xác nhận
        //    chuyển khoản thì mới tính là owner đã nhận tiền.
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $a->id);
        app(PaymentService::class)->confirmBankTransfer($payment);

        $rows = app(PaymentService::class)->breakdownByOwner($campaign->fresh());
        $rowA = $rows->firstWhere(fn ($r) => $r['owner']->id === $a->id);
        $rowB = $rows->firstWhere(fn ($r) => $r['owner']->id === $b->id);

        $this->assertTrue($rowA['is_paid']);
        $this->assertFalse($rowB['is_paid'], 'trả cho owner A không thể làm owner B thành đã nhận tiền');
        $this->assertSame(20_000_000.0 * (1 + (float) config('pricing.vat_rate')), $rowB['remaining']);
    }

    public function test_payment_ghi_nhan_dung_owner_nhan_tien(): void
    {
        $a = $this->makeOwner('A');
        $campaign = $this->makeCampaign([$a->id => 10_000_000]);

        // Không truyền số tiền: máy chủ tính phần còn nợ, gồm VAT hiện hành.
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $a->id);

        $this->assertSame($a->id, $payment->owner_id);
        $this->assertSame(
            (int) round(10_000_000 * (1 + (float) config('pricing.vat_rate'))),
            (int) round((float) $payment->amount),
        );
    }

    // ── Trang thanh toán ─────────────────────────────────────────────────────

    private function paymentUrl(Campaign $c): string
    {
        return 'http://' . config('domains.frontpage', 'oohx.net') . '/booking/' . $c->id . '/payment';
    }

    public function test_trang_thanh_toan_hien_tai_khoan_cua_media_owner(): void
    {
        $a = $this->makeOwner('Kim Ngân ADV');
        $campaign = $this->makeCampaign([$a->id => 10_000_000]);

        $response = $this->actingAs($this->user)->get($this->paymentUrl($campaign));

        $response->assertOk();
        $response->assertSee('Kim Ngân ADV');
        $response->assertSee('0011001234567');
    }

    public function test_trang_thanh_toan_khong_con_tai_khoan_gia_cua_san(): void
    {
        $a = $this->makeOwner('Kim Ngân ADV');
        $campaign = $this->makeCampaign([$a->id => 10_000_000]);

        $response = $this->actingAs($this->user)->get($this->paymentUrl($campaign));

        $response->assertDontSee('1234 5678 9012');
        $response->assertDontSee('CONG TY OOHX VIETNAM');
    }

    public function test_owner_chua_khai_tai_khoan_thi_noi_thang_ra(): void
    {
        $a = $this->makeOwner('Chưa Khai TK', withBank: false);
        $campaign = $this->makeCampaign([$a->id => 10_000_000]);

        $response = $this->actingAs($this->user)->get($this->paymentUrl($campaign));

        $response->assertOk();
        $response->assertSee('chưa cung cấp thông tin tài khoản nhận tiền');
    }

    public function test_khong_tick_dong_y_quy_che_thi_khong_xac_nhan_duoc(): void
    {
        $a = $this->makeOwner('A');
        $campaign = $this->makeCampaign([$a->id => 10_000_000]);

        $response = $this->actingAs($this->user)->post($this->paymentUrl($campaign), [
            'method'   => 'bank_transfer',
            'owner_id' => $a->id,
            'amount'   => 10_800_000,   // 10 triệu + VAT 8%
        ]);

        $response->assertSessionHasErrors('accept_terms');
        $this->assertSame(0, Payment::count());
    }

    public function test_khong_tra_duoc_cho_owner_khong_co_trong_campaign(): void
    {
        $a       = $this->makeOwner('A');
        $stranger = $this->makeOwner('Người lạ');
        $campaign = $this->makeCampaign([$a->id => 10_000_000]);

        // Phép kiểm này chuyển từ `abort(422)` trong controller sang lỗi của
        // trường `owner_id` trong `StorePaymentRequest`, để trang Blade và
        // `/api/v2` dùng chung một bộ luật (mốc 3 giai đoạn 5).
        //
        // Assert mạnh hơn trước: 422 trần chỉ nói "có gì sai", còn
        // `assertSessionHasErrors('owner_id')` nói sai Ở ĐÂU — và đó mới là
        // điều cần khóa, vì lỗi này là gán tiền của mình cho một owner không
        // có màn hình nào trong campaign.
        $this->actingAs($this->user)->post($this->paymentUrl($campaign), [
            'method'       => 'bank_transfer',
            'owner_id'     => $stranger->id,
            'amount'       => 10_800_000,
            'accept_terms' => '1',
        ])->assertSessionHasErrors('owner_id');

        $this->assertSame(0, Payment::count());
    }
}
