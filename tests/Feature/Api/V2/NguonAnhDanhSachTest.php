<?php

namespace Tests\Feature\Api\V2;

use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `photo_source` trên DTO **danh sách** — lát 3 của `docs/PLAN-GHEP-MOCKUP.md`.
 *
 * ══ Lỗi tệp này canh ══
 *
 * Chuỗi ảnh dự phòng của `ScreenSummaryResource` có năm bậc, và chỉ bậc đầu là
 * ảnh của chính màn hình. Bốn bậc còn lại — ảnh site, ảnh network, bìa chủ sở
 * hữu, ảnh mặc định của loại điểm đặt — cho người mua xem một thứ **không phải**
 * mặt biển họ sắp thuê.
 *
 * DTO chi tiết nói ra chuyện đó từ 24/10/2026. DTO danh sách thì **không**, cho
 * tới 09/11/2026 — nên `/explore`, nơi người mua sàng kho, không có cách nào
 * nói. Đo trên production 08/11/2026: cả 104 vị trí dùng đúng hai tấm ảnh và cả
 * hai là `owner_cover`. Lưới 104 thẻ hiện ra như 104 vị trí đều có ảnh riêng.
 *
 * ══ Vì sao không chỉ canh "khoá có mặt" ══
 *
 * Một `'photo_source' => 'screen'` viết cứng cũng làm phép kiểm có-mặt xanh, và
 * nó là **đúng cái lỗi tệ nhất** ở đây: mọi thẻ đều tự khai là ảnh thật, tức
 * giao diện dán nhãn cho không vị trí nào. Nên mỗi ca dưới đây dựng một bậc
 * khác của chuỗi và đòi đúng mã của bậc đó.
 */
class NguonAnhDanhSachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Một màn hình công khai, với quyền kiểm soát từng bậc ảnh.
     *
     * Bốn tham số ứng đúng bốn bậc đầu của `Screen::displayPhotoWithSource()`:
     *
     *   1. `$anhMan`    → `spec.photos[0]`              → `screen`
     *   2. `$anhSite`   → `sites.banner`                → `site`
     *   3. `$anhNetwork`→ `networks.banner`, qua SITE   → `network`
     *   4. `$biaOwner`  → `owners.cover_url`            → `owner_cover`
     *
     * Bậc 5 (`venue_category` — `venue_categories.thumb`) không có tham số: nó
     * cần một bản ghi danh mục có ảnh, và `VenueCategorySeeder` quyết định ảnh
     * nào. Dựng tay một danh mục ở đây là dựng một thế giới không khớp sàn.
     *
     * ── Bản đầu của helper này SAI, ghi lại vì cái sai có ích ──
     *
     * Tôi viết `sites.photo_url`, `networks.photo_url`, và gắn network vào
     * **screen**. Cả ba đều không tồn tại: cột là `banner` (hai migration ngày
     * 11/04/2026), và chuỗi dự phòng đọc `$this->site?->network?->banner` —
     * network treo trên SITE, không trên màn hình. Tám ca đỏ cùng lúc với
     * `Unknown column 'photo_url'`.
     *
     * Bài học không phải "đọc kỹ hơn" mà là: fixture của một chuỗi dự phòng
     * phải dựng từ chính mã của chuỗi đó, không từ trí nhớ về hình dạng dữ
     * liệu. Cùng loại lỗi đã làm `LoaiHinhNhanKhopBoLocTest` xanh suốt trong
     * khi nhánh LED không bao giờ chạy được (07/11/2026).
     */
    private function manHinh(
        ?string $anhMan = null,
        ?string $anhSite = null,
        ?string $anhNetwork = null,
        ?string $biaOwner = null,
    ): Screen {
        $owner = Owner::factory()->create([
            'status'    => 'active',
            'cover_url' => $biaOwner,
        ]);

        $network = Network::factory()->create([
            'owner_id' => $owner->id,
            'banner'   => $anhNetwork,
        ]);

        $site = Site::factory()->create([
            'owner_id'   => $owner->id,
            'network_id' => $network->id,
            'status'     => 'active',
            'city'       => 'Hà Nội',
            'banner'     => $anhSite,
        ]);

        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenSpec::factory()->create([
            'screen_id' => $screen->id,
            'width_cm'  => 400,
            'height_cm' => 200,
            'photos'    => $anhMan !== null ? [$anhMan] : [],
        ]);

        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'io',
            'io_rate'       => 1_000_000,
            'io_rate_unit'  => 'month',
        ]);

        return $screen;
    }

    /** Bản ghi đầu tiên của `GET /api/v2/screens`. */
    private function the(): array
    {
        return $this->getJson('/api/v2/screens?per_page=10')->assertOk()->json('data.0');
    }

    public function test_dto_danh_sach_co_truong_nguon_anh(): void
    {
        $this->manHinh(anhMan: 'screens/that.jpg');

        $this->assertArrayHasKey('photo_source', $this->the());
    }

    public function test_anh_cua_chinh_man_hinh_khai_la_screen(): void
    {
        $this->manHinh(anhMan: 'screens/that.jpg');

        $the = $this->the();

        $this->assertSame('screen', $the['photo_source']);
        $this->assertNotNull($the['photo_url']);
    }

    public function test_bia_owner_khai_la_owner_cover(): void
    {
        // Đúng tình trạng production: không ảnh màn hình, không ảnh site,
        // không ảnh network — chỉ bìa owner.
        $this->manHinh(biaOwner: 'owners/covers/bia.webp');

        $the = $this->the();

        // Thẻ VẪN có ảnh, và đó chính là vấn đề: không có trường này thì
        // giao diện không phân biệt được nó với một ảnh thật.
        $this->assertNotNull($the['photo_url']);
        $this->assertSame('owner_cover', $the['photo_source']);
    }

    public function test_anh_site_khai_la_site(): void
    {
        $this->manHinh(anhSite: 'sites/khu.jpg');

        $this->assertSame('site', $this->the()['photo_source']);
    }

    /**
     * Bậc 3 — và nó đi qua SITE, không qua màn hình.
     *
     * Ca này canh đúng chỗ bản đầu của fixture làm sai: chuỗi dự phòng đọc
     * `$this->site?->network?->banner`. Một màn hình có `network_id` riêng mà
     * site không thuộc network nào thì bậc này **không** chạy — nên ca phải
     * dựng quan hệ thật, không chỉ dựng một bản ghi `Network` cạnh đó.
     */
    public function test_anh_network_khai_la_network_va_di_qua_site(): void
    {
        $this->manHinh(anhNetwork: 'networks/he-thong.jpg');

        $this->assertSame('network', $this->the()['photo_source']);
    }

    /**
     * Thứ tự bốn bậc, kiểm một lượt.
     *
     * Bảy ca trên mỗi ca dựng một bậc. Ca này dựng **cả bốn** rồi gỡ dần từ
     * trên xuống: nếu có ai đổi thứ tự trong `displayPhotoWithSource()` thì
     * các ca kia vẫn xanh (mỗi ca chỉ có một bậc khả dụng), còn ca này đỏ.
     */
    public function test_bon_bac_uu_tien_dung_thu_tu(): void
    {
        // Bốn màn hình cùng tồn tại, mỗi cái gỡ thêm một bậc từ trên xuống.
        //
        // KHÔNG xoá rồi dựng lại trong vòng lặp: `/api/v2/screens` đi qua
        // `Cache::remember`, nên lượt thứ hai sẽ đọc danh sách của lượt đầu và
        // ca này xanh vì một lý do không liên quan gì tới thứ tự bậc.
        $mong = [
            'screen'      => $this->manHinh('screens/that.jpg', 'sites/khu.jpg', 'networks/ht.jpg', 'owners/covers/bia.webp'),
            'site'        => $this->manHinh(null, 'sites/khu.jpg', 'networks/ht.jpg', 'owners/covers/bia.webp'),
            'network'     => $this->manHinh(null, null, 'networks/ht.jpg', 'owners/covers/bia.webp'),
            'owner_cover' => $this->manHinh(null, null, null, 'owners/covers/bia.webp'),
        ];

        $theo = collect($this->getJson('/api/v2/screens?per_page=50')->assertOk()->json('data'))
            ->keyBy('slug');

        $this->assertCount(4, $theo, 'Không phải cả bốn màn hình đều công khai.');

        foreach ($mong as $bac => $man) {
            $this->assertSame(
                $bac,
                $theo[$man->slug]['photo_source'],
                "Bậc `{$bac}` không thắng khi mọi bậc dưới nó cũng có ảnh.",
            );
        }
    }

    /**
     * Bậc 1 thắng bậc 4 — thứ tự chuỗi dự phòng, không phải thứ tự ngẫu nhiên.
     *
     * Ca này là ca duy nhất chứng minh trường không bị viết cứng: cùng một màn
     * hình có CẢ ảnh riêng và bìa owner thì nó phải khai `screen`.
     */
    public function test_co_ca_hai_thi_anh_rieng_thang(): void
    {
        $this->manHinh(anhMan: 'screens/that.jpg', biaOwner: 'owners/covers/bia.webp');

        $this->assertSame('screen', $this->the()['photo_source']);
    }

    public function test_khong_anh_nao_thi_ca_hai_khoa_null(): void
    {
        $this->manHinh();

        $the = $this->the();

        $this->assertNull($the['photo_url']);
        $this->assertNull($the['photo_source']);
    }

    /**
     * Danh sách và chi tiết phải nói CÙNG một bậc cho cùng một màn hình.
     *
     * Hai DTO tính nguồn ở hai phạm vi — danh sách trên MỘT ảnh hiển thị, chi
     * tiết trên cả thư viện — nên chúng có thể khác nhau về nguyên tắc. Ca này
     * canh trường hợp duy nhất mà khác nhau là một lỗi: màn hình chỉ có đúng
     * một ảnh. Lúc đó "ảnh hiển thị" và "cả thư viện" là cùng một tấm, và hai
     * trang nói hai chữ khác nhau về nó thì một trong hai đang nói sai.
     */
    public function test_danh_sach_va_chi_tiet_khong_noi_hai_bac_khac_nhau(): void
    {
        $man = $this->manHinh(biaOwner: 'owners/covers/bia.webp');

        $chiTiet = $this->getJson("/api/v2/screens/{$man->slug}")->assertOk()->json('data');

        $this->assertSame($this->the()['photo_source'], $chiTiet['photo_source']);
    }

    /**
     * Trường thêm vào KHÔNG kéo theo bề mặt mới.
     *
     * Mỗi trường thêm vào một DTO danh sách trắng là một chỗ `device_token`,
     * `revenue_share_pct` hay giá sàn nội bộ có thể lọt ra (CLAUDE.md §2).
     */
    public function test_khong_lo_truong_nhay_cam_nao(): void
    {
        $this->manHinh(biaOwner: 'owners/covers/bia.webp');

        $json = $this->getJson('/api/v2/screens?per_page=10')->assertOk()->getContent();

        foreach ([
            'device_token',
            'revenue_share_pct',
            'billing_info',
            'bank_',
            'tax_code',
            'business_license_path',

            // Giá sàn **nội bộ** của network, không phải `pricing.floor_cpm`.
            //
            // Bản đầu của ca này cấm luôn `floor_cpm` và đỏ ngay: DTO danh
            // sách trả `pricing.floor_cpm` một cách hợp lệ — đó là giá sàn
            // CÔNG KHAI của màn hình, và người mua được thấy nó. Thứ CLAUDE.md
            // §2 cấm là `networks.default_floor_cpm`: mức sàn nội bộ owner đặt
            // cho cả hệ thống, không phải con số niêm yết của một vị trí.
            //
            // Cấm sai một chuỗi ở đây không chỉ làm ca đỏ — nó dạy người đọc
            // sau rằng một trường hợp lệ là trường bị cấm.
            'default_floor_cpm',
        ] as $camKhong) {
            $this->assertStringNotContainsString($camKhong, $json);
        }
    }
}
