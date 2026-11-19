<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPromotion extends Model
{
    protected $table = 'product_promotions';

    protected $fillable = [
        'product_id',
        'customer_id',
        'promotion_type',
        'message',
        'sent_at',
        'status',
        'opened',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
