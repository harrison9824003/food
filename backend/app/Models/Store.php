<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFactory> */
    use HasFactory;

    /** 只有總部能改的欄位；店長可改其餘欄位（見 StorePolicy）。 */
    public const HQ_ONLY_FIELDS = ['name', 'is_active', 'deposit_per_person'];

    /**
     * 與 migration 的預設值一致。沒有這個，剛 create() 的實例不會帶有 DB 預設值
     * （is_active 會是 null），直接拿去回傳或判斷都會出錯。
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'advance_days' => 30,
        'cutoff_minutes' => 60,
        'slot_capacity_default' => 20,
        'deposit_per_person' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'address',
        'phone',
        'is_active',
        'advance_days',
        'cutoff_minutes',
        'slot_capacity_default',
        'deposit_per_person',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'advance_days' => 'integer',
            'cutoff_minutes' => 'integer',
            'slot_capacity_default' => 'integer',
            'deposit_per_person' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(StoreBusinessHour::class);
    }

    public function closedDates(): HasMany
    {
        return $this->hasMany(StoreClosedDate::class);
    }

    public function diningTables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class);
    }
}
