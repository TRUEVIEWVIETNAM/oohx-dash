<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration đổi tên `scheduler` → `operator` phải GIỮ dữ liệu, cả hai chiều.
 *
 * ══ Vì sao một ca test cho một migration ══
 *
 * Đổi một giá trị enum trên MySQL không làm được bằng một `ALTER` khi cột đang
 * có dữ liệu mang giá trị đó, nên migration phải đi qua `varchar` tạm: ba lần
 * `ALTER` và hai lần `UPDATE` ở giữa. Mỗi bước trong đó có một cách sai lặng
 * lẽ — quên câu `UPDATE` thứ nhất thì mọi hàng về `read_only` (mặc định của
 * cột), và hậu quả không phải một nhãn sai mà là **quyền sai**: một người đang
 * sửa được kho bỗng chỉ còn xem.
 *
 * Trên một CSDL rỗng thì không bước nào trong số đó đỏ. Nên ca test phải có
 * dữ liệu, và phải có **cả sáu** vai trò, không chỉ vai trò được đổi tên: cái
 * dễ hỏng là năm vai trò kia bị cuốn theo.
 *
 * ══ Chiều về cũng được canh, và đó không phải cho đẹp ══
 *
 * Migration `2025_01_01_000013` (lần đổi bộ vai trò trước) có `down()` gộp
 * `scheduler`, `sales_manager` và `manager` về cùng `manager`. Rollback một lần
 * là mất vĩnh viễn thông tin ai từng là gì — và mất theo hướng **nới quyền**:
 * `manager` sửa được giá, hai vai trò kia thì không.
 *
 * Lần này là một lần đổi tên thuần, nên nó phải đi về được nguyên trạng. Ca
 * `test_di_ve_khong_mat_vai_tro_nao` chạy `down()` rồi `up()` thật trên sáu
 * hàng và đối chiếu từng hàng.
 */
class DoiTenVaiTroOperatorTest extends TestCase
{
    use RefreshDatabase;

    private const TEP_MIGRATION = 'database/migrations/2026_10_10_000001_rename_owner_role_scheduler_to_operator.php';

    /** Sáu vai trò, mỗi vai trò một hàng thật. @return array<string, int> `vai trò => id` */
    private function sauThanhVien(): array
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $ra    = [];

        foreach (array_keys(OwnerUser::ROLE_LABELS) as $vaiTro) {
            $ra[$vaiTro] = OwnerUser::create([
                'owner_id' => $owner->id,
                'user_id'  => User::factory()->create()->id,
                'role'     => $vaiTro,
            ])->id;
        }

        return $ra;
    }

    private function migration(): object
    {
        return require base_path(self::TEP_MIGRATION);
    }

    /** @return array<string, string> `id => role` đọc thẳng từ CSDL */
    private function vaiTroTrongCsdl(array $ids): array
    {
        return DB::table('owner_users')
            ->whereIn('id', array_values($ids))
            ->pluck('role', 'id')
            ->map(fn ($r) => (string) $r)
            ->all();
    }

    public function test_enum_csdl_da_doi_sang_operator(): void
    {
        $kieu = DB::selectOne(
            "SELECT COLUMN_TYPE AS kieu FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'owner_users' AND COLUMN_NAME = 'role'",
        )->kieu;

        $this->assertStringContainsString("'operator'", $kieu, "Enum chưa có `operator`: {$kieu}");
        $this->assertStringNotContainsString("'scheduler'", $kieu, "Enum vẫn còn `scheduler`: {$kieu}");
    }

    public function test_di_ve_khong_mat_vai_tro_nao(): void
    {
        $ids       = $this->sauThanhVien();
        $migration = $this->migration();

        // ── Chiều về: chỉ `operator` đổi, năm vai trò kia đứng im ──
        $migration->down();

        $sauKhiVe = $this->vaiTroTrongCsdl($ids);

        $this->assertSame('scheduler', $sauKhiVe[$ids['operator']], 'down() phải map operator → scheduler');

        foreach (['owner', 'manager', 'read_only', 'reporting_only', 'sales_manager'] as $khac) {
            $this->assertSame(
                $khac,
                $sauKhiVe[$ids[$khac]],
                "down() làm đổi vai trò `{$khac}` — nó chỉ được đổi `operator`",
            );
        }

        // ── Chiều đi: về đúng trạng thái ban đầu ──
        $migration->up();

        $sauKhiDi = $this->vaiTroTrongCsdl($ids);

        foreach ($ids as $vaiTro => $id) {
            $this->assertSame($vaiTro, $sauKhiDi[$id], "up() không trả `{$vaiTro}` về đúng chỗ");
        }
    }

    /**
     * Chạy lại `up()` không làm hỏng dữ liệu (CLAUDE.md §6).
     *
     * Nói đúng ca này canh gì, vì đọc tên nó dễ tưởng nhiều hơn: nó khẳng định
     * sáu hàng **vẫn đúng vai trò** sau lần `up()` thứ hai. Nó **không** thấy
     * được chốt `if (! str_contains($kieu, ...)) return;` có chặn hay không —
     * bỏ chốt đó đi thì cột bị dựng lại ba lần vô ích nhưng dữ liệu vẫn đúng,
     * và ca này vẫn xanh. Đã đo bằng đột biến, không suy luận.
     *
     * Thứ nó thật sự bắt là hình dạng mất dữ liệu: một `up()` chạy lại mà quên
     * sao `role` sang `role_tmp` sẽ đưa cả sáu hàng về `read_only` — mặc định
     * của cột — và đó là mất **quyền**, không phải mất một nhãn.
     */
    public function test_chay_lai_up_khong_doi_gi(): void
    {
        $ids = $this->sauThanhVien();

        $this->migration()->up();

        $this->assertSame(
            array_combine(array_values($ids), array_keys($ids)),
            $this->vaiTroTrongCsdl($ids),
        );
    }

    /**
     * Lời mời CHƯA nhận được map; lời mời đã nhận là lịch sử, không sửa.
     *
     * `user_invitations.role` là `varchar(32)`, nên CSDL không chặn một mã vai
     * trò không còn tồn tại. Một lời mời treo mang `scheduler` sẽ đi qua
     * `UserInvitationService::accept()` và ghi thẳng vào pivot, nơi enum mới từ
     * chối — người được mời nhận một lỗi không liên quan gì tới họ.
     */
    public function test_loi_moi_chua_nhan_duoc_map_loi_moi_da_nhan_thi_khong(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);

        $treo = UserInvitation::create([
            'email'       => 'treo@example.com',
            'tenant_type' => UserInvitation::TENANT_OWNER,
            'tenant_id'   => $owner->id,
            'role'        => 'operator',
            'token'       => 'token-treo-' . uniqid(),
            'invited_by_user_id' => User::factory()->create()->id,
            'expires_at'  => now()->addDays(7),
        ]);

        $daNhan = UserInvitation::create([
            'email'       => 'danhan@example.com',
            'tenant_type' => UserInvitation::TENANT_OWNER,
            'tenant_id'   => $owner->id,
            'role'        => 'operator',
            'token'       => 'token-da-nhan-' . uniqid(),
            'invited_by_user_id' => User::factory()->create()->id,
            'expires_at'  => now()->addDays(7),
            'accepted_at' => now(),
        ]);

        $this->migration()->down();

        $this->assertSame(
            'scheduler',
            DB::table('user_invitations')->where('id', $treo->id)->value('role'),
            'lời mời chưa nhận phải được map',
        );

        $this->assertSame(
            'operator',
            DB::table('user_invitations')->where('id', $daNhan->id)->value('role'),
            'lời mời ĐÃ nhận là lịch sử — không được sửa',
        );

        $this->migration()->up();
    }
}
