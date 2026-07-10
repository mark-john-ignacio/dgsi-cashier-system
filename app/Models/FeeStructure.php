<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeStructure extends Model
{
    /** @use HasFactory<\Database\Factories\FeeStructureFactory> */
    use HasFactory;

    protected $fillable = ['school_year_id', 'grade_level'];

    public function items()
    {
        return $this->hasMany(FeeStructureItem::class);
    }

    public function schoolYear()
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function total(): float
    {
        return round((float) $this->items()->sum('amount'), 2);
    }
}
