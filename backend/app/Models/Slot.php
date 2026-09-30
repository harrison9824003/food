<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Slot extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'store_id',
        'slot_date',
        'start_time',
        'capacity',
        'booked',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot_date' => 'date:Y-m-d',
            'capacity' => 'integer',
            'booked' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->booked);
    }
}
