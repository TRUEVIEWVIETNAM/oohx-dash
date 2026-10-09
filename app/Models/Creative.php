<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\URL;

class Creative extends Model
{
    use HasUlids;

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPE_HTML5 = 'html5';

    public const TYPE_VAST_TAG = 'vast_tag';

    /**
     * Chữ tiếng Việt cho loại nội dung quảng cáo — **một định nghĩa**.
     *
     * ══ Lỗi bảng này sửa ══
     *
     * Hai trang đặt chỗ in `strtoupper($c->type)`, nên `vast_tag` hiện ra
     * **`VAST_TAG`** — một mã CSDL kèm dấu gạch dưới, trên trang người mua.
     * Cột `type` ở bảng quản trị sàn thì có `->badge()` và có màu nhưng
     * **không có nhãn chữ**, nên nó hiện thẳng `vast_tag`. Bộ lọc cạnh đó có
     * chữ, nhưng tiếng Anh và là một bản chép rời.
     *
     * Ba kiểu sai cho cùng một cột — đúng hình dạng của lỗi trạng thái chiến
     * dịch trước PR #41 và lỗi loại hình tổ chức trước PR #47.
     *
     * ══ Vì sao "Video" và "HTML5" giữ nguyên ══
     *
     * Chúng là tên định dạng, không phải từ nghiệp vụ: "Video" là từ tiếng
     * Việt đã dùng, và "HTML5" là một tên riêng. Dịch chúng là đặt ra từ mới
     * cho thứ ai cũng gọi bằng tên đó.
     *
     * Thứ **không** giữ được là `VAST_TAG`. VAST là tên chuẩn của IAB nên giữ
     * chữ viết tắt, nhưng dấu gạch dưới là dấu của một mã CSDL, không phải của
     * một nhãn — nên "Thẻ VAST". Có test đòi **không nhãn nào chứa dấu gạch
     * dưới**; đó là phép kiểm bắt đúng lỗi này, chứ không phải phép kiểm "nhãn
     * khác mã" (nhãn của `html5` đúng là `HTML5`).
     */
    public const TYPE_LABELS = [
        self::TYPE_IMAGE    => 'Hình ảnh',
        self::TYPE_VIDEO    => 'Video',
        self::TYPE_HTML5    => 'HTML5',
        self::TYPE_VAST_TAG => 'Thẻ VAST',
    ];

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Chữ tiếng Việt cho trạng thái nội dung quảng cáo — **một định nghĩa**.
     *
     * ══ Lỗi bảng này sửa ══
     *
     * Trang tải nội dung của người mua (`buyer/booking/creative`) trước đây viết
     * chữ bằng một biểu thức ba ngôi chỉ biết **một** mã:
     *
     *     {{ $c->status === 'pending_review' ? 'Chờ duyệt' : $c->status }}
     *
     * nên `approved` hiện ra `approved` và `rejected` hiện ra `rejected` — hai
     * trong ba trạng thái ra nguyên văn tiếng Anh, ngay trên trang người mua
     * nhìn sau khi tải tệp lên. Bảng đầy đủ nằm ở Filament, nhưng Filament là
     * khu quản trị nội bộ: người mua không bao giờ thấy nó.
     *
     * ══ Enum thứ BA, không gộp được với hai enum kia ══
     *
     * `Campaign` dùng `pending_approval`, `BookingLine` dùng `pending`, còn
     * nội dung quảng cáo dùng `pending_review` — ba enum, ba bảng chữ. Việc gộp
     * hai bảng đầu đã gây đúng một lỗi hiển thị (xem `BookingLine::STATUS_LABELS`),
     * nên có test đòi ba bảng này khác nhau chứ không trùng.
     *
     * Enum lấy từ migration `create_creatives_table`: ba mã, mặc định
     * `pending_review`.
     *
     * Không có `statusLabels(?array)` như `Campaign` vì không giao diện nào lọc
     * sẵn enum này ở tầng truy vấn — không có tập con nào để phục vụ, và một
     * hàm không ai gọi là một phỏng đoán về tương lai.
     */
    public const STATUS_LABELS = [
        self::STATUS_PENDING_REVIEW => 'Chờ duyệt',
        self::STATUS_APPROVED       => 'Đã duyệt',
        self::STATUS_REJECTED       => 'Từ chối',
    ];

    protected $fillable = [
        'campaign_id', 'organization_id',
        'name', 'type', 'file_path', 'file_size',
        'width_px', 'height_px', 'duration_sec',
        'vast_tag_url', 'status',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width_px' => 'integer',
        'height_px' => 'integer',
        'duration_sec' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function bookingLines(): BelongsToMany
    {
        return $this->belongsToMany(BookingLine::class, 'booking_line_creatives')
            ->using(BookingLineCreative::class)
            ->withPivot('weight', 'start_date', 'end_date');
    }

    /**
     * URL ký hạn tới tệp nội dung, hoặc `null` nếu chưa có tệp.
     *
     * Bản cũ trả `asset('storage/' . $file_path)` — một URL **công khai** trên
     * disk `public`, tải được không cần đăng nhập. Nội dung quảng cáo nằm
     * trong nhóm tệp nhạy cảm mà CLAUDE.md mục 5 yêu cầu để disk riêng và
     * truy cập qua URL ký hạn.
     *
     * Không chỗ nào trong view đang gọi accessor này lúc đổi, nên việc đổi
     * không làm vỡ gì — nhưng nó là cái bẫy đã nạp sẵn: chỉ cần một người viết
     * `{{ $creative->file_url }}` vào một trang công khai là nội dung chưa duyệt
     * của người mua ra ngoài, và không ai thấy gì bất thường khi đọc dòng đó.
     *
     * URL sinh ra chỉ là một tấm vé: route `creatives.file` vẫn kiểm
     * `CreativePolicy`, nên nó không cấp quyền cho ai chưa có.
     */
    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'creatives.file',
            now()->addMinutes((int) config('creatives.url_ttl_minutes', 60)),
            ['creative' => $this->getKey()],
        );
    }

    public function getDimensionsAttribute(): string
    {
        if ($this->width_px && $this->height_px) {
            return $this->width_px . 'x' . $this->height_px;
        }
        return '—';
    }
}
