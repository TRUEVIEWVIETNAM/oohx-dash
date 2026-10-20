<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\QuoteRequest;
use App\Models\Screen;
use App\Models\Site;
use App\Services\QuoteRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `POST /api/v2/quote-requests` — đường ghi công khai của lát 5.
 *
 * ══ Điều quan trọng nhất ở đây không phải "gửi được" ══
 *
 * Mà là **cái gì không rời khỏi máy chủ**. Endpoint mở cho người lạ (chủ dự
 * án chốt 19/10/2026 rằng khách chưa đăng nhập vẫn gửi được), nên nó cũng là
 * một đường để dò: gửi 50 slug rồi đọc phản hồi xem chúng thuộc về ai.
 *
 * Vì thế phản hồi chỉ có mã tra cứu và hai con số. Mấy ca dưới đây canh đúng
 * điều đó, và canh cả việc máy chủ **tra lại** tên màn hình thay vì tin tên
 * client gửi lên.
 */
class QuoteRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function manHinh(string $ten, ?Owner $owner = null, bool $congKhai = true): Screen
    {
        $owner = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'status'   => 'active',
        ]);

        return Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => $congKhai,
            'name'     => $ten,
        ]);
    }

    private function than(array $ghiDe = []): array
    {
        return array_merge([
            'contact_name'      => 'Nguyễn Văn A',
            'contact_email'     => 'a@brand.vn',
            'contact_phone'     => '0901234567',
            'contact_company'   => 'Brand Một',
            'dong_y_chinh_sach' => true,
        ], $ghiDe);
    }

    // ── Đường chính ──────────────────────────────────────────────────────────

    public function test_gui_duoc_khi_chua_dang_nhap(): void
    {
        $a = $this->manHinh('Màn hình A');
        $b = $this->manHinh('Màn hình B');

        $res = $this->postJson('/api/v2/quote-requests', $this->than([
            'slugs' => [$a->slug, $b->slug],
        ]));

        $res->assertStatus(201)
            ->assertJsonPath('data.so_vi_tri', 2)
            ->assertJsonPath('data.so_bi_bo', 0);

        $this->assertMatchesRegularExpression(
            '/^YCB-\d{6}-\d{4}$/',
            $res->json('data.code'),
            'Mã tra cứu phải cùng dạng với PA- và CPN-.',
        );

        $this->assertDatabaseCount('quote_requests', 1);
        $this->assertDatabaseCount('quote_request_items', 2);

        // Khách ẩn danh: không có tổ chức, và đó là hợp lệ.
        $this->assertNull(QuoteRequest::first()->organization_id);
    }

    public function test_may_chu_tra_lai_ten_man_hinh_khong_tin_client(): void
    {
        $a = $this->manHinh('Tên Thật Trên Sàn');

        $this->postJson('/api/v2/quote-requests', $this->than([
            'slugs' => [$a->slug],

            // Người gửi cố khai tên khác. Nếu máy chủ nhận, owner đọc được
            // một yêu cầu nói sai về chính kho của mình.
            'screen_names' => ['Tên Do Client Bịa'],
        ]))->assertStatus(201);

        $this->assertSame(
            'Tên Thật Trên Sàn',
            QuoteRequest::first()->items->first()->screen_name,
        );
    }

    public function test_item_mang_owner_tai_thoi_diem_gui(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $a     = $this->manHinh('Màn hình A', $owner);

        $this->postJson('/api/v2/quote-requests', $this->than(['slugs' => [$a->slug]]))
            ->assertStatus(201);

        $muc = QuoteRequest::first()->items->first();

        $this->assertSame($owner->id, $muc->owner_id);
        $this->assertSame('Hà Nội', $muc->city, 'Thành phố cũng là ảnh chụp lúc gửi.');
    }

    public function test_slug_khong_con_cong_khai_bi_bo_chu_khong_lam_hong_ca_yeu_cau(): void
    {
        $con = $this->manHinh('Còn mở');
        $go  = $this->manHinh('Đã gỡ', null, congKhai: false);

        $this->postJson('/api/v2/quote-requests', $this->than([
            'slugs' => [$con->slug, $go->slug, 'khong-ton-tai'],
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.so_vi_tri', 1)
            ->assertJsonPath('data.so_bi_bo', 2);
    }

    public function test_khong_slug_nao_hop_le_thi_tra_422(): void
    {
        $this->postJson('/api/v2/quote-requests', $this->than([
            'slugs' => ['khong-ton-tai', 'cung-khong'],
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'code', 'details'])
            ->assertJsonPath('error', 'khong_con_vi_tri');

        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_slug_trung_chi_vao_mot_lan(): void
    {
        $a = $this->manHinh('Màn hình A');

        $this->postJson('/api/v2/quote-requests', $this->than([
            'slugs' => [$a->slug, $a->slug, $a->slug],
        ]))->assertStatus(201);

        $this->assertDatabaseCount('quote_request_items', 1);
    }

    // ── Phản hồi không nói gì về owner ───────────────────────────────────────

    public function test_phan_hoi_khong_lo_media_owner(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'name' => 'Owner Bí Mật']);
        $a     = $this->manHinh('Màn hình A', $owner);

        $res = $this->postJson('/api/v2/quote-requests', $this->than(['slugs' => [$a->slug]]));

        $res->assertStatus(201)
            ->assertDontSee('Owner Bí Mật')
            ->assertDontSee($owner->id)
            ->assertDontSee($a->id);

        $this->assertSame(
            ['code', 'so_vi_tri', 'so_bi_bo', 'message'],
            array_keys($res->json('data')),
            'Phản hồi mở cho người lạ chỉ được có đúng bốn khóa này. Thêm một '
                . 'khóa về kho là biến nó thành đường tra quan hệ sở hữu.',
        );
    }

    // ── Validate ─────────────────────────────────────────────────────────────

    public function test_thieu_dong_y_chinh_sach_thi_tu_choi(): void
    {
        $a = $this->manHinh('Màn hình A');

        $this->postJson('/api/v2/quote-requests', $this->than([
            'slugs'             => [$a->slug],
            'dong_y_chinh_sach' => false,
        ]))->assertStatus(422);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_o_moi_an_duoc_dien_thi_tu_choi(): void
    {
        $a = $this->manHinh('Màn hình A');

        $this->postJson('/api/v2/quote-requests', $this->than([
            'slugs'   => [$a->slug],
            'website' => 'http://spam.example',
        ]))->assertStatus(422);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_danh_sach_rong_thi_tu_choi(): void
    {
        $this->postJson('/api/v2/quote-requests', $this->than(['slugs' => []]))
            ->assertStatus(422);
    }

    public function test_qua_50_vi_tri_thi_tu_choi(): void
    {
        $a     = $this->manHinh('Màn hình A');
        $slugs = array_fill(0, QuoteRequestService::TOI_DA_MUC + 1, $a->slug);

        $this->postJson('/api/v2/quote-requests', $this->than(['slugs' => $slugs]))
            ->assertStatus(422);
    }

    public function test_ngay_ket_thuc_truoc_ngay_bat_dau_thi_tu_choi(): void
    {
        $a = $this->manHinh('Màn hình A');

        $this->postJson('/api/v2/quote-requests', $this->than([
            'slugs'          => [$a->slug],
            'campaign_start' => '2026-12-01',
            'campaign_end'   => '2026-11-01',
        ]))->assertStatus(422);
    }

    public function test_co_ngay_ket_thuc_ma_thieu_ngay_bat_dau_thi_tu_choi(): void
    {
        $a = $this->manHinh('Màn hình A');

        $res = $this->postJson('/api/v2/quote-requests', $this->than([
            'slugs'        => [$a->slug],
            'campaign_end' => '2026-11-01',
        ]))->assertStatus(422);

        // `/api/v2` trả envelope `{error, message, code, details[]}` chứ không
        // phải `{errors:{…}}` của Laravel, nên `assertJsonValidationErrors`
        // không đọc được — nó báo "không có lỗi validate nào" trên một phản
        // hồi 422. Đọc đúng hình dạng của dự án.
        $this->assertContains(
            'campaign_start',
            collect($res->json('details'))->pluck('field')->all(),
            'Cửa sổ chiến dịch chỉ có ngày kết thúc là một cửa sổ owner không '
                . 'đọc được.',
        );
    }

    // ── Dấu vết và đồng ý chính sách ─────────────────────────────────────────

    public function test_ghi_lai_ip_va_dong_y_chinh_sach(): void
    {
        $a = $this->manHinh('Màn hình A');

        $this->postJson('/api/v2/quote-requests', $this->than(['slugs' => [$a->slug]]))
            ->assertStatus(201);

        $yeuCau = QuoteRequest::first();

        $this->assertNotNull($yeuCau->submitted_ip);

        $this->assertDatabaseHas('policy_consents', [
            'policy_key' => 'privacy',
            'context'    => 'quote_request',
            'subject_id' => $yeuCau->id,
        ]);
    }

    public function test_moi_yeu_cau_bat_dau_o_trang_thai_cho_duyet(): void
    {
        $a = $this->manHinh('Màn hình A');

        $this->postJson('/api/v2/quote-requests', $this->than(['slugs' => [$a->slug]]))
            ->assertStatus(201);

        $yeuCau = QuoteRequest::first();

        $this->assertSame(QuoteRequest::STATUS_PENDING, $yeuCau->status);

        $this->assertFalse(
            $yeuCau->lienHeDaMo(),
            'Liên hệ người mua phải đóng cho tới khi quản trị duyệt — chủ dự '
                . 'án chốt 19/10/2026.',
        );
    }

    public function test_khong_dung_den_api_v1(): void
    {
        // `/api/v1` là hợp đồng với đối tác (CLAUDE.md mục 1). Đường mới của
        // v2 không được mở một cửa nào ở đó.
        //
        // Khẳng định là "không tạo được gì", không phải một mã trạng thái cụ
        // thể: đo được 405 chứ không 404, vì bộ định tuyến khớp URI này với
        // một route v1 khác method. Mã nào cũng được, miễn không có bản ghi
        // nào sinh ra — đó mới là thứ hợp đồng v1 bảo vệ.
        $res = $this->postJson('/api/v1/quote-requests', $this->than(['slugs' => ['x']]));

        $this->assertTrue(
            $res->status() >= 400,
            "POST tới /api/v1/quote-requests trả {$res->status()} — v1 nhận đường của v2.",
        );

        $this->assertDatabaseCount('quote_requests', 0);
    }
}
