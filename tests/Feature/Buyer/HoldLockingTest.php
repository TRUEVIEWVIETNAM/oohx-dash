<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Giữ chỗ có **xếp hàng thật** hay không.
 *
 * `InventoryHoldTest` chạy một luồng nên chỉ chứng minh được phép tính sức chứa.
 * Nhưng lỗi F-02 không phải lỗi phép tính — nó là lỗi "kiểm rồi mới làm": hai
 * giao dịch cùng đọc một bức ảnh cũ của kho rồi cùng ghi. Bỏ `lockForUpdate()`
 * đi thì toàn bộ `InventoryHoldTest` vẫn xanh, mà lỗ hổng thì còn nguyên.
 *
 * Nên ở đây có hai bằng chứng khác nhau:
 *
 *  1. Đường đặt chỗ thật **có** khóa hàng màn hình, và khóa **trước** khi đọc số
 *     liệu sức chứa. Bắt bằng cách nghe toàn bộ câu SQL mà nó chạy.
 *  2. Khóa đó **thật sự chặn** một kết nối khác: hai kết nối riêng, kết nối thứ
 *     hai phải chờ tới hết thời gian chờ khóa chứ không đọc được kho.
 */
class HoldLockingTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = User::factory()->create(['current_organization_id' => $this->org->id]);
        $this->buyer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->buyer->id,
            'role'            => 'admin',
        ]);
        $this->actingAs($this->buyer);
    }

    private function screen(): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen->fresh('inventory');
    }

    // ── Bằng chứng 1: đường đặt chỗ có khóa, và khóa trước khi đọc ───────────

    public function test_gianh_giu_cho_khoa_hang_man_hinh_truoc_khi_doc_suc_chua(): void
    {
        $screen = $this->screen();
        $start  = now()->addYear()->startOfYear();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        app(CartService::class)->addItem($this->cartFor(), $screen->id, [
            'start_date'         => $start->toDateString(),
            'end_date'           => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
            'share_of_voice_pct' => 50,
        ]);

        $lockIndex = $this->firstIndexMatching($queries, fn ($sql) => str_contains($sql, 'from `screens`') && str_contains($sql, 'for update'));
        $countIndex = $this->firstIndexMatching($queries, fn ($sql) => str_contains($sql, 'from `inventory_holds`') && str_contains($sql, 'sum('));

        $this->assertNotNull($lockIndex, 'Không thấy câu khóa hàng màn hình. Thiếu nó thì hai người mua không phải xếp hàng.');
        $this->assertNotNull($countIndex, 'Không thấy câu đếm suất đang giữ.');
        $this->assertLessThan(
            $countIndex,
            $lockIndex,
            'Khóa phải nằm TRƯỚC phép đọc sức chứa. Khóa sau khi đọc thì chẳng chặn được gì.'
        );
    }

    // ── Bằng chứng 2: khóa chặn được kết nối khác ───────────────────────────

    public function test_khoa_hang_man_hinh_chan_that_su_mot_ket_noi_khac(): void
    {
        [$connA, $connB] = $this->twoRealConnections();

        $screenId = (string) Str::ulid();

        // Dựng đúng một hàng `screens` làm mốc khóa, trên kết nối riêng nên nó
        // được commit thật và kết nối thứ hai nhìn thấy. Tắt kiểm khóa ngoại
        // trong phiên này để không phải dựng cả owner và site chỉ để khóa một hàng.
        DB::connection($connA)->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection($connB)->statement('SET FOREIGN_KEY_CHECKS=0');

        // Kết nối thứ hai không chờ lâu: 1 giây là đủ để phân biệt "bị chặn" với
        // "đọc được". Không đặt thì test treo tới 50 giây mặc định của InnoDB.
        DB::connection($connB)->statement('SET SESSION innodb_lock_wait_timeout=1');

        try {
            DB::connection($connA)->table('screens')->insert([
                'id'          => $screenId,
                'site_id'     => (string) Str::ulid(),
                'owner_id'    => (string) Str::ulid(),
                'external_id' => 'lock-' . Str::random(8),
                'uuid'        => (string) Str::uuid(),
                'name'        => 'Màn hình thử khóa',
                'active'      => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // A giữ khóa và chưa commit — đúng trạng thái của một người mua đang
            // ở giữa quá trình giành suất.
            DB::connection($connA)->beginTransaction();
            DB::connection($connA)->table('screens')->where('id', $screenId)->lockForUpdate()->first();

            $blocked = false;
            $message = '';

            try {
                DB::connection($connB)->beginTransaction();
                DB::connection($connB)->table('screens')->where('id', $screenId)->lockForUpdate()->first();
                DB::connection($connB)->rollBack();
            } catch (\Throwable $e) {
                $blocked = true;
                $message = $e->getMessage();
                try {
                    DB::connection($connB)->rollBack();
                } catch (\Throwable) {
                    // phiên đã bị hủy cùng lỗi khóa — không sao
                }
            }

            DB::connection($connA)->rollBack();

            $this->assertTrue(
                $blocked,
                'Kết nối thứ hai đọc được hàng màn hình trong khi kết nối thứ nhất đang giữ khóa. '
                . 'Nghĩa là khóa không chặn gì, và hai người mua vẫn giành được cùng một suất.'
            );
            $this->assertStringContainsStringIgnoringCase(
                'lock wait timeout',
                $message,
                'Phải là lỗi hết thời gian chờ khóa, không phải lỗi khác.'
            );
        } finally {
            DB::connection($connA)->table('screens')->where('id', $screenId)->delete();
            DB::connection($connA)->statement('SET FOREIGN_KEY_CHECKS=1');
            DB::connection($connA)->disconnect();
            DB::connection($connB)->disconnect();
        }
    }

    /**
     * Hai kết nối thật, riêng biệt, không phải kết nối mặc định của test.
     *
     * Kết nối mặc định đang nằm trong transaction của RefreshDatabase nên dữ
     * liệu của nó chưa commit và kết nối khác không thấy — muốn kiểm tranh chấp
     * thì buộc phải đi ra ngoài nó.
     *
     * @return array{0: string, 1: string}
     */
    private function twoRealConnections(): array
    {
        $base = Config::get('database.connections.' . Config::get('database.default'));

        foreach (['lock_test_a', 'lock_test_b'] as $name) {
            Config::set("database.connections.$name", $base);
            DB::purge($name);
        }

        return ['lock_test_a', 'lock_test_b'];
    }

    private function cartFor(): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $this->buyer->id, 'status' => 'active'],
            ['organization_id' => $this->org->id, 'name' => 'Plan']
        );
    }

    /** @param array<int, string> $items */
    private function firstIndexMatching(array $items, callable $matches): ?int
    {
        foreach ($items as $i => $item) {
            if ($matches($item)) {
                return $i;
            }
        }

        return null;
    }
}
