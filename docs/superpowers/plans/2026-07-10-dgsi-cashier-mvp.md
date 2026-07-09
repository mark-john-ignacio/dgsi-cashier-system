# DGSI Cashier System MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Laravel web app the Dei Gratia School cashier uses daily to record tuition payments, see student balances instantly, and produce audit-ready reports.

**Architecture:** Server-rendered Laravel 12 monolith (Blade + Livewire 3 for interactive screens), MySQL in production (SQLite in-memory for tests), deployed on an Oracle VM via Coolify. All money mutations go through service classes (`RegistrationService`, `PaymentService`); money rows are never edited or deleted, only voided.

**Tech Stack:** PHP 8.3+, Laravel 12, Laravel Breeze (Blade auth scaffolding), Livewire 3, MySQL 8 (prod) / SQLite (tests), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-10-dgsi-cashier-system-design.md`

## Global Constraints

- Laravel `^12.0`, PHP `>=8.3`, Livewire `^3.0`, Breeze Blade stack (PHPUnit, not Pest).
- Money columns are `decimal(12,2)`; amounts are Philippine pesos. Compare with `assertEqualsWithDelta(..., 0.001)` in tests.
- **Immutability:** `payments` and `ledger_entries` rows are never updated (except void columns) or deleted. Corrections = void (sets `voided_at`, `voided_by`, `void_reason`) + optional new entry.
- Duplicate OR numbers are blocked **per school year**, including voided payments (a voided OR is still physically used).
- Roles: `admin`, `cashier` (string column `users.role`). Admin can do everything a cashier can. Cashier may void only payments created today.
- All money mutations live in `app/Services/`; controllers and Livewire components never write payments/ledger rows directly.
- Every task ends with `php artisan test` green and a commit. Commit messages use conventional prefixes (`feat:`, `test:`, `chore:`).
- Spec deviation (deliberate): report "Excel export" ships as CSV download (opens natively in Excel) — no export package in MVP; a formatted-XLSX package is a later fast-follow if the school asks.
- Dev machine is Windows; commands below are PowerShell-safe. Run everything from repo root `D:\Code Projects\dgsi-app`.

## File Structure (end state)

```
app/
  Exceptions/DuplicateOrNumber.php, VoidNotAllowed.php
  Http/Controllers/ (Dashboard, StudentLedger, Students, FeeTypes, FeeStructures, Reports, Slips)
  Http/Middleware/EnsureRole.php
  Livewire/StudentSearch.php, RecordPayment.php
  Models/ (SchoolYear, Student, Enrollment, FeeType, FeeStructure, FeeStructureItem,
           LedgerEntry, Payment, PaymentAllocation, PromissoryNote, AuditLog, User)
  Services/RegistrationService.php, PaymentService.php
database/migrations/, database/factories/, database/seeders/
resources/views/ (dashboard, students, ledger, livewire, reports, print)
tests/Feature/ (one test class per task)
Dockerfile, .dockerignore
```

---

### Task 1: Scaffold Laravel app with auth and roles

**Files:**
- Create: entire Laravel 12 skeleton at repo root (via composer, then moved in)
- Create: `database/migrations/0001_01_01_000003_add_role_to_users_table.php` (timestamp will differ — use whatever `make:migration` generates; same applies to all migrations below)
- Create: `app/Http/Middleware/EnsureRole.php`
- Create: `database/seeders/AdminUserSeeder.php`
- Modify: `routes/auth.php` (remove self-registration), `bootstrap/app.php` (middleware alias), `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/AuthRolesTest.php`

**Interfaces:**
- Produces: `User` model with `role` string column and `isAdmin(): bool`; route middleware alias `role` (usage: `->middleware('role:admin')`, where `admin` role passes every check); seeded admin from env `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

- [ ] **Step 1: Create the Laravel project and merge into this repo**

The repo root is non-empty (`docs/`, `.git`), so scaffold in a temp folder and move:

```powershell
composer create-project laravel/laravel:^12.0 ..\dgsi-tmp
Get-ChildItem ..\dgsi-tmp -Force | Where-Object Name -notin '.git' | Move-Item -Destination .
Remove-Item ..\dgsi-tmp -Recurse -Force
composer install
```

- [ ] **Step 2: Install Breeze (Blade) and Livewire**

```powershell
composer require laravel/breeze --dev
php artisan breeze:install blade
composer require livewire/livewire:^3.0
npm install && npm run build
```

Verify `phpunit.xml` contains `<env name="DB_CONNECTION" value="sqlite"/>` and `<env name="DB_DATABASE" value=":memory:"/>` (Laravel 12 default — add if missing).

- [ ] **Step 3: Write the failing tests**

`tests/Feature/AuthRolesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_role_middleware_blocks_cashier_from_admin_routes(): void
    {
        \Illuminate\Support\Facades\Route::get('/_test-admin', fn () => 'ok')
            ->middleware(['web', 'auth', 'role:admin']);

        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($cashier)->get('/_test-admin')->assertForbidden();
        $this->actingAs($admin)->get('/_test-admin')->assertOk();
    }

    public function test_admin_passes_cashier_role_checks(): void
    {
        \Illuminate\Support\Facades\Route::get('/_test-cashier', fn () => 'ok')
            ->middleware(['web', 'auth', 'role:cashier']);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/_test-cashier')->assertOk();
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test --filter=AuthRolesTest`
Expected: FAIL (`/register` exists; `role` alias not defined; `role` column missing).

- [ ] **Step 5: Implement**

Migration `add_role_to_users_table` (`php artisan make:migration add_role_to_users_table`):

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('role')->default('cashier');
    });
}
public function down(): void
{
    Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
}
```

`app/Models/User.php` — add `'role'` to `$fillable` and:

```php
public function isAdmin(): bool
{
    return $this->role === 'admin';
}
```

`database/factories/UserFactory.php` — add `'role' => 'cashier',` to `definition()`.

`app/Http/Middleware/EnsureRole.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role)
    {
        $user = $request->user();
        abort_unless($user && ($user->role === $role || $user->isAdmin()), 403);

        return $next($request);
    }
}
```

`bootstrap/app.php` — inside `->withMiddleware(...)`:

```php
$middleware->alias(['role' => \App\Http\Middleware\EnsureRole::class]);
```

`routes/auth.php` — delete the two `register` routes (GET and POST) from the guest group. Leave login/logout/password routes untouched.

`database/seeders/AdminUserSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@dgsi.local')],
            [
                'name' => 'Administrator',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'change-me-now')),
                'role' => 'admin',
            ]
        );
    }
}
```

Call it from `DatabaseSeeder::run()`: `$this->call(AdminUserSeeder::class);`

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test`
Expected: PASS (including Breeze's own auth tests, minus any registration tests — delete `tests/Feature/Auth/RegistrationTest.php` since registration is disabled).

- [ ] **Step 7: Commit**

```powershell
git add -A
git commit -m "feat: scaffold Laravel 12 app with Breeze auth, Livewire, and cashier/admin roles"
```

---

### Task 2: School years

**Files:**
- Create: migration `create_school_years_table`, `app/Models/SchoolYear.php`, `database/factories/SchoolYearFactory.php`
- Test: `tests/Feature/SchoolYearTest.php`

**Interfaces:**
- Produces: `SchoolYear` model (`name` unique, `is_active` bool); `SchoolYear::active(): ?SchoolYear` (static); `$year->activate(): void` (deactivates all others, activates this one, atomic).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/SchoolYearTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_makes_exactly_one_year_active(): void
    {
        $old = SchoolYear::factory()->create(['name' => '2025-2026', 'is_active' => true]);
        $new = SchoolYear::factory()->create(['name' => '2026-2027', 'is_active' => false]);

        $new->activate();

        $this->assertTrue($new->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertSame(1, SchoolYear::where('is_active', true)->count());
        $this->assertTrue(SchoolYear::active()->is($new));
    }

    public function test_active_returns_null_when_no_active_year(): void
    {
        $this->assertNull(SchoolYear::active());
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=SchoolYearTest`
Expected: FAIL — class `SchoolYear` not found.

- [ ] **Step 3: Implement**

Migration (`php artisan make:model SchoolYear -mf`):

```php
Schema::create('school_years', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->boolean('is_active')->default(false);
    $table->timestamps();
});
```

`app/Models/SchoolYear.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SchoolYear extends Model
{
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
```

`database/factories/SchoolYearFactory.php` `definition()`:

```php
return [
    'name' => fake()->unique()->numerify('20##-20##'),
    'is_active' => false,
];
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=SchoolYearTest` → PASS.

- [ ] **Step 5: Commit**

```powershell
git add -A && git commit -m "feat: school years with single-active-year rule"
```

---

### Task 3: Fee types and fee structures

**Files:**
- Create: migrations `create_fee_types_table`, `create_fee_structures_table`, `create_fee_structure_items_table`
- Create: `app/Models/FeeType.php`, `app/Models/FeeStructure.php`, `app/Models/FeeStructureItem.php` + factories for FeeType and FeeStructure
- Test: `tests/Feature/FeeStructureTest.php`

**Interfaces:**
- Produces: `FeeType` (`name` unique). `FeeStructure` (`school_year_id`, `grade_level` string; unique together) with `items(): HasMany` of `FeeStructureItem` (`fee_type_id`, `amount` decimal) and `total(): float`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/FeeStructureTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_fee_structure_totals_its_items(): void
    {
        $year = SchoolYear::factory()->create();
        $tuition = FeeType::create(['name' => 'Tuition Fee']);
        $books = FeeType::create(['name' => 'Books']);

        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => $tuition->id, 'amount' => 25000]);
        $structure->items()->create(['fee_type_id' => $books->id, 'amount' => 3500]);

        $this->assertEqualsWithDelta(28500.00, $structure->total(), 0.001);
    }

    public function test_one_structure_per_grade_per_year(): void
    {
        $year = SchoolYear::factory()->create();
        FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=FeeStructureTest` → FAIL (models missing).

- [ ] **Step 3: Implement**

Migrations:

```php
Schema::create('fee_types', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->timestamps();
});

Schema::create('fee_structures', function (Blueprint $table) {
    $table->id();
    $table->foreignId('school_year_id')->constrained();
    $table->string('grade_level');
    $table->timestamps();
    $table->unique(['school_year_id', 'grade_level']);
});

Schema::create('fee_structure_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('fee_structure_id')->constrained()->cascadeOnDelete();
    $table->foreignId('fee_type_id')->constrained();
    $table->decimal('amount', 12, 2);
    $table->timestamps();
});
```

Models:

```php
// app/Models/FeeType.php
class FeeType extends Model
{
    use HasFactory;
    protected $fillable = ['name'];
}

// app/Models/FeeStructure.php
class FeeStructure extends Model
{
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

// app/Models/FeeStructureItem.php
class FeeStructureItem extends Model
{
    protected $fillable = ['fee_type_id', 'amount'];
    protected $casts = ['amount' => 'decimal:2'];

    public function feeType()
    {
        return $this->belongsTo(FeeType::class);
    }
}
```

Factories: `FeeTypeFactory` → `['name' => fake()->unique()->words(2, true)]`; `FeeStructureFactory` → `['school_year_id' => SchoolYear::factory(), 'grade_level' => 'Grade '.fake()->numberBetween(1, 12)]`.

- [ ] **Step 4: Run to verify pass** — `php artisan test --filter=FeeStructureTest` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: fee types and per-grade fee structures"`

---

### Task 4: Students and enrollments

**Files:**
- Create: migrations `create_students_table`, `create_enrollments_table`; `app/Models/Student.php`, `app/Models/Enrollment.php`; factories for both
- Test: `tests/Feature/StudentEnrollmentTest.php`

**Interfaces:**
- Produces: `Student` (`student_no` unique, `first_name`, `last_name`, `middle_name?`, `guardian_name`, `guardian_contact`, `status` in `enrolled|withdrawn`, accessor `full_name`). `Enrollment` (`student_id`, `school_year_id`, `grade_level`, `section?`; unique student+year) with relations `student()`, `schoolYear()`. Ledger/payment relations arrive in Tasks 5–6.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/StudentEnrollmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_has_full_name_accessor(): void
    {
        $s = Student::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $this->assertSame('Dela Cruz, Juan', $s->full_name);
    }

    public function test_student_cannot_enroll_twice_in_same_year(): void
    {
        $year = SchoolYear::factory()->create();
        $student = Student::factory()->create();
        Enrollment::create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level' => 'Grade 3']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Enrollment::create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level' => 'Grade 4']);
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=StudentEnrollmentTest` → FAIL.

- [ ] **Step 3: Implement**

Migrations:

```php
Schema::create('students', function (Blueprint $table) {
    $table->id();
    $table->string('student_no')->unique();
    $table->string('first_name');
    $table->string('last_name');
    $table->string('middle_name')->nullable();
    $table->string('guardian_name');
    $table->string('guardian_contact');
    $table->string('status')->default('enrolled'); // enrolled | withdrawn
    $table->timestamps();
});

Schema::create('enrollments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('student_id')->constrained();
    $table->foreignId('school_year_id')->constrained();
    $table->string('grade_level');
    $table->string('section')->nullable();
    $table->timestamps();
    $table->unique(['student_id', 'school_year_id']);
});
```

Models:

```php
// app/Models/Student.php
class Student extends Model
{
    use HasFactory;
    protected $fillable = ['student_no', 'first_name', 'last_name', 'middle_name',
        'guardian_name', 'guardian_contact', 'status'];

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->last_name}, {$this->first_name}";
    }
}

// app/Models/Enrollment.php
class Enrollment extends Model
{
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
}
```

`StudentFactory` `definition()`:

```php
return [
    'student_no' => fake()->unique()->numerify('DGS-####'),
    'first_name' => fake()->firstName(),
    'last_name' => fake()->lastName(),
    'guardian_name' => fake()->name(),
    'guardian_contact' => fake()->numerify('09#########'),
    'status' => 'enrolled',
];
```

`EnrollmentFactory` `definition()`:

```php
return [
    'student_id' => Student::factory(),
    'school_year_id' => SchoolYear::factory(),
    'grade_level' => 'Grade '.fake()->numberBetween(1, 12),
];
```

- [ ] **Step 4: Run to verify pass** — `php artisan test --filter=StudentEnrollmentTest` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: students and per-year enrollments"`

---

### Task 5: Ledger entries, registration assessment, and balance math

**Files:**
- Create: migration `create_ledger_entries_table`, `app/Models/LedgerEntry.php`, `app/Services/RegistrationService.php`
- Modify: `app/Models/Enrollment.php` (relations + money methods)
- Test: `tests/Feature/LedgerTest.php`

**Interfaces:**
- Consumes: `FeeStructure::items()`, `Enrollment`, `SchoolYear`.
- Produces:
  - `LedgerEntry` (`enrollment_id`, `fee_type_id?`, `type` in `charge|adjustment`, `description`, `amount` decimal — positive charge / negative adjustment, `created_by?`, void columns) with scope `active()` (`whereNull('voided_at')`).
  - `RegistrationService::register(Student $student, SchoolYear $year, string $gradeLevel, ?string $section = null): Enrollment` — creates enrollment and copies the matching fee structure's items onto the ledger as `charge` entries (throws `RuntimeException` if no fee structure exists for that grade+year).
  - `Enrollment::ledgerEntries(): HasMany`; `Enrollment::totalAssessed(): float`; `Enrollment::totalPaid(): float` (0.0 until Task 6); `Enrollment::balance(): float` = assessed − paid.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/LedgerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function makeStructure(SchoolYear $year, string $grade = 'Grade 3'): FeeStructure
    {
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => $grade]);
        $structure->items()->create([
            'fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 25000,
        ]);
        $structure->items()->create([
            'fee_type_id' => FeeType::create(['name' => 'Books'])->id, 'amount' => 3500,
        ]);
        return $structure;
    }

    public function test_registration_copies_fee_structure_to_ledger(): void
    {
        $year = SchoolYear::factory()->create();
        $this->makeStructure($year);

        $enrollment = app(RegistrationService::class)
            ->register(Student::factory()->create(), $year, 'Grade 3', 'St. Luke');

        $this->assertCount(2, $enrollment->ledgerEntries);
        $this->assertEqualsWithDelta(28500.00, $enrollment->totalAssessed(), 0.001);
        $this->assertEqualsWithDelta(28500.00, $enrollment->balance(), 0.001);
    }

    public function test_later_fee_structure_change_does_not_rewrite_existing_ledgers(): void
    {
        $year = SchoolYear::factory()->create();
        $structure = $this->makeStructure($year);
        $enrollment = app(RegistrationService::class)
            ->register(Student::factory()->create(), $year, 'Grade 3');

        $structure->items()->first()->update(['amount' => 99999]);

        $this->assertEqualsWithDelta(28500.00, $enrollment->fresh()->totalAssessed(), 0.001);
    }

    public function test_negative_adjustment_reduces_balance(): void
    {
        $year = SchoolYear::factory()->create();
        $this->makeStructure($year);
        $enrollment = app(RegistrationService::class)
            ->register(Student::factory()->create(), $year, 'Grade 3');

        $enrollment->ledgerEntries()->create([
            'type' => 'adjustment', 'description' => 'Sibling discount', 'amount' => -2000,
        ]);

        $this->assertEqualsWithDelta(26500.00, $enrollment->balance(), 0.001);
    }

    public function test_registration_fails_without_fee_structure(): void
    {
        $year = SchoolYear::factory()->create();
        $this->expectException(\RuntimeException::class);
        app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 7');
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=LedgerTest` → FAIL.

- [ ] **Step 3: Implement**

Migration:

```php
Schema::create('ledger_entries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('enrollment_id')->constrained();
    $table->foreignId('fee_type_id')->nullable()->constrained();
    $table->string('type'); // charge | adjustment
    $table->string('description');
    $table->decimal('amount', 12, 2); // positive = charge, negative = discount/adjustment
    $table->foreignId('created_by')->nullable()->constrained('users');
    $table->timestamp('voided_at')->nullable();
    $table->foreignId('voided_by')->nullable()->constrained('users');
    $table->string('void_reason')->nullable();
    $table->timestamps();
});
```

`app/Models/LedgerEntry.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $fillable = ['enrollment_id', 'fee_type_id', 'type', 'description', 'amount', 'created_by'];
    protected $casts = ['amount' => 'decimal:2', 'voided_at' => 'datetime'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function feeType()
    {
        return $this->belongsTo(FeeType::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class); // table arrives in Task 6
    }
}
```

`app/Services/RegistrationService.php`:

```php
<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function register(Student $student, SchoolYear $year, string $gradeLevel, ?string $section = null): Enrollment
    {
        $structure = FeeStructure::where('school_year_id', $year->id)
            ->where('grade_level', $gradeLevel)->with('items.feeType')->first();

        if (! $structure) {
            throw new \RuntimeException("No fee structure for {$gradeLevel} in {$year->name}.");
        }

        return DB::transaction(function () use ($student, $year, $gradeLevel, $section, $structure) {
            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'school_year_id' => $year->id,
                'grade_level' => $gradeLevel,
                'section' => $section,
            ]);

            foreach ($structure->items as $item) {
                $enrollment->ledgerEntries()->create([
                    'fee_type_id' => $item->fee_type_id,
                    'type' => 'charge',
                    'description' => $item->feeType->name,
                    'amount' => $item->amount,
                    'created_by' => auth()->id(),
                ]);
            }

            return $enrollment;
        });
    }
}
```

Add to `app/Models/Enrollment.php`:

```php
public function ledgerEntries()
{
    return $this->hasMany(LedgerEntry::class);
}

public function payments()
{
    return $this->hasMany(Payment::class); // table arrives in Task 6
}

public function totalAssessed(): float
{
    return round((float) $this->ledgerEntries()->active()->sum('amount'), 2);
}

public function totalPaid(): float
{
    if (! \Illuminate\Support\Facades\Schema::hasTable('payments')) {
        return 0.0;
    }
    return round((float) $this->payments()->whereNull('voided_at')->sum('amount'), 2);
}

public function balance(): float
{
    return round($this->totalAssessed() - $this->totalPaid(), 2);
}
```

(The `hasTable` guard is temporary scaffolding; **Task 6 Step 3 removes it** once the payments table exists.)

- [ ] **Step 4: Run to verify pass** — `php artisan test --filter=LedgerTest` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: ledger entries, registration assessment copy, balance math"`

---

### Task 6: Payments with allocation, duplicate-OR block, and overpayment credit

**Files:**
- Create: migrations `create_payments_table`, `create_payment_allocations_table`; `app/Models/Payment.php`, `app/Models/PaymentAllocation.php`; `app/Exceptions/DuplicateOrNumber.php`; `app/Services/PaymentService.php`
- Modify: `app/Models/Enrollment.php` (remove the `hasTable` guard in `totalPaid()`)
- Test: `tests/Feature/PaymentTest.php`

**Interfaces:**
- Consumes: `Enrollment` + ledger from Task 5.
- Produces:
  - `Payment` (`enrollment_id`, `school_year_id`, `or_number`, `payment_date` date, `amount`, `method` in `cash|gcash|bank`, `received_by`, void columns; unique `school_year_id`+`or_number`) with scope `active()`, relations `enrollment()`, `allocations()`, `receivedBy()`.
  - `PaymentAllocation` (`payment_id`, `ledger_entry_id`, `amount`).
  - `PaymentService::record(Enrollment $e, string $orNumber, string $paymentDate, float $amount, string $method, User $receivedBy): Payment` — allocates oldest-unpaid-charge-first; unallocated remainder is advance credit; throws `DuplicateOrNumber`.
  - `Payment::unallocatedAmount(): float`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/PaymentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateOrNumber;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private $enrollment;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 25000]);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Books'])->id, 'amount' => 3500]);
        $this->enrollment = app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 3');
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    public function test_partial_payment_reduces_balance_and_allocates_oldest_first(): void
    {
        $payment = app(PaymentService::class)->record(
            $this->enrollment, 'OR-1001', '2026-08-01', 5000.00, 'cash', $this->cashier);

        $this->assertEqualsWithDelta(23500.00, $this->enrollment->fresh()->balance(), 0.001);
        $this->assertCount(1, $payment->allocations); // all 5000 to Tuition (oldest charge)
        $this->assertEqualsWithDelta(5000.00, (float) $payment->allocations->first()->amount, 0.001);
    }

    public function test_payment_spanning_multiple_charges_splits_allocation(): void
    {
        app(PaymentService::class)->record($this->enrollment, 'OR-1001', '2026-08-01', 26000.00, 'cash', $this->cashier);

        $payment = \App\Models\Payment::first();
        $this->assertCount(2, $payment->allocations); // 25000 tuition + 1000 books
        $this->assertEqualsWithDelta(2500.00, $this->enrollment->fresh()->balance(), 0.001);
    }

    public function test_duplicate_or_number_in_same_year_is_rejected_even_if_voided(): void
    {
        $svc = app(PaymentService::class);
        $p = $svc->record($this->enrollment, 'OR-1001', '2026-08-01', 1000.00, 'cash', $this->cashier);
        $p->forceFill(['voided_at' => now()])->save();

        $this->expectException(DuplicateOrNumber::class);
        $svc->record($this->enrollment, 'OR-1001', '2026-08-02', 500.00, 'cash', $this->cashier);
    }

    public function test_overpayment_records_unallocated_credit(): void
    {
        $payment = app(PaymentService::class)->record(
            $this->enrollment, 'OR-1001', '2026-08-01', 30000.00, 'cash', $this->cashier);

        $this->assertEqualsWithDelta(-1500.00, $this->enrollment->fresh()->balance(), 0.001);
        $this->assertEqualsWithDelta(1500.00, $payment->unallocatedAmount(), 0.001);
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=PaymentTest` → FAIL.

- [ ] **Step 3: Implement**

Migrations:

```php
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('enrollment_id')->constrained();
    $table->foreignId('school_year_id')->constrained();
    $table->string('or_number');
    $table->date('payment_date');
    $table->decimal('amount', 12, 2);
    $table->string('method'); // cash | gcash | bank
    $table->foreignId('received_by')->constrained('users');
    $table->timestamp('voided_at')->nullable();
    $table->foreignId('voided_by')->nullable()->constrained('users');
    $table->string('void_reason')->nullable();
    $table->timestamps();
    $table->unique(['school_year_id', 'or_number']);
});

Schema::create('payment_allocations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ledger_entry_id')->constrained();
    $table->decimal('amount', 12, 2);
    $table->timestamps();
});
```

`app/Models/Payment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['enrollment_id', 'school_year_id', 'or_number', 'payment_date',
        'amount', 'method', 'received_by'];
    protected $casts = ['amount' => 'decimal:2', 'payment_date' => 'date', 'voided_at' => 'datetime'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('voided_at');
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function unallocatedAmount(): float
    {
        return round((float) $this->amount - (float) $this->allocations()->sum('amount'), 2);
    }
}
```

`app/Models/PaymentAllocation.php`:

```php
class PaymentAllocation extends Model
{
    protected $fillable = ['payment_id', 'ledger_entry_id', 'amount'];
    protected $casts = ['amount' => 'decimal:2'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function ledgerEntry()
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
```

`app/Exceptions/DuplicateOrNumber.php`:

```php
<?php

namespace App\Exceptions;

class DuplicateOrNumber extends \RuntimeException
{
}
```

`app/Services/PaymentService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\DuplicateOrNumber;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function record(Enrollment $enrollment, string $orNumber, string $paymentDate,
        float $amount, string $method, User $receivedBy): Payment
    {
        return DB::transaction(function () use ($enrollment, $orNumber, $paymentDate, $amount, $method, $receivedBy) {
            $exists = Payment::where('school_year_id', $enrollment->school_year_id)
                ->where('or_number', $orNumber)->exists();
            if ($exists) {
                throw new DuplicateOrNumber("OR number {$orNumber} is already used this school year.");
            }

            $payment = Payment::create([
                'enrollment_id' => $enrollment->id,
                'school_year_id' => $enrollment->school_year_id,
                'or_number' => $orNumber,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'method' => $method,
                'received_by' => $receivedBy->id,
            ]);

            $remaining = $amount;
            $charges = $enrollment->ledgerEntries()->active()
                ->where('type', 'charge')->orderBy('id')->get();

            foreach ($charges as $charge) {
                if ($remaining <= 0.005) {
                    break;
                }
                $alreadyPaid = (float) $charge->allocations()
                    ->whereHas('payment', fn ($q) => $q->whereNull('voided_at'))
                    ->sum('amount');
                $unpaid = round((float) $charge->amount - $alreadyPaid, 2);
                if ($unpaid <= 0.005) {
                    continue;
                }
                $applied = min($unpaid, $remaining);
                $payment->allocations()->create([
                    'ledger_entry_id' => $charge->id,
                    'amount' => $applied,
                ]);
                $remaining = round($remaining - $applied, 2);
            }
            // Any remainder stays unallocated on the payment = advance credit.

            return $payment;
        });
    }
}
```

In `app/Models/Enrollment.php`, simplify `totalPaid()` (remove the temporary `hasTable` guard from Task 5):

```php
public function totalPaid(): float
{
    return round((float) $this->payments()->whereNull('voided_at')->sum('amount'), 2);
}
```

- [ ] **Step 4: Run to verify pass** — `php artisan test` (full suite — Task 5 tests must still pass) → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: payments with oldest-first allocation, duplicate OR block, overpayment credit"`

---

### Task 7: Voiding and audit log

**Files:**
- Create: migration `create_audit_logs_table`, `app/Models/AuditLog.php`, `app/Exceptions/VoidNotAllowed.php`
- Modify: `app/Services/PaymentService.php` (add `void()`; log on `record()`)
- Test: `tests/Feature/VoidPaymentTest.php`

**Interfaces:**
- Consumes: `Payment`, `User`, `PaymentService`.
- Produces:
  - `AuditLog::record(?User $user, string $action, Model $subject, array $details = []): AuditLog` (static helper; table: `user_id?`, `action`, `subject_type`, `subject_id`, `details` json, `created_at`).
  - `PaymentService::void(Payment $payment, User $by, string $reason): void` — throws `VoidNotAllowed` when: already voided; reason blank; or actor is a non-admin voiding a payment not created today.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/VoidPaymentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\VoidNotAllowed;
use App\Models\AuditLog;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoidPaymentTest extends TestCase
{
    use RefreshDatabase;

    private $enrollment;
    private User $cashier;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 25000]);
        $this->enrollment = app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 3');
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_void_restores_balance_and_keeps_row_visible(): void
    {
        $svc = app(PaymentService::class);
        $payment = $svc->record($this->enrollment, 'OR-1', '2026-08-01', 5000, 'cash', $this->cashier);
        $this->assertEqualsWithDelta(20000.00, $this->enrollment->fresh()->balance(), 0.001);

        $svc->void($payment, $this->cashier, 'Wrong amount keyed in');

        $fresh = $payment->fresh();
        $this->assertTrue($fresh->isVoided());
        $this->assertSame('Wrong amount keyed in', $fresh->void_reason);
        $this->assertEqualsWithDelta(25000.00, $this->enrollment->fresh()->balance(), 0.001);
        $this->assertDatabaseCount('payments', 1); // never deleted
    }

    public function test_cashier_cannot_void_payment_created_on_a_previous_day(): void
    {
        $svc = app(PaymentService::class);
        $payment = $svc->record($this->enrollment, 'OR-1', '2026-08-01', 5000, 'cash', $this->cashier);
        $payment->forceFill(['created_at' => now()->subDay()])->save();

        $this->expectException(VoidNotAllowed::class);
        $svc->void($payment->fresh(), $this->cashier, 'Late void attempt');
    }

    public function test_admin_can_void_old_payments_and_action_is_audited(): void
    {
        $svc = app(PaymentService::class);
        $payment = $svc->record($this->enrollment, 'OR-1', '2026-08-01', 5000, 'cash', $this->cashier);
        $payment->forceFill(['created_at' => now()->subDays(10)])->save();

        $svc->void($payment->fresh(), $this->admin, 'Audit correction');

        $this->assertTrue($payment->fresh()->isVoided());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.voided',
            'subject_type' => \App\Models\Payment::class,
            'subject_id' => $payment->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_void_requires_a_reason_and_rejects_double_void(): void
    {
        $svc = app(PaymentService::class);
        $payment = $svc->record($this->enrollment, 'OR-1', '2026-08-01', 5000, 'cash', $this->cashier);

        try {
            $svc->void($payment, $this->cashier, '  ');
            $this->fail('Blank reason should be rejected');
        } catch (VoidNotAllowed) {
        }

        $svc->void($payment, $this->cashier, 'Valid reason');
        $this->expectException(VoidNotAllowed::class);
        $svc->void($payment->fresh(), $this->admin, 'Double void');
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=VoidPaymentTest` → FAIL.

- [ ] **Step 3: Implement**

Migration:

```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained();
    $table->string('action');
    $table->string('subject_type');
    $table->unsignedBigInteger('subject_id');
    $table->json('details')->nullable();
    $table->timestamp('created_at')->useCurrent();
});
```

`app/Models/AuditLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Exceptions/VoidNotAllowed.php`:

```php
<?php

namespace App\Exceptions;

class VoidNotAllowed extends \RuntimeException
{
}
```

Add to `app/Services/PaymentService.php`:

```php
public function void(Payment $payment, User $by, string $reason): void
{
    if ($payment->isVoided()) {
        throw new VoidNotAllowed('This payment is already voided.');
    }
    if (trim($reason) === '') {
        throw new VoidNotAllowed('A void reason is required.');
    }
    if (! $by->isAdmin() && ! $payment->created_at->isToday()) {
        throw new VoidNotAllowed('Cashiers may only void payments recorded today. Ask an admin.');
    }

    DB::transaction(function () use ($payment, $by, $reason) {
        $payment->forceFill([
            'voided_at' => now(),
            'voided_by' => $by->id,
            'void_reason' => trim($reason),
        ])->save();

        \App\Models\AuditLog::record($by, 'payment.voided', $payment, [
            'or_number' => $payment->or_number,
            'amount' => (string) $payment->amount,
            'reason' => trim($reason),
        ]);
    });
}
```

Also add imports (`App\Exceptions\VoidNotAllowed`, `App\Models\AuditLog`) and, at the end of `record()`'s transaction (just before `return $payment;`):

```php
\App\Models\AuditLog::record($receivedBy, 'payment.recorded', $payment, [
    'or_number' => $orNumber, 'amount' => (string) $amount,
]);
```

- [ ] **Step 4: Run to verify pass** — `php artisan test` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: payment voiding with role rules and audit log"`

---

### Task 8: Promissory notes

**Files:**
- Create: migration `create_promissory_notes_table`, `app/Models/PromissoryNote.php`, factory
- Modify: `app/Models/Enrollment.php`
- Test: `tests/Feature/PromissoryNoteTest.php`

**Interfaces:**
- Produces: `PromissoryNote` (`enrollment_id`, `amount`, `due_date` date, `notes?` text, `status` in `pending|fulfilled|broken`, `created_by?`), scope `pending()`; `Enrollment::promissoryNotes(): HasMany`; `Enrollment::pendingPromissoryTotal(): float`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/PromissoryNoteTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\PromissoryNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromissoryNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_promissory_total_counts_only_pending_notes(): void
    {
        $enrollment = Enrollment::factory()->create();
        PromissoryNote::factory()->create(['enrollment_id' => $enrollment->id, 'amount' => 3000, 'status' => 'pending']);
        PromissoryNote::factory()->create(['enrollment_id' => $enrollment->id, 'amount' => 1000, 'status' => 'fulfilled']);

        $this->assertEqualsWithDelta(3000.00, $enrollment->pendingPromissoryTotal(), 0.001);
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=PromissoryNoteTest` → FAIL.

- [ ] **Step 3: Implement**

Migration:

```php
Schema::create('promissory_notes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('enrollment_id')->constrained();
    $table->decimal('amount', 12, 2);
    $table->date('due_date');
    $table->text('notes')->nullable();
    $table->string('status')->default('pending'); // pending | fulfilled | broken
    $table->foreignId('created_by')->nullable()->constrained('users');
    $table->timestamps();
});
```

`app/Models/PromissoryNote.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromissoryNote extends Model
{
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
```

Factory `definition()`:

```php
return [
    'enrollment_id' => \App\Models\Enrollment::factory(),
    'amount' => fake()->randomFloat(2, 500, 10000),
    'due_date' => fake()->dateTimeBetween('now', '+2 months'),
    'status' => 'pending',
];
```

Add to `Enrollment`:

```php
public function promissoryNotes()
{
    return $this->hasMany(PromissoryNote::class);
}

public function pendingPromissoryTotal(): float
{
    return round((float) $this->promissoryNotes()->pending()->sum('amount'), 2);
}
```

- [ ] **Step 4: Run to verify pass** — `php artisan test --filter=PromissoryNoteTest` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: promissory notes"`

---

### Task 9: Dashboard and student search screens

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`, `app/Livewire/StudentSearch.php`, `resources/views/dashboard.blade.php` (replace Breeze's), `resources/views/livewire/student-search.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/navigation.blade.php`
- Test: `tests/Feature/StudentSearchTest.php`

**Interfaces:**
- Consumes: `SchoolYear::active()`, `Enrollment` money methods, `Payment::active()`.
- Produces: routes `GET /dashboard` (name `dashboard`) and `GET /students/search` (name `students.search`); Livewire component `student-search` with public props `$query` (string), `$gradeLevel` (string), `$withBalanceOnly` (bool). Task 10 links each result row to `route('ledger.show', $enrollment)`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/StudentSearchTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\StudentSearch;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentSearchTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $this->year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);
    }

    public function test_dashboard_requires_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_dashboard_shows_todays_collections(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $enrollment = app(RegistrationService::class)
            ->register(Student::factory()->create(), $this->year, 'Grade 3');
        app(PaymentService::class)->record($enrollment, 'OR-1', now()->toDateString(), 1500, 'cash', $cashier);

        $this->actingAs($cashier)->get('/dashboard')
            ->assertOk()
            ->assertSee('1,500.00');
    }

    public function test_search_filters_by_name_and_balance(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $paid = app(RegistrationService::class)->register(
            Student::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']), $this->year, 'Grade 3');
        $unpaid = app(RegistrationService::class)->register(
            Student::factory()->create(['first_name' => 'Jose', 'last_name' => 'Reyes']), $this->year, 'Grade 3');
        app(PaymentService::class)->record($paid, 'OR-1', now()->toDateString(), 10000, 'cash', $cashier);

        Livewire::actingAs($cashier)->test(StudentSearch::class)
            ->set('query', 'Reyes')
            ->assertSee('Reyes, Jose')
            ->assertDontSee('Santos, Maria');

        Livewire::actingAs($cashier)->test(StudentSearch::class)
            ->set('withBalanceOnly', true)
            ->assertSee('Reyes, Jose')
            ->assertDontSee('Santos, Maria');
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=StudentSearchTest` → FAIL.

- [ ] **Step 3: Implement**

`app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\SchoolYear;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = Payment::active()->whereDate('payment_date', today());

        return view('dashboard', [
            'activeYear' => SchoolYear::active(),
            'todayTotal' => (float) (clone $today)->sum('amount'),
            'todayCount' => (clone $today)->count(),
        ]);
    }
}
```

`routes/web.php` — replace Breeze's dashboard route and add search:

```php
use App\Http\Controllers\DashboardController;

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::view('/students/search', 'students.search')->name('students.search');
});
```

`resources/views/dashboard.blade.php` (replace Breeze's placeholder):

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl">Dashboard
            <span class="text-sm text-gray-500">{{ $activeYear?->name ?? 'No active school year' }}</span>
        </h2>
    </x-slot>
    <div class="py-6 max-w-5xl mx-auto space-y-6 px-4">
        <div class="grid grid-cols-2 gap-4">
            <div class="bg-white shadow rounded p-6">
                <div class="text-sm text-gray-500">Today's Collections</div>
                <div class="text-3xl font-bold">₱{{ number_format($todayTotal, 2) }}</div>
            </div>
            <div class="bg-white shadow rounded p-6">
                <div class="text-sm text-gray-500">Payments Today</div>
                <div class="text-3xl font-bold">{{ $todayCount }}</div>
            </div>
        </div>
        <div class="bg-white shadow rounded p-6">
            <livewire:student-search />
        </div>
    </div>
</x-app-layout>
```

`resources/views/students/search.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Find Student</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4 bg-white shadow rounded p-6">
        <livewire:student-search />
    </div>
</x-app-layout>
```

`app/Livewire/StudentSearch.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use Livewire\Component;

class StudentSearch extends Component
{
    public string $query = '';
    public string $gradeLevel = '';
    public bool $withBalanceOnly = false;

    public function render()
    {
        $year = SchoolYear::active();
        $enrollments = collect();
        $gradeLevels = [];

        if ($year) {
            $q = Enrollment::with('student')
                ->where('school_year_id', $year->id)
                ->withSum(['ledgerEntries as assessed' => fn ($s) => $s->whereNull('voided_at')], 'amount')
                ->withSum(['payments as paid' => fn ($s) => $s->whereNull('voided_at')], 'amount')
                ->withSum(['promissoryNotes as promised' => fn ($s) => $s->where('status', 'pending')], 'amount');

            if ($this->query !== '') {
                $q->whereHas('student', function ($s) {
                    $s->where('first_name', 'like', "%{$this->query}%")
                        ->orWhere('last_name', 'like', "%{$this->query}%")
                        ->orWhere('student_no', 'like', "%{$this->query}%");
                });
            }
            if ($this->gradeLevel !== '') {
                $q->where('grade_level', $this->gradeLevel);
            }

            $enrollments = $q->orderBy('grade_level')->get()
                ->map(function ($e) {
                    $e->balance_amount = round((float) $e->assessed - (float) $e->paid, 2);
                    return $e;
                });

            if ($this->withBalanceOnly) {
                $enrollments = $enrollments->filter(fn ($e) => $e->balance_amount > 0.005)->values();
            }

            $gradeLevels = Enrollment::where('school_year_id', $year->id)
                ->distinct()->orderBy('grade_level')->pluck('grade_level');
        }

        return view('livewire.student-search', compact('enrollments', 'gradeLevels'));
    }
}
```

`resources/views/livewire/student-search.blade.php`:

```blade
<div>
    <div class="flex gap-3 mb-4">
        <input type="text" wire:model.live.debounce.300ms="query" placeholder="Search name or student no..."
               class="border rounded px-3 py-2 flex-1" autofocus>
        <select wire:model.live="gradeLevel" class="border rounded px-3 py-2">
            <option value="">All grades</option>
            @foreach ($gradeLevels as $g)
                <option value="{{ $g }}">{{ $g }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="withBalanceOnly"> With balance only
        </label>
    </div>
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left border-b">
                <th class="py-2">Student No.</th><th>Name</th><th>Grade & Section</th>
                <th class="text-right">Balance</th><th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($enrollments as $e)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-2">{{ $e->student->student_no }}</td>
                    <td>{{ $e->student->full_name }}</td>
                    <td>{{ $e->grade_level }}{{ $e->section ? ' — '.$e->section : '' }}</td>
                    <td class="text-right {{ $e->balance_amount > 0.005 ? ((float) $e->promised > 0 ? 'text-amber-600' : 'text-red-600') : 'text-green-700' }}">
                        ₱{{ number_format($e->balance_amount, 2) }}
                        @if ($e->balance_amount > 0.005 && (float) $e->promised > 0)
                            <span class="text-xs">(promissory)</span>
                        @endif
                    </td>
                    <td class="text-right">
                        @if (\Illuminate\Support\Facades\Route::has('ledger.show'))
                            <a href="{{ route('ledger.show', $e) }}" class="text-blue-600 underline">Open</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-4 text-gray-500">No students found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

(The `Route::has('ledger.show')` guard keeps this task shippable before Task 10; **Task 10 Step 3 removes the guard** once the route exists.)

`resources/views/layouts/navigation.blade.php` — inside the existing `hidden sm:flex` nav-links div, add links (repeat in the responsive section):

```blade
<x-nav-link :href="route('students.search')" :active="request()->routeIs('students.search')">Find Student</x-nav-link>
```

- [ ] **Step 4: Run to verify pass** — `php artisan test` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: dashboard with today totals and live student search"`

---

### Task 10: Student ledger page, record-payment form, acknowledgment slip

**Files:**
- Create: `app/Http/Controllers/StudentLedgerController.php`, `app/Livewire/RecordPayment.php`, `app/Http/Controllers/SlipController.php`
- Create: `resources/views/ledger/show.blade.php`, `resources/views/livewire/record-payment.blade.php`, `resources/views/print/slip.blade.php`, `resources/views/print/layout.blade.php`
- Modify: `routes/web.php`, `resources/views/livewire/student-search.blade.php` (remove `Route::has` guard)
- Test: `tests/Feature/RecordPaymentUiTest.php`

**Interfaces:**
- Consumes: `PaymentService::record()` / `void()`, `Enrollment` money methods, `PromissoryNote`.
- Produces: routes `GET /ledger/{enrollment}` (name `ledger.show`), `GET /slips/{payment}` (name `slips.show`), `POST /ledger/{enrollment}/promissory` (name `promissory.store`), `POST /promissory/{promissoryNote}/status` (name `promissory.status`), `POST /payments/{payment}/void` (name `payments.void`); Livewire component `record-payment` with props `$enrollment`, `$or_number`, `$payment_date`, `$amount`, `$method`, `$confirmOverpay`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/RecordPaymentUiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\RecordPayment;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecordPaymentUiTest extends TestCase
{
    use RefreshDatabase;

    private $enrollment;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $s = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $s->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);
        $this->enrollment = app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 3');
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    public function test_ledger_page_shows_charges_and_balance(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('ledger.show', $this->enrollment))
            ->assertOk()
            ->assertSee('Tuition Fee')
            ->assertSee('10,000.00');
    }

    public function test_recording_payment_via_form_redirects_to_slip(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(RecordPayment::class, ['enrollment' => $this->enrollment])
            ->set('or_number', 'OR-500')
            ->set('amount', '2500')
            ->set('method', 'cash')
            ->call('save')
            ->assertRedirect(route('slips.show', Payment::first()));

        $this->assertEqualsWithDelta(7500.00, $this->enrollment->fresh()->balance(), 0.001);
    }

    public function test_duplicate_or_shows_validation_error_not_crash(): void
    {
        app(PaymentService::class)->record($this->enrollment, 'OR-500', now()->toDateString(), 100, 'cash', $this->cashier);

        Livewire::actingAs($this->cashier)
            ->test(RecordPayment::class, ['enrollment' => $this->enrollment])
            ->set('or_number', 'OR-500')
            ->set('amount', '100')
            ->set('method', 'cash')
            ->call('save')
            ->assertHasErrors('or_number');
    }

    public function test_overpayment_requires_confirmation(): void
    {
        $component = Livewire::actingAs($this->cashier)
            ->test(RecordPayment::class, ['enrollment' => $this->enrollment])
            ->set('or_number', 'OR-501')
            ->set('amount', '15000')
            ->set('method', 'cash')
            ->call('save')
            ->assertHasErrors('amount'); // blocked until confirmed

        $component->set('confirmOverpay', true)->call('save');
        $this->assertEqualsWithDelta(-5000.00, $this->enrollment->fresh()->balance(), 0.001);
    }

    public function test_promissory_note_can_be_marked_fulfilled(): void
    {
        $note = $this->enrollment->promissoryNotes()->create([
            'amount' => 2000, 'due_date' => now()->addMonth(), 'status' => 'pending',
        ]);

        $this->actingAs($this->cashier)
            ->post(route('promissory.status', $note), ['status' => 'fulfilled'])
            ->assertRedirect();

        $this->assertSame('fulfilled', $note->fresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->enrollment->pendingPromissoryTotal(), 0.001);
    }

    public function test_void_route_requires_reason_and_restores_balance(): void
    {
        $payment = app(PaymentService::class)->record($this->enrollment, 'OR-500', now()->toDateString(), 2500, 'cash', $this->cashier);

        $this->actingAs($this->cashier)
            ->post(route('payments.void', $payment), ['reason' => 'Keyed wrong student'])
            ->assertRedirect();

        $this->assertTrue($payment->fresh()->isVoided());
        $this->assertEqualsWithDelta(10000.00, $this->enrollment->fresh()->balance(), 0.001);
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=RecordPaymentUiTest` → FAIL.

- [ ] **Step 3: Implement**

Routes (inside the `auth` group in `routes/web.php`):

```php
use App\Http\Controllers\SlipController;
use App\Http\Controllers\StudentLedgerController;

Route::get('/ledger/{enrollment}', [StudentLedgerController::class, 'show'])->name('ledger.show');
Route::post('/ledger/{enrollment}/promissory', [StudentLedgerController::class, 'storePromissory'])->name('promissory.store');
Route::post('/promissory/{promissoryNote}/status', [StudentLedgerController::class, 'updatePromissoryStatus'])->name('promissory.status');
Route::post('/payments/{payment}/void', [StudentLedgerController::class, 'voidPayment'])->name('payments.void');
Route::get('/slips/{payment}', [SlipController::class, 'show'])->name('slips.show');
```

`app/Http/Controllers/StudentLedgerController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\VoidNotAllowed;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class StudentLedgerController extends Controller
{
    public function show(Enrollment $enrollment)
    {
        $enrollment->load(['student', 'schoolYear',
            'ledgerEntries.feeType',
            'payments' => fn ($q) => $q->orderBy('payment_date')->orderBy('id'),
            'promissoryNotes']);

        return view('ledger.show', compact('enrollment'));
    }

    public function storePromissory(Request $request, Enrollment $enrollment)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'due_date' => ['required', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $enrollment->promissoryNotes()->create($data + ['created_by' => $request->user()->id]);

        return redirect()->route('ledger.show', $enrollment)->with('status', 'Promissory note added.');
    }

    public function updatePromissoryStatus(Request $request, \App\Models\PromissoryNote $promissoryNote)
    {
        $data = $request->validate(['status' => ['required', 'in:pending,fulfilled,broken']]);
        $promissoryNote->update($data);

        return redirect()->route('ledger.show', $promissoryNote->enrollment)
            ->with('status', 'Promissory note marked '.$data['status'].'.');
    }

    public function voidPayment(Request $request, Payment $payment, PaymentService $service)
    {
        $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $service->void($payment, $request->user(), $request->input('reason'));
        } catch (VoidNotAllowed $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('ledger.show', $payment->enrollment)->with('status', 'Payment voided.');
    }
}
```

`app/Livewire/RecordPayment.php`:

```php
<?php

namespace App\Livewire;

use App\Exceptions\DuplicateOrNumber;
use App\Models\Enrollment;
use App\Services\PaymentService;
use Livewire\Component;

class RecordPayment extends Component
{
    public Enrollment $enrollment;
    public string $or_number = '';
    public string $payment_date = '';
    public string $amount = '';
    public string $method = 'cash';
    public bool $confirmOverpay = false;

    public function mount(): void
    {
        $this->payment_date = now()->toDateString();
    }

    public function save(PaymentService $service)
    {
        $this->validate([
            'or_number' => ['required', 'string', 'max:50'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,gcash,bank'],
        ]);

        $amount = (float) $this->amount;
        $balance = $this->enrollment->balance();

        if ($amount > $balance + 0.005 && ! $this->confirmOverpay) {
            $this->addError('amount',
                'Amount exceeds the remaining balance of ₱'.number_format($balance, 2)
                .'. Tick "Record as advance/credit" to confirm.');
            return;
        }

        try {
            $payment = $service->record($this->enrollment, trim($this->or_number),
                $this->payment_date, $amount, $this->method, auth()->user());
        } catch (DuplicateOrNumber $e) {
            $this->addError('or_number', $e->getMessage());
            return;
        }

        return $this->redirectRoute('slips.show', $payment);
    }

    public function render()
    {
        return view('livewire.record-payment');
    }
}
```

`resources/views/livewire/record-payment.blade.php`:

```blade
<form wire:submit="save" class="space-y-3">
    <div>
        <label class="block text-sm">OR Number (from booklet)</label>
        <input type="text" wire:model="or_number" class="border rounded px-3 py-2 w-full">
        @error('or_number') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm">Payment Date</label>
        <input type="date" wire:model="payment_date" class="border rounded px-3 py-2 w-full">
        @error('payment_date') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm">Amount (₱)</label>
        <input type="number" step="0.01" wire:model="amount" class="border rounded px-3 py-2 w-full">
        @error('amount') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
        <label class="flex items-center gap-2 text-sm mt-1">
            <input type="checkbox" wire:model="confirmOverpay"> Record as advance/credit if over the balance
        </label>
    </div>
    <div>
        <label class="block text-sm">Method</label>
        <select wire:model="method" class="border rounded px-3 py-2 w-full">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="bank">Bank Deposit</option>
        </select>
    </div>
    <button type="submit" class="bg-blue-600 text-white rounded px-4 py-2" wire:loading.attr="disabled">
        <span wire:loading.remove>Save Payment</span><span wire:loading>Saving…</span>
    </button>
</form>
```

`resources/views/ledger/show.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl">
            {{ $enrollment->student->full_name }}
            <span class="text-sm text-gray-500">
                {{ $enrollment->student->student_no }} · {{ $enrollment->grade_level }}
                {{ $enrollment->section }} · {{ $enrollment->schoolYear->name }}
            </span>
        </h2>
    </x-slot>
    <div class="py-6 max-w-6xl mx-auto px-4 grid grid-cols-3 gap-6">
        <div class="col-span-2 space-y-6">
            @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
            @if ($errors->any()) <div class="bg-red-100 p-3 rounded">{{ $errors->first() }}</div> @endif

            <div class="bg-white shadow rounded p-6">
                <h3 class="font-semibold mb-3">Assessment</h3>
                <table class="w-full text-sm">
                    @foreach ($enrollment->ledgerEntries as $entry)
                        <tr class="border-b {{ $entry->voided_at ? 'line-through text-gray-400' : '' }}">
                            <td class="py-1">{{ $entry->description }}</td>
                            <td class="text-right">₱{{ number_format($entry->amount, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold">
                        <td class="py-2">Total Assessed</td>
                        <td class="text-right">₱{{ number_format($enrollment->totalAssessed(), 2) }}</td>
                    </tr>
                </table>
            </div>

            <div class="bg-white shadow rounded p-6">
                <h3 class="font-semibold mb-3">Payments</h3>
                <table class="w-full text-sm">
                    <thead><tr class="text-left border-b"><th class="py-1">OR No.</th><th>Date</th><th>Method</th><th class="text-right">Amount</th><th></th></tr></thead>
                    @forelse ($enrollment->payments as $payment)
                        <tr class="border-b {{ $payment->isVoided() ? 'line-through text-gray-400' : '' }}">
                            <td class="py-1">{{ $payment->or_number }}</td>
                            <td>{{ $payment->payment_date->format('M d, Y') }}</td>
                            <td>{{ strtoupper($payment->method) }}</td>
                            <td class="text-right">₱{{ number_format($payment->amount, 2) }}</td>
                            <td class="text-right">
                                @if ($payment->isVoided())
                                    <span class="text-xs">VOID: {{ $payment->void_reason }}</span>
                                @else
                                    <a href="{{ route('slips.show', $payment) }}" class="text-blue-600 underline text-xs">Slip</a>
                                    <form method="POST" action="{{ route('payments.void', $payment) }}" class="inline"
                                          onsubmit="const r = prompt('Reason for voiding OR {{ $payment->or_number }}:'); if (!r) return false; this.reason.value = r;">
                                        @csrf
                                        <input type="hidden" name="reason">
                                        <button class="text-red-600 underline text-xs">Void</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2 text-gray-500">No payments yet.</td></tr>
                    @endforelse
                </table>
            </div>

            @if ($enrollment->promissoryNotes->isNotEmpty())
                <div class="bg-white shadow rounded p-6">
                    <h3 class="font-semibold mb-3">Promissory Notes</h3>
                    <table class="w-full text-sm">
                        @foreach ($enrollment->promissoryNotes as $note)
                            <tr class="border-b">
                                <td class="py-1">Due {{ $note->due_date->format('M d, Y') }}</td>
                                <td>{{ $note->notes }}</td>
                                <td class="uppercase text-xs">{{ $note->status }}</td>
                                <td class="text-right">₱{{ number_format($note->amount, 2) }}</td>
                                <td class="text-right">
                                    @if ($note->status === 'pending')
                                        @foreach (['fulfilled', 'broken'] as $newStatus)
                                            <form method="POST" action="{{ route('promissory.status', $note) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="status" value="{{ $newStatus }}">
                                                <button class="text-xs underline {{ $newStatus === 'fulfilled' ? 'text-green-700' : 'text-red-600' }}">
                                                    Mark {{ $newStatus }}
                                                </button>
                                            </form>
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white shadow rounded p-6">
                <div class="text-sm text-gray-500">Current Balance</div>
                <div class="text-3xl font-bold {{ $enrollment->balance() > 0.005 ? 'text-red-600' : 'text-green-700' }}">
                    ₱{{ number_format($enrollment->balance(), 2) }}
                </div>
                <div class="mt-3 text-sm space-x-2">
                    <a class="text-blue-600 underline" target="_blank"
                       href="{{ route('reports.statement', $enrollment) }}">Statement</a>
                    <a class="text-blue-600 underline" target="_blank"
                       href="{{ route('reports.notice', $enrollment) }}">Notice</a>
                </div>
            </div>
            <div class="bg-white shadow rounded p-6">
                <h3 class="font-semibold mb-3">Record Payment</h3>
                <livewire:record-payment :enrollment="$enrollment" />
            </div>
            <div class="bg-white shadow rounded p-6">
                <h3 class="font-semibold mb-3">Add Promissory Note</h3>
                <form method="POST" action="{{ route('promissory.store', $enrollment) }}" class="space-y-3">
                    @csrf
                    <input type="number" step="0.01" name="amount" placeholder="Amount" class="border rounded px-3 py-2 w-full" required>
                    <input type="date" name="due_date" class="border rounded px-3 py-2 w-full" required>
                    <input type="text" name="notes" placeholder="Notes (optional)" class="border rounded px-3 py-2 w-full">
                    <button class="bg-gray-700 text-white rounded px-4 py-2">Save Note</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
```

Note: `reports.statement` and `reports.notice` routes arrive in Task 12. To keep this task green, register placeholder routes now in `routes/web.php` (Task 12 replaces them with real controllers):

```php
Route::get('/reports/statement/{enrollment}', fn () => abort(501))->name('reports.statement');
Route::get('/reports/notice/{enrollment}', fn () => abort(501))->name('reports.notice');
```

`app/Http/Controllers/SlipController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Payment;

class SlipController extends Controller
{
    public function show(Payment $payment)
    {
        $payment->load(['enrollment.student', 'enrollment.schoolYear', 'allocations.ledgerEntry', 'receivedBy']);

        return view('print.slip', compact('payment'));
    }
}
```

`resources/views/print/layout.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 13px; margin: 2rem; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 0; }
        .sub { text-align: center; color: #555; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; text-align: left; }
        .right { text-align: right; }
        .total { font-weight: bold; }
        .void { text-decoration: line-through; color: #999; }
        .no-print { margin-top: 1.5rem; }
        @media print { .no-print { display: none; } body { margin: 0.5rem; } }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <h1>DEI GRATIA SCHOOL, INC.</h1>
    <p class="sub">@yield('subtitle')</p>
    @yield('content')
    <div class="no-print"><button onclick="window.print()">Print</button></div>
</body>
</html>
```

`resources/views/print/slip.blade.php`:

```blade
@extends('print.layout')
@section('title', 'Payment Acknowledgment — OR '.$payment->or_number)
@section('subtitle', 'Payment Acknowledgment Slip (official receipt: OR No. '.$payment->or_number.')')
@section('content')
    <table>
        <tr><td>Student</td><td>{{ $payment->enrollment->student->full_name }} ({{ $payment->enrollment->student->student_no }})</td></tr>
        <tr><td>Grade & Section</td><td>{{ $payment->enrollment->grade_level }} {{ $payment->enrollment->section }}</td></tr>
        <tr><td>School Year</td><td>{{ $payment->enrollment->schoolYear->name }}</td></tr>
        <tr><td>Payment Date</td><td>{{ $payment->payment_date->format('F d, Y') }}</td></tr>
        <tr><td>Method</td><td>{{ strtoupper($payment->method) }}</td></tr>
        <tr><td>Received By</td><td>{{ $payment->receivedBy->name }}</td></tr>
    </table>
    <table>
        <thead><tr><th>Applied To</th><th class="right">Amount</th></tr></thead>
        @foreach ($payment->allocations as $allocation)
            <tr><td>{{ $allocation->ledgerEntry->description }}</td><td class="right">₱{{ number_format($allocation->amount, 2) }}</td></tr>
        @endforeach
        @if ($payment->unallocatedAmount() > 0.005)
            <tr><td>Advance / Credit</td><td class="right">₱{{ number_format($payment->unallocatedAmount(), 2) }}</td></tr>
        @endif
        <tr class="total"><td>Total {{ $payment->isVoided() ? '(VOIDED)' : '' }}</td><td class="right">₱{{ number_format($payment->amount, 2) }}</td></tr>
    </table>
    <p>Remaining balance: <strong>₱{{ number_format($payment->enrollment->balance(), 2) }}</strong></p>
@endsection
```

Finally, in `resources/views/livewire/student-search.blade.php`, replace the `Route::has('ledger.show')` guard block with a plain link:

```blade
<a href="{{ route('ledger.show', $e) }}" class="text-blue-600 underline">Open</a>
```

- [ ] **Step 4: Run to verify pass** — `php artisan test` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: student ledger page, payment form with overpay confirm, acknowledgment slip"`

---

### Task 11: Admin screens — students, registration, fee types, fee structures, school years

**Files:**
- Create: `app/Http/Controllers/StudentController.php`, `app/Http/Controllers/FeeTypeController.php`, `app/Http/Controllers/FeeStructureController.php`, `app/Http/Controllers/SchoolYearController.php`
- Create: views `resources/views/students/index.blade.php`, `students/form.blade.php`, `fees/types.blade.php`, `fees/structures.blade.php`, `fees/structure-form.blade.php`, `school-years/index.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/navigation.blade.php`
- Test: `tests/Feature/AdminScreensTest.php`

**Interfaces:**
- Consumes: `RegistrationService::register()`, models from Tasks 2–5.
- Produces: resource-ish routes — `students.index/create/store/edit/update` + `students.register` (POST, uses RegistrationService); `fee-types.index/store`; `fee-structures.index/create/store/edit/update`; `school-years.index/store/activate`. Fee and school-year routes are `role:admin`; student routes are any authenticated user.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AdminScreensTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_create_student_and_register_to_active_year(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 1']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 20000]);

        $this->actingAs($cashier)->post(route('students.store'), [
            'student_no' => 'DGS-0001', 'first_name' => 'Ana', 'last_name' => 'Lim',
            'guardian_name' => 'Ben Lim', 'guardian_contact' => '09171234567',
        ])->assertRedirect();

        $student = Student::where('student_no', 'DGS-0001')->firstOrFail();

        $this->actingAs($cashier)->post(route('students.register', $student), [
            'grade_level' => 'Grade 1', 'section' => 'St. Mark',
        ])->assertRedirect();

        $enrollment = Enrollment::where('student_id', $student->id)->firstOrFail();
        $this->assertEqualsWithDelta(20000.00, $enrollment->balance(), 0.001);
    }

    public function test_fee_structure_management_is_admin_only(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);
        SchoolYear::factory()->create(['is_active' => true]);

        $this->actingAs($cashier)->get(route('fee-structures.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('fee-structures.index'))->assertOk();
    }

    public function test_admin_can_create_fee_structure_with_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $tuition = FeeType::create(['name' => 'Tuition Fee']);
        $books = FeeType::create(['name' => 'Books']);

        $this->actingAs($admin)->post(route('fee-structures.store'), [
            'school_year_id' => $year->id,
            'grade_level' => 'Grade 2',
            'items' => [
                ['fee_type_id' => $tuition->id, 'amount' => 22000],
                ['fee_type_id' => $books->id, 'amount' => 3000],
            ],
        ])->assertRedirect();

        $structure = FeeStructure::where('grade_level', 'Grade 2')->firstOrFail();
        $this->assertEqualsWithDelta(25000.00, $structure->total(), 0.001);
    }

    public function test_admin_can_activate_school_year(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $old = SchoolYear::factory()->create(['is_active' => true]);
        $new = SchoolYear::factory()->create();

        $this->actingAs($admin)->post(route('school-years.activate', $new))->assertRedirect();

        $this->assertTrue($new->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=AdminScreensTest` → FAIL.

- [ ] **Step 3: Implement**

Routes (in `routes/web.php`, inside the `auth` group):

```php
use App\Http\Controllers\FeeStructureController;
use App\Http\Controllers\FeeTypeController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\StudentController;

Route::resource('students', StudentController::class)->only(['index', 'create', 'store', 'edit', 'update']);
Route::post('students/{student}/register', [StudentController::class, 'register'])->name('students.register');

Route::middleware('role:admin')->group(function () {
    Route::get('fee-types', [FeeTypeController::class, 'index'])->name('fee-types.index');
    Route::post('fee-types', [FeeTypeController::class, 'store'])->name('fee-types.store');
    Route::resource('fee-structures', FeeStructureController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::get('school-years', [SchoolYearController::class, 'index'])->name('school-years.index');
    Route::post('school-years', [SchoolYearController::class, 'store'])->name('school-years.store');
    Route::post('school-years/{schoolYear}/activate', [SchoolYearController::class, 'activate'])->name('school-years.activate');
});
```

`app/Http/Controllers/StudentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Services\RegistrationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index()
    {
        return view('students.index', [
            'students' => Student::with('enrollments')
                ->orderBy('last_name')->orderBy('first_name')->paginate(30),
            'activeYear' => SchoolYear::active(),
        ]);
    }

    public function create()
    {
        return view('students.form', ['student' => new Student()]);
    }

    public function store(Request $request)
    {
        $student = Student::create($this->validated($request));

        return redirect()->route('students.index')->with('status', "Student {$student->full_name} added.");
    }

    public function edit(Student $student)
    {
        return view('students.form', compact('student'));
    }

    public function update(Request $request, Student $student)
    {
        $student->update($this->validated($request, $student));

        return redirect()->route('students.index')->with('status', 'Student updated.');
    }

    public function register(Request $request, Student $student, RegistrationService $service)
    {
        $data = $request->validate([
            'grade_level' => ['required', 'string', 'max:30'],
            'section' => ['nullable', 'string', 'max:50'],
        ]);

        $year = SchoolYear::active();
        abort_unless($year, 422, 'No active school year.');

        try {
            $enrollment = $service->register($student, $year, $data['grade_level'], $data['section'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['grade_level' => $e->getMessage()]);
        }

        return redirect()->route('ledger.show', $enrollment)->with('status', 'Student registered and assessed.');
    }

    private function validated(Request $request, ?Student $student = null): array
    {
        return $request->validate([
            'student_no' => ['required', 'string', 'max:30', Rule::unique('students')->ignore($student)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'guardian_name' => ['required', 'string', 'max:150'],
            'guardian_contact' => ['required', 'string', 'max:30'],
            'status' => ['sometimes', 'in:enrolled,withdrawn'],
        ]);
    }
}
```

`app/Http/Controllers/FeeTypeController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\FeeType;
use Illuminate\Http\Request;

class FeeTypeController extends Controller
{
    public function index()
    {
        return view('fees.types', ['feeTypes' => FeeType::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:fee_types']]);
        FeeType::create($data);

        return back()->with('status', 'Fee type added.');
    }
}
```

`app/Http/Controllers/FeeStructureController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FeeStructureController extends Controller
{
    public function index()
    {
        return view('fees.structures', [
            'structures' => FeeStructure::with(['schoolYear', 'items.feeType'])
                ->orderByDesc('school_year_id')->orderBy('grade_level')->get(),
        ]);
    }

    public function create()
    {
        return $this->form(new FeeStructure());
    }

    public function edit(FeeStructure $feeStructure)
    {
        return $this->form($feeStructure->load('items'));
    }

    private function form(FeeStructure $structure)
    {
        return view('fees.structure-form', [
            'structure' => $structure,
            'years' => SchoolYear::orderByDesc('name')->get(),
            'feeTypes' => FeeType::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $structure = FeeStructure::create([
                'school_year_id' => $data['school_year_id'],
                'grade_level' => $data['grade_level'],
            ]);
            foreach ($data['items'] as $item) {
                $structure->items()->create($item);
            }
        });

        return redirect()->route('fee-structures.index')->with('status', 'Fee structure created.');
    }

    public function update(Request $request, FeeStructure $feeStructure)
    {
        $data = $this->validated($request, $feeStructure);

        DB::transaction(function () use ($feeStructure, $data) {
            $feeStructure->update([
                'school_year_id' => $data['school_year_id'],
                'grade_level' => $data['grade_level'],
            ]);
            $feeStructure->items()->delete(); // safe: ledgers hold copies, never references
            foreach ($data['items'] as $item) {
                $feeStructure->items()->create($item);
            }
        });

        return redirect()->route('fee-structures.index')
            ->with('status', 'Fee structure updated. Existing student ledgers are unchanged.');
    }

    private function validated(Request $request, ?FeeStructure $existing = null): array
    {
        return $request->validate([
            'school_year_id' => ['required', 'exists:school_years,id'],
            'grade_level' => ['required', 'string', 'max:30',
                Rule::unique('fee_structures')
                    ->where('school_year_id', $request->input('school_year_id'))
                    ->ignore($existing)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_type_id' => ['required', 'exists:fee_types,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
```

`app/Http/Controllers/SchoolYearController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\SchoolYear;
use Illuminate\Http\Request;

class SchoolYearController extends Controller
{
    public function index()
    {
        return view('school-years.index', ['years' => SchoolYear::orderByDesc('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:20', 'unique:school_years']]);
        SchoolYear::create($data);

        return back()->with('status', 'School year added.');
    }

    public function activate(SchoolYear $schoolYear)
    {
        $schoolYear->activate();

        return back()->with('status', "{$schoolYear->name} is now the active school year.");
    }
}
```

Views — complete but minimal (all wrapped in `<x-app-layout>` with a `header` slot and a `max-w-5xl mx-auto py-6 px-4` container; `status` flash shown as a green box as in `ledger/show.blade.php`):

`resources/views/students/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Students</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4 space-y-4">
        @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
        <a href="{{ route('students.create') }}" class="bg-blue-600 text-white rounded px-4 py-2 inline-block">Add Student</a>
        <div class="bg-white shadow rounded p-6">
            <table class="w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">Student No.</th><th>Name</th><th>Guardian</th><th>Status</th><th></th></tr></thead>
                @foreach ($students as $student)
                    @php $enrollment = $activeYear ? $student->enrollments->firstWhere('school_year_id', $activeYear->id) : null; @endphp
                    <tr class="border-b">
                        <td class="py-2">{{ $student->student_no }}</td>
                        <td>{{ $student->full_name }}</td>
                        <td>{{ $student->guardian_name }} ({{ $student->guardian_contact }})</td>
                        <td>{{ $student->status }}</td>
                        <td class="text-right space-x-2">
                            <a href="{{ route('students.edit', $student) }}" class="text-blue-600 underline">Edit</a>
                            @if ($activeYear && ! $enrollment)
                                <form method="POST" action="{{ route('students.register', $student) }}" class="inline-flex gap-1">
                                    @csrf
                                    <input type="text" name="grade_level" placeholder="Grade 1" class="border rounded px-2 py-1 w-24 text-xs" required>
                                    <input type="text" name="section" placeholder="Section" class="border rounded px-2 py-1 w-24 text-xs">
                                    <button class="text-green-700 underline">Register {{ $activeYear->name }}</button>
                                </form>
                            @elseif ($enrollment)
                                <a href="{{ route('ledger.show', $enrollment) }}" class="text-green-700 underline">Ledger</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            {{ $students->links() }}
        </div>
    </div>
</x-app-layout>
```

`resources/views/students/form.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">{{ $student->exists ? 'Edit' : 'Add' }} Student</h2></x-slot>
    <div class="py-6 max-w-xl mx-auto px-4 bg-white shadow rounded p-6">
        <form method="POST" action="{{ $student->exists ? route('students.update', $student) : route('students.store') }}" class="space-y-3">
            @csrf
            @if ($student->exists) @method('PUT') @endif
            @foreach (['student_no' => 'Student No.', 'first_name' => 'First Name', 'last_name' => 'Last Name',
                       'middle_name' => 'Middle Name (optional)', 'guardian_name' => 'Guardian Name',
                       'guardian_contact' => 'Guardian Contact'] as $field => $label)
                <div>
                    <label class="block text-sm">{{ $label }}</label>
                    <input type="text" name="{{ $field }}" value="{{ old($field, $student->$field) }}"
                           class="border rounded px-3 py-2 w-full" @if (! str_contains($field, 'middle')) required @endif>
                    @error($field) <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
                </div>
            @endforeach
            @if ($student->exists)
                <div>
                    <label class="block text-sm">Status</label>
                    <select name="status" class="border rounded px-3 py-2 w-full">
                        <option value="enrolled" @selected($student->status === 'enrolled')>Enrolled</option>
                        <option value="withdrawn" @selected($student->status === 'withdrawn')>Withdrawn</option>
                    </select>
                </div>
            @endif
            <button class="bg-blue-600 text-white rounded px-4 py-2">Save</button>
        </form>
    </div>
</x-app-layout>
```

`resources/views/fees/types.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Fee Types</h2></x-slot>
    <div class="py-6 max-w-xl mx-auto px-4 space-y-4">
        @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
        <div class="bg-white shadow rounded p-6">
            <form method="POST" action="{{ route('fee-types.store') }}" class="flex gap-2 mb-4">
                @csrf
                <input type="text" name="name" placeholder="e.g. Uniform" class="border rounded px-3 py-2 flex-1" required>
                <button class="bg-blue-600 text-white rounded px-4 py-2">Add</button>
            </form>
            @error('name') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            <ul class="text-sm divide-y">
                @foreach ($feeTypes as $type) <li class="py-2">{{ $type->name }}</li> @endforeach
            </ul>
        </div>
    </div>
</x-app-layout>
```

`resources/views/fees/structures.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Fee Structures</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4 space-y-4">
        @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
        <a href="{{ route('fee-structures.create') }}" class="bg-blue-600 text-white rounded px-4 py-2 inline-block">New Fee Structure</a>
        @foreach ($structures as $structure)
            <div class="bg-white shadow rounded p-6">
                <div class="flex justify-between">
                    <h3 class="font-semibold">{{ $structure->grade_level }} — {{ $structure->schoolYear->name }}</h3>
                    <a href="{{ route('fee-structures.edit', $structure) }}" class="text-blue-600 underline text-sm">Edit</a>
                </div>
                <table class="w-full text-sm mt-2">
                    @foreach ($structure->items as $item)
                        <tr class="border-b"><td class="py-1">{{ $item->feeType->name }}</td>
                            <td class="text-right">₱{{ number_format($item->amount, 2) }}</td></tr>
                    @endforeach
                    <tr class="font-semibold"><td class="py-1">Total</td>
                        <td class="text-right">₱{{ number_format($structure->total(), 2) }}</td></tr>
                </table>
            </div>
        @endforeach
    </div>
</x-app-layout>
```

`resources/views/fees/structure-form.blade.php` (plain HTML rows + a little vanilla JS to add rows — no Livewire needed):

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">{{ $structure->exists ? 'Edit' : 'New' }} Fee Structure</h2></x-slot>
    <div class="py-6 max-w-xl mx-auto px-4 bg-white shadow rounded p-6">
        @if ($errors->any()) <div class="bg-red-100 p-3 rounded mb-3">{{ $errors->first() }}</div> @endif
        <form method="POST"
              action="{{ $structure->exists ? route('fee-structures.update', $structure) : route('fee-structures.store') }}"
              class="space-y-3">
            @csrf
            @if ($structure->exists) @method('PUT') @endif
            <div>
                <label class="block text-sm">School Year</label>
                <select name="school_year_id" class="border rounded px-3 py-2 w-full" required>
                    @foreach ($years as $year)
                        <option value="{{ $year->id }}" @selected(old('school_year_id', $structure->school_year_id) == $year->id)>
                            {{ $year->name }}{{ $year->is_active ? ' (active)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm">Grade Level</label>
                <input type="text" name="grade_level" value="{{ old('grade_level', $structure->grade_level) }}"
                       placeholder="e.g. Grade 3" class="border rounded px-3 py-2 w-full" required>
            </div>
            <div id="items" class="space-y-2">
                <label class="block text-sm">Fees</label>
                @foreach (old('items', $structure->items->map(fn ($i) => ['fee_type_id' => $i->fee_type_id, 'amount' => $i->amount])->all() ?: [['fee_type_id' => '', 'amount' => '']]) as $index => $item)
                    <div class="flex gap-2 item-row">
                        <select name="items[{{ $index }}][fee_type_id]" class="border rounded px-3 py-2 flex-1" required>
                            @foreach ($feeTypes as $type)
                                <option value="{{ $type->id }}" @selected(($item['fee_type_id'] ?? '') == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        <input type="number" step="0.01" name="items[{{ $index }}][amount]"
                               value="{{ $item['amount'] ?? '' }}" placeholder="Amount" class="border rounded px-3 py-2 w-32" required>
                    </div>
                @endforeach
            </div>
            <button type="button" onclick="addRow()" class="text-blue-600 underline text-sm">+ Add fee line</button>
            <div><button class="bg-blue-600 text-white rounded px-4 py-2">Save Structure</button></div>
        </form>
    </div>
    <script>
        function addRow() {
            const container = document.getElementById('items');
            const rows = container.querySelectorAll('.item-row');
            const clone = rows[rows.length - 1].cloneNode(true);
            const nextIndex = rows.length;
            clone.querySelectorAll('select, input').forEach(el => {
                el.name = el.name.replace(/\d+/, nextIndex);
                if (el.tagName === 'INPUT') el.value = '';
            });
            container.appendChild(clone);
        }
    </script>
</x-app-layout>
```

`resources/views/school-years/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">School Years</h2></x-slot>
    <div class="py-6 max-w-xl mx-auto px-4 space-y-4">
        @if (session('status')) <div class="bg-green-100 p-3 rounded">{{ session('status') }}</div> @endif
        <div class="bg-white shadow rounded p-6">
            <form method="POST" action="{{ route('school-years.store') }}" class="flex gap-2 mb-4">
                @csrf
                <input type="text" name="name" placeholder="e.g. 2026-2027" class="border rounded px-3 py-2 flex-1" required>
                <button class="bg-blue-600 text-white rounded px-4 py-2">Add</button>
            </form>
            <table class="w-full text-sm">
                @foreach ($years as $year)
                    <tr class="border-b">
                        <td class="py-2">{{ $year->name }}</td>
                        <td class="text-right">
                            @if ($year->is_active)
                                <span class="text-green-700 font-semibold">ACTIVE</span>
                            @else
                                <form method="POST" action="{{ route('school-years.activate', $year) }}" class="inline">
                                    @csrf <button class="text-blue-600 underline">Make active</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
</x-app-layout>
```

Navigation — add alongside the Task 9 link (and mirror in the responsive menu):

```blade
<x-nav-link :href="route('students.index')" :active="request()->routeIs('students.*')">Students</x-nav-link>
@if (auth()->user()->isAdmin())
    <x-nav-link :href="route('fee-structures.index')" :active="request()->routeIs('fee-structures.*')">Fees</x-nav-link>
    <x-nav-link :href="route('school-years.index')" :active="request()->routeIs('school-years.*')">School Years</x-nav-link>
@endif
```

- [ ] **Step 4: Run to verify pass** — `php artisan test` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: admin screens for students, registration, fees, and school years"`

---

### Task 12: Reports — daily collections, unpaid balances, notices, statement, CSV export

**Files:**
- Create: `app/Http/Controllers/ReportController.php`
- Create: views `resources/views/reports/index.blade.php`, `reports/daily.blade.php`, `reports/unpaid.blade.php`, `print/notice.blade.php`, `print/statement.blade.php`
- Modify: `routes/web.php` (replace the two Task 10 placeholder routes; add report routes), navigation
- Test: `tests/Feature/ReportsTest.php`

**Interfaces:**
- Consumes: everything from Tasks 2–8.
- Produces: routes `reports.index` (GET `/reports`), `reports.daily` (GET `/reports/daily?date=Y-m-d&csv=1`), `reports.unpaid` (GET `/reports/unpaid?grade_level=&csv=1`), `reports.notice` (GET `/reports/notice/{enrollment}`), `reports.notices.batch` (GET `/reports/notices?grade_level=`), `reports.statement` (GET `/reports/statement/{enrollment}`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ReportsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $year;
    private User $cashier;
    private $paidEnrollment;
    private $unpaidEnrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = SchoolYear::factory()->create(['is_active' => true]);
        $s = FeeStructure::create(['school_year_id' => $this->year->id, 'grade_level' => 'Grade 3']);
        $s->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);
        $this->cashier = User::factory()->create(['role' => 'cashier']);

        $reg = app(RegistrationService::class);
        $this->paidEnrollment = $reg->register(
            Student::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']), $this->year, 'Grade 3');
        $this->unpaidEnrollment = $reg->register(
            Student::factory()->create(['first_name' => 'Jose', 'last_name' => 'Reyes']), $this->year, 'Grade 3');

        app(PaymentService::class)->record($this->paidEnrollment, 'OR-1', now()->toDateString(), 10000, 'cash', $this->cashier);
    }

    public function test_daily_report_totals_todays_collections_and_shows_voids(): void
    {
        $voided = app(PaymentService::class)->record($this->unpaidEnrollment, 'OR-2', now()->toDateString(), 500, 'cash', $this->cashier);
        app(PaymentService::class)->void($voided, $this->cashier, 'Test void');

        $this->actingAs($this->cashier)
            ->get(route('reports.daily', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('OR-1')
            ->assertSee('OR-2')      // voided still listed
            ->assertSee('VOIDED')
            ->assertSee('10,000.00'); // total excludes the voided 500
    }

    public function test_daily_report_exports_csv(): void
    {
        $response = $this->actingAs($this->cashier)
            ->get(route('reports.daily', ['date' => now()->toDateString(), 'csv' => 1]));

        $response->assertOk()->assertHeader('content-disposition');
        $this->assertStringContainsString('OR-1', $response->streamedContent());
    }

    public function test_unpaid_report_lists_only_students_with_balance(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('reports.unpaid'))
            ->assertOk()
            ->assertSee('Reyes, Jose')
            ->assertDontSee('Santos, Maria');
    }

    public function test_notice_and_statement_render(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('reports.notice', $this->unpaidEnrollment))
            ->assertOk()->assertSee('Reyes, Jose')->assertSee('10,000.00');

        $this->actingAs($this->cashier)
            ->get(route('reports.statement', $this->paidEnrollment))
            ->assertOk()->assertSee('Santos, Maria')->assertSee('OR-1');
    }

    public function test_batch_notices_render_one_page_per_unpaid_student(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('reports.notices.batch', ['grade_level' => 'Grade 3']))
            ->assertOk()
            ->assertSee('Reyes, Jose')
            ->assertDontSee('Santos, Maria');
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=ReportsTest` → FAIL.

- [ ] **Step 3: Implement**

Routes — **delete the two placeholder routes from Task 10**, then add (inside `auth` group):

```php
use App\Http\Controllers\ReportController;

Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/daily', [ReportController::class, 'daily'])->name('daily');
    Route::get('/unpaid', [ReportController::class, 'unpaid'])->name('unpaid');
    Route::get('/notices', [ReportController::class, 'batchNotices'])->name('notices.batch');
    Route::get('/notice/{enrollment}', [ReportController::class, 'notice'])->name('notice');
    Route::get('/statement/{enrollment}', [ReportController::class, 'statement'])->name('statement');
});
```

`app/Http/Controllers/ReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SchoolYear;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index', ['activeYear' => SchoolYear::active()]);
    }

    public function daily(Request $request)
    {
        $date = $request->date('date') ?? today();
        $payments = Payment::with(['enrollment.student', 'receivedBy'])
            ->whereDate('payment_date', $date)
            ->orderBy('or_number')->get();

        $activeTotal = $payments->whereNull('voided_at');
        $totals = [
            'grand' => $activeTotal->sum(fn ($p) => (float) $p->amount),
            'byMethod' => $activeTotal->groupBy('method')->map(fn ($g) => $g->sum(fn ($p) => (float) $p->amount)),
        ];

        if ($request->boolean('csv')) {
            return $this->csv("daily-collections-{$date->toDateString()}.csv",
                ['OR No.', 'Student', 'Method', 'Amount', 'Status', 'Cashier'],
                $payments->map(fn ($p) => [
                    $p->or_number,
                    $p->enrollment->student->full_name,
                    strtoupper($p->method),
                    $p->amount,
                    $p->voided_at ? 'VOIDED: '.$p->void_reason : 'OK',
                    $p->receivedBy->name,
                ]));
        }

        return view('reports.daily', compact('payments', 'totals', 'date'));
    }

    public function unpaid(Request $request)
    {
        $year = SchoolYear::active();
        abort_unless($year, 404, 'No active school year.');

        $rows = $this->unpaidEnrollments($year, $request->input('grade_level'));

        if ($request->boolean('csv')) {
            return $this->csv('unpaid-balances.csv',
                ['Student No.', 'Student', 'Grade', 'Section', 'Assessed', 'Paid', 'Balance', 'Promissory (pending)'],
                $rows->map(fn ($e) => [
                    $e->student->student_no, $e->student->full_name, $e->grade_level, $e->section,
                    number_format($e->assessed_total, 2, '.', ''), number_format($e->paid_total, 2, '.', ''),
                    number_format($e->balance_amount, 2, '.', ''), number_format($e->promised_total, 2, '.', ''),
                ]));
        }

        $gradeLevels = Enrollment::where('school_year_id', $year->id)
            ->distinct()->orderBy('grade_level')->pluck('grade_level');

        return view('reports.unpaid', compact('rows', 'year', 'gradeLevels'));
    }

    public function notice(Enrollment $enrollment)
    {
        return view('print.notice', ['enrollments' => collect([$this->loadForNotice($enrollment)])]);
    }

    public function batchNotices(Request $request)
    {
        $year = SchoolYear::active();
        abort_unless($year, 404);

        $enrollments = $this->unpaidEnrollments($year, $request->input('grade_level'))
            ->map(fn ($e) => $this->loadForNotice($e));

        return view('print.notice', compact('enrollments'));
    }

    public function statement(Enrollment $enrollment)
    {
        $enrollment->load(['student', 'schoolYear', 'ledgerEntries.feeType',
            'payments' => fn ($q) => $q->orderBy('payment_date')->orderBy('id')]);

        return view('print.statement', compact('enrollment'));
    }

    private function unpaidEnrollments(SchoolYear $year, ?string $gradeLevel)
    {
        $q = Enrollment::with('student')
            ->where('school_year_id', $year->id)
            ->withSum(['ledgerEntries as assessed_total' => fn ($s) => $s->whereNull('voided_at')], 'amount')
            ->withSum(['payments as paid_total' => fn ($s) => $s->whereNull('voided_at')], 'amount')
            ->withSum(['promissoryNotes as promised_total' => fn ($s) => $s->where('status', 'pending')], 'amount');

        if ($gradeLevel) {
            $q->where('grade_level', $gradeLevel);
        }

        return $q->orderBy('grade_level')->get()
            ->each(function ($e) {
                $e->assessed_total = (float) $e->assessed_total;
                $e->paid_total = (float) $e->paid_total;
                $e->promised_total = (float) $e->promised_total;
                $e->balance_amount = round($e->assessed_total - $e->paid_total, 2);
            })
            ->filter(fn ($e) => $e->balance_amount > 0.005)
            ->sortBy([['grade_level', 'asc'], fn ($a, $b) => strcmp($a->student->full_name, $b->student->full_name)])
            ->values();
    }

    private function loadForNotice(Enrollment $enrollment): Enrollment
    {
        return $enrollment->load(['student', 'schoolYear', 'ledgerEntries.feeType']);
    }

    private function csv(string $filename, array $header, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, is_array($row) ? $row : $row->toArray());
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
```

`resources/views/reports/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Reports — {{ $activeYear?->name }}</h2></x-slot>
    <div class="py-6 max-w-3xl mx-auto px-4 space-y-4">
        <div class="bg-white shadow rounded p-6 space-y-2">
            <p><a class="text-blue-600 underline" href="{{ route('reports.daily') }}">Daily Collection Report</a> — today's payments; pick another date on the page.</p>
            <p><a class="text-blue-600 underline" href="{{ route('reports.unpaid') }}">Unpaid Balances</a> — who still owes, per grade; batch-print notices from there.</p>
        </div>
    </div>
</x-app-layout>
```

`resources/views/reports/daily.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Daily Collections — {{ $date->format('F d, Y') }}</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4 space-y-4">
        <form method="GET" class="flex gap-2 items-center">
            <input type="date" name="date" value="{{ $date->toDateString() }}" class="border rounded px-3 py-2">
            <button class="bg-blue-600 text-white rounded px-4 py-2">View</button>
            <a href="{{ route('reports.daily', ['date' => $date->toDateString(), 'csv' => 1]) }}"
               class="text-blue-600 underline">Download CSV</a>
            <button type="button" onclick="window.print()" class="text-blue-600 underline">Print</button>
        </form>
        <div class="bg-white shadow rounded p-6">
            <table class="w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">OR No.</th><th>Student</th><th>Method</th><th>Cashier</th><th class="text-right">Amount</th></tr></thead>
                @forelse ($payments as $p)
                    <tr class="border-b {{ $p->voided_at ? 'line-through text-gray-400' : '' }}">
                        <td class="py-1">{{ $p->or_number }} {{ $p->voided_at ? '(VOIDED)' : '' }}</td>
                        <td>{{ $p->enrollment->student->full_name }}</td>
                        <td>{{ strtoupper($p->method) }}</td>
                        <td>{{ $p->receivedBy->name }}</td>
                        <td class="text-right">₱{{ number_format($p->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-3 text-gray-500">No payments on this date.</td></tr>
                @endforelse
                @foreach ($totals['byMethod'] as $method => $amount)
                    <tr><td colspan="4" class="text-right py-1">Total ({{ strtoupper($method) }})</td>
                        <td class="text-right">₱{{ number_format($amount, 2) }}</td></tr>
                @endforeach
                <tr class="font-bold"><td colspan="4" class="text-right py-2">GRAND TOTAL</td>
                    <td class="text-right">₱{{ number_format($totals['grand'], 2) }}</td></tr>
            </table>
        </div>
    </div>
</x-app-layout>
```

`resources/views/reports/unpaid.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Unpaid Balances — {{ $year->name }}</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4 space-y-4">
        <form method="GET" class="flex gap-2 items-center">
            <select name="grade_level" class="border rounded px-3 py-2">
                <option value="">All grades</option>
                @foreach ($gradeLevels as $g)
                    <option value="{{ $g }}" @selected(request('grade_level') === $g)>{{ $g }}</option>
                @endforeach
            </select>
            <button class="bg-blue-600 text-white rounded px-4 py-2">Filter</button>
            <a href="{{ route('reports.unpaid', array_filter(['grade_level' => request('grade_level'), 'csv' => 1])) }}"
               class="text-blue-600 underline">Download CSV</a>
            <a href="{{ route('reports.notices.batch', array_filter(['grade_level' => request('grade_level')])) }}"
               target="_blank" class="text-blue-600 underline">Print Notices (batch)</a>
        </form>
        <div class="bg-white shadow rounded p-6">
            <table class="w-full text-sm">
                <thead><tr class="text-left border-b">
                    <th class="py-2">Student</th><th>Grade & Section</th>
                    <th class="text-right">Assessed</th><th class="text-right">Paid</th>
                    <th class="text-right">Balance</th><th class="text-right">Promissory</th><th></th>
                </tr></thead>
                @forelse ($rows as $e)
                    <tr class="border-b">
                        <td class="py-1">{{ $e->student->full_name }}</td>
                        <td>{{ $e->grade_level }} {{ $e->section }}</td>
                        <td class="text-right">₱{{ number_format($e->assessed_total, 2) }}</td>
                        <td class="text-right">₱{{ number_format($e->paid_total, 2) }}</td>
                        <td class="text-right text-red-600">₱{{ number_format($e->balance_amount, 2) }}</td>
                        <td class="text-right text-amber-600">{{ $e->promised_total > 0 ? '₱'.number_format($e->promised_total, 2) : '—' }}</td>
                        <td class="text-right"><a class="text-blue-600 underline" href="{{ route('ledger.show', $e) }}">Ledger</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-3 text-gray-500">Everyone is fully paid. 🎉</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</x-app-layout>
```

`resources/views/print/notice.blade.php` (one page per enrollment; used for both single and batch):

```blade
@extends('print.layout')
@section('title', 'Statement of Unpaid Fees')
@section('subtitle', 'Notice of Outstanding Balance')
@section('content')
    @foreach ($enrollments as $enrollment)
        <div @if (! $loop->last) class="page-break" @endif>
            <p>Date: {{ now()->format('F d, Y') }}</p>
            <p>Dear Parent/Guardian of <strong>{{ $enrollment->student->full_name }}</strong>
               ({{ $enrollment->grade_level }} {{ $enrollment->section }}, SY {{ $enrollment->schoolYear->name }}),</p>
            <p>Our records show the following outstanding fees. Kindly settle at the Cashier's Office
               or coordinate with us for a payment arrangement.</p>
            <table>
                <thead><tr><th>Fee</th><th class="right">Assessed</th></tr></thead>
                @foreach ($enrollment->ledgerEntries->whereNull('voided_at') as $entry)
                    <tr><td>{{ $entry->description }}</td><td class="right">₱{{ number_format($entry->amount, 2) }}</td></tr>
                @endforeach
                <tr><td class="total">Total Assessed</td><td class="right total">₱{{ number_format($enrollment->totalAssessed(), 2) }}</td></tr>
                <tr><td>Less: Payments</td><td class="right">₱{{ number_format($enrollment->totalPaid(), 2) }}</td></tr>
                <tr class="total"><td>BALANCE DUE</td><td class="right">₱{{ number_format($enrollment->balance(), 2) }}</td></tr>
            </table>
            <p>Please disregard this notice if payment has been made recently. Thank you.</p>
            <p style="margin-top:2rem;">_______________________<br>Cashier / Finance Office</p>
        </div>
    @endforeach
@endsection
```

`resources/views/print/statement.blade.php`:

```blade
@extends('print.layout')
@section('title', 'Statement of Account — '.$enrollment->student->full_name)
@section('subtitle', 'Statement of Account — SY '.$enrollment->schoolYear->name)
@section('content')
    <p><strong>{{ $enrollment->student->full_name }}</strong> ({{ $enrollment->student->student_no }})
       — {{ $enrollment->grade_level }} {{ $enrollment->section }}</p>
    <table>
        <thead><tr><th>Assessment</th><th class="right">Amount</th></tr></thead>
        @foreach ($enrollment->ledgerEntries as $entry)
            <tr @if ($entry->voided_at) class="void" @endif>
                <td>{{ $entry->description }}</td><td class="right">₱{{ number_format($entry->amount, 2) }}</td></tr>
        @endforeach
        <tr class="total"><td>Total Assessed</td><td class="right">₱{{ number_format($enrollment->totalAssessed(), 2) }}</td></tr>
    </table>
    <table>
        <thead><tr><th>OR No.</th><th>Date</th><th>Method</th><th class="right">Amount</th></tr></thead>
        @forelse ($enrollment->payments as $payment)
            <tr @if ($payment->isVoided()) class="void" @endif>
                <td>{{ $payment->or_number }}{{ $payment->isVoided() ? ' (VOIDED)' : '' }}</td>
                <td>{{ $payment->payment_date->format('M d, Y') }}</td>
                <td>{{ strtoupper($payment->method) }}</td>
                <td class="right">₱{{ number_format($payment->amount, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No payments recorded.</td></tr>
        @endforelse
        <tr class="total"><td colspan="3">Total Paid</td><td class="right">₱{{ number_format($enrollment->totalPaid(), 2) }}</td></tr>
        <tr class="total"><td colspan="3">BALANCE</td><td class="right">₱{{ number_format($enrollment->balance(), 2) }}</td></tr>
    </table>
@endsection
```

Navigation — add:

```blade
<x-nav-link :href="route('reports.index')" :active="request()->routeIs('reports.*')">Reports</x-nav-link>
```

- [ ] **Step 4: Run to verify pass** — `php artisan test` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: daily collections, unpaid balances, printable notices and statements, CSV export"`

---

### Task 13: Seeders and demo data

**Files:**
- Create: `database/seeders/FeeTypeSeeder.php`, `database/seeders/DemoDataSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/SeederTest.php`

**Interfaces:**
- Produces: `php artisan db:seed` → admin user + base fee types (always); `php artisan db:seed --class=DemoDataSeeder` → active school year, fee structures for Grade 1–12, ~60 students registered with a mix of fully paid / partial / unpaid / promissory. Never run DemoDataSeeder in production.

- [ ] **Step 1: Write the failing test**

`tests/Feature/SeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_a_realistic_dataset(): void
    {
        $this->seed(\Database\Seeders\DemoDataSeeder::class);

        $this->assertNotNull(SchoolYear::active());
        $this->assertGreaterThanOrEqual(50, Enrollment::count());

        $enrollments = Enrollment::all();
        $this->assertTrue($enrollments->contains(fn ($e) => $e->balance() <= 0.005), 'some fully paid');
        $this->assertTrue($enrollments->contains(fn ($e) => $e->balance() > 0.005 && $e->totalPaid() > 0), 'some partial');
        $this->assertTrue($enrollments->contains(fn ($e) => $e->pendingPromissoryTotal() > 0), 'some promissory');
    }
}
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=SeederTest` → FAIL.

- [ ] **Step 3: Implement**

`database/seeders/FeeTypeSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\FeeType;
use Illuminate\Database\Seeder;

class FeeTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Tuition Fee', 'Registration Fee', 'Books', 'Uniform', 'Miscellaneous'] as $name) {
            FeeType::firstOrCreate(['name' => $name]);
        }
    }
}
```

`database/seeders/DemoDataSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AdminUserSeeder::class, FeeTypeSeeder::class]);

        $year = SchoolYear::firstOrCreate(['name' => '2026-2027']);
        $year->activate();

        $cashier = User::firstOrCreate(
            ['email' => 'cashier@dgsi.local'],
            ['name' => 'Demo Cashier', 'password' => bcrypt('password'), 'role' => 'cashier']);

        $tuition = FeeType::where('name', 'Tuition Fee')->first();
        $misc = FeeType::where('name', 'Miscellaneous')->first();

        foreach (range(1, 12) as $grade) {
            $structure = FeeStructure::firstOrCreate(
                ['school_year_id' => $year->id, 'grade_level' => "Grade {$grade}"]);
            if ($structure->items()->count() === 0) {
                $structure->items()->create(['fee_type_id' => $tuition->id, 'amount' => 20000 + $grade * 1000]);
                $structure->items()->create(['fee_type_id' => $misc->id, 'amount' => 3000]);
            }
        }

        $reg = app(RegistrationService::class);
        $pay = app(PaymentService::class);
        $or = 1000;

        foreach (range(1, 12) as $grade) {
            foreach (Student::factory()->count(5)->create() as $i => $student) {
                $enrollment = $reg->register($student, $year, "Grade {$grade}");
                match ($i % 4) {
                    0 => $pay->record($enrollment, 'OR-'.$or++, now()->toDateString(),
                        $enrollment->totalAssessed(), 'cash', $cashier), // fully paid
                    1 => $pay->record($enrollment, 'OR-'.$or++, now()->subDays(7)->toDateString(),
                        5000, 'gcash', $cashier), // partial
                    2 => $enrollment->promissoryNotes()->create([
                        'amount' => 4000, 'due_date' => now()->addMonth(),
                        'notes' => 'Promised after payday', 'status' => 'pending',
                    ]),
                    default => null, // unpaid
                };
            }
        }
    }
}
```

`DatabaseSeeder::run()`:

```php
$this->call([AdminUserSeeder::class, FeeTypeSeeder::class]);
```

- [ ] **Step 4: Run to verify pass** — `php artisan test` → PASS.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: base seeders and demo dataset"`

---

### Task 14: Deployment to Coolify

**Files:**
- Create: `Dockerfile`, `.dockerignore`, `docs/deploy.md`

**Interfaces:**
- Consumes: the finished app.
- Produces: a container image Coolify can build and run; `docs/deploy.md` records the exact Coolify settings so the deployment is reproducible.

No TDD here — verification is a live smoke test.

- [ ] **Step 1: Write the Dockerfile**

`Dockerfile` (uses `serversideup/php`, a production-ready PHP+nginx image):

```dockerfile
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
RUN npm ci && npm run build

FROM serversideup/php:8.3-fpm-nginx AS app
ENV PHP_OPCACHE_ENABLE=1
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
USER root
RUN composer install --no-dev --optimize-autoloader --no-interaction
USER www-data
```

`.dockerignore`:

```
.git
node_modules
vendor
.env
storage/logs/*
tests
docs
```

- [ ] **Step 2: Write `docs/deploy.md`**

```markdown
# Deploying to Coolify

1. Push this repo to a Git remote Coolify can reach (GitHub/Gitea).
2. In Coolify: **New Resource → Application → Dockerfile** build pack, pick this repo/branch.
3. Add a **MySQL 8** resource; note the internal connection details.
4. Application environment variables:
   - APP_ENV=production, APP_DEBUG=false, APP_URL=https://<your-domain>
   - APP_KEY= (run `php artisan key:generate --show` locally, paste result)
   - DB_CONNECTION=mysql, DB_HOST=<coolify mysql host>, DB_PORT=3306,
     DB_DATABASE=dgsi, DB_USERNAME=..., DB_PASSWORD=...
   - ADMIN_EMAIL / ADMIN_PASSWORD (first admin login; change the password in-app after first login)
   - SESSION_DRIVER=database, CACHE_STORE=database, QUEUE_CONNECTION=sync
5. Post-deployment command (Coolify app settings):
   `php artisan migrate --force && php artisan db:seed --force && php artisan config:cache && php artisan route:cache`
6. Attach your domain; Coolify provisions HTTPS automatically.
7. **Backups (non-negotiable):** in the Coolify MySQL resource, enable Scheduled Backups
   (daily, keep 14). Also schedule an off-VM copy (e.g. S3-compatible storage in Coolify's
   backup settings) — one copy on one VM is not a backup strategy for money records.
8. Smoke test: log in as admin, create the school year + fee structures, add one real
   student, record a test payment, void it (reason: "deployment smoke test"), check the
   daily report shows both, then check the automatic backup ran.
```

- [ ] **Step 3: Verify the image builds locally (if Docker is installed; otherwise let Coolify build it)**

Run: `docker build -t dgsi-app .`
Expected: image builds; `composer install` and `npm run build` succeed.

- [ ] **Step 4: Commit**

```powershell
git add -A && git commit -m "chore: Dockerfile and Coolify deployment guide"
```

- [ ] **Step 5: Deploy and run the smoke test in `docs/deploy.md` step 8**

---

## Post-plan checklist (not tasks — reminders for the humans)

- Ask Gelle: discounts? installment vs annual billing? current records format (Excel?)? accepted payment methods? → feed answers into fast-follow issues.
- Change the seeded admin password and demo cashier password before giving the school access. Never run `DemoDataSeeder` on the production database.
- Encode the real school year, fee structures, and students with Gelle before go-live.
