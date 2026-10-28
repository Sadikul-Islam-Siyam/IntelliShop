<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'sku',
        'barcode',
        'category_id',
        'brand_id',
        'unit_id',
        'unit_size',
        'shelf_life_days',
        'cost_price',
        'selling_price',
        'min_stock_level',
        'reorder_point',
        'reorder_quantity',
        'is_expiry_tracked',
        'image_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'unit_size' => 'float',
            'shelf_life_days' => 'integer',
            'cost_price' => 'float',
            'selling_price' => 'float',
            'min_stock_level' => 'integer',
            'reorder_point' => 'integer',
            'reorder_quantity' => 'integer',
            'is_expiry_tracked' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function demandForecasts(): HasMany
    {
        return $this->hasMany(DemandForecast::class);
    }

    public function modelRuns(): HasMany
    {
        return $this->hasMany(ModelRun::class);
    }
}
