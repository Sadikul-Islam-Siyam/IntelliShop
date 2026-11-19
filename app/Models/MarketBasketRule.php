<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketBasketRule extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'antecedent_product_id',
        'consequent_product_id',
        'support',
        'confidence',
        'lift',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'support' => 'decimal:4',
            'confidence' => 'decimal:4',
            'lift' => 'decimal:4',
            'generated_at' => 'datetime',
        ];
    }

    public function antecedent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'antecedent_product_id');
    }

    public function consequent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'consequent_product_id');
    }
}
