<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUlids;

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
