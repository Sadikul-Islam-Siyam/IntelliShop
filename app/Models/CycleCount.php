<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CycleCount extends Model
{
    protected $fillable = [
        'product_id',
        'branch_id',
        'batch_id',
        'trigger_source',
        'baseline_mean',
        'baseline_std',
        'z_score',
        'system_quantity',
        'physical_quantity',
        'variance',
        'status',
        'assigned_to',
        'counted_by',
        'reviewed_by',
        'variance_reason',
        'previous_quantity',
        'adjusted_quantity',
        'adjustment_transaction_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'baseline_mean' => 'decimal:2',
            'baseline_std' => 'decimal:2',
            'z_score' => 'decimal:3',
            'system_quantity' => 'integer',
            'physical_quantity' => 'integer',
            'variance' => 'integer',
            'previous_quantity' => 'integer',
            'adjusted_quantity' => 'integer',
            'closed_at' => 'datetime',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
