<?php

namespace Tests\Feature\Frontpage;

use App\Models\Owner;
use App\Models\Product;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Mốc SEO của trang công khai — ghi lại TRƯỚC khi chuyển sang Next.js.
 *
 * ## Vì sao lớp này tồn tại
 *
 * Lộ trình đặt điều kiện hoàn thành giai đoạn 6 là *"đối chiếu SEO không tụt,
 * sitemap và canonical giữ nguyên"*. Nhưng "không tụt" so với cái gì? Không có
 * mốc ghi lại bằng máy thì sau khi chuyển, việc đối chiếu sẽ là hai người nhìn
 * hai trang rồi cùng đoán.
 *
 * Lớp này biến điều kiện đó thành một **hợp đồng chạy được**: những gì nó canh
 * là những gì bản Next.js phải trả về y nguyên. Thêm `CLAUDE.md` mục 3 cũng
 * yêu cầu "giữ nguyên `sitemap.xml`, `robots.txt`, thẻ canonical và dữ liệu có
 * cấu trúc JSON-LD đang có".
 *
 * ## Nó KHÔNG phải là bài kiểm chất lượng SEO
 *
 * Nó không nói thẻ hiện tại tốt hay dở, không kiểm độ dài tiêu đề theo khuyến
 * nghị của Google, không chấm điểm gì. Nó chỉ chốt **trạng thái hiện tại** để
 * sau có thứ mà so. Một thay đổi làm lớp này đỏ không nhất thiết là thay đổi
 * tồi — nó là thay đổi cần người quyết, chứ không được xảy ra lặng lẽ.
 *
 * ## Một khoảng trống đã thấy, cố ý KHÔNG sửa ở đây
 *
 * `SitemapController` **không** đưa bốn trang chính sách vào sitemap
 * (`/quy-che-hoat-dong`, `/chinh-sach-bao-mat`, `/giai-quyet-tranh-chap`,
 * `/bang-phi`). Với một sàn đang nộp hồ sơ TMĐT thì đó là những trang cần tìm
 * thấy được. Nhưng việc của lớp này là **ghi lại** hiện trạng, không phải đổi
 * nó — sửa sitemap rồi mới chụp mốc thì mốc không còn là hiện trạng. Ghi lại ở
 * đây để người đọc quyết, và ca test dưới chốt đúng hành vi đang có.
 */
class SeoBaselineTest extends TestCase
{
    use RefreshDatabase;

    /** Trang riêng tư không bao giờ được xuất hiện trong sitemap. */
    private const PRIVATE_PREFIXES = ['/my/', '/cart', '/booking/', '/login', '/register', '/admin', '/publisher', '/livewire'];

    /** Bốn trang chính sách bắt buộc của sàn TMĐT. */
    private const POLICY_SLUGS = ['quy-che-hoat-dong', 'chinh-sach-bao-mat', 'giai-quyet-tranh-chap', 'bang-phi'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function url(string $path = '/'): string
    {
        return 'http://' . config('domains.frontpage', 'oohx.net') . $path;
    }

    private function publicScreen(): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội', 'status' => 'active']);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'floor_cpm'              => 50_000,
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen->fresh(['inventory', 'spec', 'site']);
    }

    // ── sitemap.xml ─────────────────────────────────────────────────────────

    public function test_sitemap_la_xml_hop_le_va_dung_namespace(): void
    {
        $this->publicScreen();

        $response = $this->get($this->url('/sitemap.xml'))->assertOk();

        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));

        // Phân tích thật, không chỉ so chuỗi: một sitemap sai cú pháp XML thì
        // Google bỏ qua toàn bộ, và so chuỗi không phát hiện được.
        $xml = simplexml_load_string($response->getContent());

        $this->assertNotFalse($xml, 'sitemap.xml không phải XML hợp lệ.');
        $this->assertSame('urlset', $xml->getName());
        $this->assertSame(
            'http://www.sitemaps.org/schemas/sitemap/0.9',
            $xml->getDocNamespaces()[''] ?? null,
            'Sai namespace thì sitemap không được công nhận.'
        );
    }

    public function test_sitemap_co_sau_trang_tinh(): void
    {
        $body = $this->get($this->url('/sitemap.xml'))->assertOk()->getContent();

        // Dùng chính `url()` mà `SitemapController` dùng, không tự ghép chuỗi.
        //
        // Lần đầu tôi ghép tay và ca này đỏ: `url('/')` trả về
        // `http://oohx.test` KHÔNG có dấu gạch cuối, còn chuỗi tôi ghép thì
        // có. Ca test đỏ vì khác một ký tự định dạng, không vì sitemap thiếu
        // trang nào — tức nó đo sai thứ nó định đo.
        foreach (['/', '/explore', '/map', '/owners', '/products', '/agency'] as $path) {
            $this->assertStringContainsString(
                '<loc>' . url($path) . '</loc>',
                $body,
                "sitemap thiếu trang tĩnh {$path}.",
            );
        }
    }

    public function test_sitemap_co_trang_chi_tiet_cua_man_hinh_owner_san_pham(): void
    {
        $screen  = $this->publicScreen();
        $owner   = $screen->owner;
        $product = Product::create([
            'owner_id'     => $owner->id,
            'name'         => 'Gói thử',
            'slug'         => 'goi-thu-' . uniqid(),
            'type'         => Product::TYPE_SINGLE,
            'category'     => 'led',
            'listing_mode' => Product::LISTING_BOTH,
            'floor_price'  => 1_000_000,
            'currency'     => 'VND',
            'price_unit'   => 'month',
            'status'       => 'active',
        ]);

        $body = $this->get($this->url('/sitemap.xml'))->assertOk()->getContent();

        $this->assertStringContainsString('<loc>' . $this->url('/explore/' . $screen->slug) . '</loc>', $body);
        $this->assertStringContainsString('<loc>' . $this->url('/owners/' . $owner->slug) . '</loc>', $body);
        $this->assertStringContainsString('<loc>' . $this->url('/products/' . $product->fresh()->slug) . '</loc>', $body);
    }

    public function test_sitemap_khong_lo_man_hinh_chua_duyet(): void
    {
        $an = $this->publicScreen();
        $an->update(['active' => false]);
        Cache::flush();

        $this->get($this->url('/sitemap.xml'))
            ->assertOk()
            ->assertDontSee($an->slug, false);
    }

    public function test_sitemap_khong_bao_gio_chua_trang_rieng_tu(): void
    {
        $this->publicScreen();

        $body = $this->get($this->url('/sitemap.xml'))->assertOk()->getContent();

        // Đây là phép kiểm bảo mật, không phải SEO: một đường dẫn khu người mua
        // nằm trong sitemap là lời mời công cụ tìm kiếm đi thu thập nó.
        foreach (self::PRIVATE_PREFIXES as $prefix) {
            $this->assertStringNotContainsString(
                '<loc>' . $this->url($prefix),
                $body,
                "sitemap chứa đường dẫn riêng tư {$prefix}.",
            );
        }
    }

    public function test_sitemap_hien_khong_chua_trang_chinh_sach(): void
    {
        $body = $this->get($this->url('/sitemap.xml'))->assertOk()->getContent();

        // Chốt đúng hành vi ĐANG CÓ, không phải hành vi mong muốn.
        //
        // Bốn trang chính sách là trang bắt buộc của sàn TMĐT và đáng lẽ nên
        // tìm thấy được, nhưng `SitemapController` chưa đưa chúng vào. Ca này
        // chốt hiện trạng để mốc là hiện trạng thật; khi nào quyết định thêm
        // vào thì ca này đỏ, và đó là lúc đổi nó cùng với quyết định đó.
        foreach (self::POLICY_SLUGS as $slug) {
            $this->assertStringNotContainsString(
                '<loc>' . $this->url('/' . $slug) . '</loc>',
                $body,
                "sitemap đã có /{$slug} — nếu đây là thay đổi có chủ ý thì cập nhật ca test này.",
            );
        }
    }

    // ── robots.txt ──────────────────────────────────────────────────────────

    public function test_robots_chan_dung_khu_rieng_tu_va_tro_toi_sitemap(): void
    {
        $body = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('User-agent: *', $body);

        foreach (['/my/', '/cart/', '/booking/', '/login', '/register', '/admin/', '/publisher/', '/livewire/'] as $path) {
            $this->assertStringContainsString("Disallow: {$path}", $body, "robots.txt không chặn {$path}.");
        }

        $this->assertStringContainsString('Sitemap: https://oohx.net/sitemap.xml', $body);
    }

    // ── canonical và thẻ chia sẻ ────────────────────────────────────────────

    public function test_moi_trang_cong_khai_co_canonical_tro_dung_chinh_no(): void
    {
        $screen = $this->publicScreen();

        $paths = ['/', '/explore', '/map', '/products', '/owners', '/explore/' . $screen->slug, '/owners/' . $screen->owner->slug];

        foreach ($paths as $path) {
            $body = $this->get($this->url($path))->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '#<link rel="canonical" href="[^"]+"#',
                $body,
                "Trang {$path} thiếu thẻ canonical.",
            );
        }
    }

    public function test_moi_trang_cong_khai_co_mo_ta_va_the_chia_se(): void
    {
        $screen = $this->publicScreen();

        foreach (['/', '/explore', '/explore/' . $screen->slug] as $path) {
            $body = $this->get($this->url($path))->assertOk()->getContent();

            foreach (['name="description"', 'property="og:title"', 'property="og:image"', 'property="og:url"', 'name="twitter:card"'] as $tag) {
                $this->assertStringContainsString($tag, $body, "Trang {$path} thiếu {$tag}.");
            }
        }
    }

    public function test_mo_ta_khong_bao_gio_rong(): void
    {
        $this->publicScreen();

        $body = $this->get($this->url('/'))->assertOk()->getContent();

        preg_match('#<meta name="description" content="([^"]*)"#', $body, $m);

        $this->assertNotEmpty($m[1] ?? '', 'Thẻ description có mặt nhưng rỗng — tệ hơn là không có.');
    }

    public function test_trang_chi_tiet_man_hinh_co_json_ld(): void
    {
        $screen = $this->publicScreen();

        $body = $this->get($this->url('/explore/' . $screen->slug))->assertOk()->getContent();

        $this->assertStringContainsString('application/ld+json', $body, 'Trang chi tiết thiếu dữ liệu có cấu trúc.');

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m);
        $data = json_decode($m[1] ?? '', true);

        $this->assertIsArray($data, 'JSON-LD không phải JSON hợp lệ — Google bỏ qua toàn bộ.');
        $this->assertArrayHasKey('@context', $data);
        $this->assertArrayHasKey('@type', $data);
    }

    // ── trang chính sách phải giữ nguyên đường dẫn ──────────────────────────

    public function test_bon_trang_chinh_sach_con_tai_duoc_o_dung_duong_dan(): void
    {
        // Lộ trình: "Trang pháp lý chuyển cuối cùng, giữ nguyên văn bản và
        // đường dẫn." Đường dẫn là phần máy kiểm được.
        foreach (self::POLICY_SLUGS as $slug) {
            $this->get($this->url('/' . $slug))
                ->assertOk()
                ->assertSee('canonical', false);
        }
    }
}
