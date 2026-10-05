<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'reservation_id',
        'merchant_trade_no',
        'ecpay_trade_no',
        'amount',
        'status',
        'needs_refund',
        'refund_reason',
        'failure_reason',
        'confirmed_via',
        'raw_notify',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'needs_refund' => 'boolean',
            'raw_notify' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
