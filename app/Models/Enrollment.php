<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enrollment extends Model
{
    /** @use HasFactory<\Database\Factories\EnrollmentFactory> */
    use HasFactory;

    protected $fillable = ['student_id', 'school_year_id', 'grade_level', 'section'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolYear()
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
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
