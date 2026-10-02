<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Đo thật mối nguy R04: khóa hàng bắt xếp hàng, nhưng KHÔNG làm mới ảnh chụp.
 *
 * Ở giai đoạn 1 tôi trưng ra một test hai kết nối và gọi nó là "bằng chứng
 * khóa thật". Codex chỉ ra nó chỉ chứng minh khóa gây hết thời gian chờ,
 * **không** chứng minh người chờ đọc được dữ liệu vừa được commit — mà dưới
 * REPEATABLE READ đó là hai chuyện khác nhau, và khoảng cách giữa chúng chính
 * là chỗ bán vượt suất.
 *
 * Test này đo đúng khoảng cách đó, trên MySQL thật, với hai kết nối thật:
 *
 *   1. B mở transaction và đọc một lần → ảnh chụp của B hình thành tại đây.
 *   2. A ghi thêm một giữ chỗ và commit.
 *   3. B đọc lại **không khóa** → vẫn thấy dữ liệu cũ. Đây là mối nguy.
 *   4. B đọc lại **có khóa** → thấy dữ liệu mới. Đây là bản sửa.
 *
 * Bước 3 không phải lỗi cần sửa của MySQL; nó là hành vi đúng của REPEATABLE
 * READ. Lỗi là ở chỗ code tính sức chứa bằng phép đọc thường sau khi đã khóa.
 */
class SnapshotReadTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: string} */
    private function twoConnections(): array
    {
        $base = Config::get('database.connections.' . Config::get('database.default'));

        foreach (['race_a', 'race_b'] as $name) {
            Config::set("database.connections.$name", $base);
            DB::purge($name);
        }

        return ['race_a', 'race_b'];
    }

    public function test_doc_thuong_thay_du_lieu_cu_con_doc_co_khoa_thay_du_lieu_moi(): void
    {
        [$a, $b] = $this->twoConnections();

        $screenId = (string) Str::ulid();
        $start    = now()->addYear()->startOfYear()->toDateString();
        $end      = now()->addYear()->startOfYear()->addMonth()->toDateString();

        // Dựng tối thiểu trên kết nối riêng để dữ liệu được commit thật. Tắt
        // kiểm khóa ngoại trong phiên này để không phải dựng cả owner và site.
        foreach ([$a, $b] as $conn) {
            DB::connection($conn)->statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            DB::connection($a)->table('screens')->insert([
                'id'          => $screenId,
                'site_id'     => (string) Str::ulid(),
                'owner_id'    => (string) Str::ulid(),
                'external_id' => 'race-' . Str::random(6),
                'uuid'        => (string) Str::uuid(),
                'name'        => 'Màn hình đo tranh chấp',
                'active'      => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // ── B mở transaction và đọc một lần: ảnh chụp hình thành ở đây.
            DB::connection($b)->beginTransaction();
            $seenBefore = (int) DB::connection($b)->table('inventory_holds')
                ->where('screen_id', $screenId)
                ->count();

            $this->assertSame(0, $seenBefore);

            // ── A ghi một giữ chỗ 100% và commit.
            DB::connection($a)->table('inventory_holds')->insert([
                'id'         => (string) Str::ulid(),
                'screen_id'  => $screenId,
                'start_date' => $start,
                'end_date'   => $end,
                'sov_pct'    => 100,
                'expires_at' => now()->addMinutes(30),
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ── B đọc lại KHÔNG khóa: vẫn là ảnh chụp cũ.
            $plainRead = (int) DB::connection($b)->table('inventory_holds')
                ->where('screen_id', $screenId)
                ->where('status', 'active')
                ->sum('sov_pct');

            // ── B đọc lại CÓ khóa: đọc bản mới nhất đã commit.
            $lockingRead = (int) DB::connection($b)->table('inventory_holds')
                ->where('screen_id', $screenId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->sum('sov_pct');

            DB::connection($b)->rollBack();

            $this->assertSame(
                0,
                $plainRead,
                'Nếu phép đọc thường đã thấy dữ liệu mới thì isolation không phải REPEATABLE READ — '
                . 'xem lại cấu hình, vì lập luận của bản sửa dựa trên điều này.'
            );

            $this->assertSame(
                100,
                $lockingRead,
                'Phép đọc CÓ KHÓA phải thấy giữ chỗ vừa commit. Đây là toàn bộ lý do assertCapacity '
                . 'dùng locking read — không có nó, người chờ tính sức chứa trên dữ liệu cũ và bán vượt suất.'
            );
        } finally {
            try {
                DB::connection($b)->rollBack();
            } catch (\Throwable) {
                // đã rollback ở trên
            }

            DB::connection($a)->table('inventory_holds')->where('screen_id', $screenId)->delete();
            DB::connection($a)->table('screens')->where('id', $screenId)->delete();

            foreach ([$a, $b] as $conn) {
                DB::connection($conn)->statement('SET FOREIGN_KEY_CHECKS=1');
                DB::connection($conn)->disconnect();
            }
        }
    }

    public function test_muc_isolation_dung_nhu_lap_luan_cua_ban_sua(): void
    {
        $level = DB::selectOne('SELECT @@transaction_isolation AS level')->level;

        $this->assertSame(
            'REPEATABLE-READ',
            $level,
            'Bản sửa R04 lập luận trên REPEATABLE READ. Mức isolation khác thì lập luận đó phải xem lại, '
            . 'nên ghi nó thành một phép kiểm chứ không để trong đầu.'
        );
    }
}
