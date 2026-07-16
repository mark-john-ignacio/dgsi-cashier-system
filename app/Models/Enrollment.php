<?php

namespace App\Models;

use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dynamic aggregate columns attached at runtime via withSum()/manual assignment
 * in ReportController::unpaidEnrollments() and Livewire\StudentSearch::render().
 *
 * @property float|null $assessed_total
 * @property float|null $paid_total
 * @property float|null $promised_total
 * @property float|null $balance_amount
 * @property float|null $assessed
 * @property float|null $paid
 * @property float|null $promised
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    protected $fillable = ['student_id', 'school_year_id', 'grade_level', 'section'];

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<SchoolYear, $this>
     */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<PromissoryNote, $this>
     */
    public function promissoryNotes(): HasMany
    {
        return $this->hasMany(PromissoryNote::class);
    }

    public function pendingPromissoryTotal(): float
    {
        return round((float) $this->promissoryNotes()->pending()->sum('amount'), 2);
    }

    public function totalAssessed(): float
    {
        return round((float) $this->ledgerEntries()->active()->sum('amount'), 2);
    }

    public function totalPaid(): float
    {
        return round((float) $this->payments()->whereNull('voided_at')->sum('amount'), 2);
    }

    public function balance(): float
    {
        return round($this->totalAssessed() - $this->totalPaid(), 2);
    }
}
