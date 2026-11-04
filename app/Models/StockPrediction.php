<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPrediction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'predicted_stockout_date' => 'date',
        'generated_at' => 'datetime',
        'current_stock' => 'float',
        'predicted_demand' => 'float',
        'safety_stock' => 'float',
        'reorder_point' => 'float',
        'lead_time_days' => 'integer',
        'recommended_reorder_qty' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
