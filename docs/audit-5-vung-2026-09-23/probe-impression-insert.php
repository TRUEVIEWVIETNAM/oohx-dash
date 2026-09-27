<?php
/**
 * Probe: ImpressionLog::create() có ghi được không?
 *
 * Chạy qua `artisan tinker --execute` với CSDL test dùng một lần.
 * Gọi đúng model của ứng dụng và đúng schema do migration tạo ra —
 * không dựng bảng giả, không mock.
 *
 * Kỳ vọng trên commit 112e2aa: INSERT_FAILED, SQLSTATE 1364,
 * vì impression_logs.id là ULID không có giá trị mặc định
 * còn App\Models\ImpressionLog không dùng trait HasUlids.
 */

use App\Models\ImpressionLog;
use Illuminate\Support\Facades\DB;

echo "== Probe ImpressionLog::create()\n";

// Ghi rõ chế độ SQL đang dùng: chế độ lỏng có thể che lỗi thiếu giá trị mặc định.
echo 'sql_mode=' . DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m . "\n";

$col = DB::selectOne("SHOW COLUMNS FROM impression_logs LIKE 'id'");
echo "id_column: type={$col->Type} null={$col->Null} key={$col->Key} default=" . var_export($col->Default, true) . "\n";

$partitions = DB::select(
    'SELECT partition_name FROM information_schema.partitions
     WHERE table_schema = DATABASE() AND table_name = ? AND partition_name IS NOT NULL',
    ['impression_logs']
);
echo 'partitions=' . count($partitions) . "\n";

echo 'model_uses_HasUlids=' . var_export(
    in_array(Illuminate\Database\Eloquent\Concerns\HasUlids::class, class_uses_recursive(ImpressionLog::class), true),
    true
) . "\n";

// Payload giống hệt PlayerController::impression() dựng (app/Http/Controllers/Api/V1/PlayerController.php:58-70)
try {
    $log = ImpressionLog::create([
        'screen_id'          => '01JQZZZZZZZZZZZZZZZZZZZZZZ',
        'owner_id'           => '01JQYYYYYYYYYYYYYYYYYYYYYY',
        'campaign_id'        => null,
        'creative_id'        => null,
        'played_at'          => now(),
        'duration_sec'       => 15,
        'multiplier_applied' => 1.0,
        'imp_count'          => 1,
        'deal_type'          => 'direct',
        'proof_url'          => null,
        'source'             => 'adtrue_player',
    ]);
    echo 'RESULT=INSERT_OK id=' . var_export($log->id, true) . "\n";
} catch (\Throwable $e) {
    echo 'RESULT=INSERT_FAILED class=' . get_class($e) . "\n";
    echo 'message=' . substr($e->getMessage(), 0, 240) . "\n";
}

echo 'rows_in_table=' . DB::table('impression_logs')->count() . "\n";
echo "== Hết probe\n";
