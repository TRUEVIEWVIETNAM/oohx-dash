<?php

namespace Tests\Feature\Api\V2;

use App\Models\PublicReflection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Giai đoạn 6 — `/api/v2/policies` và `/api/v2/reflections`.
 *
 * Đây là phần `/api/v2` cho những route của trang công khai không phải màn
 * hình hay sản phẩm.
 *
 * Bốn thứ test này canh:
 *
 *  1. Endpoint chính sách trả **siêu dữ liệu, không trả nội dung** — văn bản
 *     pháp lý chỉ có một nơi phát ra.
 *  2. Phản ánh chưa công bố **không** ra ngoài.
 *  3. Dữ liệu cá nhân người gửi **không bao giờ** ra ngoài.
 *  4. Đường ghi dùng chung bộ luật kiểm với trang Blade, kể cả bẫy mật.
 */
class PublicContentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function reflection(array $attributes = []): PublicReflection
    {
        return PublicReflection::create(array_merge([
            'code'              => 'PA-202610-' . substr(uniqid(), -4),
            'organization_name' => 'Hội Bảo vệ Người tiêu dùng',
            'subject'           => 'Phản ánh về một quảng cáo',
            'content'           => str_repeat('Nội dung phản ánh đủ dài. ', 3),
            'received_at'       => now(),
            'contact_name'      => 'Người Liên Hệ',
            'contact_email'     => 'lien-he-rieng-tu@example.com',
            'contact_phone'     => '0900000001',
            'internal_notes'    => 'ghi chú nội bộ không được lộ',
            'submitted_ip'      => '203.0.113.7',
            'status'            => 'received',
        ], $attributes));
    }

    // ── Trang chính sách: siêu dữ liệu, không nội dung ──────────────────────

    public function test_tra_dung_so_trang_chinh_sach_theo_config(): void
    {
        $response = $this->getJson('/api/v2/policies')->assertOk();

        $this->assertCount(count(config('policies.pages')), $response->json('data'));
    }

    public function test_moi_trang_co_url_tro_ve_trang_laravel(): void
    {
        $response = $this->getJson('/api/v2/policies')->assertOk();

        foreach ($response->json('data') as $page) {
            $this->assertSame(url('/' . $page['slug']), $page['url']);
            $this->assertNotEmpty($page['title']);
        }
    }

    public function test_noi_dung_van_ban_khong_di_qua_api(): void
    {
        $body = $this->getJson('/api/v2/policies')->assertOk()->getContent();

        // Văn bản pháp lý chỉ có MỘT nơi phát ra. API dựng lại nó thành HTML là
        // tạo đường render thứ hai cho cùng một văn bản, trong khi lộ trình yêu
        // cầu "giữ nguyên văn bản và đường dẫn" cho nhóm trang này.
        foreach (['content', 'body', 'html'] as $khong) {
            $this->assertStringNotContainsString(
                '"' . $khong . '"',
                $body,
                "Endpoint chính sách đang trả trường \"{$khong}\" — nội dung không được đi qua đây.",
            );
        }
    }

    public function test_noi_ro_trang_nao_chua_ban_hanh(): void
    {
        $response = $this->getJson('/api/v2/policies')->assertOk();

        foreach ($response->json('data') as $page) {
            // `effective_from` rỗng nghĩa là chưa ban hành — bản nháp. Bên tiêu
            // thụ phải thấy được điều đó chứ không hiển thị như một văn bản đã
            // có hiệu lực.
            $this->assertSame(
                ! empty($page['effective_from']),
                $page['is_effective'],
                "Trang {$page['slug']}: is_effective không khớp effective_from.",
            );
        }
    }

    // ── Phản ánh: chỉ bản đã công bố, không lộ dữ liệu cá nhân ──────────────

    public function test_chi_tra_phan_anh_da_cong_bo(): void
    {
        $daCongBo = $this->reflection(['published_at' => now()]);
        $chuaCongBo = $this->reflection(['published_at' => null]);

        $codes = collect($this->getJson('/api/v2/reflections')->assertOk()->json('data'))
            ->pluck('code')->all();

        $this->assertContains($daCongBo->code, $codes);
        $this->assertNotContains(
            $chuaCongBo->code,
            $codes,
            'Phản ánh chưa công bố lọt ra ngoài — nó có thể đang trong quá trình xử lý.',
        );
    }

    public function test_khong_bao_gio_lo_du_lieu_ca_nhan_nguoi_gui(): void
    {
        $this->reflection(['published_at' => now()]);

        $body = $this->getJson('/api/v2/reflections')->assertOk()->getContent();

        foreach ([
            'lien-he-rieng-tu@example.com',
            'Người Liên Hệ',
            '0900000001',
            'ghi chú nội bộ không được lộ',
            '203.0.113.7',
            'contact_email',
            'internal_notes',
            'submitted_ip',
        ] as $riengTu) {
            $this->assertStringNotContainsString(
                $riengTu,
                $body,
                "Danh sách phản ánh để lộ \"{$riengTu}\".",
            );
        }
    }

    public function test_phan_trang_co_gioi_han_cung(): void
    {
        $this->reflection(['published_at' => now()]);

        $this->getJson('/api/v2/reflections?per_page=10000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.max_per_page', 50);
    }

    // ── Gửi phản ánh: dùng chung luật kiểm với trang Blade ──────────────────

    public function test_gui_duoc_va_tra_ve_ma_tra_cuu(): void
    {
        $response = $this->postJson('/api/v2/reflections', [
            'organization_name' => 'Hội Bảo vệ Người tiêu dùng',
            'subject'           => 'Phản ánh thử',
            'content'           => str_repeat('Nội dung đủ dài để xử lý. ', 3),
            'contact_email'     => 'nguoi-gui@example.com',
        ])->assertStatus(201);

        $this->assertMatchesRegularExpression('/^PA-\d{6}-/', $response->json('data.code'));
        $this->assertSame(1, PublicReflection::count());
    }

    public function test_ban_ghi_moi_chua_duoc_cong_bo(): void
    {
        $this->postJson('/api/v2/reflections', [
            'organization_name' => 'Hội thử',
            'subject'           => 'Phản ánh thử',
            'content'           => str_repeat('Nội dung đủ dài để xử lý. ', 3),
            'contact_email'     => 'nguoi-gui@example.com',
        ])->assertStatus(201);

        // Người lạ gửi lên thì chưa ai duyệt. Tự công bố ngay là biến endpoint
        // này thành chỗ đăng nội dung tuỳ ý lên trang công khai.
        $this->assertNull(PublicReflection::first()->published_at);
        $this->assertSame(0, PublicReflection::published()->count());
    }

    public function test_noi_dung_qua_ngan_bi_tu_choi_voi_envelope_thong_nhat(): void
    {
        $this->postJson('/api/v2/reflections', [
            'organization_name' => 'Hội thử',
            'subject'           => 'Ngắn',
            'content'           => 'quá ngắn',
            'contact_email'     => 'nguoi-gui@example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('code', 422)
            ->assertJsonStructure(['error', 'message', 'code', 'details' => [['field', 'message']]]);

        $this->assertSame(0, PublicReflection::count());
    }

    public function test_bay_mat_chan_duoc_bot_qua_api(): void
    {
        // `StorePublicReflectionRequest` có `website` => `prohibited`. Dùng
        // chung bộ luật đó nghĩa là bẫy mật của trang web cũng bảo vệ API —
        // viết lại bộ luật thứ hai cho API là mở một cửa mà trang web không có.
        $this->postJson('/api/v2/reflections', [
            'organization_name' => 'Hội thử',
            'subject'           => 'Phản ánh thử',
            'content'           => str_repeat('Nội dung đủ dài để xử lý. ', 3),
            'contact_email'     => 'nguoi-gui@example.com',
            'website'           => 'http://spam.example.com',
        ])->assertStatus(422);

        $this->assertSame(0, PublicReflection::count());
    }

    public function test_duong_ghi_co_han_muc_chat_hon_nhom_api(): void
    {
        $payload = [
            'organization_name' => 'Hội thử',
            'subject'           => 'Phản ánh thử',
            'content'           => str_repeat('Nội dung đủ dài để xử lý. ', 3),
            'contact_email'     => 'nguoi-gui@example.com',
        ];

        // `throttle:5,60` — lần thứ sáu phải bị chặn. Đây là đường GHI mở cho
        // người lạ, nên hạn mức `api` (300/phút) là quá rộng.
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v2/reflections', $payload)->assertStatus(201);
        }

        $this->postJson('/api/v2/reflections', $payload)
            ->assertStatus(429)
            ->assertJsonPath('code', 429);

        $this->assertSame(5, PublicReflection::count());
    }
}
