<?php

namespace App\Http\Controllers;

use App\Models\Creative;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phát tệp nội dung quảng cáo từ disk riêng.
 *
 * ══ Vì sao cần route này ══
 *
 * Trước 03/10/2026 nội dung quảng cáo lưu trên disk `public`, nên tải được qua
 * `/storage/creatives/{campaign}/{file}` **không cần đăng nhập**. Đường dẫn
 * gồm id chiến dịch và tên tệp băm nên khó đoán — nhưng khó đoán không phải
 * phân quyền. CLAUDE.md mục 5 nêu đúng "nội dung quảng cáo" trong nhóm phải để
 * disk riêng và truy cập qua URL ký hạn.
 *
 * ══ Hai lớp, không phải một ══
 *
 * Route này yêu cầu **cả** chữ ký hợp lệ (`signed`) **và** người dùng đã đăng
 * nhập có quyền (`CreativePolicy`). Không phải vì một lớp là đủ, mà vì hai lớp
 * trả lời hai câu khác nhau:
 *
 * - Chữ ký chặn việc dò id và đặt hạn sống cho URL. Không có nó thì
 *   `/creatives/{id}/file` là một mặt tiền để thử từng id.
 * - Policy chặn người không có quyền. Không có nó thì một URL lộ ra ngoài
 *   (lịch sử trình duyệt, ảnh chụp màn hình chia sẻ lại) là một đường vào
 *   dùng được cho tới khi hết hạn.
 *
 * Bỏ lớp nào cũng mở đúng lỗ mà lớp đó đang bịt, nên giữ cả hai.
 *
 * ══ Không dùng `temporaryUrl()` của disk ══
 *
 * Driver `local` chỉ sinh được URL tạm khi bật `'serve' => true`, và khi đó
 * Laravel tự dựng một route phát tệp **không qua policy nào**. Đó là đổi một
 * disk công khai thành một route công khai — cùng lỗ, chỗ khác.
 */
class CreativeFileController extends Controller
{
    public function __invoke(Request $request, Creative $creative): StreamedResponse
    {
        abort_unless(
            $request->user()?->can('view', $creative) ?? false,
            403,
            'Bạn không có quyền xem nội dung này.',
        );

        $disk = Storage::disk(config('creatives.disk'));

        abort_unless(
            $creative->file_path && $disk->exists($creative->file_path),
            404,
            'Không tìm thấy tệp nội dung.',
        );

        // `inline` chứ không `attachment`: thẻ `<img>` ở bảng duyệt nội dung
        // cần hiển thị tại chỗ, không tải về.
        //
        // Tên tệp gửi ra lấy từ `name` do người dùng đặt, nhưng phần mở rộng
        // lấy từ đường dẫn thật trên đĩa — `name` là chuỗi tự do và không phải
        // nguồn đáng tin để quyết định kiểu tệp.
        $extension = pathinfo($creative->file_path, PATHINFO_EXTENSION);
        $filename  = trim(pathinfo($creative->name ?: 'creative', PATHINFO_FILENAME)) ?: 'creative';

        return $disk->response(
            $creative->file_path,
            $filename . ($extension ? '.' . $extension : ''),
            [
                'Content-Type' => $disk->mimeType($creative->file_path) ?: 'application/octet-stream',

                // Riêng tư và có hạn: không để proxy hay CDN giữ lại. URL đã
                // ký hạn thì bản sao nằm trong cache chung sẽ sống lâu hơn
                // chính cái hạn đó.
                'Cache-Control' => 'private, max-age=0, no-store',
            ],
            'inline',
        );
    }
}
