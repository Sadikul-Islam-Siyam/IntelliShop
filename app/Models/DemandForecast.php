<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandForecast extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'branch_id',
        'forecast_date',
        'predicted_demand',
        'model_used',
        'model_run_id',
        'confidence_score',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'branch_id' => 'integer',
            'forecast_date' => 'date:Y-m-d',
            'predicted_demand' => 'float',
            'model_run_id' => 'integer',
            'confidence_score' => 'float',
            'generated_at' => 'datetime',
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

    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class, 'model_run_id');
    }
}
