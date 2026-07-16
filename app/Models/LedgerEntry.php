<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerEntry extends Model
{
    protected $fillable = ['enrollment_id', 'fee_type_id', 'type', 'description', 'amount', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'voided_at' => 'datetime'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<FeeType, $this>
     */
    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
