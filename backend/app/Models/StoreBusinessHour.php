<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreBusinessHour extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'store_id',
        'weekday',
        'open_time',
        'close_time',
        'slot_minutes',
        'is_closed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'slot_minutes' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
