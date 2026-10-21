<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'address', 'segment', 'loyalty_points'];

    protected function casts(): array
    {
        return ['loyalty_points' => 'integer'];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(ProductPromotion::class);
    }

    public function scopeInSegment($query, ?string $segment)
    {
        return $segment ? $query->where('segment', $segment) : $query;
    }
}
