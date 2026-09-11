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

    /**
     * Sum of allocations belonging to non-voided payments. Uses the loaded
     * `allocations` collection when available (filtering by each allocation's
     * payment itself, so it does not depend on any eager-load constraint);
     * otherwise queries with the same voided-payment exclusion.
     */
    public function paidAmount(): float
    {
        if ($this->relationLoaded('allocations')) {
            return round((float) $this->allocations
                ->filter(fn (PaymentAllocation $allocation) => ! $allocation->payment->isVoided())
                ->sum('amount'), 2);
        }

        return round((float) $this->allocations()
            ->whereHas('payment', fn ($q) => $q->whereNull('voided_at'))
            ->sum('amount'), 2);
    }

    public function unpaidAmount(): float
    {
        return round(max((float) $this->amount - $this->paidAmount(), 0), 2);
    }
}
