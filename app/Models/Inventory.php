<?php

namespace App\Models;

use App\Services\Analytics\StockRiskService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    public $timestamps = false;

    protected $table = 'inventory';

    protected $guarded = [];

    protected $casts = [
        'quantity_available' => 'integer',
        'quantity_reserved' => 'integer',
        'max_capacity' => 'integer',
        'last_updated' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Automatically recalculate stock projection and risk after any sell or replenishment
        static::saved(function (Inventory $inventory) {
            try {
                if ($inventory->product_id && $inventory->branch_id) {
                    app(StockRiskService::class)->runStockRisk($inventory->product_id, $inventory->branch_id);
                }
            } catch (\Throwable $e) {
                // Ensure inventory transactions never fail even if analytics service is unreachable
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
