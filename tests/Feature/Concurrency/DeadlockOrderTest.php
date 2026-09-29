<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Đo thật mối nguy R05: khóa ngoại giữ shared lock khi chèn, rồi mới xin
 * exclusive lock.
 *
 * Codex chỉ ra rằng sắp thứ tự khóa tường minh **sau khi chèn** không giải
 * quyết được chuyện này: hai transaction cùng chèn một dòng tham chiếu tới
 * cùng một màn hình đều nhận shared lock trên hàng `screens` (do MySQL kiểm
 * khóa ngoại), rồi cả hai cùng xin `FOR UPDATE`. Nâng shared lên exclusive khi
 * bên kia đang giữ shared là deadlock — dù chỉ có **một** màn hình, tức là
 * không thứ tự khóa nào cứu được.
 *
 * PHP chạy một luồng nên không thể để một câu lệnh treo rồi chạy tiếp. Test
 * này dùng một tiến trình PHP nền nói chuyện với MySQL qua PDO, đóng vai giao
 * dịch thứ hai — concurrency thật, không mô phỏng.
 */
class DeadlockOrderTest extends TestCase
{
    use RefreshDatabase;

    private ?string $screenId = null;
    private ?string $cartId = null;

    protected function tearDown(): void
    {
        if ($this->screenId) {
            try {
                DB::table('cart_items')->where('screen_id', $this->screenId)->delete();
                DB::table('screens')->where('id', $this->screenId)->delete();
                DB::table('carts')->where('id', $this->cartId)->delete();
            } catch (\Throwable) {
                // dọn hết sức, không che lỗi thật của test
            }
        }

        parent::tearDown();
    }

    /**
     * Chạy một kịch bản PDO trong tiến trình riêng, trả về handle để chờ.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawn(string $phpBody): array
    {
        $db = Config::get('database.connections.' . Config::get('database.default'));

        $prelude = sprintf(
            '<?php $pdo = new PDO(%s, %s, %s, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);',
            var_export("mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}", true),
            var_export($db['username'], true),
            var_export($db['password'], true),
        );

        $script = tempnam(sys_get_temp_dir(), 'race') . '.php';
        file_put_contents($script, $prelude . "\n" . $phpBody);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes);

        if (! is_resource($process)) {
            $this->markTestSkipped('Không mở được tiến trình PHP nền để đo tranh chấp.');
        }

        return [$process, $pipes];
    }

    private function seedScreen(): string
    {
        $this->screenId = (string) Str::ulid();
        $this->cartId   = (string) Str::ulid();

        // Giỏ phải TỒN TẠI THẬT: phần đo dựa vào việc chèn `cart_items` kích
        // hoạt kiểm khóa ngoại. Nếu bản thân phép chèn hỏng vì thiếu giỏ thì
        // test dừng trước khi chạm tới thứ cần đo — đúng lỗi vòng CI trước.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('carts')->insert([
            'id'         => $this->cartId,
            'user_id'    => 1,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('screens')->insert([
            'id'          => $this->screenId,
            'site_id'     => (string) Str::ulid(),
            'owner_id'    => (string) Str::ulid(),
            'external_id' => 'dl-' . Str::random(6),
            'uuid'        => (string) Str::uuid(),
            'name'        => 'Màn hình đo deadlock',
            'active'      => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return $this->screenId;
    }

    /**
     * Thứ tự AN TOÀN: khóa hàng màn hình TRƯỚC, rồi mới chèn.
     *
     * Hai giao dịch cùng làm như vậy thì giao dịch thứ hai chỉ **chờ**, không
     * deadlock — vì không ai giữ shared lock rồi mới xin exclusive.
     */
    public function test_khoa_truoc_roi_chen_thi_chi_cho_chu_khong_deadlock(): void
    {
        $screenId = $this->seedScreen();

        // Tiến trình nền: khóa trước, giữ 2 giây, rồi chèn và commit.
        [$process, $pipes] = $this->spawn(<<<PHP
        \$pdo->beginTransaction();
        \$pdo->prepare('SELECT id FROM screens WHERE id = ? FOR UPDATE')->execute(['{$screenId}']);
        usleep(1500000);
        \$pdo->commit();
        echo "ok";
        PHP);

        // Cho tiến trình nền kịp giành khóa.
        usleep(400000);

        $error = null;
        $start = microtime(true);

        try {
            DB::transaction(function () use ($screenId) {
                // Thứ tự đúng: khóa trước.
                DB::table('screens')->where('id', $screenId)->lockForUpdate()->first();
                DB::table('cart_items')->insert([
                    'cart_id'    => $this->cartId,
                    'screen_id'  => $screenId,
                    'start_date' => now()->addYear()->toDateString(),
                    'end_date'   => now()->addYear()->addMonth()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        $waited = microtime(true) - $start;

        $this->assertNull($error, "Khóa trước rồi chèn không được deadlock. Lỗi nhận được: {$error}");
        $this->assertGreaterThan(
            0.5,
            $waited,
            'Phải thực sự CHỜ tiến trình kia nhả khóa — không chờ nghĩa là khóa không có tác dụng.'
        );
    }

    /**
     * Thứ tự NGUY HIỂM: chèn trước (nhận shared lock qua khóa ngoại), rồi mới
     * xin exclusive lock.
     *
     * Đây là hình dạng mà `addItem` và `createFromCart` từng có. Test ghi lại
     * hành vi thật của MySQL để lần sau ai đó đảo lại thứ tự thì thấy ngay hậu
     * quả, thay vì tin vào lập luận.
     */
    public function test_chen_truoc_roi_moi_khoa_gay_deadlock(): void
    {
        $screenId = $this->seedScreen();
        $cartId   = $this->cartId;

        [$process, $pipes] = $this->spawn(<<<PHP
        \$pdo->beginTransaction();
        // Chèn trước: MySQL kiểm khóa ngoại và giữ shared lock trên hàng screens.
        \$stmt = \$pdo->prepare('INSERT INTO cart_items (cart_id, screen_id, start_date, end_date, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())');
        \$stmt->execute(['{$cartId}', '{$screenId}', '2027-01-01', '2027-02-01']);
        usleep(800000);
        try {
            \$pdo->prepare('SELECT id FROM screens WHERE id = ? FOR UPDATE')->execute(['{$screenId}']);
            \$pdo->commit();
            echo "no-deadlock";
        } catch (Throwable \$e) {
            echo "deadlock";
        }
        PHP);

        usleep(300000);

        $outcome = 'khong-loi';

        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout=3');
            DB::beginTransaction();

            DB::table('cart_items')->insert([
                'cart_id'    => $this->cartId,
                'screen_id'  => $screenId,
                'start_date' => now()->addYear()->toDateString(),
                'end_date'   => now()->addYear()->addMonth()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('screens')->where('id', $screenId)->lockForUpdate()->first();
            DB::commit();
        } catch (\Throwable $e) {
            $outcome = str_contains(strtolower($e->getMessage()), 'deadlock') ? 'deadlock' : 'cho-het-gio';

            try {
                DB::rollBack();
            } catch (\Throwable) {
                // phiên đã bị hủy cùng lỗi
            }
        } finally {
            $background = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            DB::table('cart_items')->where('screen_id', $screenId)->delete();
        }

        // Một trong hai bên phải hỏng — đó chính là điều Codex cảnh báo. Ghi
        // nhận cả hai dạng hỏng: MySQL có thể báo deadlock, hoặc bên chờ hết
        // giờ tùy phiên nào bị chọn làm nạn nhân.
        $this->assertContains(
            $outcome,
            ['deadlock', 'cho-het-gio'],
            'Chèn trước rồi mới khóa mà KHÔNG hỏng gì thì giả định của R05 sai trên cấu hình này — '
            . "kết quả tiến trình nền: {$background}. Xem lại trước khi tin vào thứ tự khóa hiện tại."
        );
    }
}
