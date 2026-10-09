<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Loại hình tổ chức: **một bảng chữ**, và nó phải là tiếng Việt.
 *
 * ══ Lỗi tệp này chống ══
 *
 * `organizations.type` là `enum('agency','client','brand')` và **không có bảng
 * chữ nào**. Bốn chỗ hiện nó, bốn kiểu sai:
 *
 *  1. Trang đầu khu người mua: `ucfirst($org->type)` → "Agency", "Brand",
 *     "Client". Tiếng Anh, ngay dưới tên người dùng, trên trang họ thấy đầu
 *     tiên sau khi đăng nhập.
 *  2. Cột `type` ở bảng quản trị sàn: có `->badge()` và có màu, **không có
 *     nhãn chữ** — nên nó hiện thẳng mã `agency`. Đúng lỗi cột trạng thái
 *     chiến dịch đã mắc trước PR #41.
 *  3. Khối thông tin (infolist): cũng vậy.
 *  4. Ô chọn của form và của bộ lọc: có chữ, nhưng tiếng Anh, và là **hai bản
 *     chép** rời nhau.
 *
 * ══ Những gì tệp này canh ══
 *
 * Không canh "chữ nào đúng" — đó là việc nghiệp vụ. Canh **tính đầy đủ** (mọi
 * mã trong enum CSDL có chữ, và không có chữ cho mã không tồn tại) và **việc
 * chữ tới được chỗ người dùng đọc**.
 *
 * Enum đọc thẳng từ `information_schema`, không chép tay: một danh sách chép
 * tay là thêm một bản chép nữa của đúng thứ tệp này tồn tại để chặn.
 */
class NhanLoaiToChucMotNoiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> giá trị enum của `organizations.type` */
    private function enumTrongCsdl(): array
    {
        $cot = DB::selectOne(
            'SELECT COLUMN_TYPE AS kieu FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['organizations', 'type'],
        );

        $this->assertNotNull($cot, 'không đọc được cột organizations.type');

        preg_match_all("/'([^']+)'/", $cot->kieu, $khop);

        $this->assertNotEmpty($khop[1], "không trích được giá trị enum từ: {$cot->kieu}");

        return $khop[1];
    }

    public function test_moi_loai_hinh_trong_csdl_deu_co_chu(): void
    {
        $enum = $this->enumTrongCsdl();

        foreach ($enum as $ma) {
            $this->assertArrayHasKey(
                $ma,
                Organization::TYPE_LABELS,
                "Loại hình `{$ma}` có trong enum CSDL nhưng không có chữ trong "
                . 'Organization::TYPE_LABELS — nó sẽ hiện ra nguyên văn.',
            );
        }

        // Chiều ngược lại: chữ cho một mã không tồn tại là một lựa chọn trong ô
        // chọn mà CSDL sẽ từ chối khi lưu.
        foreach (array_keys(Organization::TYPE_LABELS) as $ma) {
            $this->assertContains(
                $ma,
                $enum,
                "`{$ma}` có chữ nhưng không có trong enum CSDL.",
            );
        }
    }

    public function test_moi_hang_type_cua_to_chuc_deu_co_chu(): void
    {
        $hang = collect((new ReflectionClass(Organization::class))->getConstants())
            ->filter(fn ($v, $k) => str_starts_with($k, 'TYPE_') && is_string($v))
            ->values();

        $this->assertCount(3, $hang, 'đổi số loại hình thì sửa cả test này cùng lượt');

        foreach ($hang as $ma) {
            $this->assertArrayHasKey($ma, Organization::TYPE_LABELS);
        }
    }

    /**
     * Chữ phải là **tiếng Việt**, không phải mã viết hoa chữ đầu.
     *
     * Đây là phép kiểm bắt đúng lỗi cũ: `ucfirst('agency')` ra `Agency`, và một
     * bảng chữ chép lại chính mã đó thì đầy đủ nhưng vô dụng.
     */
    public function test_chu_khong_phai_ma_viet_hoa_chu_dau(): void
    {
        foreach (Organization::TYPE_LABELS as $ma => $chu) {
            $this->assertNotSame(
                ucfirst($ma),
                $chu,
                "Chữ của `{$ma}` vẫn là chính mã đó viết hoa chữ đầu — chưa dịch.",
            );
        }
    }

    /**
     * Trang đầu khu người mua hiện chữ Việt, không hiện mã.
     *
     * Dòng chào là khối duy nhất của trang đó còn do máy chủ vẽ, nên đây là
     * phép kiểm đi qua HTML thật. Ba loại hình, ba lần render.
     */
    public function test_trang_dau_hien_chu_viet_cho_moi_loai_hinh(): void
    {
        foreach (Organization::TYPE_LABELS as $ma => $chu) {
            $org   = Organization::factory()->create(['status' => 'active', 'type' => $ma]);
            $buyer = User::factory()->create(['current_organization_id' => $org->id]);
            OrganizationUser::create([
                'organization_id' => $org->id,
                'user_id'         => $buyer->id,
                'role'            => OrganizationUser::ROLE_ADMIN,
            ]);

            $html = $this->actingAs($buyer)->get('/my')->assertOk()->getContent();

            preg_match('/<p class="buyer-welcome-sub">(.*?)<\/p>/s', $html, $khop);

            $this->assertNotEmpty($khop, 'không tìm được dòng chào trên trang');
            $this->assertStringContainsString($chu, $khop[1], "loại hình `{$ma}` không hiện chữ Việt");
            $this->assertStringNotContainsString(
                ucfirst($ma),
                $khop[1],
                "dòng chào còn in mã `{$ma}` kiểu tiếng Anh",
            );
        }
    }

    /**
     * Bảng chữ **không** bị gộp với bảng trạng thái chiến dịch.
     *
     * Hai enum khác nhau hoàn toàn, nhưng đây là chỗ các bản chép hay tụ lại:
     * một ngày nào đó có người thấy hai bảng chữ giống hình dạng rồi gộp. Giao
     * của hai tập mã phải rỗng, và nếu không rỗng thì phải có người nhìn lại.
     */
    public function test_bang_loai_hinh_khong_lan_voi_bang_trang_thai(): void
    {
        $this->assertSame(
            [],
            array_intersect(
                array_keys(Organization::TYPE_LABELS),
                array_keys(Campaign::STATUS_LABELS),
            ),
            'Hai bảng chữ dùng chung một mã — kiểm lại xem có phải đã gộp hai enum.',
        );
    }
}
