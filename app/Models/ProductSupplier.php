<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSupplier extends Model
{
    protected $guarded = [];

    protected $casts = [
        'supply_price' => 'decimal:2',
        'lead_time_days' => 'integer',
        'min_order_qty' => 'integer',
        'order_multiple' => 'integer',
        'quality_score' => 'decimal:2',
        'fulfillment_rate' => 'float',
        'is_preferred' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
