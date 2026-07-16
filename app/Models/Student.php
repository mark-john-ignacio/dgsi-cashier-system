<?php

namespace App\Models;

use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    protected $fillable = ['student_no', 'first_name', 'last_name', 'middle_name',
        'guardian_name', 'guardian_contact', 'status'];

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->last_name}, {$this->first_name}";
    }
}
