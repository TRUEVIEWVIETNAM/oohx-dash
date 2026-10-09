<?php

namespace Tests\Feature;

use App\Filament\Publisher\Resources\BookingInboxResource;
use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Creative;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * Nhãn trạng thái: **một định nghĩa cho mỗi enum**.
 *
 * ══ Vấn đề đã có ══
 *
 * Chữ tiếng Việt cho trạng thái chiến dịch từng được chép lại ở năm chỗ: hai
 * khối `@php` trong Blade khu người mua, một `match` và hai `options()` trong
 * Filament. Năm bản chép đã lệch nhau thật, theo ba cách khác nhau:
 *
 *  1. Hộp thư media owner: bảng `match` **thiếu `paused`**, trong khi truy vấn
 *     của chính hộp thư đó gồm `paused` — nên một chiến dịch tạm dừng hiện ra
 *     `paused` nguyên văn tiếng Anh.
 *  2. Bảng quản trị sàn: cột trạng thái **không có nhãn chữ nào**, nên nó hiện
 *     `pending_approval` trong khi cùng trạng thái đó ở hộp thư hiện
 *     "Chờ duyệt".
 *  3. Trang chi tiết chiến dịch: dùng **một** bảng chữ cho cả chiến dịch và
 *     dòng đặt chỗ. Hai enum đó khác nhau ở đúng một mã — `pending_approval`
 *     so với `pending` — nên dòng `pending` hiện ra nguyên văn tiếng Anh.
 *
 * ══ Những gì tệp này canh ══
 *
 * Không canh "chữ nào đúng" — đó là việc nghiệp vụ. Canh **tính đầy đủ** và
 * **tính nhất quán**: mọi mã trạng thái có trong enum phải có chữ, và mọi danh
 * sách trạng thái dùng ở giao diện phải khớp với danh sách mà truy vấn tương
 * ứng cho qua.
 */
class NhanTrangThaiMotNoiTest extends TestCase
{
    use RefreshDatabase;

    // ── Tính đầy đủ ─────────────────────────────────────────────────────────

    /**
     * Mọi hằng `STATUS_*` của `Campaign` phải có chữ.
     *
     * Đây là phép kiểm quan trọng nhất của tệp: thêm một trạng thái mới mà quên
     * chữ thì giao diện hiện mã tiếng Anh, và không có gì đỏ — đúng cách
     * `paused` đã lọt qua.
     */
    public function test_moi_trang_thai_campaign_deu_co_chu(): void
    {
        $hang = collect((new ReflectionClass(Campaign::class))->getConstants())
            ->filter(fn ($v, $k) => str_starts_with($k, 'STATUS_') && is_string($v))
            ->values();

        $this->assertCount(8, $hang, 'đổi số trạng thái thì sửa cả test này cùng lượt');

        foreach ($hang as $ma) {
            $this->assertArrayHasKey(
                $ma,
                Campaign::STATUS_LABELS,
                "Trạng thái \"{$ma}\" không có chữ trong `Campaign::STATUS_LABELS`, "
                . 'nên giao diện sẽ hiện mã tiếng Anh.'
            );
            $this->assertNotSame($ma, Campaign::STATUS_LABELS[$ma], "Chữ của \"{$ma}\" vẫn là chính nó.");
        }
    }

    /**
     * Mọi giá trị trong enum `booking_lines.status` phải có chữ.
     *
     * `BookingLine` không có hằng `STATUS_*`, nên nguồn sự thật là enum của
     * CSDL. Đọc thẳng từ `information_schema` thay vì chép tay danh sách: chép
     * tay là lại thêm một bản chép nữa, đúng thứ tệp này tồn tại để chặn.
     */
    public function test_moi_trang_thai_dong_dat_cho_deu_co_chu(): void
    {
        $cot = \DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['booking_lines', 'status'],
        );

        $this->assertNotNull($cot, 'không đọc được enum của booking_lines.status');

        preg_match_all("/'([^']+)'/", $cot->t, $khop);
        $enum = $khop[1];

        $this->assertNotEmpty($enum);

        foreach ($enum as $ma) {
            $this->assertArrayHasKey(
                $ma,
                BookingLine::STATUS_LABELS,
                "Trạng thái dòng \"{$ma}\" có trong enum CSDL nhưng không có chữ."
            );
        }

        // Và không có chữ nào dư — một mã không còn trong enum thì chữ của nó
        // là chữ cho một thứ không tồn tại.
        foreach (array_keys(BookingLine::STATUS_LABELS) as $ma) {
            $this->assertContains($ma, $enum, "Chữ cho \"{$ma}\" nhưng enum CSDL không có mã đó.");
        }
    }

    /**
     * Hai bảng chữ là HAI bảng, và khác nhau ở đúng một mã.
     *
     * Nếu một ngày nào đó hai enum trùng khít thì gộp được; test này sẽ đỏ và
     * buộc người sửa phải nhìn lại quyết định đó, chứ không để hai bảng giống
     * nhau tồn tại song song mà không ai biết.
     */
    public function test_hai_bang_chu_khac_nhau_dung_mot_ma(): void
    {
        $campaign = array_keys(Campaign::STATUS_LABELS);
        $dong     = array_keys(BookingLine::STATUS_LABELS);

        $this->assertSame(['draft', 'pending_approval'], array_values(array_diff($campaign, $dong)));
        $this->assertSame(['pending'], array_values(array_diff($dong, $campaign)));
    }

    // ── `statusLabels()` ────────────────────────────────────────────────────

    public function test_lay_tap_con_giu_dung_thu_tu_chuan(): void
    {
        // Truyền vào theo thứ tự ngược: kết quả vẫn phải theo thứ tự của
        // `STATUS_LABELS` (nháp → … → đã hủy), vì đó là thứ tự người đọc mong
        // thấy trong một ô chọn.
        $ra = Campaign::statusLabels(['completed', 'draft', 'active']);

        $this->assertSame(['draft', 'active', 'completed'], array_keys($ra));
        $this->assertSame('Nháp', $ra['draft']);
    }

    public function test_ma_la_trong_tap_con_bi_bo_chu_khong_thanh_muc_khong_co_chu(): void
    {
        $ra = Campaign::statusLabels(['approved', 'khong-ton-tai']);

        $this->assertSame(['approved'], array_keys($ra));
    }

    public function test_goi_khong_tham_so_tra_ca_tam(): void
    {
        $this->assertSame(Campaign::STATUS_LABELS, Campaign::statusLabels());
        $this->assertCount(8, Campaign::statusLabels());
    }

    // ── Bộ lọc phải khớp phạm vi truy vấn ───────────────────────────────────

    /**
     * Bộ lọc của hộp thư không được mời chọn thứ truy vấn đã loại.
     *
     * Hai danh sách này trước đây được giữ riêng và **đã lệch**: truy vấn gồm
     * `paused`, bộ lọc thì không. Một lựa chọn luôn trả về rỗng là một bộ lọc
     * nói dối; một trạng thái hiện trong bảng mà không lọc ra được thì người
     * dùng tưởng bộ lọc hỏng.
     */
    public function test_bo_loc_hop_thu_khop_dung_pham_vi_truy_van(): void
    {
        $this->assertSame(
            BookingInboxResource::VISIBLE_STATUSES,
            array_keys(Campaign::statusLabels(BookingInboxResource::VISIBLE_STATUSES)),
            'mọi trạng thái hộp thư cho qua đều phải có chữ, và không có chữ dư',
        );

        // `draft` và `cancelled` KHÔNG được có mặt: chiến dịch chưa gửi thì
        // chưa đến tay media owner, và đã hủy thì không còn là việc của hộp
        // thư.
        $this->assertNotContains('draft', BookingInboxResource::VISIBLE_STATUSES);
        $this->assertNotContains('cancelled', BookingInboxResource::VISIBLE_STATUSES);
    }

    /**
     * Và truy vấn thật phải tuân đúng hằng đó.
     *
     * Phép kiểm trên chỉ so hai mảng. Cái này chạy truy vấn thật của hộp thư
     * trên dữ liệu đủ tám trạng thái, nên nếu ai đó sửa `getEloquentQuery()`
     * sang một danh sách viết tay khác thì nó đỏ.
     */
    public function test_truy_van_hop_thu_chi_tra_cac_trang_thai_da_khai(): void
    {
        $owner = Owner::factory()->create([
            'name'   => 'Kim Ngân ADV',
            'slug'   => 'kim-ngan-' . uniqid(),
            'status' => 'active',
        ]);

        $org  = Organization::factory()->create(['status' => 'active']);
        $buyer = User::factory()->create(['current_organization_id' => $org->id]);
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $publisher = User::factory()->create(['current_owner_id' => $owner->id]);
        OwnerUser::create([
            'owner_id' => $owner->id,
            'user_id'  => $publisher->id,
            'role'     => 'owner',
        ]);

        // Một chiến dịch cho MỖI trạng thái, mỗi cái có một dòng của owner này.
        foreach (array_keys(Campaign::STATUS_LABELS) as $trangThai) {
            $campaign = Campaign::create([
                'organization_id' => $org->id,
                'created_by'      => $buyer->id,
                'code'            => 'CPN-' . Str::random(8),
                'name'            => 'CD ' . $trangThai,
                'start_date'      => now()->addMonth(),
                'end_date'        => now()->addMonths(2),
                'status'          => $trangThai,
            ]);

            $site   = Site::factory()->create(['owner_id' => $owner->id]);
            $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id]);

            BookingLine::create([
                'campaign_id'    => $campaign->id,
                'screen_id'      => $screen->id,
                'owner_id'       => $owner->id,
                'start_date'     => now()->addMonth(),
                'end_date'       => now()->addMonths(2),
                'status'         => 'pending',
                'estimated_cost' => 1_000_000,
            ]);
        }

        $this->actingAs($publisher);

        $raTruyVan = BookingInboxResource::getEloquentQuery()
            ->pluck('status')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $daKhai = collect(BookingInboxResource::VISIBLE_STATUSES)->sort()->values()->all();

        $this->assertSame($daKhai, $raTruyVan);
    }

    // ── Enum thứ ba: nội dung quảng cáo ─────────────────────────────────────

    /**
     * Mọi giá trị trong enum `creatives.status` phải có chữ, và không có chữ dư.
     *
     * Đọc enum thẳng từ `information_schema` như hai enum kia — chép tay danh
     * sách là lại thêm một bản chép nữa.
     */
    public function test_moi_trang_thai_creative_deu_co_chu(): void
    {
        $cot = \DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['creatives', 'status'],
        );

        $this->assertNotNull($cot, 'không đọc được enum của creatives.status');

        preg_match_all("/'([^']+)'/", $cot->t, $khop);
        $enum = $khop[1];

        $this->assertNotEmpty($enum);

        foreach ($enum as $ma) {
            $this->assertArrayHasKey(
                $ma,
                Creative::STATUS_LABELS,
                "Trạng thái nội dung \"{$ma}\" có trong enum CSDL nhưng không có chữ."
            );
            $this->assertNotSame($ma, Creative::STATUS_LABELS[$ma], "Chữ của \"{$ma}\" vẫn là chính nó.");
        }

        foreach (array_keys(Creative::STATUS_LABELS) as $ma) {
            $this->assertContains($ma, $enum, "Chữ cho \"{$ma}\" nhưng enum CSDL không có mã đó.");
        }
    }

    /**
     * Mỗi hằng `STATUS_*` của `Creative` phải có chữ.
     *
     * Đối xứng với phép kiểm của `Campaign`: thêm một hằng mà quên chữ thì giao
     * diện hiện mã tiếng Anh và không có gì đỏ.
     */
    public function test_moi_hang_status_cua_creative_deu_co_chu(): void
    {
        $hang = collect((new ReflectionClass(Creative::class))->getConstants())
            ->filter(fn ($v, $k) => str_starts_with($k, 'STATUS_') && is_string($v))
            ->values();

        $this->assertCount(3, $hang, 'đổi số trạng thái thì sửa cả test này cùng lượt');

        foreach ($hang as $ma) {
            $this->assertArrayHasKey($ma, Creative::STATUS_LABELS, "Hằng \"{$ma}\" không có chữ.");
        }
    }

    /**
     * BA bảng chữ, và "Chờ duyệt" có ba mã khác nhau.
     *
     * Đây là lý do ba bảng không gộp được, nói bằng một phép kiểm: cùng một
     * nghĩa cho người đọc — "đang chờ ai đó duyệt" — nhưng chiến dịch gọi là
     * `pending_approval`, dòng đặt chỗ gọi là `pending`, nội dung quảng cáo gọi
     * là `pending_review`. Gộp bảng thì đúng hai trong ba mã rơi ra ngoài và
     * hiện nguyên văn tiếng Anh. Việc gộp hai bảng đầu đã gây đúng lỗi đó.
     */
    public function test_ba_bang_chu_la_ba_bang_rieng(): void
    {
        $choDuyet = [];

        foreach ([Campaign::class, BookingLine::class, Creative::class] as $lop) {
            $ma = array_keys($lop::STATUS_LABELS, 'Chờ duyệt', true);

            $this->assertCount(1, $ma, "{$lop} phải có đúng một mã mang chữ \"Chờ duyệt\".");
            $choDuyet[] = $ma[0];
        }

        $this->assertSame(['pending_approval', 'pending', 'pending_review'], $choDuyet);
        $this->assertCount(3, array_unique($choDuyet), 'Ba mã phải khác nhau — nếu trùng thì gộp được.');
    }

    /**
     * Trang tải nội dung của NGƯỜI MUA hiện chữ Việt cho cả ba trạng thái.
     *
     * Lỗi thật đang có trên production: biểu thức trên trang chỉ biết một mã,
     *
     *     {{ $c->status === 'pending_review' ? 'Chờ duyệt' : $c->status }}
     *
     * nên `approved` hiện ra `approved` và `rejected` hiện ra `rejected`. Bảng
     * đầy đủ có ở Filament, nhưng người mua không bao giờ thấy Filament.
     *
     * Phép kiểm đọc chữ **bên trong từng thẻ** `.badge` chứ không `assertDontSee`
     * cả trang: mã trạng thái còn xuất hiện ở tên class và ở nơi khác, nên một
     * phép kiểm trên toàn trang sẽ đỏ vì lý do sai.
     */
    public function test_trang_tai_noi_dung_hien_chu_viet_cho_ca_ba_trang_thai(): void
    {
        $org  = Organization::factory()->create(['status' => 'active']);
        $buyer = User::factory()->create(['current_organization_id' => $org->id]);
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'CD nội dung',
            'start_date'      => now()->addMonth(),
            'end_date'        => now()->addMonths(2),
            'status'          => Campaign::STATUS_DRAFT,
        ]);

        foreach (array_keys(Creative::STATUS_LABELS) as $trangThai) {
            Creative::create([
                'campaign_id'     => $campaign->id,
                'organization_id' => $org->id,
                'name'            => 'Nội dung ' . $trangThai,
                'type'            => 'image',
                'file_size'       => 102_400,
                'status'          => $trangThai,
            ]);
        }

        $html = $this->actingAs($buyer)
            ->get('/booking/' . $campaign->id . '/creative')
            ->assertOk()
            ->getContent();

        preg_match_all('/<span class="badge[^"]*"[^>]*>([^<]*)<\/span>/', $html, $khop);

        $chuTrenThe = array_map('trim', $khop[1]);

        $this->assertCount(3, $chuTrenThe, 'phải có đúng ba thẻ trạng thái, một cho mỗi nội dung');

        sort($chuTrenThe);
        $mongDoi = array_values(Creative::STATUS_LABELS);
        sort($mongDoi);

        $this->assertSame(
            $mongDoi,
            $chuTrenThe,
            'Có thẻ trạng thái hiện mã nguyên văn tiếng Anh thay vì chữ Việt.',
        );
    }

    /**
     * Trang đầu khu người mua (`/my`) phải hiện chữ Việt cho **cả tám** trạng thái.
     *
     * ══ Lỗi test này chống ══
     *
     * `buyer/dashboard/index.blade.php` in thẳng `{{ $c->status }}`, nên tám mã
     * chiến dịch ra nguyên văn tiếng Anh — `draft`, `pending_approval`,
     * `completed` — ngay trên trang người mua thấy **đầu tiên** sau khi đăng
     * nhập. Hai trang chiến dịch kia đã đi qua bảng chữ từ PR #41; trang này bị
     * bỏ lại vì nó dựng bằng Blade chứ không đọc API, nên không test nào đi qua.
     *
     * ══ Vì sao chia lô ══
     *
     * `BuyerDashboardController` lấy `->take(5)`, nên một lần render không thể
     * phủ tám trạng thái. Lặp theo lô năm: mỗi lô xóa sạch chiến dịch của tổ
     * chức rồi tạo đúng một chiến dịch cho mỗi mã trong lô. Cách này cũng tự
     * giãn nếu enum có thêm mã thứ chín.
     */
    public function test_trang_dau_khu_nguoi_mua_hien_chu_viet_cho_moi_trang_thai(): void
    {
        $org   = Organization::factory()->create(['status' => 'active']);
        $buyer = User::factory()->create(['current_organization_id' => $org->id]);
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        foreach (array_chunk(array_keys(Campaign::STATUS_LABELS), 5) as $lo) {
            Campaign::where('organization_id', $org->id)->delete();

            foreach ($lo as $trangThai) {
                Campaign::create([
                    'organization_id' => $org->id,
                    'created_by'      => $buyer->id,
                    'code'            => 'CPN-' . Str::random(8),
                    'name'            => 'CD ' . $trangThai,
                    'start_date'      => now()->addMonth(),
                    'end_date'        => now()->addMonths(2),
                    'status'          => $trangThai,
                ]);
            }

            $html = $this->actingAs($buyer)->get('/my')->assertOk()->getContent();

            preg_match_all('/<span class="badge[^"]*"[^>]*>([^<]*)<\/span>/', $html, $khop);

            $chuTrenThe = array_map('trim', $khop[1]);
            sort($chuTrenThe);

            $mongDoi = array_values(array_intersect_key(Campaign::STATUS_LABELS, array_flip($lo)));
            sort($mongDoi);

            $this->assertSame(
                $mongDoi,
                $chuTrenThe,
                'Thẻ trạng thái trên /my hiện mã nguyên văn tiếng Anh thay vì chữ Việt, '
                . 'lô: ' . implode(', ', $lo),
            );
        }
    }

    /**
     * Bảng màu của trang đầu phải phủ **mọi** mã, không chỉ vài mã.
     *
     * Bản cũ là một ternary lồng tô đúng hai mã (`active`, `pending_approval`);
     * sáu mã còn lại rơi vào nhánh xám, nên "Đã hủy" và "Hoàn thành" cùng màu
     * với "Nháp". Phép kiểm trên chỉ đọc CHỮ bên trong thẻ nên nó không thấy
     * việc đó — màu nằm ở thuộc tính `class`.
     */
    public function test_bang_mau_trang_dau_phu_moi_trang_thai_campaign(): void
    {
        $nguon = file_get_contents(resource_path('views/buyer/dashboard/index.blade.php'));

        $this->assertMatchesRegularExpression(
            '/\$mauTrangThai\s*=\s*\[/',
            $nguon,
            'không còn bảng màu `$mauTrangThai` trong trang — nếu đã đổi cách viết thì sửa test cùng lượt',
        );

        preg_match('/\$mauTrangThai\s*=\s*\[(.*?)\];/s', $nguon, $khop);

        $tenHang = collect((new ReflectionClass(Campaign::class))->getConstants())
            ->filter(fn ($v, $k) => str_starts_with($k, 'STATUS_') && is_string($v))
            ->flip();

        foreach (array_keys(Campaign::STATUS_LABELS) as $ma) {
            $this->assertMatchesRegularExpression(
                '/Campaign::' . preg_quote($tenHang[$ma], '/') . '\b/',
                $khop[1],
                "bảng màu trang đầu thiếu `{$ma}` — nó sẽ rơi vào nhánh xám cùng với `draft`",
            );
        }
    }
}
