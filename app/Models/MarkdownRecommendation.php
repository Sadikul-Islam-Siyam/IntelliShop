<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarkdownRecommendation extends Model
{
    protected $fillable = [
        'batch_id',
        'product_id',
        'branch_id',
        'baseline_days',
        'avg_daily_sales',
        'days_remaining',
        'available_qty',
        'projected_sales',
        'surplus_qty',
        'suggested_markdown_pct',
        'max_markdown_pct',
        'status',
        'approval_authority',
        'reviewed_by',
        'reviewed_at',
        'discount_id',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'baseline_days' => 'integer',
            'avg_daily_sales' => 'decimal:2',
            'days_remaining' => 'integer',
            'available_qty' => 'integer',
            'projected_sales' => 'decimal:2',
            'surplus_qty' => 'decimal:2',
            'suggested_markdown_pct' => 'decimal:2',
            'max_markdown_pct' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
