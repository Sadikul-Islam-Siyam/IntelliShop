<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntelliShop Audit Log Model
 *
 * Represents an immutable audit trail entry for sensitive operations and security events.
 */
class AuditLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_logs';

    /**
     * Disable updated_at since audit records are append-only and immutable.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'details',
        'ip_address',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Boot the model. Automatically ensure created_at is populated.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    /**
     * Associated user who triggered the audited action (null for system/unauthenticated events).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope query to filter by specific action.
     */
    public function scopeFilterByAction(Builder $query, ?string $action): Builder
    {
        return $action ? $query->where('action', $action) : $query;
    }

    /**
     * Scope query to filter by target entity type and optional entity ID.
     */
    public function scopeFilterByEntity(Builder $query, ?string $entityType, ?int $entityId = null): Builder
    {
        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        if ($entityId) {
            $query->where('entity_id', $entityId);
        }

        return $query;
    }

    /**
     * Scope query to filter by actor user ID.
     */
    public function scopeFilterByUser(Builder $query, ?int $userId): Builder
    {
        return $userId ? $query->where('user_id', $userId) : $query;
    }

    /**
     * Scope query by created_at date range.
     */
    public function scopeFilterByDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    /**
     * Scope query to search across action, entity_type, ip_address, and details.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function (Builder $subQuery) use ($term) {
            $subQuery->where('action', 'like', "%{$term}%")
                ->orWhere('entity_type', 'like', "%{$term}%")
                ->orWhere('ip_address', 'like', "%{$term}%")
                ->orWhere('details', 'like', "%{$term}%");
        });
    }
}
