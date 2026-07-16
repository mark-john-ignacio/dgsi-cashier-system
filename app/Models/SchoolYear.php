<?php

namespace App\Models;

use Database\Factories\SchoolYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SchoolYear extends Model
{
    /** @use HasFactory<SchoolYearFactory> */
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
            // Write via the query builder: if this year was already active, the model
            // is not dirty and save() would issue no UPDATE, leaving no active year.
            static::whereKey($this->getKey())->update(['is_active' => true]);
            $this->forceFill(['is_active' => true])->syncOriginal();
        });
    }
}
