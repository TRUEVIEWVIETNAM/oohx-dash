<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\OwnerRemittanceResource;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Owner;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Nơi chuyển tiền tới — `GET campaigns/{campaign}/payment-recipients`.
 *
 * Một action, một controller. Không gộp vào `Api\V2\PaymentController` dù cùng
 * nhóm nghiệp vụ, và đó là chủ ý: controller kia hứa trong chú thích đầu tệp
 * rằng thông tin nhận tiền **không đi qua nó**, và `by_owner` của nó chỉ có
 * `owner.id` + `owner.name`. Giữ lời hứa đó theo đúng nghĩa chữ thì mọi phản
 * hồi thanh toán vẫn sạch, chỉ đúng một đường mang dữ liệu nhạy cảm, và đường
 * đó canh được bằng một tệp.
 *
 * ══ Vì sao endpoint này tồn tại dù CLAUDE.md mục 2 cấm ══
 *
 * Lý do nghiệp vụ nằm ở `OwnerRemittanceResource`: sàn không thu hộ, người mua
 * chuyển thẳng cho từng media owner, nên không thấy nơi nhận tiền là không trả
 * được. Ngoại lệ được duyệt ngày 08/10/2026.
 *
 * ══ Năm lớp chặn, và vì sao cần cả năm ══
 *
 * Một trang render phía máy chủ phơi dữ liệu hẹp hơn một endpoint: nó không
 * cache được, không gọi lại bằng script được, không nằm trong lịch sử của một
 * client nào. Đổi sang endpoint là mở rộng diện phơi, nên phải bù lại:
 *
 * 1. **Không có tham số nào.** Danh sách owner dẫn từ `bookingLines` của chính
 *    campaign này. "Chỉ owner CÓ màn hình trong campaign" vì vậy đúng do cấu
 *    trúc, không do một phép kiểm có thể quên gọi. Nhận `owner_id` từ client
 *    rồi kiểm là cách còn chỗ cho lỗi; không nhận thì không có chỗ.
 *
 * 2. **Cần quyền `pay`, không phải quyền xem.** Đây là siết chặt so với trang
 *    Blade hiện tại — trang đó chỉ gọi `view`, nên vai trò `viewer` đang đọc
 *    được số tài khoản. Người cần số tài khoản là người đi chuyển tiền, và đó
 *    là `manage_payments`. Xem `OrganizationUser::PERMISSIONS`.
 *
 * 3. **Campaign phải ở trạng thái trả được.** Cùng `PaymentService::PAYABLE_STATUSES`
 *    với đường ghi — một định nghĩa, ba nơi dùng. Chưa duyệt thì chưa có gì để
 *    trả, nên chưa có lý do để đọc.
 *
 * 4. **Chặn cache ở mọi tầng.** `no-store` + `private` để trình duyệt,
 *    Cloudflare và mọi proxy trên đường đều không giữ lại bản sao.
 *
 * 5. **Ghi nhật ký ai đọc, lúc nào.** CLAUDE.md mục 5. Không có "trước/sau" vì
 *    đây là đường đọc; thứ cần giữ là *ai* và *lúc nào*.
 *
 * Hạn mức tần suất là lớp thứ sáu, khai ở route.
 *
 * ══ Không có số tiền ở đây ══
 *
 * Endpoint này trả danh tính + nơi nhận tiền, hết. Số tiền phải trả vẫn ở
 * `GET campaigns/{campaign}/payments`. Tách như vậy có hai cái được: đường
 * nhạy cảm không mang một bản sao thứ hai của phép tính tiền (một bản sao là
 * một chỗ để trôi khỏi bản gốc), và trang gọi hai đường song song nên không
 * mất thêm vòng mạng nào.
 */
class PaymentRecipientController extends Controller
{
    /** Mỗi người, mỗi campaign, ghi nhật ký nhiều nhất một lần trong 10 phút. */
    private const CUA_CHONG_LUT_GIAY = 600;

    // Không tiêm `PaymentService`: controller này không làm phép tính tiền
    // nào, chỉ đọc hằng số trạng thái từ đó. Tiêm một service rồi không dùng
    // là nói sai về việc controller này làm gì.

    public function __invoke(Request $request, Campaign $campaign): JsonResponse
    {
        $user = $request->user();

        // 404 khi không được xem, 403 khi được xem nhưng không được trả — cùng
        // hình dạng với `Api\V2\PaymentController`. Không được xem thì không
        // được biết campaign này có tồn tại hay không.
        abort_unless(
            $user?->can('view', $campaign) ?? false,
            404,
            'Không tìm thấy campaign này.',
        );

        abort_unless(
            $user->can('pay', $campaign),
            403,
            'Bạn không có quyền xem thông tin nhận tiền của campaign này.',
        );

        abort_unless(
            in_array($campaign->status, PaymentService::PAYABLE_STATUSES, true),
            422,
            'Campaign chưa được duyệt nên chưa có thông tin nhận tiền.',
        );

        $owners = $this->ownersInCampaign($campaign);

        $this->ghiNhatKy($request, $campaign, $owners);

        return response()
            ->json([
                'data' => [
                    'campaign' => [
                        'id'   => $campaign->id,
                        'code' => $campaign->code,
                    ],

                    // Nội dung người mua ghi khi chuyển khoản. Do máy chủ đưa
                    // ra, không để client tự ghép: đối soát dựa vào đúng chuỗi
                    // này, và hai client ghép hai kiểu là hai kiểu đối soát.
                    'transfer_note' => $campaign->code,

                    'recipients' => OwnerRemittanceResource::collection($owners)->resolve(),
                ],
            ])
            // `no-store` là cái chính: nó cấm *lưu*, trong khi `no-cache` chỉ
            // bắt kiểm lại trước khi dùng. `private` chặn thêm tầng dùng chung
            // (Cloudflare, proxy công ty). `Pragma` và `Expires` cho client cũ
            // không hiểu `Cache-Control`.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Các media owner CÓ màn hình trong campaign này.
     *
     * Dẫn từ `booking_lines`, cùng nguồn với `PaymentService::breakdownByOwner()`
     * — nên danh sách ở đây và danh sách công nợ không bao giờ lệch nhau. Lọc
     * `status` giống hệt bên đó: một dòng đã hủy không còn là nghĩa vụ trả
     * tiền, nên owner chỉ còn dòng đã hủy thì không có lý do để lộ thông tin
     * nhận tiền của họ.
     */
    private function ownersInCampaign(Campaign $campaign)
    {
        $ownerIds = $campaign->bookingLines()
            ->whereIn('status', ['approved', 'active', 'completed'])
            ->distinct()
            ->pluck('owner_id');

        if ($ownerIds->isEmpty()) {
            return collect();
        }

        return Owner::whereIn('id', $ownerIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * Ai đọc thông tin nhận tiền, lúc nào, của bao nhiêu owner.
     *
     * ══ Vì sao có cửa chống lụt ══
     *
     * `CampaignActivity` hiện nguyên trên trang `/my/campaigns/{campaign}` và
     * **không phân trang**. Ghi một dòng cho mỗi lần tải trang thanh toán là
     * nhấn chìm lịch sử thật — duyệt, gửi, thanh toán — dưới hàng chục dòng
     * "đã xem". Một lần trong 10 phút là đủ để trả lời câu hỏi mà nhật ký này
     * tồn tại để trả lời: *ai đã nhìn thấy số tài khoản, và khi nào*.
     *
     * Cửa đặt ở cache chứ không phải một truy vấn "dòng gần nhất": truy vấn đó
     * tốn một lượt đọc bảng cho mỗi request, và cache đã có sẵn.
     *
     * ══ Vì sao vẫn vào bảng, không chỉ vào tệp log ══
     *
     * Tệp log bị luân chuyển. Một bản ghi "người này đã xem nơi nhận tiền của
     * owner kia" là thứ cần còn đó khi có tranh chấp đối soát, tức tính bằng
     * tháng, nên nó phải nằm trong CSDL cạnh chính campaign đó.
     */
    private function ghiNhatKy(Request $request, Campaign $campaign, $owners): void
    {
        $khoa = 'remittance-viewed:' . $campaign->id . ':' . $request->user()->id;

        if (! Cache::add($khoa, true, self::CUA_CHONG_LUT_GIAY)) {
            return;
        }

        CampaignActivity::log(
            $campaign,
            'remittance_details_viewed',
            'Xem thông tin nhận tiền của ' . $owners->count() . ' media owner',
            $request->user()->id,
            [
                'owner_ids'  => $owners->pluck('id')->all(),
                'ip'         => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );
    }
}
