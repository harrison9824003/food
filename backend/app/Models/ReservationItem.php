<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 訂位時預點的餐點。name 與 unit_price 都是下單當下的快照，
 * 之後菜單改名或改價，已成立的訂位仍顯示當時預點的內容。
 */
class ReservationItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'reservation_id',
        'menu_item_id',
        'name',
        'quantity',
        'unit_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
