<?php

namespace Tests\Feature\Concurrency;

use App\Models\Cart;
use App\Models\InventoryHold;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Hai tiến trình thật cùng giành một suất, **đi qua đường service**.
 *
 * Đây là bản làm lại sau khi Codex chỉ ra ba lỗi trong phép đo cũ (R29):
 *
 *  1. Phép đo cũ dùng `RefreshDatabase`, nên fixture nằm trong transaction
 *     chưa commit và tiến trình con **không thể thấy**. Nó có thể chết vì
 *     thiếu dữ liệu chứ không vì tranh chấp. → Lớp này **không** dùng
 *     `RefreshDatabase`; fixture được commit thật và dọn tường minh.
 *  2. Phép đo cũ tính thời gian chờ **sau** `proc_close`, tức gồm cả thời gian
 *     tiến trình con kết thúc — con số vượt ngưỡng kể cả khi không có khóa nào
 *     chặn gì. → Lớp này không đo thời gian; nó đo **kết quả**: đúng một bên
 *     được suất.
 *  3. Phép đo cũ không assert exit code của tiến trình con. → Lớp này đọc kết
 *     quả tiến trình con từ file và assert tường minh.
 *
 * Và quan trọng nhất: tiến trình con **nạp Laravel rồi gọi `CartService`**,
 * không chạy một bản sao SQL. Bản sao SQL chỉ chứng minh MySQL hoạt động như
 * tài liệu nói; nó không chứng minh đường code của mình có dùng đúng cơ chế đó.
 */
class ServiceRaceTest extends TestCase
{
    /** @var array<int, string> */
    private array $cleanupScreenIds = [];

    /** @var array<int, string> */
    private array $cleanupCartIds = [];

    private array $cleanupUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Không có RefreshDatabase nên schema có thể chưa tồn tại nếu lớp này
        // chạy trước mọi lớp khác. migrate là idempotent.
        if (! Schema::hasTable('inventory_holds')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        // Dọn tường minh: lớp này commit thật, nên không dọn là để lại dữ liệu
        // rác cho mọi test chạy sau.
        try {
            InventoryHold::whereIn('screen_id', $this->cleanupScreenIds)->delete();
            \App\Models\CartItem::whereIn('cart_id', $this->cleanupCartIds)->delete();
            Cart::whereIn('id', $this->cleanupCartIds)->delete();

            foreach ($this->cleanupScreenIds as $screenId) {
                ScreenInventory::where('screen_id', $screenId)->delete();
                $screen = Screen::withoutGlobalScopes()->find($screenId);
                if ($screen) {
                    $siteId  = $screen->site_id;
                    $ownerId = $screen->owner_id;
                    Screen::withoutGlobalScopes()->whereKey($screenId)->forceDelete();
                    Site::withoutGlobalScopes()->whereKey($siteId)->forceDelete();
                    Owner::whereKey($ownerId)->forceDelete();
                }
            }

            User::whereIn('id', $this->cleanupUserIds)->delete();
        } catch (\Throwable) {
            // Dọn hết sức; không che lỗi thật của test.
        }

        parent::tearDown();
    }

    private function makeBuyer(Organization $org): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $user->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $this->cleanupUserIds[] = $user->id;

        return $user;
    }

    public function test_hai_tien_trinh_cung_gianh_mot_suat_thi_dung_mot_ben_thang(): void
    {
        $org = Organization::factory()->create(['status' => 'active']);

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

        $this->cleanupScreenIds[] = $screen->id;

        $buyerA = $this->makeBuyer($org);
        $buyerB = $this->makeBuyer($org);

        $cartA = Cart::create(['user_id' => $buyerA->id, 'organization_id' => $org->id, 'status' => 'active', 'name' => 'A']);
        $cartB = Cart::create(['user_id' => $buyerB->id, 'organization_id' => $org->id, 'status' => 'active', 'name' => 'B']);

        $this->cleanupCartIds = [$cartA->id, $cartB->id];

        $start = now()->addYear()->startOfYear();
        $dates = [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];

        $ready  = tempnam(sys_get_temp_dir(), 'ready');
        $go     = tempnam(sys_get_temp_dir(), 'go');
        $result = tempnam(sys_get_temp_dir(), 'result');
        @unlink($ready);
        @unlink($go);

        // Tiến trình con NẠP LARAVEL và gọi chính CartService — không phải một
        // bản sao SQL. Bản sao SQL chỉ chứng minh MySQL chạy đúng tài liệu; nó
        // không chứng minh đường code của mình dùng đúng cơ chế đó.
        $base   = base_path();
        $child  = <<<PHP
        <?php
        require '{$base}/vendor/autoload.php';
        \$app = require '{$base}/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

        file_put_contents('{$ready}', 'ready');
        \$deadline = microtime(true) + 15;
        while (! file_exists('{$go}') && microtime(true) < \$deadline) { usleep(2000); }

        try {
            app(App\\Services\\CartService::class)->addItem(
                App\\Models\\Cart::findOrFail('{$cartB->id}'),
                '{$screen->id}',
                ['start_date' => '{$dates['start_date']}', 'end_date' => '{$dates['end_date']}', 'share_of_voice_pct' => 100],
            );
            file_put_contents('{$result}', 'ok');
        } catch (Symfony\\Component\\HttpKernel\\Exception\\HttpException \$e) {
            // Ghi RIÊNG mã trạng thái: bên thua phải thua vì HẾT SUẤT (422),
            // không phải vì một lỗi bất kỳ nào khác.
            file_put_contents('{$result}', 'refused:' . \$e->getStatusCode() . ':' . \$e->getMessage());
        } catch (Throwable \$e) {
            file_put_contents('{$result}', 'error:' . get_class(\$e) . ':' . \$e->getMessage());
        }
        PHP;

        $script = tempnam(sys_get_temp_dir(), 'race') . '.php';
        file_put_contents($script, $child);

        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            $this->markTestSkipped('Không mở được tiến trình nền.');
        }

        // Chờ con nạp xong Laravel — nếu không chờ, "tranh chấp" chỉ là hai
        // thao tác cách nhau vài trăm mili giây.
        $deadline = microtime(true) + 30;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(5000);
        }

        $this->assertFileExists($ready, 'Tiến trình con không nạp được Laravel — chưa đo được gì.');

        $parentOutcome = 'ok';

        // Bắn cờ rồi lao vào ngay: hai bên cùng gọi service trong cùng khoảnh khắc.
        file_put_contents($go, 'go');

        try {
            $this->actingAs($buyerA);
            app(CartService::class)->addItem($cartA, $screen->id, $dates + ['share_of_voice_pct' => 100]);
        } catch (HttpException $e) {
            $parentOutcome = 'refused:' . $e->getStatusCode() . ':' . $e->getMessage();
        } catch (\Throwable $e) {
            $parentOutcome = 'error:' . get_class($e) . ':' . $e->getMessage();
        }

        // Chờ con xong và đọc kết quả của nó — không đoán theo exit code suông.
        $waited = microtime(true) + 30;
        while (proc_get_status($process)['running'] && microtime(true) < $waited) {
            usleep(10000);
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $childOutcome = file_exists($result) ? trim((string) file_get_contents($result)) : 'khong-co-ket-qua';

        @unlink($ready);
        @unlink($go);
        @unlink($result);
        @unlink($script);

        $this->assertSame(
            0,
            $exitCode,
            "Tiến trình con phải kết thúc bình thường; nó hỏng thì phép đo không nói lên điều gì. stderr: {$stderr}"
        );
        $this->assertNotSame('khong-co-ket-qua', $childOutcome, 'Tiến trình con không ghi kết quả.');

        $outcomes = [$parentOutcome, $childOutcome];
        $shown    = json_encode($outcomes, JSON_UNESCAPED_UNICODE);

        // KHÔNG chấp nhận lỗi bất kỳ ở bên thua.
        //
        // Bản trước chỉ đếm "đúng một chuỗi ok" và tổng SOV ≤ 100. Nếu tiến
        // trình con ném một ngoại lệ nào khác — deadlock, lỗi tenancy, lỗi lập
        // trình — nó vẫn ghi "fail" và exit 0, nên test vẫn xanh **dù nó chưa
        // hề tranh chấp service** (Codex R33). Một phép đo chấp nhận mọi kiểu
        // hỏng thì không phân biệt được "chặn đúng" với "hỏng".
        foreach ($outcomes as $outcome) {
            $this->assertStringStartsNotWith(
                'error:',
                $outcome,
                "Một bên hỏng vì lý do khác chứ không phải hết suất, nên phép đo không nói lên điều gì. Kết quả: {$shown}"
            );
        }

        $wins    = array_values(array_filter($outcomes, fn ($o) => $o === 'ok'));
        $refused = array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'refused:')));

        $this->assertCount(1, $wins, "Đúng MỘT bên được suất 100%. Kết quả: {$shown}");
        $this->assertCount(1, $refused, "Bên còn lại phải bị TỪ CHỐI, không phải hỏng. Kết quả: {$shown}");

        $this->assertStringStartsWith(
            'refused:422:',
            $refused[0],
            "Bên thua phải nhận 422 vì hết suất. Kết quả: {$shown}"
        );
        $this->assertStringContainsString(
            'SOV',
            $refused[0],
            "Thông báo phải là lý do hết suất, không phải một lỗi 422 khác. Kết quả: {$shown}"
        );

        // Bên thắng phải có đủ dấu vết: một dòng giỏ VÀ một giữ chỗ.
        $this->assertSame(
            1,
            \App\Models\CartItem::whereIn('cart_id', $this->cleanupCartIds)->where('screen_id', $screen->id)->count(),
            "Đúng một dòng giỏ được ghi. Kết quả: {$shown}"
        );
        $this->assertSame(
            1,
            InventoryHold::effective()->where('screen_id', $screen->id)->count(),
            "Đúng một giữ chỗ còn hiệu lực. Kết quả: {$shown}"
        );

        // Và quan trọng hơn con số thắng/thua: kho không được bán vượt.
        $held = (int) InventoryHold::effective()
            ->where('screen_id', $screen->id)
            ->sum('sov_pct');

        $this->assertSame(
            100,
            $held,
            'Tổng SOV đang giữ phải đúng 100%: hơn là bán vượt suất, kém là bên thắng không giữ được gì.'
        );
    }
}
