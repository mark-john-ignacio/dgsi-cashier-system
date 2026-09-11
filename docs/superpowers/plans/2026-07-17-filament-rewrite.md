# Filament v4 Rewrite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the whole cashier app into one role-gated Filament v4 panel at `/app` and delete the Breeze UI, per `docs/superpowers/specs/2026-07-17-filament-rewrite-design.md`.

**Architecture:** One panel for both roles. Filament resources own CRUD (school years, fee types, fee structures, users, students); custom Filament pages own the cashier flow (StudentLedger with Record Payment / Void / promissory actions) and reports. All money logic stays in `App\Services\PaymentService` / `RegistrationService` — the UI only calls them. Policies replace the `role:admin` middleware. Print views stay Blade behind a slim `PrintController`.

**Tech Stack:** Laravel 12, PHP 8.3, Filament ^4.0 (Livewire 3), Tailwind v4 via `@tailwindcss/vite`, PHPUnit 11 (sqlite `:memory:`), Pint, Larastan 3.

**Version strategy (decided 2026-07-17):** Filament v5 exists (v5.0.0 released 2026-01-16, current v5.6.x) but requires Livewire 4 + Tailwind 4. Tasks 1–14 build on **v4** so the Breeze/Livewire-3 UI keeps working until cutover and the plan's v4 code stays valid. **Task 15** then upgrades to v5 with the official upgrade script, after the old UI is gone. Task 15 is severable: if the upgrade misbehaves, merge on v4 and backlog it.

## Global Constraints

- Branch: `feat/filament-rewrite` (already checked out; Phase 2 spec committed).
- Before EVERY commit: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` ([OK] required, no baseline/ignores), and the task's tests must pass. Run the FULL suite (`php artisan test`) before committing tasks 8+.
- **Generator-wins rule:** scaffold Filament classes with the artisan generators (`php artisan make:filament-resource`, `make:filament-page`, `make:filament-widget`). If an import path or method signature in this plan differs from what the installed Filament v4.x generator emits (e.g. `Schema` vs `Form` types), the generator's structure wins — this plan's **fields, validation rules, behavior, visibility rules, and test assertions are binding**, exact namespaces are not.
- Money logic: only ever call `PaymentService::record()/void()` and `RegistrationService::register()`. Never reimplement allocation/void/registration logic in UI code. Floats + `decimal(12,2)` stay.
- The duplicate-OR error must surface on the OR-number field with the exact message produced by `DuplicateOrNumber` (`OR number {orNumber} is already used this school year.`).
- Roles are the strings `admin` and `cashier` on `users.role`. `User::isAdmin()` exists.
- Old Breeze UI keeps working until Task 14 (cutover). Do not delete or break old routes/tests before then; new Filament tests live alongside.
- Domain reference: enrollment balance = `Enrollment::balance()`; charges are `ledger_entries` rows with `type='charge'`; promissory statuses are `pending|fulfilled|broken`; grade levels are free strings (max 30) like `Grade 3`.

## File Structure (end state)

```
app/Filament/Resources/SchoolYears/SchoolYearResource.php        (+Pages/)
app/Filament/Resources/FeeTypes/FeeTypeResource.php              (+Pages/)
app/Filament/Resources/FeeStructures/FeeStructureResource.php    (+Pages/)
app/Filament/Resources/Users/UserResource.php                    (+Pages/)
app/Filament/Resources/Students/StudentResource.php              (+Pages/)
app/Filament/Pages/StudentLedger.php   + resources/views/filament/pages/student-ledger.blade.php
app/Filament/Pages/DailyCollections.php + resources/views/filament/pages/daily-collections.blade.php
app/Filament/Pages/UnpaidBalances.php  + resources/views/filament/pages/unpaid-balances.blade.php
app/Filament/Widgets/TodayCollectionsStats.php
app/Filament/Widgets/LatestPayments.php
app/Policies/{SchoolYearPolicy,FeeTypePolicy,FeeStructurePolicy,UserPolicy,PaymentPolicy}.php
app/Providers/Filament/AppPanelProvider.php
app/Http/Controllers/PrintController.php
tests/Feature/Filament/*.php (one file per task area)
```

---

### Task 1: Install Filament v4, panel at /app, panel access, Tailwind v4

**Files:**
- Modify: `composer.json`/`composer.lock` (via composer), `app/Models/User.php`, `package.json`, `resources/css/app.css`, `vite.config.js`
- Create: `app/Providers/Filament/AppPanelProvider.php` (via installer), `tests/Feature/Filament/PanelAccessTest.php`
- Delete: `tailwind.config.js`, `postcss.config.js` (superseded by Tailwind v4 vite plugin)

**Interfaces:**
- Produces: panel id `app` at path `/app`; `User implements FilamentUser` with `canAccessPanel(): bool` true for roles `admin|cashier`. Every later task's pages/resources live in this panel. Test helper pattern: `$this->actingAs(User::factory()->create(['role' => 'admin']))`.

- [ ] **Step 1: Install**

```bash
composer require filament/filament:"^4.0"
php artisan filament:install --panels --no-interaction
```

The installer creates a panel provider (likely `app/Providers/Filament/AdminPanelProvider.php`) and registers it in `bootstrap/providers.php`. Rename class+file to `AppPanelProvider`, set `->id('app')->path('app')->login()`, update the provider registration. Keep default middleware stack as generated.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Filament/PanelAccessTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_panel_login(): void
    {
        $this->get('/app')->assertRedirect('/app/login');
    }

    public function test_cashier_and_admin_can_access_panel(): void
    {
        foreach (['cashier', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/app')->assertOk();
        }
    }
}
```

- [ ] **Step 3: Run tests to verify current failure**

Run: `php artisan test tests/Feature/Filament/PanelAccessTest.php`
Expected: FAIL (403 for authenticated users until `canAccessPanel` exists; login redirect may already pass).

- [ ] **Step 4: Implement panel access on User**

In `app/Models/User.php`: implement `Filament\Models\Contracts\FilamentUser`; add

```php
public function canAccessPanel(\Filament\Panel $panel): bool
{
    return in_array($this->role, ['admin', 'cashier'], true);
}
```

- [ ] **Step 5: Tailwind v4 assets**

Remove `tailwindcss:^3` and `@tailwindcss/forms` from package.json devDependencies (keep `@tailwindcss/vite:^4`), add the plugin to `vite.config.js` per its README, replace `resources/css/app.css` tailwind directives with `@import "tailwindcss";`, delete `tailwind.config.js` and `postcss.config.js`. Run `npm install && npm run build` — the OLD Breeze views must still render (they use utility classes compiled by v4 the same way). If the build fails, fix within these files only; report BLOCKED if it needs more.

- [ ] **Step 6: Verify**

Run: `php artisan test` (full suite — old UI tests must still pass with rebuilt assets), then `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, then `vendor/bin/pint --test`.
Expected: all green; PanelAccessTest passes.

- [ ] **Step 7: Commit**

```bash
git add -A -- ':!.superpowers'
git commit -m "feat: install Filament v4 panel at /app with role-gated access, Tailwind v4 assets"
```

---

### Task 2: Policies replace role checks

**Files:**
- Create: `app/Policies/SchoolYearPolicy.php`, `app/Policies/FeeTypePolicy.php`, `app/Policies/FeeStructurePolicy.php`, `app/Policies/UserPolicy.php`, `app/Policies/PaymentPolicy.php`
- Test: `tests/Feature/Filament/PolicyTest.php`

**Interfaces:**
- Produces: standard Laravel auto-discovered policies. Admin-only models (`SchoolYear`, `FeeType`, `FeeStructure`, `User`): `viewAny/view/create/update` return `$user->isAdmin()`; `delete` returns `false` (no deletion in this phase). `PaymentPolicy::void(User $user, Payment $payment): bool` — admin always; cashier only when `$payment->created_at->isToday()` and payment not already voided is NOT checked here (service owns that). Resources in Tasks 3-7 rely on these via Filament's automatic policy integration.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Filament/PolicyTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\Payment;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_only_models_deny_cashier(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([SchoolYear::class, \App\Models\FeeType::class, \App\Models\FeeStructure::class, User::class] as $model) {
            $this->assertFalse($cashier->can('viewAny', $model), "$model should deny cashier");
            $this->assertTrue($admin->can('viewAny', $model), "$model should allow admin");
            $this->assertFalse($admin->can('delete', new $model), "$model delete stays closed");
        }
    }

    public function test_void_policy_cashier_today_only(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);
        $today = Payment::factory()->create();
        $old = Payment::factory()->create(['created_at' => now()->subDays(2)]);

        $this->assertTrue($cashier->can('void', $today));
        $this->assertFalse($cashier->can('void', $old));
        $this->assertTrue($admin->can('void', $old));
    }
}
```

If `Payment::factory()` does not exist, create `database/factories/PaymentFactory.php` producing a payment attached to fresh enrollment/school-year/user records with `or_number => fake()->unique()->numerify('OR-####')`, `payment_date => now()->toDateString()`, `amount => 100`, `method => 'cash'`.

- [ ] **Step 2: Run to verify failure** — `php artisan test tests/Feature/Filament/PolicyTest.php` → FAIL (policies missing, `can()` false for admin).

- [ ] **Step 3: Implement the five policies**

Pattern (repeat per admin-only model, class name matching):

```php
<?php

namespace App\Policies;

use App\Models\SchoolYear;
use App\Models\User;

class SchoolYearPolicy
{
    public function viewAny(User $user): bool { return $user->isAdmin(); }
    public function view(User $user, SchoolYear $schoolYear): bool { return $user->isAdmin(); }
    public function create(User $user): bool { return $user->isAdmin(); }
    public function update(User $user, SchoolYear $schoolYear): bool { return $user->isAdmin(); }
    public function delete(User $user, SchoolYear $schoolYear): bool { return false; }
}
```

`FeeTypePolicy`, `FeeStructurePolicy` identical shape for their models. `UserPolicy` same shape for `User $model` as subject. `PaymentPolicy`:

```php
<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function void(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $payment->created_at->isToday();
    }
}
```

- [ ] **Step 4: Verify** — policy test passes; full PolicyTest + `vendor/bin/pint --test` + phpstan clean.

- [ ] **Step 5: Commit** — `git add app/Policies tests/Feature/Filament/PolicyTest.php database/factories && git commit -m "feat: policies for admin-only models and payment voiding"`

---

### Task 3: SchoolYearResource with Activate action

**Files:**
- Create: `app/Filament/Resources/SchoolYears/**` (generator layout)
- Test: `tests/Feature/Filament/SchoolYearResourceTest.php`

**Interfaces:**
- Consumes: `SchoolYearPolicy` (Task 2). `SchoolYear` model: `name` (unique string), `is_active` bool; existing semantics: activating a year deactivates all others; activating the already-active year is a no-op (see `SchoolYearController@activate` + `SchoolYearTest` for exact behavior — replicate, then port assertions).
- Produces: navigation item "School Years" (admin only), List page class `App\Filament\Resources\SchoolYears\Pages\ListSchoolYears` used in tests.

- [ ] **Step 1: Scaffold** — `php artisan make:filament-resource SchoolYear --generate` then relocate/rename per generator layout.

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Filament/SchoolYearResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SchoolYears\Pages\ListSchoolYears;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolYearResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User { return User::factory()->create(['role' => 'admin']); }

    public function test_cashier_cannot_see_resource(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $this->get(ListSchoolYears::getUrl())->assertForbidden();
    }

    public function test_admin_lists_school_years(): void
    {
        $year = SchoolYear::factory()->create(['name' => 'SY 2026-2027']);
        $this->actingAs($this->admin());
        Livewire::test(ListSchoolYears::class)->assertCanSeeTableRecords([$year]);
    }

    public function test_activate_action_switches_active_year(): void
    {
        $old = SchoolYear::factory()->create(['is_active' => true]);
        $new = SchoolYear::factory()->create(['is_active' => false]);
        $this->actingAs($this->admin());

        Livewire::test(ListSchoolYears::class)->callTableAction('activate', $new);

        $this->assertTrue($new->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_activating_active_year_keeps_it_active(): void
    {
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $this->actingAs($this->admin());
        Livewire::test(ListSchoolYears::class)->callTableAction('activate', $year);
        $this->assertTrue($year->fresh()->is_active);
    }
}
```

- [ ] **Step 3: Run to verify failure** — resource/pages missing → class not found.

- [ ] **Step 4: Implement**

Form: `TextInput::make('name')->required()->maxLength(50)->unique(ignoreRecord: true)`. Table: `name`, `IconColumn::make('is_active')->boolean()`, plus table action:

```php
Action::make('activate')
    ->requiresConfirmation()
    ->visible(fn (SchoolYear $record) => ! $record->is_active)
    ->action(function (SchoolYear $record) {
        DB::transaction(function () use ($record) {
            SchoolYear::query()->where('id', '!=', $record->id)->update(['is_active' => false]);
            $record->update(['is_active' => true]);
        });
    }),
```

Only List+Create+Edit pages; no delete actions anywhere (policy denies anyway).

- [ ] **Step 5: Verify** — task tests pass; pint + phpstan clean.
- [ ] **Step 6: Commit** — `git add app/Filament tests/Feature/Filament/SchoolYearResourceTest.php && git commit -m "feat: SchoolYearResource with activate action"`

---

### Task 4: FeeTypeResource

**Files:** Create `app/Filament/Resources/FeeTypes/**`; Test `tests/Feature/Filament/FeeTypeResourceTest.php`
**Interfaces:** Consumes `FeeTypePolicy`. `FeeType`: `name` (string, unique). Produces "Fee Types" nav (admin).

- [ ] **Step 1: Write the failing tests** — same skeleton as Task 3's test file with: cashier `assertForbidden()` on list URL; admin creates a fee type via `Livewire::test(CreateFeeType::class)->fillForm(['name' => 'Laboratory Fee'])->call('create')->assertHasNoFormErrors();` then `assertDatabaseHas('fee_types', ['name' => 'Laboratory Fee'])`; duplicate name via `fillForm(['name' => <existing>])->call('create')->assertHasFormErrors(['name'])`.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** — `php artisan make:filament-resource FeeType --generate`; form `TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true)`; table shows `name` + created_at; List+Create+Edit only.
- [ ] **Step 4: Verify** (tests, pint, phpstan). **Step 5: Commit** `feat: FeeTypeResource`.

---

### Task 5: FeeStructureResource with items repeater

**Files:** Create `app/Filament/Resources/FeeStructures/**`; Test `tests/Feature/Filament/FeeStructureResourceTest.php`
**Interfaces:**
- Consumes: `FeeStructurePolicy`. Models: `FeeStructure` (`school_year_id`, `grade_level` string ≤30, unique per year+grade — check the migration `2026_07_10_100655` for the composite unique and mirror it as a form rule), `FeeStructureItem` (`fee_structure_id`, `fee_type_id`, `amount` decimal). Relation: `FeeStructure::items()`.
- Produces: "Fee Structures" nav (admin).

- [ ] **Step 1: Write the failing tests** — admin creates a structure with two repeater items and asserts `fee_structures` + 2 `fee_structure_items` rows; cashier forbidden on list URL; editing an existing structure's item amount persists.

```php
public function test_admin_creates_structure_with_items(): void
{
    $year = SchoolYear::factory()->create(['is_active' => true]);
    $tuition = FeeType::create(['name' => 'Tuition Fee']);
    $books = FeeType::create(['name' => 'Books']);
    $this->actingAs(User::factory()->create(['role' => 'admin']));

    Livewire::test(CreateFeeStructure::class)->fillForm([
        'school_year_id' => $year->id,
        'grade_level' => 'Grade 3',
        'items' => [
            ['fee_type_id' => $tuition->id, 'amount' => 25000],
            ['fee_type_id' => $books->id, 'amount' => 3500],
        ],
    ])->call('create')->assertHasNoFormErrors();

    $this->assertDatabaseCount('fee_structure_items', 2);
}
```

- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** — form: `Select::make('school_year_id')->relationship('schoolYear', 'name')->required()`, `TextInput::make('grade_level')->required()->maxLength(30)`, `Repeater::make('items')->relationship()->schema([Select::make('fee_type_id')->relationship('feeType', 'name')->required(), TextInput::make('amount')->numeric()->required()->minValue(0)])->minItems(1)`. Table: school year name, grade_level, items count, total amount. Uniqueness of (school_year_id, grade_level) via a closure rule matching the DB constraint.
- [ ] **Step 4: Verify.** **Step 5: Commit** `feat: FeeStructureResource with items repeater`.

---

### Task 6: UserResource

**Files:** Create `app/Filament/Resources/Users/**`; Test `tests/Feature/Filament/UserResourceTest.php`
**Interfaces:** Consumes `UserPolicy`. Produces "Users" nav (admin): create/edit users with `name`, `email` (unique), `role` select (admin|cashier), `password` (required on create, optional on edit, hashed, min 8).

- [ ] **Step 1: Write the failing tests** — cashier forbidden on list URL; admin creates `['name' => 'Cash One', 'email' => 'cash1@example.com', 'role' => 'cashier', 'password' => 'secret123']` → `assertDatabaseHas('users', ['email' => 'cash1@example.com', 'role' => 'cashier'])` and `Hash::check('secret123', $user->password)` true; editing without password keeps the old hash.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** — form:

```php
TextInput::make('name')->required()->maxLength(255),
TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
Select::make('role')->options(['admin' => 'Admin', 'cashier' => 'Cashier'])->required(),
TextInput::make('password')->password()->revealable()
    ->required(fn (string $operation) => $operation === 'create')
    ->minLength(8)->dehydrated(fn ($state) => filled($state))
    ->dehydrateStateUsing(fn ($state) => Hash::make($state)),
```

Table: name, email, role badge. List+Create+Edit only.
- [ ] **Step 4: Verify.** **Step 5: Commit** `feat: UserResource for admin-managed accounts`.

---

### Task 7: StudentResource with Register action

**Files:** Create `app/Filament/Resources/Students/**`; Test `tests/Feature/Filament/StudentResourceTest.php`
**Interfaces:**
- Consumes: `RegistrationService::register(Student $student, SchoolYear $year, string $gradeLevel, ?string $section = null): Enrollment` (throws `\RuntimeException` when no fee structure); `SchoolYear::active()` static.
- Produces: "Students" nav visible to BOTH roles (no policy = allowed). Fields (mirror old validation): `student_no` (required ≤30, unique ignoreRecord), `first_name`/`last_name` (required ≤100), `middle_name` (nullable ≤100), `guardian_name` (required ≤100... check old controller lines 68-75 for exact maxes and mirror them), `guardian_contact` (required), `status` select `enrolled|withdrawn`. Table searchable by `student_no`, `first_name`, `last_name`. Table row action `register` (grade_level required ≤30, section nullable ≤50) calling the service against the active year. Row action/link `ledger` per current-year enrollment → `StudentLedger::getUrl(['enrollment' => $enrollment->id])` (page exists Task 8; keep the URL builder in one place: `StudentResource::ledgerUrl(Enrollment $e)` can wrap it so Task 8 only fills in the page).
- [ ] **Step 1: Write the failing tests** — both roles can load list page (`assertOk`); search by student_no finds the record (`searchTable`/`assertCanSeeTableRecords`); create with valid data persists; `register` action with an active year + fee structure creates an enrollment AND the fee-structure charges (assert `ledger_entries` count equals items count — proves the service was called, not reimplemented); register without fee structure surfaces a failure notification and creates nothing.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.** Register action:

```php
Action::make('register')
    ->form([
        TextInput::make('grade_level')->required()->maxLength(30),
        TextInput::make('section')->maxLength(50),
    ])
    ->action(function (Student $record, array $data) {
        $year = SchoolYear::active();
        if (! $year) {
            Notification::make()->danger()->title('No active school year.')->send();
            return;
        }
        try {
            app(RegistrationService::class)->register($record, $year, $data['grade_level'], $data['section'] ?? null);
            Notification::make()->success()->title('Enrolled.')->send();
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }),
```

- [ ] **Step 4: Verify (full suite from here on).** **Step 5: Commit** `feat: StudentResource with register-for-school-year action`.

---

### Task 8: StudentLedger custom page (read-only)

**Files:**
- Create: `app/Filament/Pages/StudentLedger.php`, `resources/views/filament/pages/student-ledger.blade.php`
- Test: `tests/Feature/Filament/StudentLedgerPageTest.php`

**Interfaces:**
- Consumes: `Enrollment` with `ledgerEntries` (active charges), `payments` (+`allocations`, `voided_at`), `promissoryNotes`, `balance()`, `totalAssessed()`, `totalPaid()`. Old Blade reference: `resources/views/ledger/show.blade.php` for what cashiers currently see — content parity is the requirement, not markup parity.
- Produces: page class `App\Filament\Pages\StudentLedger` with route param `enrollment`, `getUrl(['enrollment' => $id])`, hidden from navigation (`protected static bool $shouldRegisterNavigation = false`). Tasks 9-10 attach actions to THIS page; it must expose `public Enrollment $enrollment;` hydrated in `mount(int|string $enrollment)`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_ledger_page_shows_charges_payments_balance(): void
{
    // build year + structure(2 items: 25000, 3500) + student + register + one 5000 payment
    // via RegistrationService and PaymentService (reuse the exact setUp pattern from tests/Feature/PaymentTest.php)
    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    Livewire::test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
        ->assertSee('Tuition Fee')->assertSee('Books')
        ->assertSee('25,000.00')->assertSee('5,000.00')
        ->assertSee(number_format($this->enrollment->balance(), 2));
}

public function test_voided_payment_shown_struck_and_excluded_from_totals(): void { /* record then void via PaymentService, assert balance shown reflects exclusion and 'Voided' label visible */ }
```

- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** — `make:filament-page StudentLedger`; slug `ledger/{enrollment}`; `mount` resolves the Enrollment (404 on missing); Blade renders three sections (charges table with per-charge paid/unpaid from non-voided allocation sums, payments table with method/OR/receiver/void status, promissory notes list) + balance/advance-credit summary using `x-filament::section` components; print links (`route('slips.show', $payment)`, `route('reports.notice', $enrollment)`, `route('reports.statement', $enrollment)`) `target="_blank"`.
- [ ] **Step 4: Verify (full suite).** **Step 5: Commit** `feat: StudentLedger panel page`.

---

### Task 9: Record Payment action on StudentLedger

**Files:** Modify `app/Filament/Pages/StudentLedger.php` (+blade); Test: `tests/Feature/Filament/RecordPaymentActionTest.php`
**Interfaces:**
- Consumes: `PaymentService::record(Enrollment $enrollment, string $orNumber, string $paymentDate, float $amount, string $method, User $receivedBy): Payment` throwing `DuplicateOrNumber`; `Enrollment::balance()`.
- Produces: header action named `recordPayment` on StudentLedger. Redirects to `route('slips.show', $payment)` on success.

- [ ] **Step 1: Write the failing tests** — using the PaymentTest-style setUp:

```php
public function test_records_payment_and_redirects_to_slip(): void
{
    Livewire::test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
        ->callAction('recordPayment', [
            'or_number' => 'OR-9001', 'payment_date' => now()->toDateString(),
            'amount' => 5000, 'method' => 'cash',
        ])->assertHasNoActionErrors()
        ->assertRedirect(route('slips.show', \App\Models\Payment::first()));

    $this->assertEqualsWithDelta(23500.0, $this->enrollment->fresh()->balance(), 0.001);
}

public function test_duplicate_or_number_errors_on_or_field(): void
{
    app(PaymentService::class)->record($this->enrollment, 'OR-9001', now()->toDateString(), 100, 'cash', $this->cashier);
    Livewire::test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
        ->callAction('recordPayment', ['or_number' => 'OR-9001', 'payment_date' => now()->toDateString(), 'amount' => 100, 'method' => 'cash'])
        ->assertHasActionErrors(['or_number']);
}

public function test_overpay_requires_confirmation_checkbox(): void
{
    // amount > balance without confirm_overpay => action error on amount;
    // with confirm_overpay=true => succeeds and unallocated credit recorded (Payment::first()->unallocatedAmount() > 0)
}

public function test_future_payment_date_rejected(): void { /* assertHasActionErrors(['payment_date']) */ }
```

- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement**

```php
Action::make('recordPayment')
    ->form([
        TextInput::make('or_number')->label('OR Number')->required()->maxLength(50),
        DatePicker::make('payment_date')->default(now())->required()->maxDate(now()),
        TextInput::make('amount')->numeric()->required()->minValue(0.01),
        Select::make('method')->options(['cash' => 'Cash', 'gcash' => 'GCash', 'bank' => 'Bank'])->default('cash')->required(),
        Checkbox::make('confirm_overpay')->label('Record excess as advance/credit'),
    ])
    ->action(function (array $data) {
        $balance = $this->enrollment->balance();
        if ((float) $data['amount'] > $balance + 0.005 && ! ($data['confirm_overpay'] ?? false)) {
            Notification::make()->danger()->title('Amount exceeds the remaining balance of ₱'.number_format($balance, 2).'. Tick "Record as advance/credit" to confirm.')->send();
            throw ValidationException::withMessages(['mountedActionsData.0.amount' => 'Exceeds balance; confirm advance/credit.']);
        }
        try {
            $payment = app(PaymentService::class)->record(
                $this->enrollment, trim($data['or_number']), $data['payment_date'],
                (float) $data['amount'], $data['method'], auth()->user());
        } catch (DuplicateOrNumber $e) {
            throw ValidationException::withMessages(['mountedActionsData.0.or_number' => $e->getMessage()]);
        }
        $this->redirect(route('slips.show', $payment));
    }),
```

(Exact ValidationException key for action fields: follow the installed Filament v4 testing helper — `assertHasActionErrors(['or_number'])` defines the contract; adjust the key so that assertion passes.)

- [ ] **Step 4: Verify (full suite).** **Step 5: Commit** `feat: record-payment action on ledger page`.

---

### Task 10: Void + promissory-note actions on StudentLedger

**Files:** Modify `app/Filament/Pages/StudentLedger.php` (+blade); Test: `tests/Feature/Filament/VoidAndPromissoryActionTest.php`
**Interfaces:**
- Consumes: `PaymentService::void(Payment $payment, User $by, string $reason)` throwing `VoidNotAllowed`; `PaymentPolicy::void`; `PromissoryNote` (`amount`, `due_date`, `notes`, `status` pending|fulfilled|broken, `created_by`). Old behavior reference: `StudentLedgerController@storePromissory/@updatePromissoryStatus`.
- Produces: page actions `void` (per payment, reason textarea required, visible per policy), `addPromissory` (header), `promissoryStatus` (per note: fulfilled/broken).

- [ ] **Step 1: Write the failing tests** — cashier voids today's payment with reason (assert `voided_at` set + `void_reason`); cashier CANNOT see/call void on a 2-day-old payment (`assertActionHidden`/policy) while admin can and succeeds; void without reason → action error; promissory create persists with `created_by = auth user`; status update pending→fulfilled persists.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** — void action `->authorize(fn (Payment $p) => auth()->user()->can('void', $p))`, form `Textarea::make('reason')->required()`, action calls the service catching `VoidNotAllowed` into a danger notification. Promissory forms: `TextInput::make('amount')->numeric()->required()->minValue(0.01)`, `DatePicker::make('due_date')->required()`, `Textarea::make('notes')`; status action with `Select::make('status')->options(['fulfilled' => 'Fulfilled', 'broken' => 'Broken'])`.
- [ ] **Step 4: Verify (full suite).** **Step 5: Commit** `feat: void and promissory actions on ledger page`.

---

### Task 11: Dashboard widgets

**Files:** Create `app/Filament/Widgets/TodayCollectionsStats.php`, `app/Filament/Widgets/LatestPayments.php`; Test `tests/Feature/Filament/DashboardWidgetsTest.php`
**Interfaces:** Consumes `Payment` (non-voided, `payment_date` = today). Produces both widgets registered on the panel dashboard. Reference for figures: `DashboardController` (today's total + count) — port its exact queries.
- [ ] **Step 1: Write the failing tests** — seed 2 payments today (cash 1000, gcash 500) + 1 voided today (999) + 1 yesterday; assert stats widget shows total `1,500.00`, count 2, and per-method lines `1,000.00` cash / `500.00` gcash (voided + yesterday excluded); `LatestPayments` table widget lists the two.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** — `TodayCollectionsStats extends StatsOverviewWidget` (stats: Total today ₱, Payments count, per-method split as description or extra stats); `LatestPayments extends TableWidget` (latest 10 non-voided payments: OR, student name via `enrollment.student`, amount, method, received by).
- [ ] **Step 4: Verify (full suite).** **Step 5: Commit** `feat: dashboard widgets for today's collections`.

---

### Task 12: DailyCollections report page with CSV

**Files:** Create `app/Filament/Pages/DailyCollections.php` + `resources/views/filament/pages/daily-collections.blade.php`; Test `tests/Feature/Filament/DailyCollectionsPageTest.php`
**Interfaces:**
- Consumes: `ReportController@daily` (port the query + CSV columns EXACTLY — open the controller and copy the header/row arrays; the CSV format is a compatibility contract for whoever consumes today's exports), non-voided payments by `payment_date`.
- Produces: nav item "Daily Collections" (both roles), date filter (default today), totals per cashier (`received_by` → user name) and per method, `export` header action streaming the CSV.

- [ ] **Step 1: Write the failing tests** — two cashiers, three payments on one date + one voided + one other date; page shows the three rows, grand total, per-cashier subtotals (assert each cashier name + subtotal string), per-method subtotals; `callAction('export')` returns a streamed CSV response whose first line equals the current `ReportController` daily CSV header and which contains a row for each payment (call the action via Livewire and assert `assertFileDownloaded`/response content per Filament v4 testing helpers).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** — page property `public ?string $date = null` (form date picker), computed payments query, Blade table + subtotal sections; export action:

```php
Action::make('export')->action(function () {
    $rows = $this->reportRows(); // same array shape as ReportController@daily csv branch
    return response()->streamDownload(function () use ($rows) {
        $out = fopen('php://output', 'w');
        fputcsv($out, self::CSV_HEADER); // copied verbatim from ReportController
        foreach ($rows as $row) { fputcsv($out, $row); }
        fclose($out);
    }, 'daily-collections-'.$this->date.'.csv', ['Content-Type' => 'text/csv']);
});
```

- [ ] **Step 4: Verify (full suite).** **Step 5: Commit** `feat: daily collections report page with per-cashier/method totals and CSV`.

---

### Task 13: UnpaidBalances page + PrintController

**Files:**
- Create: `app/Filament/Pages/UnpaidBalances.php` + blade, `app/Http/Controllers/PrintController.php`
- Modify: `routes/web.php` (add `/print/*` routes alongside old ones — old routes still untouched)
- Test: `tests/Feature/Filament/UnpaidBalancesPageTest.php`, `tests/Feature/Filament/PrintRoutesTest.php`

**Interfaces:**
- Consumes: `ReportController@unpaid` (port query + CSV columns exactly), `@notice`, `@batchNotices`, `@statement`, `SlipController@show` (print views).
- Produces: nav "Unpaid Balances" (both roles) with CSV export and per-row link to batch/individual notice print routes. `PrintController` methods `slip(Payment)`, `notice(Enrollment)`, `statement(Enrollment)`, `batchNotices()` rendering the SAME Blade views as the old controllers, routes named `print.slip`, `print.notice`, `print.statement`, `print.notices.batch` under `auth` middleware. Task 14 repoints ledger-page links to these names and deletes the old routes.

- [ ] **Step 1: Write the failing tests** — unpaid page lists only enrollments with balance > 0 (seed one fully paid, one partial); CSV export header matches `ReportController` unpaid CSV; all four print routes render 200 with expected content for an auth'd cashier and redirect guests to login.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** — page mirrors Task 12's structure; PrintController is a thin pass-through:

```php
public function slip(Payment $payment) { return view('print.slip', ['payment' => $payment->load('enrollment.student', 'allocations.ledgerEntry')]); }
```

(match each old controller method's view data exactly — open `SlipController`/`ReportController` and copy).
- [ ] **Step 4: Verify (full suite).** **Step 5: Commit** `feat: unpaid balances page and consolidated print routes`.

---

### Task 14: Cutover — delete Breeze and old UI

**Files:**
- Delete: `app/Http/Controllers/Auth/**`, `app/Http/Controllers/{DashboardController,StudentController,FeeTypeController,FeeStructureController,SchoolYearController,ReportController,StudentLedgerController,SlipController,ProfileController}.php`, `app/Http/Middleware/EnsureRole.php`, `app/Livewire/{RecordPayment,StudentSearch}.php`, `app/View/Components/{AppLayout,GuestLayout}.php`, `app/Http/Requests/**`, `routes/auth.php`, `resources/views/{auth,components,layouts,livewire,dashboard.blade.php,fees,ledger,profile,reports,school-years,students,welcome.blade.php}` (keep `resources/views/print/**` and `resources/views/filament/**`)
- Modify: `routes/web.php`, `bootstrap/app.php` (drop `role` alias), `composer.json` (remove `laravel/breeze`), `app/Filament/Pages/StudentLedger.php` + `StudentResource` (repoint print/slip links to `print.*` route names)
- Delete tests: `tests/Feature/{AdminScreensTest,AuthRolesTest,FeeStructureTest,ProfileTest,RecordPaymentUiTest,ReportsTest,SchoolYearTest,StudentSearchTest}.php`, `tests/Feature/Auth/**` — their behavior is covered by `tests/Feature/Filament/**` (verify each deleted assertion has a Filament counterpart; if one doesn't, ADD it to the Filament test first). KEEP service-level tests: `PaymentTest`, `VoidPaymentTest`, `LedgerTest`, `PromissoryNoteTest`, `StudentEnrollmentTest`, `SeederTest`, `ExampleTest`.

**Interfaces:** Produces final `routes/web.php`:

```php
<?php

use App\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

Route::middleware('auth')->prefix('print')->name('print.')->group(function () {
    Route::get('/slip/{payment}', [PrintController::class, 'slip'])->name('slip');
    Route::get('/notice/{enrollment}', [PrintController::class, 'notice'])->name('notice');
    Route::get('/statement/{enrollment}', [PrintController::class, 'statement'])->name('statement');
    Route::get('/notices', [PrintController::class, 'batchNotices'])->name('notices.batch');
});
```

- [ ] **Step 1: Coverage audit** — for each test file slated for deletion, list its test methods and name the Filament test covering each; add missing coverage to `tests/Feature/Filament/**` FIRST (commit separately if non-trivial: `test: port remaining breeze-era coverage to Filament tests`).
- [ ] **Step 2: Delete + rewire** — perform the deletions/modifications above; `composer remove laravel/breeze --dev`; repoint ledger/slip links from old route names to `print.*`; ensure `/` redirects to `/app`.
- [ ] **Step 3: Full verification**

```bash
php artisan test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pint --test
npm run build
php artisan route:list   # confirm no orphan routes referencing deleted controllers
```

Expected: everything green; route list contains only `/`, `print/*`, Filament `app/*`, livewire internals.
- [ ] **Step 4: Update deploy docs** — `docs/deploy.md`: add `php artisan filament:optimize` (or the v4 equivalent caching commands shown by `php artisan list filament`) to the Docker/Coolify build steps; confirm the Dockerfile asset stage still builds (Tailwind v4). Update `CLAUDE.md` architecture section: Filament panel replaces "Cashier flow is Livewire; admin CRUD is classic controllers; auth is Breeze" and the roadmap line (Phase 2 done, Phase 3 next).
- [ ] **Step 5: Commit** — `git add -A -- ':!.superpowers' && git commit -m "feat!: cut over to Filament panel, remove Breeze UI"`

---

### Task 15: Upgrade to Filament v5 (Livewire 4) — severable

**Why after Task 14:** Filament v5 requires Livewire 4 and Tailwind 4. Doing the bump only after cutover means the Livewire major upgrade never coexists with the deleted Breeze/Livewire-3 UI, and it lands on a fresh, plugin-free, fully-tested Filament codebase — the cheapest moment to upgrade.

**Files:** `composer.json`/`composer.lock`, `package.json`/lockfile, plus whatever the official upgrade script rewrites under `app/Filament/**`, `app/Providers/Filament/**`, `resources/`.

- [ ] **Step 1: Read the upgrade guide** at `https://filamentphp.com/docs/5.x/upgrade-guide` (fetch it — this is post-knowledge-cutoff material; do not work from memory). Note the exact composer commands and the automated upgrade script it prescribes.
- [ ] **Step 2: Run the automated upgrade** exactly as the guide directs (upgrade script, then `composer require` bumps for `filament/filament:"^5.0"` and `livewire/livewire:"^4.0"`, then any `php artisan filament:upgrade`-style commands it prints). Review every change the script makes; apply the manual adjustments the guide lists.
- [ ] **Step 3: Full verification**

```bash
php artisan test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pint --test
npm run build
```

- [ ] **Step 4: Manual smoke** via `composer dev`: login, record a payment (duplicate-OR + overpay paths), void, print slip, dashboard, both report pages + CSV.
- [ ] **Step 5: Commit** `feat: upgrade to Filament v5 (Livewire 4)`.
- [ ] **Bail-out (allowed, not a failure):** if after honest effort the upgrade breaks tests or the smoke flow in ways that need design decisions, `git reset --hard` back to the Task 14 commit, record the findings in the progress ledger and this plan's deviations section, and merge Phase 2 on v4 — the upgrade becomes its own future task.

---

## Final verification (after all tasks)

- [ ] `php artisan test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/pint --test && npm run build` — all green.
- [ ] Manual smoke via `composer dev`: login as admin → activate SY, build fee structure, create cashier user; login as cashier → search student, register, record payment (incl. duplicate OR + overpay paths), print slip, void with reason, dashboard + reports + CSVs.
- [ ] `git log --oneline main..HEAD` shows spec + one commit per task (+ coverage-port commit if any).
