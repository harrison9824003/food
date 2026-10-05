<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'description',
        'price',
        'sort_order',
        'is_active',
        'is_sold_out',
    ];

    /**
     * 與 migration 的預設值一致，剛 create() 的實例才不會缺欄位。
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
        'is_sold_out' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_sold_out' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(MenuItemImage::class)->orderBy('sort_order')->orderBy('id');
    }
}
