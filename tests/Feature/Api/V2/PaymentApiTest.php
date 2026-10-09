<?php

namespace Tests\Feature\Api\V2;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\PolicyConsent;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Giai đoạn 5, mốc 3 — nhóm cần quyền: **thanh toán**.
 *
 * Đường ghi vào bảng tiền. Những thứ test này canh:
 *
 *  1. **Số tiền do máy chủ quyết.** `amount` là đề nghị; vượt công nợ thì 422,
 *     bỏ trống thì lấy đúng công nợ còn lại.
 *  2. **Công nợ theo TỪNG owner**, không phải tổng campaign. `is_fully_paid`
 *     chỉ đúng khi mọi owner đã đủ — đó là F07 trong `FINDINGS.md`.
 *  3. **Quyền `manage_payments`, không phải quyền xem.** Vai trò `viewer` xem
 *     được nhưng không trả được.
 *  4. **Nơi nhận tiền không đi qua đường NÀY.** Nó có đường riêng cần quyền
 *     `manage_payments` — xem `PaymentRecipientApiTest`. Đường này chỉ cần
 *     quyền xem, nên nó phải sạch `bank_*` và `tax_code` (CLAUDE.md mục 2).
 *  5. Khóa chống trùng chặn lần gửi lặp của cùng một biểu mẫu.
 *  6. Chỉ còn một cách trả tiền trong enum, vì chỉ một cách hoạt động.
 */
class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = $this->makeMember($this->org, OrganizationUser::ROLE_ADMIN);
    }

    private function makeMember(Organization $org, string $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $user->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'role'            => $role,
        ]);

        return $user;
    }

    private function owner(string $name): Owner
    {
        return Owner::factory()->create([
            'name'                => $name,
            'slug'                => Str::slug($name) . '-' . uniqid(),
            'status'              => 'active',
            'revenue_share_pct'   => 63.17,
            'tax_code'            => '0101234567',
            'bank_name'           => 'Vietcombank (VCB)',
            'bank_account_number' => '0011001234567',
            'bank_account_name'   => mb_strtoupper($name),
        ]);
    }

    /** @param array<string, int> $ownerCosts */
    private function campaign(array $ownerCosts, string $status = Campaign::STATUS_APPROVED): Campaign
    {
        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'Chiến dịch thử',
            'start_date'      => now(),
            'end_date'        => now()->addMonth(),
            'status'          => $status,
        ]);

        foreach ($ownerCosts as $ownerId => $cost) {
            $site   = Site::factory()->create(['owner_id' => $ownerId]);
            $screen = Screen::factory()->create([
                'owner_id'     => $ownerId,
                'site_id'      => $site->id,
                'device_token' => 'bi-mat-cua-thiet-bi',
            ]);

            BookingLine::create([
                'campaign_id'          => $campaign->id,
                'screen_id'            => $screen->id,
                'owner_id'             => $ownerId,
                'start_date'           => now(),
                'end_date'             => now()->addMonth(),
                'status'               => 'approved',
                'estimated_cost'       => $cost,
                'floor_cpm_at_booking' => 50_000,
            ]);
        }

        return $campaign;
    }

    private function url(Campaign $campaign): string
    {
        return '/api/v2/campaigns/' . $campaign->id . '/payments';
    }

    // ── Đọc công nợ ─────────────────────────────────────────────────────────

    public function test_chua_dang_nhap_thi_khong_xem_duoc(): void
    {
        $owner = $this->owner('Kim Ngân ADV');

        $this->getJson($this->url($this->campaign([$owner->id => 10_000_000])))
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_cong_no_tach_theo_tung_owner(): void
    {
        $a = $this->owner('Kim Ngân ADV');
        $b = $this->owner('Đại Phát Media');
        $campaign = $this->campaign([$a->id => 100_000_000, $b->id => 50_000_000]);

        $response = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonCount(2, 'data.by_owner');

        $vatRate = (float) config('pricing.vat_rate');
        $rows    = collect($response->json('data.by_owner'))->keyBy('owner.id');

        $this->assertSame(100_000_000, $rows[$a->id]['cost']);
        $this->assertSame((int) round(100_000_000 * (1 + $vatRate)), $rows[$a->id]['total']);
        $this->assertSame((int) round(50_000_000 * (1 + $vatRate)), $rows[$b->id]['total']);
    }

    public function test_tien_la_so_nguyen_o_moi_truong(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 1_000_001]);

        $data = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->json('data');

        // Giá 1.000.001 đồng từng làm công nợ về 0 trong khi `is_paid` vẫn
        // false, và chiến dịch kẹt vĩnh viễn (Codex R09). Số lẻ không được
        // vượt qua biên API.
        foreach (['total_cost', 'vat', 'total_cost_vat', 'total_paid', 'refunded', 'pending', 'remaining'] as $khoa) {
            $this->assertIsInt($data['summary'][$khoa], "summary.{$khoa} phải là số nguyên VND.");
        }

        foreach (['cost', 'vat', 'total', 'paid', 'refunded', 'pending', 'remaining'] as $khoa) {
            $this->assertIsInt($data['by_owner'][0][$khoa], "by_owner.{$khoa} phải là số nguyên VND.");
        }
    }

    public function test_tra_du_cho_mot_owner_khong_lam_ca_campaign_thanh_da_tra_du(): void
    {
        $a = $this->owner('Kim Ngân ADV');
        $b = $this->owner('Đại Phát Media');
        $campaign = $this->campaign([$a->id => 10_000_000, $b->id => 10_000_000]);

        // Trả đủ cho A, chưa đồng nào cho B.
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $a->id);
        app(PaymentService::class)->confirmBankTransfer($payment);

        $data = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign->fresh()))
            ->assertOk()
            ->json('data');

        // F07: tổng tiền che khuất công nợ từng owner. Một chiến dịch có thể
        // trông như đã trả đủ trong khi một owner chưa nhận đồng nào.
        $this->assertFalse($data['summary']['is_fully_paid']);

        $rows = collect($data['by_owner'])->keyBy('owner.id');
        $this->assertTrue($rows[$a->id]['is_paid']);
        $this->assertFalse($rows[$b->id]['is_paid']);
    }

    // ── DTO danh sách trắng ─────────────────────────────────────────────────

    public function test_khong_lo_thong_tin_ngan_hang_va_du_lieu_rieng_cua_owner(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);
        app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $owner->id);

        $response = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk();

        $body = $response->getContent();

        foreach ([
            // Người mua cần biết chuyển tiền cho AI, nhưng nơi nhận tiền là
            // việc của một đường riêng có phân quyền riêng:
            // `GET campaigns/{campaign}/payment-recipients`, cần quyền
            // `manage_payments`. Đường NÀY chỉ cần quyền xem, nên nó phải
            // sạch — kéo `bank_*` vào đây là cho vai trò `viewer` đọc được thứ
            // mà đường kia cố ý không cho họ đọc.
            'bank_name',
            'bank_account_number',
            'bank_account_name',
            '0011001234567',
            'Vietcombank',
            // CLAUDE.md mục 2 cấm tuyệt đối.
            'revenue_share_pct',
            'tax_code',
            'billing_info',
            'device_token',
            // Nội bộ của đường tiền.
            'idempotency_key',
            'gateway_ref',
            'metadata',
            'invoice_url',
            // Giá sàn nội bộ.
            'floor_cpm_at_booking',
        ] as $camKy) {
            $this->assertStringNotContainsString(
                $camKy,
                $body,
                "Nhóm thanh toán để lộ \"{$camKy}\".",
            );
        }

        // Nhưng tên owner thì phải có — không biết trả cho ai là không trả
        // được.
        //
        // Qua `assertJsonPath`, không qua `assertStringContainsString`:
        // Laravel escape ký tự ngoài ASCII, nên tên nằm trong body dưới dạng
        // `Kim Ngân ADV` và phép so chuỗi thô luôn trượt. Danh sách cấm ở
        // trên thì so chuỗi thô vẫn đúng, vì mọi mục trong đó là ASCII.
        $response->assertJsonPath('data.by_owner.0.owner.name', 'Kim Ngân ADV');
    }

    // ── Phân quyền ──────────────────────────────────────────────────────────

    public function test_campaign_cua_to_chuc_khac_tra_404(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $nguoiLa = $this->makeMember(
            Organization::factory()->create(['status' => 'active']),
            OrganizationUser::ROLE_ADMIN,
        );

        $this->actingAs($nguoiLa)->getJson($this->url($campaign))->assertStatus(404);
    }

    public function test_viewer_xem_duoc_cong_no_nhung_khong_xac_nhan_duoc_thanh_toan(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);
        $viewer   = $this->makeMember($this->org, OrganizationUser::ROLE_VIEWER);

        $this->actingAs($viewer)->getJson($this->url($campaign))->assertOk();

        // `pay` là quyền riêng (`manage_payments`), không phải quyền xem. Phép
        // so `organization_id` mà controller Blade từng dùng cho cả vai trò
        // `viewer` xác nhận thanh toán.
        $this->actingAs($viewer)->postJson($this->url($campaign), [
            'method'       => 'bank_transfer',
            'owner_id'     => $owner->id,
            'accept_terms' => true,
        ])->assertStatus(403);

        $this->assertSame(0, Payment::count());
    }

    // ── Số tiền do máy chủ quyết ────────────────────────────────────────────

    public function test_bo_trong_amount_thi_lay_dung_cong_no_con_lai(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'       => 'bank_transfer',
            'owner_id'     => $owner->id,
            'accept_terms' => true,
        ])->assertStatus(201);

        $vatRate = (float) config('pricing.vat_rate');

        $this->assertSame(
            (int) round(10_000_000 * (1 + $vatRate)),
            (int) round((float) Payment::firstOrFail()->amount),
        );
    }

    public function test_gui_so_tien_vuot_cong_no_thi_bi_tu_choi(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'       => 'bank_transfer',
            'owner_id'     => $owner->id,
            'amount'       => 999_999_999,
            'accept_terms' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 422);

        $this->assertSame(0, Payment::count());
    }

    public function test_owner_khong_co_man_hinh_trong_campaign_thi_bi_tu_choi_dung_truong(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $nguoiLa  = $this->owner('Owner Người Lạ');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        // Thiếu phép kiểm này thì một người mua gán tiền của mình cho một owner
        // bất kỳ, và công nợ của hai campaign khác nhau lẫn vào nhau.
        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'       => 'bank_transfer',
            'owner_id'     => $nguoiLa->id,
            'accept_terms' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('details.0.field', 'owner_id');

        $this->assertSame(0, Payment::count());
    }

    /**
     * Chiến dịch **đã hoàn thành** mà còn nợ thì vẫn trả được.
     *
     * ══ Lỗi ca này chống ══
     *
     * Công nợ tính từ các dòng đặt chỗ ở `approved|active|completed`, nhưng
     * cổng trạng thái trước đây chỉ cho `approved|active`. Nên một chiến dịch
     * đã chạy xong mà còn nợ thì `remaining` > 0 — trang vẫn hiện số tiền —
     * trong khi `can_pay` = false và đường ghi trả 422 **"Campaign chưa được
     * duyệt"**, một thông báo nói sai nguyên nhân.
     *
     * Tức sàn ghi nhận một khoản nợ mà không cho người mua trả nó. Một chiến
     * dịch kết thúc không xoá nghĩa vụ tiền với media owner.
     */
    public function test_campaign_da_hoan_thanh_ma_con_no_thi_van_tra_duoc(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_COMPLETED);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonPath('data.can_pay', true);

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'       => 'bank_transfer',
            'owner_id'     => $owner->id,
            'accept_terms' => true,
        ])->assertStatus(201);

        $this->assertSame(1, Payment::count());
    }

    /**
     * `cancelled` và `rejected` vẫn **ngoài** danh sách.
     *
     * Ở đó nghĩa vụ đi qua đường hoàn tiền, không qua đường thu. Thêm
     * `completed` không được kéo theo hai mã này.
     */
    public function test_campaign_bi_huy_hoac_tu_choi_thi_van_khong_tra_duoc(): void
    {
        foreach ([Campaign::STATUS_CANCELLED, Campaign::STATUS_REJECTED] as $ma) {
            $owner    = $this->owner('Owner ' . $ma);
            $campaign = $this->campaign([$owner->id => 10_000_000], $ma);

            $this->actingAs($this->buyer)->postJson($this->url($campaign), [
                'method'       => 'bank_transfer',
                'owner_id'     => $owner->id,
                'accept_terms' => true,
            ])->assertStatus(422);
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_campaign_chua_duyet_thi_chua_tra_duoc(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_DRAFT);

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'       => 'bank_transfer',
            'owner_id'     => $owner->id,
            'accept_terms' => true,
        ])->assertStatus(422);

        $this->assertSame(0, Payment::count());
    }

    // ── Hai đường vào, MỘT luật trạng thái ──────────────────────────────────
    //
    // Cổng trạng thái từng chỉ có ở `Api\V2\PaymentController::store()`.
    // `Buyer\PaymentController::process()` thì không, và không lớp nào dưới nó
    // bù lại: `CampaignPolicy::pay()` chỉ kiểm quyền `manage_payments`,
    // `StorePaymentRequest` không có luật trạng thái, `createPayment()` cũng
    // không có.
    //
    // Hai ca dưới đây dựng ĐÚNG thế mà phép kiểm số tiền không che được: dòng
    // booking ở `approved` nên `remaining` > 0. Trước khi đẩy cổng xuống
    // service, đường Blade GHI được khoản tiền vào CSDL ở cả hai ca.

    private function urlBlade(Campaign $campaign): string
    {
        return 'http://' . config('domains.frontpage', 'oohx.net')
            . '/booking/' . $campaign->id . '/payment';
    }

    public function test_duong_blade_cung_chan_campaign_chua_duyet(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_DRAFT);

        $this->actingAs($this->buyer)->post($this->urlBlade($campaign), [
            'method'        => 'bank_transfer',
            'owner_id'      => $owner->id,
            'accept_terms'  => '1',
            'payment_nonce' => (string) Str::uuid(),
        ])->assertStatus(422);

        $this->assertSame(0, Payment::count(), 'Đường Blade ghi khoản tiền cho campaign chưa duyệt.');
    }

    public function test_duong_blade_chan_campaign_bi_tu_choi_du_dong_da_duyet(): void
    {
        // Thế hở thật: campaign bị từ chối SAU khi vài dòng đã `approved`.
        // `remaining` > 0 nên thông báo "đã thanh toán đủ" không cứu được, và
        // trước khi sửa thì khoản tiền được ghi vào một chiến dịch sẽ không
        // chạy.
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_REJECTED);

        $this->actingAs($this->buyer)->post($this->urlBlade($campaign), [
            'method'        => 'bank_transfer',
            'owner_id'      => $owner->id,
            'accept_terms'  => '1',
            'payment_nonce' => (string) Str::uuid(),
        ])->assertStatus(422);

        $this->assertSame(0, Payment::count(), 'Đường Blade ghi khoản tiền cho campaign bị từ chối.');
    }

    public function test_hai_duong_dung_cung_mot_danh_sach_trang_thai(): void
    {
        // Chốt chặn cho chính việc vừa hợp nhất: nếu ai tách danh sách ra hai
        // bản lần nữa, ca này đỏ trước khi hai bản kịp lệch giá trị.
        $this->assertSame(
            \App\Services\PaymentService::PAYABLE_STATUSES,
            (new \ReflectionClass(\App\Http\Controllers\Api\V2\PaymentController::class))
                ->getConstant('PAYABLE_STATUSES'),
            'Controller v2 không còn dùng danh sách của PaymentService.'
        );

        // `completed` có trong danh sách vì công nợ được tính từ các dòng ở
        // `approved|active|completed`: một chiến dịch đã chạy xong mà còn nợ
        // thì vẫn phải trả được. `cancelled` và `rejected` thì không — ở đó
        // nghĩa vụ đi qua đường hoàn tiền.
        $this->assertSame(
            [Campaign::STATUS_APPROVED, Campaign::STATUS_ACTIVE, Campaign::STATUS_COMPLETED],
            \App\Services\PaymentService::PAYABLE_STATUSES
        );
    }

    // ── Hợp đồng API: slug thay cho khóa nội bộ ─────────────────────────────

    public function test_nhan_owner_slug_chu_khong_bat_client_doan_khoa_noi_bo(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'       => 'bank_transfer',
            'owner_slug'   => $owner->slug,
            'accept_terms' => true,
        ])->assertStatus(201);

        $this->assertSame($owner->id, Payment::firstOrFail()->owner_id);
    }

    public function test_owner_slug_la_thi_bao_loi_truong_owner_id(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'       => 'bank_transfer',
            'owner_slug'   => 'khong-ton-tai',
            'accept_terms' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('details.0.field', 'owner_id');

        $this->assertSame(0, Payment::count());
    }

    // ── Chống trùng, đồng ý, và enum trung thực ─────────────────────────────

    /**
     * Khoản đang chờ của cùng owner được dùng lại, dù có mã hay không.
     *
     * Đây là hành vi CÓ CHỦ Ý ở `PaymentService::createPayment()`, và nó đứng
     * trước phép kiểm mã: bấm hai lần khi chưa ai xác nhận thì nhận lại đúng
     * khoản đó, thay vì sinh thêm một dòng công nợ ma.
     *
     * Ghi lại thành test vì nó cũng là cái bẫy khi ĐỌC các test dưới: hai lần
     * gọi liên tiếp luôn ra một khoản, nên một test "chống trùng" chạy trên
     * trạng thái đang chờ sẽ xanh mà không chạm tới cơ chế mã lần nào.
     */
    /**
     * Mỗi khoản mang **chữ** đi cùng mã, cho cả năm trạng thái.
     *
     * ══ Lỗi ca này chống ══
     *
     * DTO trước đây chỉ trả `status`. Năm chữ tiếng Việt của enum đó tồn tại ở
     * **đúng một chỗ** trong cả repo: một bảng viết tay trong JS của
     * `buyer/booking/payment.blade.php`. Hệ quả là khối "Payments" ở trang xem
     * chiến dịch của khu quản trị không có gì để tra và hiện thẳng `pending`,
     * `completed`, `failed`.
     *
     * Nay chữ ở `Payment::STATUS_LABELS` và ra ngoài qua `status_label`.
     */
    public function test_moi_khoan_mang_chu_cua_trang_thai(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        foreach (array_keys(Payment::STATUS_LABELS) as $ma) {
            Payment::create([
                'campaign_id'     => $campaign->id,
                'organization_id' => $this->org->id,
                'owner_id'        => $owner->id,
                'amount'          => 1_000_000,
                'currency'        => 'VND',
                'method'          => 'bank_transfer',
                'status'          => $ma,
                'idempotency_key' => Str::random(16),
            ]);
        }

        $ds = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->json('data.payments');

        $this->assertCount(count(Payment::STATUS_LABELS), $ds);

        foreach ($ds as $khoan) {
            $this->assertSame(
                Payment::STATUS_LABELS[$khoan['status']],
                $khoan['status_label'],
                "khoản trạng thái `{$khoan['status']}` không mang chữ đúng",
            );

            // Cách trả cũng vậy. `bank_transfer` là mã có dấu gạch dưới, và
            // trang từng "làm đẹp" nó thành "Bank transfer".
            $this->assertSame(
                Payment::METHOD_LABELS[$khoan['method']],
                $khoan['method_label'],
                "cách trả `{$khoan['method']}` không mang chữ đúng",
            );
        }
    }

    public function test_khoan_dang_cho_cua_cung_owner_duoc_dung_lai(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $payload = [
            'method'       => 'bank_transfer',
            'owner_id'     => $owner->id,
            'amount'       => 1_000_000,
            'accept_terms' => true,
        ];

        $first  = $this->actingAs($this->buyer)->postJson($this->url($campaign), $payload)->assertStatus(201);
        $second = $this->actingAs($this->buyer)->postJson($this->url($campaign), $payload)->assertStatus(201);

        $this->assertSame(1, Payment::count());
        $this->assertSame($first->json('data.payment.id'), $second->json('data.payment.id'));
    }

    public function test_gui_lai_cung_mot_ma_sau_khi_da_xac_nhan_khong_tao_khoan_moi(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $payload = [
            'method'        => 'bank_transfer',
            'owner_id'      => $owner->id,
            'amount'        => 1_000_000,
            'payment_nonce' => 'ma-cua-lan-gui-nay',
            'accept_terms'  => true,
        ];

        $first = $this->actingAs($this->buyer)->postJson($this->url($campaign), $payload)->assertStatus(201);

        // Xác nhận để khoản đó không còn `pending` — nếu không thì nhánh "dùng
        // lại khoản đang chờ" trả lời thay, và test này không kiểm cơ chế mã.
        app(PaymentService::class)->confirmBankTransfer(Payment::firstOrFail());

        $second = $this->actingAs($this->buyer)->postJson($this->url($campaign), $payload)->assertStatus(201);

        $this->assertSame(1, Payment::count(), 'Gửi lại cùng một mã không được tạo khoản thứ hai.');
        $this->assertSame($first->json('data.payment.id'), $second->json('data.payment.id'));
    }

    public function test_tra_mot_phan_roi_tra_not_bang_ma_khac_thi_tao_duoc_khoan_moi(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'        => 'bank_transfer',
            'owner_id'      => $owner->id,
            'amount'        => 1_000_000,
            'payment_nonce' => 'lan-mot',
            'accept_terms'  => true,
        ])->assertStatus(201);

        app(PaymentService::class)->confirmBankTransfer(Payment::firstOrFail());

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'        => 'bank_transfer',
            'owner_id'      => $owner->id,
            'amount'        => 1_000_000,
            'payment_nonce' => 'lan-hai',
            'accept_terms'  => true,
        ])->assertStatus(201);

        // Đây là Codex R07: khóa chống trùng dựng từ mã của LẦN GỬI, không từ
        // token phiên. Token phiên không đổi giữa hai lần trả, nên lần thứ hai
        // sẽ nhận lại khoản cũ đã hoàn tất và người mua không trả nốt được.
        $this->assertSame(2, Payment::count());
    }

    public function test_thieu_o_dong_y_thi_khong_tra_duoc(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'   => 'bank_transfer',
            'owner_id' => $owner->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('details.0.field', 'accept_terms');

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, PolicyConsent::count());
    }

    public function test_tra_thanh_cong_thi_ghi_ban_ghi_dong_y(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)->postJson($this->url($campaign), [
            'method'       => 'bank_transfer',
            'owner_id'     => $owner->id,
            'accept_terms' => true,
        ])->assertStatus(201);

        $this->assertGreaterThan(
            0,
            PolicyConsent::where('subject_id', $campaign->id)->count(),
            'Xác nhận thanh toán qua API phải ghi bản ghi đồng ý như trang Blade.',
        );
    }

    public function test_chi_co_mot_cach_tra_tien_trong_enum(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        // VNPay và MoMo chưa có đường chạy nào. Bản cũ cho chúng qua validate
        // rồi mới từ chối trong controller, tức đặc tả nói có ba cách trả tiền
        // trong khi chỉ một cách hoạt động (CLAUDE.md mục 8).
        foreach (['vnpay', 'momo'] as $chuaCo) {
            $this->actingAs($this->buyer)->postJson($this->url($campaign), [
                'method'       => $chuaCo,
                'owner_id'     => $owner->id,
                'accept_terms' => true,
            ])
                ->assertStatus(422)
                ->assertJsonPath('details.0.field', 'method');
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_can_pay_do_may_chu_tra_loi(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonPath('data.can_pay', true);

        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $owner->id);
        app(PaymentService::class)->confirmBankTransfer($payment);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign->fresh()))
            ->assertOk()
            ->assertJsonPath('data.can_pay', false);
    }
}
