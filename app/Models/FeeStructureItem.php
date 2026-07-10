<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeStructureItem extends Model
{
    protected $fillable = ['fee_type_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function feeType()
    {
        return $this->belongsTo(FeeType::class);
    }
}
