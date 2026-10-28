<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'branch_id',
        'model_used',
        'trained_at',
        'train_end_date',
        'folds',
        'mae',
        'rmse',
        'smape',
        'baseline_model',
        'baseline_smape',
        'beats_baseline',
        'selected',
        'params',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'branch_id' => 'integer',
            'trained_at' => 'datetime',
            'train_end_date' => 'date:Y-m-d',
            'folds' => 'integer',
            'mae' => 'float',
            'rmse' => 'float',
            'smape' => 'float',
            'baseline_smape' => 'float',
            'beats_baseline' => 'boolean',
            'selected' => 'boolean',
            'params' => 'array',
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

    public function forecasts(): HasMany
    {
        return $this->hasMany(DemandForecast::class, 'model_run_id');
    }
}
