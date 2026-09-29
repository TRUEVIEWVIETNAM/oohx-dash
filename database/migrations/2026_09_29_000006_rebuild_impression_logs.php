<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dựng lại bảng bằng chứng phát sóng.
 *
 * Bảng cũ **chưa từng ghi được một dòng nào**: khóa chính là `(id, played_at)`
 * với `id` kiểu `char(26)` không có giá trị mặc định, còn model thì thiếu trait
 * `HasUlids`, nên mọi lần chèn đều hỏng với SQLSTATE 1364. Đã dựng probe tái
 * hiện (`probe-impression-insert.php`). Nghĩa là toàn bộ "bằng chứng phát sóng"
 * của sàn hiện không tồn tại.
 *
 * Ba lỗi nữa cùng nằm ở đây:
 *
 * - `campaign_id` và `creative_id` khai là `unsignedBigInteger`, nhưng chiến
 *   dịch và nội dung đều dùng ULID. Kể cả nếu ghi được thì cũng **không nối
 *   được bằng chứng với đơn hàng** — `CampaignReportService` đang lọc
 *   `campaign_id = <ulid>` trên một cột số nguyên, vĩnh viễn không khớp.
 * - Không có cách chống trùng. Thiết bị gửi lại sau khi mất mạng là cộng thêm
 *   lượt hiển thị, tức là cộng thêm tiền.
 * - Không có đường nối tới **dòng đặt chỗ**. Bằng chứng phát sóng tồn tại để
 *   trả lời "suất đã bán này có chạy không", mà chiến dịch thì gồm nhiều dòng.
 *
 * Chủ dự án xác nhận 29/09/2026: chưa có thiết bị nào gửi dữ liệu thật, nên
 * dựng lại sạch thay vì viết migration bảo toàn dữ liệu. Migration vẫn **đếm
 * lại trước khi xóa** — giả định đó phải được kiểm lúc chạy, không phải tin.
 *
 * Hai ràng buộc của bảng phân vùng, không được quên:
 *  1. Mọi khóa unique phải chứa cột phân vùng `played_at`.
 *  2. MySQL **không cho khóa ngoại** trên bảng đã phân vùng — nên các cột
 *     `*_id` ở đây không có ràng buộc tham chiếu, và việc kiểm phải làm ở tầng
 *     ứng dụng.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('impression_logs')) {
            $rows = DB::table('impression_logs')->count();

            if ($rows > 0) {
                throw new RuntimeException(
                    "impression_logs đang có {$rows} bản ghi. Migration này dựng lại bảng từ đầu, "
                    . 'dựa trên xác nhận "chưa có thiết bị nào gửi dữ liệu thật". Xác nhận đó nay sai. '
                    . 'Dừng lại: cần một migration chuyển đổi giữ nguyên dữ liệu.'
                );
            }

            Schema::drop('impression_logs');
        }

        Schema::create('impression_logs', function (Blueprint $table) {
            $table->char('id', 26);

            $table->char('screen_id', 26);
            $table->char('owner_id', 26);

            // ULID, không phải số nguyên — xem phần mô tả ở đầu file.
            $table->char('campaign_id', 26)->nullable();
            $table->char('creative_id', 26)->nullable();

            // Bằng chứng gắn với DÒNG ĐẶT CHỖ, không chỉ với chiến dịch: một
            // chiến dịch gồm nhiều dòng, mỗi dòng là một suất đã bán riêng.
            $table->char('booking_line_id', 26)->nullable();

            // Mã sự kiện do thiết bị sinh, để gửi lại không cộng thêm lượt.
            $table->string('event_id', 64);

            // Thời điểm phát do thiết bị báo (đã được máy chủ chặn biên).
            $table->timestamp('played_at');

            $table->unsignedSmallInteger('duration_sec');
            $table->decimal('multiplier_applied', 5, 2)->default(1.00);
            $table->decimal('imp_count', 10, 2);
            $table->enum('deal_type', ['direct', 'rtb', 'pmp'])->default('direct');
            $table->decimal('cpm_charged', 10, 4)->nullable();
            $table->decimal('revenue_gross', 10, 4)->nullable();
            $table->decimal('revenue_owner', 10, 4)->nullable();
            $table->string('proof_url')->nullable();
            $table->enum('source', ['adtrue_player', 'vast_ping', 'manual', 'api'])->default('adtrue_player');

            // Thời điểm máy chủ nhận. Khác played_at khi thiết bị gửi bù sau
            // khi mất mạng — và chênh lệch giữa hai mốc là thứ cần nhìn khi
            // nghi ngờ đồng hồ thiết bị sai.
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['id', 'played_at']);

            // Chống trùng, gồm cả `screen_id`: `event_id` do thiết bị tự sinh
            // nên chỉ duy nhất trong phạm vi một thiết bị — hai màn hình trùng
            // mã sự kiện là chuyện có thể xảy ra và không được coi là trùng.
            //
            // Bắt buộc chứa `played_at`: MySQL từ chối khóa unique không chứa
            // cột phân vùng.
            $table->unique(['screen_id', 'event_id', 'played_at'], 'impression_logs_event_unique');

            $table->index(['screen_id', 'played_at']);
            $table->index(['owner_id', 'played_at']);
            $table->index(['campaign_id', 'played_at']);
            $table->index(['booking_line_id', 'played_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE impression_logs
                PARTITION BY RANGE (UNIX_TIMESTAMP(played_at)) (
                    PARTITION p2026_q1 VALUES LESS THAN (UNIX_TIMESTAMP('2026-04-01 00:00:00')),
                    PARTITION p2026_q2 VALUES LESS THAN (UNIX_TIMESTAMP('2026-07-01 00:00:00')),
                    PARTITION p2026_q3 VALUES LESS THAN (UNIX_TIMESTAMP('2026-10-01 00:00:00')),
                    PARTITION p2026_q4 VALUES LESS THAN (UNIX_TIMESTAMP('2027-01-01 00:00:00')),
                    PARTITION p2027_q1 VALUES LESS THAN (UNIX_TIMESTAMP('2027-04-01 00:00:00')),
                    PARTITION p2027_q2 VALUES LESS THAN (UNIX_TIMESTAMP('2027-07-01 00:00:00')),
                    PARTITION p_future VALUES LESS THAN MAXVALUE
                )
            ");
        }

        // Bảng tổng hợp theo ngày: báo cáo đọc ở đây, không quét bảng thô.
        if (! Schema::hasTable('impression_daily_rollups')) {
            Schema::create('impression_daily_rollups', function (Blueprint $table) {
                $table->id();
                $table->date('day');
                $table->char('screen_id', 26);
                $table->char('owner_id', 26);

                // Chuỗi rỗng thay cho NULL, và đây không phải chuyện thẩm mỹ:
                // trong MySQL, NULL khác NULL, nên khóa unique chứa cột NULL
                // **không chặn được trùng**. Phép tổng hợp chạy lại lần hai sẽ
                // đẻ thêm một dòng nữa và số liệu báo cáo nhân đôi.
                // Rỗng nghĩa là "lượt phát không gắn chiến dịch nào".
                $table->char('campaign_id', 26)->default('');
                $table->char('booking_line_id', 26)->default('');

                $table->unsignedInteger('plays')->default(0);
                $table->decimal('impressions', 14, 2)->default(0);
                $table->unsignedBigInteger('duration_sec_total')->default(0);
                $table->decimal('revenue_gross', 14, 4)->default(0);

                $table->timestamps();

                // Một dòng cho mỗi tổ hợp. Chạy lại phép tổng hợp thì cập nhật
                // đúng dòng đó, không nhân đôi số liệu.
                $table->unique(
                    ['day', 'screen_id', 'campaign_id', 'booking_line_id'],
                    'impression_rollup_unique'
                );
                $table->index(['owner_id', 'day']);
                $table->index(['campaign_id', 'day']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('impression_daily_rollups');
        Schema::dropIfExists('impression_logs');
    }
};
