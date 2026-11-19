<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesAnomaly extends Model
{
    protected $fillable = [
        'product_id',
        'branch_id',
        'detected_date',
        'expected_value',
        'actual_value',
        'deviation_score',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'detected_date' => 'date',
            'expected_value' => 'decimal:2',
            'actual_value' => 'decimal:2',
            'deviation_score' => 'decimal:3',
        ];
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
