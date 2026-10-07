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
 *  1. Endpoint chính sách danh sách trả **siêu dữ liệu**; endpoint chi tiết
 *     trả thêm `body_html`, và `body_html` đó phải là ĐÚNG phần thân trang
 *     Blade phát ra — văn bản pháp lý chỉ có một nguồn.
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
            // Dùng hằng số của model, không viết chuỗi tay: `status` là cột
            // enum, và một giá trị lạ cho "Data truncated" chứ không cho lỗi
            // nói rõ sai ở đâu. Lần đầu tôi viết 'received' và mất một vòng CI.
            'status'            => PublicReflection::STATUS_PENDING,
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

    public function test_danh_sach_khong_mang_theo_than_van_ban(): void
    {
        $body = $this->getJson('/api/v2/policies')->assertOk()->getContent();

        // Đây KHÔNG còn là luật "nội dung không được đi qua API" — luật đó đã
        // bỏ, và `GET /api/v2/policies/{slug}` trả `body_html`. Đây là luật về
        // kích cỡ response: danh sách phục vụ liên kết chân trang, nên nhét thân
        // của bốn văn bản vào đó là phát hàng trăm dòng HTML cho mọi lần render
        // chân trang.
        //
        // Lý do "một nguồn" vẫn được canh, nhưng ở chỗ khác:
        // `test_than_van_ban_la_dung_phan_than_trang_blade_phat_ra`.
        foreach (['content', 'body_html', 'html'] as $khong) {
            $this->assertStringNotContainsString(
                '"' . $khong . '"',
                $body,
                "Danh sách chính sách đang trả trường \"{$khong}\" — thân văn bản thuộc endpoint chi tiết.",
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

    // ── Chi tiết một trang chính sách: thân văn bản ─────────────────────────

    /**
     * Các slug lấy từ config, không liệt kê tay — thêm một trang chính sách mà
     * quên khóa `body` thì nhóm test này phải đỏ ngay.
     *
     * Vì sao là hàm chứ không phải `#[DataProvider]`: data provider chạy TRƯỚC
     * khi Laravel boot, nên `config()` chưa có gì. Và mỗi case của provider
     * kéo theo một lượt `RefreshDatabase` — bốn lượt migrate MySQL cho một
     * phép kiểm không dùng tới CSDL.
     */
    private function slugChinhSach(): array
    {
        return array_keys(config('policies.pages', []));
    }

    /**
     * Phép kiểm QUAN TRỌNG NHẤT của nhóm này.
     *
     * Nó so `body_html` với HTML trang Blade thật sự phát ra. Nếu ai sau này
     * cho endpoint tự dựng văn bản — đọc một partial khác, hay một bảng CSDL —
     * test này đỏ. Không có nó thì "một nguồn" chỉ là một câu trong docblock.
     *
     * So cả chuỗi, không so vài từ khoá: một chỗ khác nhau trong văn bản pháp
     * lý mà lọt lưới vì test chỉ canh ba chữ thì tệ hơn là không có test.
     */
    public function test_than_van_ban_la_dung_phan_than_trang_blade_phat_ra(): void
    {
        foreach ($this->slugChinhSach() as $slug) {
            $than = $this->getJson("/api/v2/policies/{$slug}")
                ->assertOk()
                ->json('data.body_html');

            $this->assertNotEmpty($than, "Trang {$slug} trả thân rỗng.");

            $trangBlade = $this->get('/' . $slug)->assertOk()->getContent();

            $this->assertStringContainsString(
                trim($than),
                $trangBlade,
                "Thân `body_html` của {$slug} không có trong HTML trang Blade — hai bên đã trôi khỏi nhau.",
            );
        }
    }

    public function test_moi_trang_co_khoa_body_trong_config(): void
    {
        foreach ($this->slugChinhSach() as $slug) {
            // Thiếu khóa `body` thì `policy()` nổ, không trả trang trống. Canh
            // ở đây để lỗi chỉ đúng chỗ: config, không phải controller.
            $this->assertNotEmpty(
                config("policies.pages.{$slug}.body"),
                "Trang {$slug} thiếu khóa `body` trong config/policies.php.",
            );
        }
    }

    public function test_than_van_ban_khong_chua_the_thuc_thi(): void
    {
        foreach ($this->slugChinhSach() as $slug) {
            $than = $this->getJson("/api/v2/policies/{$slug}")->assertOk()->json('data.body_html');

            // Bên tiêu thụ hiển thị chuỗi này như HTML
            // (`dangerouslySetInnerHTML`). Hôm nay nội dung do repo này viết và
            // đi qua git review, nên tin được. Nhưng nếu sau này ai đưa một
            // biến người dùng nhập vào partial thân, nó chảy thẳng ra trình
            // duyệt — và lúc đó không ai nhớ lại quyết định này nữa.
            foreach (['<script', '<iframe', 'javascript:', 'onerror=', 'onload='] as $cam) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $cam,
                    $than,
                    "Thân văn bản {$slug} chứa \"{$cam}\" — nó sẽ chảy thẳng vào dangerouslySetInnerHTML.",
                );
            }
        }
    }

    public function test_chi_tiet_khop_sieu_du_lieu_trong_config(): void
    {
        foreach ($this->slugChinhSach() as $slug) {
            $this->getJson("/api/v2/policies/{$slug}")
                ->assertOk()
                ->assertJsonPath('data.slug', $slug)
                ->assertJsonPath('data.title', config("policies.pages.{$slug}.title"))
                ->assertJsonPath('data.version', config("policies.pages.{$slug}.version"))
                ->assertJsonPath('data.effective_from', config("policies.pages.{$slug}.effective_from"))
                ->assertJsonPath('data.is_effective', ! empty(config("policies.pages.{$slug}.effective_from")))
                ->assertJsonPath('data.url', url('/' . $slug));
        }
    }

    public function test_slug_la_tra_404_dung_envelope(): void
    {
        $this->getJson('/api/v2/policies/khong-ton-tai')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found')
            ->assertJsonPath('code', 404)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_slug_khong_duoc_dung_lam_duong_dan_config(): void
    {
        // `config("policies.pages.{$slug}")` coi dấu chấm là dấu phân cấp, và
        // slug đến từ URL. `company` / `fees` / `trial_mode` nằm NGOÀI
        // `pages` nên không với tới được — nhưng `quy-che-hoat-dong.view` thì
        // với tới một giá trị CHUỖI bên trong một trang, và `$page['title']`
        // trên một chuỗi không phải là 404.
        foreach (['company', 'fees', 'trial_mode', 'quy-che-hoat-dong.view'] as $slug) {
            $response = $this->getJson('/api/v2/policies/' . $slug);

            $this->assertSame(
                404,
                $response->status(),
                "Slug \"{$slug}\" không ra 404 — đường dẫn config bị dùng làm slug.",
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

    public function test_tra_nhan_trang_thai_kem_ma(): void
    {
        foreach (array_keys(PublicReflection::STATUS_LABELS) as $trang_thai) {
            PublicReflection::query()->delete();
            $this->reflection(['published_at' => now(), 'status' => $trang_thai]);

            // Nhãn phải tới từ API, không để bên tiêu thụ tự tra. Blade dùng
            // `statusLabel()`; nếu Next phải chép bảng tra của riêng nó thì
            // thêm một trạng thái sẽ làm một trong hai bên hiện mã thô ra cho
            // người đọc — và không gì báo.
            $this->getJson('/api/v2/reflections')
                ->assertOk()
                ->assertJsonPath('data.0.status', $trang_thai)
                ->assertJsonPath(
                    'data.0.status_label',
                    PublicReflection::STATUS_LABELS[$trang_thai],
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
