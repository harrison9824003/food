<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MenuItemImage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'menu_item_id',
        'path',
        'thumb_path',
        'width',
        'height',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function url(): string
    {
        return Storage::disk(config('menu.disk'))->url($this->path);
    }

    public function thumbUrl(): string
    {
        return Storage::disk(config('menu.disk'))->url($this->thumb_path);
    }
}
