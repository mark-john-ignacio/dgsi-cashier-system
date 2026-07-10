<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SchoolYear extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolYearFactory> */
    use HasFactory;

    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function activate(): void
    {
        DB::transaction(function () {
            static::query()->update(['is_active' => false]);
            $this->forceFill(['is_active' => true])->save();
        });
    }
}
