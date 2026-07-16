<?php

namespace App\Models;

use Database\Factories\FeeStructureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeStructure extends Model
{
    /** @use HasFactory<FeeStructureFactory> */
    use HasFactory;

    protected $fillable = ['school_year_id', 'grade_level'];

    /**
     * @return HasMany<FeeStructureItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class);
    }

    /**
     * @return BelongsTo<SchoolYear, $this>
     */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function total(): float
    {
        return round((float) $this->items()->sum('amount'), 2);
    }
}
