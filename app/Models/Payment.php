<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUlids;

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_VNPAY = 'vnpay';

    public const METHOD_MOMO = 'momo';

    /**
     * Chữ tiếng Việt cho cách trả tiền — **một định nghĩa**.
     *
     * ══ Lỗi bảng này sửa ══
     *
     * Trang thanh toán của người mua không tra bảng nào; nó **làm đẹp mã**:
     *
     *     var cach = String(p.method || '').replace(/_/g, ' ');
     *     cach = cach.charAt(0).toUpperCase() + cach.slice(1);
     *
     * tức `bank_transfer` ra **"Bank transfer"** — tiếng Anh, trên trang tiền
     * của người mua. Khu quản trị thì có chữ, nhưng là một `match` viết thẳng
     * trong `ViewCampaign`, tức bản chép thứ hai.
     *
     * ══ Hai tên thương hiệu giữ nguyên ══
     *
     * `vnpay` → "VNPAY" và `momo` → "MoMo" là tên riêng, viết theo cách chủ
     * sở hữu viết. Chỉ `bank_transfer` cần dịch, và nó cũng là mã duy nhất
     * `StorePaymentRequest` cho qua — hai mã kia có trong enum CSDL nhưng chưa
     * có đường ghi nào, nên chữ của chúng là để sẵn, không phải để dùng ngay.
     */
    public const METHOD_LABELS = [
        self::METHOD_BANK_TRANSFER => 'Chuyển khoản',
        self::METHOD_VNPAY         => 'VNPAY',
        self::METHOD_MOMO          => 'MoMo',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    /**
     * Chữ tiếng Việt cho trạng thái khoản thanh toán — **một định nghĩa**.
     *
     * ══ Chữ này KHÔNG mới ══
     *
     * Năm chữ dưới đây lấy nguyên từ `buyer/booking/payment.blade.php`, nơi
     * chúng đang là một bảng viết tay **trong JS**:
     *
     *     var nhan = { pending: 'Chờ xác nhận', processing: 'Đang xử lý', … };
     *
     * Nên đây không phải một lần dịch mà là một lần **gom**: bảng đó là bản
     * duy nhất, DTO `/api/v2` không mang chữ, và khối "Payments" ở trang xem
     * chiến dịch của khu quản trị có `->badge()` + màu mà không có nhãn — nên
     * nó hiện thẳng `pending` / `completed` / `failed`.
     *
     * Đúng hình dạng đã làm chữ trạng thái chiến dịch trôi thành năm bản chép:
     * một bản ở client, một chỗ hiện mã thô, và không ai biết bản nào đúng.
     *
     * Enum lấy từ `create_payments_table`: năm mã, mặc định `pending`.
     */
    public const STATUS_LABELS = [
        self::STATUS_PENDING    => 'Chờ xác nhận',
        self::STATUS_PROCESSING => 'Đang xử lý',
        self::STATUS_COMPLETED  => 'Thành công',
        self::STATUS_FAILED     => 'Thất bại',
        self::STATUS_REFUNDED   => 'Hoàn tiền',
    ];

    protected $fillable = [
        'campaign_id', 'organization_id', 'owner_id',
        'amount', 'currency', 'method',
        'transaction_ref', 'gateway_ref', 'idempotency_key',
        'status', 'paid_at', 'due_date',
        'invoice_number', 'invoice_url',
        'notes', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'due_date' => 'date',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Media owner nhận khoản tiền này.
     *
     * Null với các payment tạo trước khi sàn chuyển sang mô hình trả thẳng cho
     * người bán.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isFailed(): bool { return $this->status === 'failed'; }
}
