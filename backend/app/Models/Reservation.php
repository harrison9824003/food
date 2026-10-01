<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Reservation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'store_id',
        'slot_id',
        'customer_name',
        'phone',
        'email',
        'party_size',
        'note',
        'status',
        'payment_status',
        'deposit_amount',
        'hold_expires_at',
        'phone_verified_at',
        'request_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'deposit_amount' => 'integer',
            'status' => ReservationStatus::class,
            'payment_status' => PaymentStatus::class,
            'hold_expires_at' => 'datetime',
            'phone_verified_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /** 併桌時一張訂位對應多張桌子，所以是多對多。 */
    public function tables(): BelongsToMany
    {
        return $this->belongsToMany(DiningTable::class, 'reservation_tables', 'reservation_id', 'table_id')
            ->withPivot('slot_id')
            ->withTimestamps();
    }
}
