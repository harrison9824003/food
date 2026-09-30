<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreClosedDate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'store_id',
        'closed_date',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_date' => 'date:Y-m-d',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
