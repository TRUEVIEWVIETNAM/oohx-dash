<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreCampaignRequest;
use App\Http\Requests\Booking\SubmitCampaignRequest;
use App\Http\Requests\Booking\UploadCreativeRequest;
use App\Http\Resources\V2\BookingLineResource;
use App\Http\Resources\V2\CampaignActivityResource;
use App\Http\Resources\V2\CampaignResource;
use App\Http\Resources\V2\CreativeResource;
use App\Http\Resources\V2\OwnerReviewResource;
use App\Http\Resources\V2\OwnerToReviewResource;
use App\Models\Campaign;
use App\Models\OwnerReview;
use App\Models\PolicyConsent;
use App\Services\AvailabilityService;
use App\Services\Booking\CancellationService;
use App\Services\CampaignService;
use App\Services\CartService;
use App\Services\CreativeService;
use App\Services\OwnerReviewService;
use App\Services\PolicyConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Đặt chỗ — nhóm **cần quyền** của `/api/v2`, mốc 3 giai đoạn 5.
 *
 * Ba bước, đúng ba bước mà trang Blade đang có:
 *
 *   POST  campaigns                     tạo campaign từ giỏ hàng
 *   GET   campaigns/{campaign}          xem lại trước khi gửi, VÀ xem chi tiết
 *   POST  campaigns/{campaign}/submit   gửi chờ duyệt
 *
 * `GET campaigns/{campaign}` phục vụ hai màn hình bằng một phản hồi, mở rộng
 * 08/10/2026: bước xem lại trước khi gửi, và trang chi tiết
 * `/my/campaigns/{campaign}` sau khi gửi. Trang sau cần thêm bốn thứ — báo giá
 * hoàn tiền, owner còn đánh giá được, đánh giá đã viết, lịch sử hoạt động —
 * nên chúng nằm ở `detailPayload()`, **chỉ** trên đường đọc. Xem chú thích của
 * `show()` về lý do không nhét vào phần dùng chung.
 *
 * ══ Vì sao nhóm này quan trọng hơn hai mốc trước ══
 *
 * Lộ trình nói rõ: phần ĐỌC hiện cho `CatalogController` gọi thẳng
 * `FrontpageService`, nên tầng DTO, phân trang và định dạng lỗi chỉ có test
 * canh — không có lưu lượng thật chạy qua mỗi ngày. Nhóm cần quyền là chỗ
 * Next.js buộc phải đi qua HTTP thật, nên đây là lần đầu hợp đồng API được
 * **dùng** chứ không chỉ được test.
 *
 * ══ Bốn điều giữ nguyên ══
 *
 * - **Cùng một service.** `CampaignService::createFromCart()` và `submit()` là
 *   đúng hai hàm trang Blade gọi. Không truy vấn nào viết lại ở đây
 *   (CLAUDE.md mục 1).
 * - **Cùng một bộ luật kiểm.** `App\Http\Requests\Booking\*` dùng chung với
 *   Blade, nên API không nhận được thứ trang từ chối.
 * - **Số tiền do máy chủ tính.** Không endpoint nào ở đây nhận số tiền.
 *   `total_budget` là ngân sách người mua tự ghi để theo dõi, không đổi một
 *   đồng nào trong `booking_lines`.
 * - **Bản ghi đồng ý vẫn được ghi.** `PolicyConsentService` lưu IP và thời
 *   điểm. Bỏ nó ở API là mở một đường tạo booking không để lại bằng chứng
 *   đồng ý, và không ai thấy cho tới khi có tranh chấp.
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly CartService $carts,
        private readonly AvailabilityService $availability,
        private readonly PolicyConsentService $consents,
        private readonly CreativeService $creatives,
        private readonly OwnerReviewService $reviews,
        private readonly CancellationService $cancellations,
    ) {}

    /**
     * Số dòng lịch sử hoạt động trả về nhiều nhất.
     *
     * CLAUDE.md mục 2 đòi mọi danh sách có giới hạn cứng. Lịch sử của một
     * chiến dịch bình thường chỉ vài chục dòng, nhưng nó **mọc theo lượt đọc**
     * từ 08/10/2026: `remittance_details_viewed` thêm một dòng mỗi lần có
     * người xem thông tin nhận tiền. Không chặn thì một chiến dịch chạy lâu sẽ
     * trả về một phản hồi lớn dần mà không ai để ý.
     *
     * Trả kèm `activity_count` để client nói được "đang hiện 50 trong 120",
     * chứ không im lặng cắt bớt.
     */
    private const TOI_DA_LICH_SU = 50;

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $user = $request->user();

        // Middleware `buyer` đã bảo đảm có tổ chức và đã đặt
        // `current_organization_id`, nên nhánh sau chỉ là lưới an toàn.
        $org = $user->currentOrganization ?? $user->organizations()->first();

        $cart = $this->carts->getOrCreateCart($user);

        abort_unless(
            $cart->items()->exists(),
            422,
            'Giỏ hàng đang trống, không có gì để đặt chỗ.',
        );

        $campaign = $this->campaigns->createFromCart($org, $user, $cart, $request->validated());

        return response()->json(['data' => $this->reviewPayload($campaign->fresh())], 201);
    }

    /**
     * Xem một chiến dịch — dùng cho CẢ bước xem lại trước khi gửi và trang
     * chi tiết sau khi gửi.
     *
     * Phản hồi gồm hai phần ghép lại:
     *
     * - `reviewPayload()` — phần dùng chung với hai đường GHI bên dưới.
     * - `detailPayload()` — chỉ ở đường ĐỌC này.
     *
     * Vì sao tách: `detailPayload()` chạy `CancellationService::quote()` cho
     * mỗi dòng còn hủy được, và mỗi lần quote là một lượt đọc bảng tiền. Nhét
     * nó vào `reviewPayload()` là bắt mỗi lần tải một tệp nội dung quảng cáo
     * phải tính lại toàn bộ báo giá hoàn tiền — một phép tính không ai hỏi,
     * trên đường mà người dùng đang chờ tệp lên.
     */
    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign, 'view');

        return response()->json([
            'data' => $this->reviewPayload($campaign) + $this->detailPayload($request, $campaign),
        ]);
    }

    /**
     * Tải nội dung quảng cáo lên — bước 2 của đặt chỗ.
     *
     * Endpoint này **không tồn tại** trong bản đầu của mốc 3, có lý do: lúc đó
     * `uploadCreative()` của Blade lưu vào disk `public`, nên thêm đường API sẽ
     * phải chọn giữa lặp lại chỗ sai hoặc dựng đường lưu trữ thứ hai cho cùng
     * một thực thể. Chỗ lưu đã sửa (xem `config/creatives.php` và
     * `CreativeFileController`), nên giờ mở được.
     *
     * Trả về `multipart/form-data`, không phải JSON — đây là đường duy nhất
     * trong `/api/v2` nhận tệp.
     */
    public function storeCreative(UploadCreativeRequest $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign, 'uploadCreative');

        $creative = $this->creatives->store(
            $campaign,
            $request->file('file'),
            $request->input('name'),
        );

        return response()->json([
            'data' => [
                'creative' => (new CreativeResource($creative))->resolve(),
            ] + $this->reviewPayload($campaign->fresh()),
        ], 201);
    }

    public function submit(SubmitCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign, 'submit');

        // Kiểm xung đột SOV ngay TRƯỚC khi gửi, không tin vào lần kiểm ở bước
        // xem lại: giữa hai lần gọi có thể có người khác đặt kín suất. Trang
        // Blade cũng kiểm lại ở đúng chỗ này.
        $conflicts = $this->availability->validateCampaign($campaign->id);

        if (! empty($conflicts)) {
            return response()->json([
                'error'   => 'sov_conflict',
                'message' => 'Có ' . count($conflicts) . ' màn hình không còn đủ thời lượng. Vui lòng điều chỉnh.',
                'code'    => 422,
                'details' => array_map([$this, 'conflictDetail'], $conflicts),
            ], 422);
        }

        $this->campaigns->submit($campaign, $request->user());

        $this->consents->record(
            ['terms', 'privacy'],
            PolicyConsent::CONTEXT_BOOKING,
            $request,
            subjectId: $campaign->id,
        );

        return response()->json(['data' => $this->reviewPayload($campaign->fresh())]);
    }

    /**
     * 404 khi không được xem, 403 khi được xem nhưng không được làm.
     *
     * Hai câu trả lời khác nhau cho hai câu hỏi khác nhau, có chủ ý:
     *
     * - Không `view` được nghĩa là campaign thuộc tổ chức khác. Trả 403 là xác
     *   nhận "bản ghi này có thật" cho người ngoài tổ chức, mà sự tồn tại của
     *   một đơn hàng cũng là thông tin riêng. Cùng lý lẽ với `CartItemPolicy`.
     * - `view` được nhưng không `submit`/`pay` được là chuyện khác: đồng
     *   nghiệp vai trò `viewer` đang nhìn thấy đúng campaign đó. Trả 404 cho
     *   họ là nói "không tồn tại" về một thứ đang hiện trên màn hình họ, và
     *   che mất điều duy nhất họ cần biết — rằng vấn đề là quyền.
     */
    private function authorizeCampaign(Request $request, Campaign $campaign, string $ability): void
    {
        $user = $request->user();

        abort_unless(
            $user?->can('view', $campaign) ?? false,
            404,
            'Không tìm thấy campaign này.',
        );

        if ($ability === 'view') {
            return;
        }

        abort_unless(
            $user->can($ability, $campaign),
            403,
            'Bạn không có quyền thực hiện việc này trên campaign.',
        );
    }

    /** @param array<string, mixed> $conflict */
    private function conflictDetail(array $conflict): array
    {
        return [
            'field'   => 'booking_lines.' . $conflict['booking_line_id'],
            'message' => sprintf(
                'Màn hình "%s" chỉ còn %d%% thời lượng cho %s, đang xin %d%%.',
                $conflict['screen_name'] ?? 'không rõ',
                (int) $conflict['available'],
                $conflict['dates'],
                (int) $conflict['requested'],
            ),
        ];
    }

    /**
     * Cùng một khối dữ liệu cho cả ba bước.
     *
     * Client vừa tạo campaign cần đúng những gì bước xem lại cần, và sau khi
     * gửi cũng vậy. Trả ba hình dạng khác nhau cho cùng một tài nguyên là bắt
     * bên tiêu thụ viết ba đường xử lý cho một thứ.
     */
    private function reviewPayload(Campaign $campaign): array
    {
        $lines = $campaign->bookingLines()
            ->with([
                'screen.spec',
                'screen.inventory',
                'screen.owner:id,name,slug,cover_url',
                'screen.site:id,network_id,name,city,address,banner',
                'screen.site.network:id,name,code,banner',
            ])
            ->get();

        $conflicts = $this->availability->validateCampaign($campaign->id);

        return [
            'campaign'  => (new CampaignResource($campaign))->resolve(),
            'lines'     => BookingLineResource::collection($lines)->resolve(),
            'creatives' => CreativeResource::collection($campaign->creatives()->get())->resolve(),

            'conflicts' => array_map(fn (array $c) => [
                'booking_line_id' => $c['booking_line_id'],
                'screen_name'     => $c['screen_name'],
                'requested_pct'   => (int) $c['requested'],
                'available_pct'   => (int) $c['available'],
                'dates'           => $c['dates'],
            ], $conflicts),

            'summary' => [
                'currency'   => 'VND',
                'line_count' => $lines->count(),

                // Tổng CHƯA gồm VAT. VAT tính một chỗ duy nhất ở
                // `PaymentService::withVat()`; cộng ở đây là một phép làm tròn
                // thứ hai, và hai chỗ làm tròn khác nhau là cách sinh ra "công
                // nợ bằng 0 nhưng chưa trả đủ".
                'subtotal'    => (int) round((float) $lines->sum('estimated_cost')),
                'impressions' => (int) $lines->sum('estimated_impressions'),

                // Gửi được hay không do MÁY CHỦ trả lời, không để client tự
                // suy từ `status` và `conflicts`. Giao diện ẩn nút không phải
                // phân quyền, nhưng giao diện tự đoán điều kiện thì lệch là
                // chắc chắn.
                'can_submit' => $campaign->status === 'draft' && empty($conflicts),
            ],
        ];
    }

    /**
     * Phần chỉ có ở đường ĐỌC: những gì trang chi tiết chiến dịch cần.
     *
     * Trước 08/10/2026, `GET campaigns/{campaign}` thiếu đúng bốn thứ mà
     * `/my/campaigns/{campaign}` hiển thị, nên trang đó là action đọc cuối
     * cùng của khu người mua còn phải dựng dữ liệu từ model. Đây là bốn thứ
     * đó, cộng phần số liệu và chính sách hủy mà trang cũ đọc thẳng từ
     * accessor và `config()`.
     */
    private function detailPayload(Request $request, Campaign $campaign): array
    {
        $user = $request->user();

        return [
            'stats' => $this->stats($campaign),

            'cancel_quotes' => $this->cancelQuotes($request, $campaign),

            // Chính sách hủy ra ngoài thay vì để client đọc lại `config()`.
            //
            // Trang Blade đọc được config nên nó không cần; một client Next.js
            // thì không, và nếu nó chép cứng "14 ngày / 100%" thì con số trên
            // màn hình và con số máy chủ đang áp dụng sẽ lệch nhau im lặng
            // ngay lần đổi chính sách đầu tiên. Cùng mảng mà
            // `CancellationService::tierFor()` đọc.
            'refund_policy' => array_map(fn (array $t) => [
                'min_days_before' => (int) $t['min_days_before'],
                'refund_pct'      => (int) $t['refund_pct'],
            ], config('pricing.refund_tiers', [])),

            // Danh sách owner CÒN được đánh giá. `reviewableOwners()` tự trả
            // mảng rỗng khi chiến dịch chưa chạy — đánh giá một dịch vụ chưa
            // được cung cấp thì không dựa trên trải nghiệm nào, và đó là cách
            // nhanh nhất làm điểm số trên sàn thành vô nghĩa.
            'reviewable_owners' => OwnerToReviewResource::collection(
                $this->reviews->reviewableOwners($campaign)
            )->resolve(),

            // Đánh giá tổ chức này ĐÃ viết cho chiến dịch này. Lọc theo
            // `campaign_id` là đủ hẹp: một chiến dịch thuộc đúng một tổ chức,
            // và người gọi đã qua `can('view')` trên chính chiến dịch đó.
            'my_reviews' => OwnerReviewResource::collection(
                OwnerReview::where('campaign_id', $campaign->id)
                    ->with('owner:id,name')
                    ->latest()
                    ->get()
            )->resolve(),

            // `activities()` đã sắp giảm dần theo thời gian ở quan hệ, nên
            // `limit` ở đây lấy đúng các dòng MỚI NHẤT, không phải một khúc
            // tuỳ ý giữa bảng.
            'activities' => CampaignActivityResource::collection(
                $campaign->activities()->with('user:id,name')->limit(self::TOI_DA_LICH_SU)->get()
            )->resolve(),

            'activity_count' => (int) $campaign->activities()->count(),
        ];
    }

    /**
     * Bốn con số đầu trang.
     *
     * Đọc từ chính các accessor mà trang Blade đang đọc, nên hai bên không ra
     * hai kết quả. `delivery_rate` là **phần trăm** nên không phải tiền —
     * giữ một chữ số thập phân, đúng như accessor làm.
     *
     * `cost` ở đây là tổng ước tính CHƯA gồm VAT, giống `summary.subtotal`.
     * Số phải trả nằm ở `GET campaigns/{campaign}/payments`, nơi
     * `PaymentService::withVat()` là chỗ duy nhất cộng VAT.
     */
    private function stats(Campaign $campaign): array
    {
        $campaign->loadMissing('bookingLines');

        return [
            'currency'           => 'VND',
            'line_count'         => $campaign->bookingLines->count(),
            'estimated_cost'     => (int) round((float) $campaign->total_estimated_cost),
            'actual_impressions' => (int) $campaign->total_actual_impressions,
            'delivery_rate_pct'  => (float) $campaign->delivery_rate,
        ];
    }

    /**
     * Báo giá hoàn tiền cho từng dòng còn hủy được — **tính ở máy chủ**.
     *
     * ══ Quyền ══
     *
     * Trả mảng rỗng khi người xem không có quyền `cancel` (xếp cùng
     * `manage_payments`), để giao diện khớp với quyền. Việc chặn THẬT vẫn nằm
     * ở `BuyerCancellationController` và ở chính `CancellationService` — mảng
     * rỗng ở đây là một gợi ý cho giao diện, không phải một lớp bảo vệ.
     *
     * ══ Đây là ảnh chụp, không phải con số quyết định ══
     *
     * Tiền đã trả được phân bổ theo các dòng CÒN MỞ, nên hủy dòng A xong thì
     * báo giá của dòng B đổi. Con số quyết định là con số `cancelLine()` tính
     * lại trong transaction có khóa — nên khóa `is_estimate` ra ngoài để client
     * buộc phải nói "dự kiến" chứ không nói chắc.
     *
     * `quote()` gọi với `locking: false` (mặc định) vì đây là đường đọc; bản có
     * khóa chỉ dùng trong transaction hủy.
     */
    private function cancelQuotes(Request $request, Campaign $campaign): array
    {
        if (! ($request->user()?->can('cancel', $campaign) ?? false)) {
            return [];
        }

        $campaign->loadMissing('bookingLines');

        return $campaign->bookingLines
            ->reject(fn ($line) => in_array($line->status, ['cancelled', 'completed', 'rejected'], true))
            ->map(function ($line) use ($campaign) {
                // Gắn sẵn quan hệ: `quote()` đọc `$line->campaign`, không gắn
                // thì mỗi dòng nạp lại chiến dịch một lần.
                $line->setRelation('campaign', $campaign);

                $bao = $this->cancellations->quote($line);

                return [
                    'booking_line_id' => $line->id,
                    'currency'        => 'VND',
                    'days_before'     => (int) $bao['days_before'],
                    'refund_pct'      => (int) $bao['refund_pct'],
                    'paid'            => (int) round((float) $bao['paid']),
                    'refundable'      => (int) round((float) $bao['refundable']),
                    'tier'            => [
                        'min_days_before' => (int) $bao['tier']['min_days_before'],
                        'refund_pct'      => (int) $bao['tier']['refund_pct'],
                    ],
                    'is_estimate' => true,
                ];
            })
            ->values()
            ->all();
    }
}
