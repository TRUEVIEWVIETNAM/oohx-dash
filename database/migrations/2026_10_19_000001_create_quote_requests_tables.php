<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yêu cầu báo giá — lát 5 của `docs/PLAN-EXPLORE-QUYET-DINH-MUA.md`.
 *
 * ══ Vì sao hai bảng chứ không một ══
 *
 * Người mua gửi 12 vị trí của 3 media owner là **một** hành động của họ,
 * nhưng là **ba** việc phải trả lời. Một bảng với cột JSON chứa danh sách thì
 * không scope theo owner được: `HasOwnerScope` lọc theo cột, không lọc được
 * vào trong một blob.
 *
 * Nên yêu cầu nằm ở `quote_requests`, còn mỗi vị trí là một dòng
 * `quote_request_items` mang `owner_id` của chính nó. Owner A thấy đúng phần
 * của mình và không bao giờ biết yêu cầu đó còn hỏi owner B.
 *
 * ══ Ảnh chụp tại thời điểm gửi ══
 *
 * `owner_id`, `screen_name`, `city` lưu **giá trị lúc gửi**, không join ra để
 * đọc lúc hiển thị. Màn hình có thể đổi chủ hoặc đổi tên sau đó; yêu cầu đã
 * gửi thì không đổi theo (CLAUDE.md mục 6: không sửa dữ liệu lịch sử). Vẫn
 * giữ `screen_id` để tra ngược khi cần, với `nullOnDelete` — xoá một màn hình
 * không được xoá mất yêu cầu đã gửi.
 *
 * ══ Liên hệ người mua: chủ dự án chốt 19/10/2026 ══
 *
 * Media owner **không** thấy tên, email, điện thoại người gửi cho tới khi
 * quản trị duyệt. Mốc đó là `approved_at`; không có nó thì `/publisher` chỉ
 * thấy danh sách vị trí và cửa sổ chiến dịch. Nếu không, sàn tự biến mình
 * thành danh bạ khách hàng miễn phí.
 *
 * **Không** có cột hạn trả lời: chủ dự án chốt sàn không cam kết thời gian.
 * `/admin` lọc theo `created_at` khi cần tìm yêu cầu để lâu — đó là công cụ
 * nội bộ, không phải lời hứa với người mua.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quote_requests')) {
            Schema::create('quote_requests', function (Blueprint $table) {
                $table->ulid('id')->primary();

                // YCB-YYYYMM-XXXX — người gửi giữ mã này để hỏi lại. Cùng dạng
                // với mã phản ánh (PA-) và mã chiến dịch (CPN-).
                $table->string('code', 20)->unique();

                // Null khi khách gửi mà chưa đăng nhập — chủ dự án chốt là cho
                // phép. Có tổ chức thì ghi lại để `/my` sau này tra được.
                $table->ulid('organization_id')->nullable();

                // ── Người gửi tự khai. KHÔNG công khai, không sang owner
                //    trước khi duyệt. ──
                $table->string('contact_name');
                $table->string('contact_email');
                $table->string('contact_phone', 30)->nullable();
                $table->string('contact_company')->nullable();

                // ── Nội dung yêu cầu ──
                $table->date('campaign_start')->nullable();
                $table->date('campaign_end')->nullable();

                // VND số nguyên (CLAUDE.md mục 6). Người mua tự khai, sàn
                // không tính gì từ con số này — nó chỉ giúp owner biết có nên
                // báo giá gói lớn hay nhỏ.
                $table->unsignedBigInteger('budget_vnd')->nullable();

                $table->text('notes')->nullable();

                // ── Xử lý ──
                $table->enum('status', ['pending', 'approved', 'rejected', 'closed'])
                    ->default('pending');

                // Mốc mở liên hệ cho media owner. Một cột riêng chứ không suy
                // từ `status`: trạng thái còn đổi tiếp (`closed`), nhưng
                // "liên hệ đã mở" thì không quay lại được.
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('internal_notes')->nullable();

                // ── Chống spam, và dấu vết cho CLAUDE.md mục 5 ──
                $table->string('submitted_ip', 45)->nullable();
                $table->string('user_agent', 512)->nullable();

                $table->timestamps();

                $table->index('status');
                $table->index('created_at');
                $table->index('organization_id');

                $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('quote_request_items')) {
            Schema::create('quote_request_items', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('quote_request_id');

                // Owner **tại thời điểm gửi** — cột mà `/publisher` scope theo.
                $table->ulid('owner_id');

                $table->ulid('screen_id')->nullable();

                // Ảnh chụp: yêu cầu đã gửi đọc được nguyên vẹn kể cả khi màn
                // hình đổi tên, đổi chủ, hay bị gỡ khỏi sàn.
                $table->string('screen_slug', 191);
                $table->string('screen_name');
                $table->string('city')->nullable();

                $table->timestamps();

                // Một màn hình chỉ vào một yêu cầu đúng một lần. Gửi trùng là
                // lỗi của client, và nếu không chặn ở đây thì owner đọc thấy
                // cùng một vị trí hai dòng.
                $table->unique(['quote_request_id', 'screen_slug'], 'qri_request_slug_unique');

                $table->index('owner_id');

                $table->foreign('quote_request_id')->references('id')->on('quote_requests')->cascadeOnDelete();
                $table->foreign('owner_id')->references('id')->on('owners')->cascadeOnDelete();
                $table->foreign('screen_id')->references('id')->on('screens')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_request_items');
        Schema::dropIfExists('quote_requests');
    }
};
