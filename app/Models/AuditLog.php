<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'details', 'created_at'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public static function record(?User $user, string $action, Model $subject, array $details = []): self
    {
        return static::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
