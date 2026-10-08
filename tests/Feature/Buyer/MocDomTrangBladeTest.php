<?php

namespace Tests\Feature\Buyer;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nửa PHP của hợp đồng giữa trang Blade và phần JS của nó.
 *
 * ══ Vấn đề mà test này giải ══
 *
 * `/cart` và `/booking/{campaign}/payment` đã chuyển sang đọc `/api/v2` từ
 * trình duyệt. Phần JS vẽ ra giao diện được canh bởi `tests/js/*.test.mjs`,
 * chạy trong jsdom trên **đúng khối script sẽ lên production**.
 *
 * Nhưng jsdom cần một bộ khung DOM để chạy, và bộ khung đó không phải trang
 * thật. Nếu nó được viết tay trong test thì nó sẽ **xanh mãi** trong khi trang
 * thật đã đổi — đổi tên một thuộc tính `data-*` trong Blade là làm trang trắng
 * trên production mà không một test nào đỏ. Một bộ khung viết tay là một bản
 * sao, và bản sao nào cũng trôi.
 *
 * Nên danh sách móc nằm ở **một tệp dùng chung**, `tests/js/moc-dom.json`:
 *
 *  - phía JS dựng bộ khung TỪ tệp đó, nên script tìm thấy đúng những móc được
 *    khai;
 *  - test này đọc **cùng tệp đó** và đòi mọi móc phải có mặt trong HTML trang
 *    đã render.
 *
 * Kết quả: đổi tên một móc trong Blade mà quên chỗ khác là đỏ ở cả hai phía —
 * ở đây vì trang thiếu móc, ở phía JS vì script không tìm thấy phần tử.
 */
class MocDomTrangBladeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['status' => 'active']);
        $this->buyer = User::factory()->create(['current_organization_id' => $this->org->id]);
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);
    }

    /** @return array<string, array{cauHinh: string, moc: array<int, string>}> */
    private function khaiBaoMoc(): array
    {
        $path = base_path('tests/js/moc-dom.json');

        $this->assertFileExists(
            $path,
            'Thiếu tests/js/moc-dom.json — đó là nguồn sự thật dùng chung giữa test PHP và test JS.'
        );

        $khai = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        // Bỏ **mọi** khóa chú thích. JSON không có cú pháp chú thích, nên tệp
        // đó dùng khóa mở đầu bằng `//` để giải thích từng trường — và chúng
        // không phải trang.
        $khai = array_filter(
            $khai,
            fn (string $khoa) => ! str_starts_with($khoa, '//'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertNotEmpty($khai, 'moc-dom.json không khai trang nào — test này sẽ xanh rỗng.');

        return $khai;
    }

    private function urlFrontpage(string $duong): string
    {
        return 'http://' . config('domains.frontpage', 'oohx.net') . $duong;
    }

    private function campaignDaDuyet(): Campaign
    {
        $owner = Owner::factory()->create([
            'name'   => 'Kim Ngân ADV',
            'slug'   => Str::slug('Kim Ngân ADV') . '-' . uniqid(),
            'status' => 'active',
            'bank_name'           => 'Vietcombank (VCB)',
            'bank_account_number' => '0011001234567',
            'bank_account_name'   => 'CONG TY TNHH KIM NGAN',
        ]);

        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'Chiến dịch thử',
            'start_date'      => now(),
            'end_date'        => now()->addMonth(),
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id]);

        BookingLine::create([
            'campaign_id'    => $campaign->id,
            'screen_id'      => $screen->id,
            'owner_id'       => $owner->id,
            'start_date'     => now(),
            'end_date'       => now()->addMonth(),
            'status'         => 'approved',
            'estimated_cost' => 10_000_000,
        ]);

        return $campaign;
    }

    /** @return array<string, string> đường dẫn tệp blade → HTML đã render */
    private function htmlCuaCacTrang(): array
    {
        $campaign = $this->campaignDaDuyet();

        return [
            'resources/views/buyer/cart.blade.php' => $this->actingAs($this->buyer)
                ->get($this->urlFrontpage('/cart'))
                ->assertOk()
                ->getContent(),

            'resources/views/buyer/booking/payment.blade.php' => $this->actingAs($this->buyer)
                ->get($this->urlFrontpage('/booking/' . $campaign->id . '/payment'))
                ->assertOk()
                ->getContent(),

            'resources/views/buyer/dashboard/campaign-detail.blade.php' => $this->actingAs($this->buyer)
                ->get($this->urlFrontpage('/my/campaigns/' . $campaign->id))
                ->assertOk()
                ->getContent(),
        ];
    }

    /**
     * Lối "Đăng nhập lại" phải có mặt — ở đúng chỗ mỗi trang khai.
     *
     * ══ Vì sao cần canh ══
     *
     * Ba trang này đều xử 401 bằng cách **nói thẳng ra**, không
     * `location.reload()`. Lý do: trang đi qua guard `web`, API đi qua
     * `auth:sanctum` phía sau `EnsureFrontendRequestsAreStateful`, và lớp sau
     * chỉ bật khi `Referer`/`Origin` khớp `sanctum.stateful` — nên có thế phiên
     * web còn hợp lệ (trang dựng được) mà API vẫn 401. Nạp lại là vòng lặp
     * không lối ra.
     *
     * Nhưng "nói thẳng ra" chỉ có ích nếu người dùng có chỗ bấm để thoát khỏi
     * tình trạng đó. Thiếu cái link ấy thì thông báo lỗi là một ngõ cụt.
     *
     * ══ Vì sao chỉ canh MỘT PHẦN các trang ở đây ══
     *
     * Hai trang đặt link trong markup; trang thanh toán thì **script vẽ nó**
     * khi nhận 401. Phép kiểm này đọc HTML trang thật nên chỉ thấy loại thứ
     * nhất. Loại thứ hai được canh ở phía JS — tệp `tests/js/thanh-toan.test.mjs`
     * trả 401 rồi tìm đúng thẻ `a` đó.
     *
     * Cờ `linkDangNhapTrongMarkup` trong `moc-dom.json` nói mỗi trang thuộc
     * loại nào. Nó có để chỗ canh là một **quyết định được ghi lại**, không
     * phải một chỗ im lặng bỏ qua: đổi một trang từ `false` sang `true` mà
     * quên thêm link thì phép kiểm này đỏ.
     */
    public function test_trang_khai_co_link_dang_nhap_trong_markup_thi_phai_co_that(): void
    {
        $khai = $this->khaiBaoMoc();
        $html = $this->htmlCuaCacTrang();
        $daKiem = 0;

        foreach ($khai as $trang => $dinhNghia) {
            $this->assertArrayHasKey(
                'linkDangNhapTrongMarkup',
                $dinhNghia,
                "Trang \"{$trang}\" chưa khai `linkDangNhapTrongMarkup`. Phải nói rõ lối "
                . '"Đăng nhập lại" nằm trong markup hay do script vẽ, để biết canh ở phía nào.'
            );

            if ($dinhNghia['linkDangNhapTrongMarkup'] !== true) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '#<a[^>]+href="[^"]*/login"#',
                $html[$trang],
                "Trang \"{$trang}\" khai có lối \"Đăng nhập lại\" trong markup nhưng không "
                . 'tìm thấy. Trang này xử 401 bằng cách nói thẳng ra thay vì nạp lại; '
                . 'thiếu lối thoát thì thông báo lỗi là ngõ cụt.'
            );

            $daKiem++;
        }

        $this->assertGreaterThan(0, $daKiem, 'không trang nào được kiểm — phép kiểm này đang rỗng');
    }

    public function test_moi_moc_dom_duoc_khai_deu_co_mat_trong_trang_that(): void
    {
        $khai = $this->khaiBaoMoc();
        $html = $this->htmlCuaCacTrang();

        foreach ($khai as $trang => $dinhNghia) {
            $this->assertArrayHasKey(
                $trang,
                $html,
                "moc-dom.json khai trang \"{$trang}\" nhưng test này không render nó. "
                . 'Thêm nó vào `htmlCuaCacTrang()`, đừng để một trang được khai mà không ai kiểm.'
            );

            $this->assertStringContainsString(
                $dinhNghia['cauHinh'],
                $html[$trang],
                "Trang \"{$trang}\" không còn khối cấu hình [{$dinhNghia['cauHinh']}]. "
                . 'Phần JS đọc cấu hình từ đó; thiếu nó là trang trắng.'
            );

            foreach ($dinhNghia['moc'] as $moc) {
                $this->assertStringContainsString(
                    $moc,
                    $html[$trang],
                    "Trang \"{$trang}\" thiếu móc [{$moc}]. Phần JS gọi "
                    . "querySelector('[{$moc}]') và sẽ vỡ trên production. "
                    . 'Sửa tên ở cả Blade lẫn tests/js/moc-dom.json.'
                );
            }
        }
    }

    /**
     * Mọi trang được khai đều phải được render ở đây.
     *
     * Phép kiểm trên đã đòi điều đó theo một chiều. Chiều này canh chiều ngược:
     * thêm một trang vào `htmlCuaCacTrang()` mà quên khai móc của nó trong
     * `moc-dom.json` thì phía JS không có gì để dựng bộ khung, và trang đó
     * không được canh — nhưng không có gì đỏ. Nay có.
     */
    public function test_khong_render_trang_nao_ma_chua_khai_moc(): void
    {
        $khai = $this->khaiBaoMoc();

        foreach (array_keys($this->htmlCuaCacTrang()) as $trang) {
            $this->assertArrayHasKey(
                $trang,
                $khai,
                "Trang \"{$trang}\" được render ở đây nhưng chưa khai móc trong "
                . 'tests/js/moc-dom.json, nên phần JS của nó không được canh.'
            );
        }
    }

    /**
     * Mỗi trang có đúng MỘT khối script hành vi.
     *
     * `tests/js/bo-khung.mjs` trích khối đó bằng biểu thức chính quy và đòi
     * đúng một. Nếu trang có hai, bộ trích lấy sai khối hoặc ném lỗi — và lỗi
     * đó hiện ra ở phía JS dưới dạng một thông điệp về biểu thức, không phải về
     * trang. Nói ở đây thì đọc được ngay.
     */
    public function test_moi_trang_co_dung_mot_khoi_script_hanh_vi(): void
    {
        foreach (array_keys($this->khaiBaoMoc()) as $trang) {
            $nguon = file_get_contents(base_path($trang));

            $this->assertSame(
                1,
                preg_match_all('/<script>\n/', $nguon),
                "Tệp \"{$trang}\" phải có đúng một khối <script> không thuộc tính. "
                . 'Bộ trích ở tests/js/bo-khung.mjs dựa vào đúng điều đó.'
            );

            // Giá trị từ PHP phải đi qua khối cấu hình json, không nhúng thẳng
            // vào JS: nhúng thẳng là mã thật phụ thuộc PHP, và bản trích ra để
            // test không còn là mã đó nữa.
            $than = preg_split('/<script>\n/', $nguon)[1] ?? '';
            $than = explode('</script>', $than)[0];

            foreach (['{{', '{!!'] as $cuPhap) {
                $this->assertStringNotContainsString(
                    $cuPhap,
                    $than,
                    "Khối script của \"{$trang}\" chứa cú pháp echo \"{$cuPhap}\" của Blade."
                );
            }
        }
    }
}
