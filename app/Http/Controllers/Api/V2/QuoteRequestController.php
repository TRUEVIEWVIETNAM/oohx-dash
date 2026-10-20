<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreQuoteRequestRequest;
use App\Services\QuoteRequestService;
use Illuminate\Http\JsonResponse;

/**
 * Yêu cầu báo giá — đường ghi công khai (lát 5).
 *
 * ══ Trả về cái gì, và cố ý KHÔNG trả về cái gì ══
 *
 * Trả **mã tra cứu** và số vị trí nhận được. Không trả tên media owner, không
 * trả danh sách owner phải trả lời, không trả gì về kho ngoài con số.
 *
 * Lý do: endpoint này mở cho người lạ và không có hạn mức nào ngăn được việc
 * gửi 50 slug rồi đọc phản hồi để biết chúng thuộc về ai. Người mua đã biết
 * mình chọn gì; thứ họ cần sau khi gửi là bằng chứng đã gửi, không phải một
 * bản đồ quan hệ sở hữu của sàn.
 */
class QuoteRequestController extends Controller
{
    public function __construct(private QuoteRequestService $quotes)
    {
    }

    public function store(StoreQuoteRequestRequest $request): JsonResponse
    {
        $duocNhan = $this->quotes->traManHinh($request->input('slugs', []));

        // Không slug nào còn công khai. Khác với "một vài slug đã gỡ" — cái
        // đó bỏ qua được — vì ở đây không còn gì để hỏi giá, và một mã tra
        // cứu cho một yêu cầu rỗng là một lời hứa suông.
        if ($duocNhan->isEmpty()) {
            return response()->json([
                'error'   => 'khong_con_vi_tri',
                'message' => 'Không vị trí nào trong danh sách còn mở trên sàn. '
                    . 'Vui lòng chọn lại.',
                'code'    => 422,
                'details' => [],
            ], 422);
        }

        $yeuCau = $this->quotes->tao(
            array_merge($request->validated(), [
                // Tổ chức lấy từ phiên, **không** nhận từ client: một người
                // gửi được `organization_id` của tổ chức khác thì yêu cầu đó
                // nằm trong hộp thư của họ.
                'organization_id' => $request->user()?->current_organization_id,
            ]),
            $request,
        );

        return response()->json([
            'data' => [
                'code'     => $yeuCau->code,
                'so_vi_tri' => $yeuCau->items->count(),

                // Nói ra khi có slug bị bỏ, thay vì im lặng nhận ít hơn người
                // dùng gửi. Danh sách rút gọn sống trong `localStorage` hàng
                // tuần, nên một màn hình gỡ khỏi sàn trong lúc đó là chuyện
                // thường — nhưng người gửi phải biết.
                'so_bi_bo' => max(0, count($request->input('slugs', [])) - $yeuCau->items->count()),

                'message' => 'Đã nhận yêu cầu báo giá. Vui lòng giữ mã này để tra cứu.',
            ],
        ], 201);
    }
}
