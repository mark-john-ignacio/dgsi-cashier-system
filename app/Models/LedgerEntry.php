<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $fillable = ['enrollment_id', 'fee_type_id', 'type', 'description', 'amount', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'voided_at' => 'datetime'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function feeType()
    {
        return $this->belongsTo(FeeType::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
