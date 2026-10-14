<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    public const CUSTOMER_CANCEL = 'CUSTOMER_CANCEL';

    public const STORE_CANCEL = 'STORE_CANCEL';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_id',
        'active_payment_id',
        'reservation_id',
        'store_id',
        'amount',
        'reason',
        'requested_by_type',
        'requested_by_id',
        'status',
        'approved_by',
        'approved_at',
        'reject_reason',
        'failure_reason',
        'attempts',
        'next_attempt_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'attempts' => 'integer',
            'status' => RefundStatus::class,
            'approved_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
