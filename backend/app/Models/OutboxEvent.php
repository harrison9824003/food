<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'store_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'last_error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'aggregate_id' => 'integer',
            'store_id' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
