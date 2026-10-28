<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'address', 'rating', 'status'];

    protected function casts(): array
    {
        return ['rating' => 'decimal:2'];
    }

    public function productSuppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
