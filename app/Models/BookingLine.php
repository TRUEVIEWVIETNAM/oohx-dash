<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BookingLine extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Chữ tiếng Việt cho trạng thái của **một dòng đặt chỗ** — một định nghĩa.
     *
     * ══ Khác `Campaign::STATUS_LABELS`, và khác ở đúng một mã ══
     *
     * Dòng đặt chỗ dùng `pending`; chiến dịch dùng `pending_approval`. Bảy mã
     * còn lại trùng tên, và chính chỗ trùng đó là nguồn của một lỗi hiển thị:
     * trang chi tiết chiến dịch (cả bản Blade cũ lẫn bản JS) dùng **một** bảng
     * chữ cho cả hai, nên một dòng `pending` rơi ra ngoài bảng và hiện ra
     * `pending` nguyên văn tiếng Anh ngay cạnh các dòng đã có chữ Việt.
     *
     * Hai enum khác nhau thì phải có hai bảng chữ. Enum lấy từ migration
     * `create_booking_lines_table`.
     */
    public const STATUS_LABELS = [
        self::STATUS_PENDING   => 'Chờ duyệt',
        self::STATUS_APPROVED  => 'Đã duyệt',
        self::STATUS_REJECTED  => 'Từ chối',
        self::STATUS_ACTIVE    => 'Đang chạy',
        self::STATUS_PAUSED    => 'Tạm dừng',
        self::STATUS_COMPLETED => 'Hoàn thành',
        self::STATUS_CANCELLED => 'Đã hủy',
    ];

    /**
     * Dòng nào tính vào **báo cáo phát sóng** — một định nghĩa.
     *
     * ══ Vì sao hằng này ra đời ══
     *
     * `CampaignReportService` lọc `booking_lines` ở ba chỗ và trả lời cùng một
     * câu hỏi bằng **hai** danh sách: `getOverview()` và `getDailyImpressions()`
     * dùng `['active','completed']`, còn `getScreenBreakdown()` thêm
     * `'approved'`. Hệ quả người mua thấy được: bảng chi tiết liệt kê N màn
     * hình, còn ô "Tổng màn hình" ngay phía trên nói N−k. Hai con số cạnh nhau
     * trên cùng một trang, đếm cùng một thứ, không bằng nhau.
     *
     * ══ Vì sao chọn tập này ══
     *
     * Bất biến đáng giữ là **ô tổng phải bằng tổng của bảng**, vì đó là thứ
     * người đọc kiểm được bằng mắt. Nên cả ba chỗ phải đọc cùng một danh sách,
     * và danh sách đó là tập của bảng chi tiết — rộng hơn — chứ không phải tập
     * hẹp của ô tổng: một màn hình đã được owner duyệt là một cam kết có thật,
     * và ẩn nó khỏi báo cáo thì người mua không thấy thứ mình đã đặt.
     *
     * Hệ quả cố ý: `delivery_rate` **giảm** khi chiến dịch có dòng `approved`
     * chưa chạy, vì mẫu số nay gồm cả phần chưa phát. Đó là con số đúng —
     * những lần phát đó chưa xảy ra. Thiết kế cũ đã chọn kiểu đo "đã phát so
     * với TOÀN KỲ" chứ không "so với phần tới hôm nay" (một dòng `active` giữa
     * kỳ cũng góp trọn `estimated_impressions` của nó), nên thêm `approved`
     * không đổi bản chất phép đo.
     *
     * Bốn mã bị loại, mỗi mã một lý do khác nhau:
     *
     *  - `pending` — owner **chưa** duyệt, và có thể từ chối. Vẽ nó vào báo cáo
     *    phát sóng là hứa một màn hình chưa ai nhận.
     *  - `rejected`, `cancelled` — không có nghĩa vụ nào, không có phát sóng.
     *
     * `paused` có trong tập dù **chưa chỗ nào trong mã nguồn đặt nó** cho một
     * dòng: `Campaign::REPORTABLE_STATUSES` đã cho một chiến dịch `paused` xem
     * báo cáo, nên ngày dòng cũng tạm dừng được thì số đã phát của nó không
     * được biến mất khỏi báo cáo. Loại nó là để dành một lỗi cho tương lai.
     *
     * @var list<string>
     */
    public const REPORT_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
    ];

    /**
     * Dòng nào mang **nghĩa vụ trả tiền** cho media owner — một định nghĩa.
     *
     * ══ Sáu chỗ, một câu hỏi, sáu bản chép ══
     *
     * Danh sách này từng viết cứng ở sáu nơi: `PaymentService` bốn lần
     * (`breakdownByOwner()`, `getSummary()`, và hai phép tính phần chưa gắn
     * owner), `CancellationService::openCostFor()`, và
     * `PaymentRecipientController::ownersInCampaign()`.
     *
     * Điều đáng chú ý: mã **đã biết** đây là một định nghĩa và đã viết ra điều
     * đó. `openCostFor()` ghi "Một định nghĩa duy nhất, dùng cho cả lúc hủy và
     * lúc đối soát; hai định nghĩa lệch nhau ở đây là hai con số tiền khác
     * nhau". `ownersInCampaign()` ghi "Lọc `status` giống hệt bên đó". Cả hai
     * lời hứa đó chỉ được giữ bằng việc ai đó nhớ ra — không có gì bắt chúng
     * giống nhau.
     *
     * Ba chỗ đó quyết định ba thứ khác nhau mà cùng phải khớp: số tiền người
     * mua phải chuyển cho từng owner, mẫu số chia tỉ lệ khi hoàn tiền, và
     * **owner nào được lộ `bank_*` + `tax_code`** qua `payment-recipients`.
     * Chỗ cuối là ngoại lệ bảo mật duy nhất của hệ thống, nên một danh sách
     * rộng hơn ở đó là một lần lộ thông tin ngân hàng của owner không liên
     * quan.
     *
     * ══ Ba mã bị loại ══
     *
     *  - `pending` — owner **chưa duyệt**, có thể từ chối. Chưa có nghĩa vụ.
     *  - `rejected`, `cancelled` — không có nghĩa vụ. Dòng đã hủy sinh ra
     *    `Refund` riêng, và `CancellationService` sở hữu phép tính đó.
     *
     * ══ Vì sao KHÁC `REPORT_STATUSES` ở đúng `paused` ══
     *
     * `REPORT_STATUSES` có `paused`, danh sách này không. Chưa chỗ nào trong
     * mã đặt `paused` cho một dòng, nên hôm nay hai danh sách cho **cùng** kết
     * quả và chỗ lệch là lý thuyết. Nhưng nó có chủ ý, và lý do là mức giá của
     * một lần đoán sai:
     *
     *  - Ở báo cáo, thêm `paused` là **phòng ngừa**: ngày dòng tạm dừng được,
     *    số đã phát của nó không được biến mất khỏi báo cáo. Đoán sai thì một
     *    con số trên trang sai.
     *  - Ở đây, thêm `paused` là **đổi số tiền** người mua phải chuyển và đổi
     *    mẫu số chia tỉ lệ hoàn tiền. Đoán sai thì tiền sai.
     *
     * Nên lần gom này giữ danh sách **đúng như nó đang là**: không thêm, không
     * bớt, không đổi thứ tự. Việc `paused` có mang nghĩa vụ tiền hay không là
     * quyết định của người làm tính năng tạm dừng dòng đặt chỗ, không phải của
     * một lần đặt tên.
     *
     * @var list<string>
     */
    public const OBLIGATION_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'campaign_id', 'product_id', 'bundle_id', 'screen_id', 'owner_id',
        'duration_discount_pct',
        'start_date', 'end_date', 'spot_length',
        'share_of_voice_pct',
        'floor_cpm_at_booking', 'negotiated_cpm',
        'estimated_impressions', 'estimated_cost',
        'actual_impressions', 'actual_cost',
        'status', 'approved_by', 'approved_at',
        'rejected_reason', 'notes',
        // Pricing model fields
        'pricing_model', 'io_rate_at_booking', 'io_rate_unit',
        'kpi_spots_per_day', 'booked_cpms', 'screen_count', 'actual_spots',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'spot_length' => 'integer',
        'share_of_voice_pct' => 'integer',
        'floor_cpm_at_booking' => 'decimal:2',
        'negotiated_cpm' => 'decimal:2',
        'io_rate_at_booking' => 'decimal:2',
        'kpi_spots_per_day' => 'integer',
        'booked_cpms' => 'integer',
        'screen_count' => 'integer',
        'actual_spots' => 'integer',
        'estimated_impressions' => 'integer',
        'estimated_cost' => 'decimal:2',
        'actual_impressions' => 'integer',
        'actual_cost' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    // ── Relationships ──

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Gói mà dòng này được mở ra từ, kèm bản chụp thành phần gói lúc mua. */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(BookingLineBundle::class, 'bundle_id');
    }

    public function screen(): BelongsTo
    {
        // Bỏ owner_scope: dòng booking đã xác định ngữ cảnh rồi. Người mua không có
        // tenant nên nếu để scope, quan hệ này trả null và hỏng cả trang chiến dịch.
        return $this->belongsTo(Screen::class)->withoutGlobalScope('owner_scope');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creatives(): BelongsToMany
    {
        return $this->belongsToMany(Creative::class, 'booking_line_creatives')
            ->using(BookingLineCreative::class)
            ->withPivot('weight', 'start_date', 'end_date');
    }

    // ── Computed ──

    public function getEffectiveCpmAttribute(): float
    {
        return $this->negotiated_cpm ?? $this->floor_cpm_at_booking;
    }

    public function getDurationDaysAttribute(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    // ── Scopes ──

    public function scopeForOwner($query, string $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
