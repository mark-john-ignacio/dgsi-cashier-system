<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = ['enrollment_id', 'school_year_id', 'or_number', 'payment_date',
        'amount', 'method', 'received_by'];

    protected $casts = ['amount' => 'decimal:2', 'payment_date' => 'date', 'voided_at' => 'datetime'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('voided_at');
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function unallocatedAmount(): float
    {
        return round((float) $this->amount - (float) $this->allocations()->sum('amount'), 2);
    }
}
