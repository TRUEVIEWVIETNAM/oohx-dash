<?php

namespace App\Models;

use App\Traits\HasOwnerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Screen import job — tracks state across upload → mapping → preview → import.
 *
 * Owned per-owner (HasOwnerScope) + uploaded_by user for audit trail.
 *
 * Status machine:
 *   uploaded   — file saved, headers parsed, awaiting AI mapping
 *   mapping    — AI mapping proposed, user is reviewing/editing
 *   previewed  — dry-run validation complete, awaiting import confirmation
 *   importing  — queue job running (Phase 2)
 *   done       — all rows processed
 *   failed     — terminal failure
 *   cancelled  — user aborted (Phase 2)
 */
class ScreenImport extends Model
{
    use HasUuids, HasOwnerScope;

    public const STATUS_UPLOADED  = 'uploaded';
    public const STATUS_MAPPING   = 'mapping';
    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_DONE      = 'done';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Chữ tiếng Việt cho từng bước của lần nhập màn hình.
     *
     * ══ Ai đọc chữ này ══
     *
     * Không phải người vận hành sàn, mà **media owner đang tự nhập kho của
     * mình** từ một tệp Excel. Trước bảng này, cột trạng thái in `uploaded`,
     * `mapping`, `previewed` — tiếng Anh, và là mã của một máy trạng thái nội
     * bộ. Đó là lý do hai món nợ của `ScreenImportResource` không nên nằm trong
     * nhóm "trạng thái vận hành giữ mã thô cho dễ đối chiếu log".
     *
     * ══ Cột này là `varchar(255)`, KHÔNG phải enum ══
     *
     * Nên `NhanEnumMotNoiTest` không đối chiếu được bảng chữ với CSDL. Thay vào
     * đó nó đối chiếu với **bảy hằng `STATUS_*` ngay trên**, và `screen_imports`
     * được khai là một ngoại lệ ở đó kèm lý do. Bảy hằng này là nguồn sự thật
     * duy nhất của máy trạng thái, và chúng khớp đúng docblock của lớp.
     *
     * Tám chỗ trong `app/` vẫn ghi trạng thái bằng chuỗi thẳng
     * (`'status' => 'uploaded'`). Nối chúng vào bảy hằng này là một việc riêng:
     * nó đi qua service, job và hai trang Filament, và không thuộc phạm vi một
     * lần dịch chữ.
     */
    public const STATUS_LABELS = [
        self::STATUS_UPLOADED  => 'Đã tải tệp lên',
        self::STATUS_MAPPING   => 'Đang ghép cột',
        self::STATUS_PREVIEWED => 'Đã xem trước',
        self::STATUS_IMPORTING => 'Đang nhập',
        self::STATUS_DONE      => 'Xong',
        self::STATUS_FAILED    => 'Lỗi',
        self::STATUS_CANCELLED => 'Đã huỷ',
    ];

    /**
     * Chưa ghi dòng nào — còn sửa được bảng ghép cột.
     *
     * Bốn chỗ trong `ViewScreenImport` và `ScreenImportService` viết tay đúng
     * tập `['uploaded', 'mapping', 'previewed']`. Bốn bản chép của cùng một ý:
     * thêm một bước vào máy trạng thái là phải sửa cả bốn, và chỗ bị sót sẽ ẩn
     * một cái nút thay vì vỡ ồn ào.
     */
    public const EDITABLE_STATUSES = [
        self::STATUS_UPLOADED,
        self::STATUS_MAPPING,
        self::STATUS_PREVIEWED,
    ];

    /** Đã ghi (hoặc đang ghi) dòng vào kho — có kết quả để xem. */
    public const STARTED_STATUSES = [
        self::STATUS_IMPORTING,
        self::STATUS_DONE,
    ];

    /**
     * Đã chạy xong và có báo cáo lỗi để đọc.
     *
     * `cancelled` **không** nằm đây, có chủ ý: một lần nhập bị huỷ thì dừng
     * trước khi sinh báo cáo, nên hiện nút "tải báo cáo lỗi" cho nó là mời
     * người dùng bấm vào một tệp không tồn tại.
     */
    public const FINISHED_STATUSES = [
        self::STATUS_DONE,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'owner_id', 'uploaded_by',
        'original_filename', 'file_path',
        'status', 'upsert_mode',
        'total_rows', 'processed_count', 'success_count', 'failed_count',
        'headers', 'sample_rows',
        'ai_mapping', 'user_mapping', 'ai_comment_history',
        'preview_data', 'validation_errors', 'error_summary',
        'error_report_path',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'headers'             => 'array',
        'sample_rows'         => 'array',
        'ai_mapping'          => 'array',
        'user_mapping'        => 'array',
        'ai_comment_history'  => 'array',
        'preview_data'        => 'array',
        'validation_errors'   => 'array',
        'total_rows'          => 'integer',
        'processed_count'     => 'integer',
        'success_count'       => 'integer',
        'failed_count'        => 'integer',
        'started_at'          => 'datetime',
        'finished_at'         => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Hàng rào theo mạng lưới — một lần nhập **không thuộc** mạng lưới nào,
     * nên ở đây hàng rào đổi hình: chỉ còn những lần nhập do chính người đó
     * tải lên.
     *
     * ══ Vì sao không để trống ══
     *
     * `preview_data` và `validation_errors` chứa **nguyên các hàng** của tệp
     * Excel: mã màn hình, địa điểm, giá sàn. Một lần nhập có thể trải trên bất
     * kỳ mạng lưới nào, nên để người bị fence đọc lần nhập của người khác là
     * mở một cửa sổ nhìn sang bên kia hàng rào — đúng thứ hàng rào này sinh ra
     * để chặn, chỉ là qua một bảng khác.
     *
     * ══ Hôm nay chưa ảnh hưởng ai ══
     *
     * `ScreenImportResource` chỉ có ở panel `/admin`, và `canAccessPanel` cho
     * panel đó đòi `super_admin` — vai trò luôn đi qua hàng rào. Nên luật này
     * là mặc định đóng cho ngày panel publisher có trang nhập, không phải một
     * thay đổi hành vi hôm nay. Nếu chủ dự án muốn thành viên bị giới hạn vẫn
     * xem được mọi lần nhập của tổ chức, đây là một dòng để sửa.
     *
     * @param list<int> $networkIds không dùng — bảng này không nối tới `networks`
     */
    protected static function locTheoNetwork(Builder $query, array $networkIds, User $thanhVien): void
    {
        $query->where($query->getModel()->getTable() . '.uploaded_by', $thanhVien->getKey());
    }

    public function getIsTerminalAttribute(): bool
    {
        return in_array($this->status, ['done', 'failed', 'cancelled'], true);
    }

    public function getIsActiveAttribute(): bool
    {
        return in_array($this->status, ['importing'], true);
    }

    public function getProgressPercentAttribute(): int
    {
        if (! $this->total_rows) return 0;
        return (int) round(($this->processed_count / $this->total_rows) * 100);
    }

    public function getEffectiveMappingAttribute(): array
    {
        return $this->user_mapping ?? $this->ai_mapping ?? [];
    }
}
