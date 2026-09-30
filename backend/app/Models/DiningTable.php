<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiningTable extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'store_id',
        'code',
        'min_seats',
        'max_seats',
        'is_combinable',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_seats' => 'integer',
            'max_seats' => 'integer',
            'is_combinable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
