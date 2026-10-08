<?php

namespace Tests\Feature;

use App\Filament\Publisher\Resources\BookingInboxResource;
use App\Models\BookingLine;
use App\Models\Campaign;
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
}
