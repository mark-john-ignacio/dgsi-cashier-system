<?php

namespace App\Models;

use Database\Factories\PromissoryNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromissoryNote extends Model
{
    /** @use HasFactory<PromissoryNoteFactory> */
    use HasFactory;

    protected $fillable = ['enrollment_id', 'amount', 'due_date', 'notes', 'status', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'due_date' => 'date'];

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'pending');
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }
}
