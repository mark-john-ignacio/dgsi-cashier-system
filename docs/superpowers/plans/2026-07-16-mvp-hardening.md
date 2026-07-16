# MVP Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make payment recording safe under concurrency, add CI + static analysis, and write real project docs — Phase 1 of the approved spec at `docs/superpowers/specs/2026-07-16-mvp-hardening-design.md`.

**Architecture:** All money logic lives in `app/Services/PaymentService.php`, called by the `App\Livewire\RecordPayment` component. Fixes go into the service inside its existing `DB::transaction`; the DB already enforces `UNIQUE (school_year_id, or_number)` on `payments`. CI is a single GitHub Actions job; static analysis is Larastan on `app/`.

**Tech Stack:** Laravel 12, PHP ≥8.2 (CI uses 8.3), Livewire 3, PHPUnit 11 (sqlite `:memory:` per `phpunit.xml`), Laravel Pint, Larastan 3, GitHub Actions, Vite/Tailwind.

## Global Constraints

- Branch: work happens on `feat/mvp-hardening` (already checked out).
- Tests run with `php artisan test` (sqlite in-memory; configured in `phpunit.xml` — no env setup needed locally).
- All PHP code must pass `vendor/bin/pint --test` before commit (from Task 3 onward).
- Do NOT change the float-based money representation; DB stays `decimal(12,2)` (spec: out of scope).
- Do NOT touch Breeze views/controllers beyond what's listed — the Filament rewrite (Phase 2) replaces them wholesale later.
- The user-facing duplicate-OR message must remain exactly: `OR number {orNumber} is already used this school year.` (the Livewire component surfaces `getMessage()` directly).

---

### Task 1: Map DB unique-constraint violation to DuplicateOrNumber

Two cashiers can pass the advisory `exists()` pre-check simultaneously; the loser hits the DB unique index and today gets an unhandled `QueryException` (HTTP 500). Map it to the domain exception the UI already handles.

**Files:**
- Modify: `app/Exceptions/DuplicateOrNumber.php`
- Modify: `app/Services/PaymentService.php:19-33`
- Test: `tests/Feature/PaymentTest.php`

**Interfaces:**
- Consumes: `payments` unique index `(school_year_id, or_number)`; Laravel's `Illuminate\Database\UniqueConstraintViolationException` (thrown by all drivers incl. sqlite since Laravel 10.20).
- Produces: `DuplicateOrNumber::forOrNumber(string $orNumber): self` static constructor. Task 2 and later tasks rely on `PaymentService::record()` keeping its exact current signature.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/PaymentTest.php` (add `use App\Models\Payment;` and `use Illuminate\Support\Facades\DB;` to the imports):

```php
public function test_or_collision_that_beats_the_precheck_still_raises_duplicate_or_number(): void
{
    // Simulate losing the race: a concurrent cashier inserts the same OR
    // number in the window between the exists() pre-check and our insert.
    // The creating hook fires exactly in that window.
    $inserted = false;
    Payment::creating(function () use (&$inserted) {
        if (! $inserted) {
            $inserted = true;
            DB::table('payments')->insert([
                'enrollment_id' => $this->enrollment->id,
                'school_year_id' => $this->enrollment->school_year_id,
                'or_number' => 'OR-2001',
                'payment_date' => '2026-08-01',
                'amount' => 100.00,
                'method' => 'cash',
                'received_by' => $this->cashier->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $this->expectException(DuplicateOrNumber::class);
    app(PaymentService::class)->record(
        $this->enrollment, 'OR-2001', '2026-08-01', 500.00, 'cash', $this->cashier);
}
```

(The listener does not leak into other tests: each PHPUnit test boots a fresh application and event dispatcher.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_or_collision_that_beats_the_precheck`
Expected: FAIL — `Illuminate\Database\UniqueConstraintViolationException` thrown instead of `App\Exceptions\DuplicateOrNumber`.

- [ ] **Step 3: Implement**

Replace the whole body of `app/Exceptions/DuplicateOrNumber.php` with:

```php
<?php

namespace App\Exceptions;

class DuplicateOrNumber extends \RuntimeException
{
    public static function forOrNumber(string $orNumber): self
    {
        return new self("OR number {$orNumber} is already used this school year.");
    }
}
```

In `app/Services/PaymentService.php`, add the import:

```php
use Illuminate\Database\UniqueConstraintViolationException;
```

and change the pre-check + create block inside `record()` to:

```php
$exists = Payment::where('school_year_id', $enrollment->school_year_id)
    ->where('or_number', $orNumber)->exists();
if ($exists) {
    throw DuplicateOrNumber::forOrNumber($orNumber);
}

try {
    $payment = Payment::create([
        'enrollment_id' => $enrollment->id,
        'school_year_id' => $enrollment->school_year_id,
        'or_number' => $orNumber,
        'payment_date' => $paymentDate,
        'amount' => $amount,
        'method' => $method,
        'received_by' => $receivedBy->id,
    ]);
} catch (UniqueConstraintViolationException) {
    throw DuplicateOrNumber::forOrNumber($orNumber);
}
```

(Throwing inside the `DB::transaction` closure rolls the transaction back and propagates — exactly the existing behavior of the pre-check path.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/PaymentTest.php`
Expected: PASS — all tests including the new one and `test_duplicate_or_number_in_same_year_is_rejected_even_if_voided`.

- [ ] **Step 5: Commit**

```bash
git add app/Exceptions/DuplicateOrNumber.php app/Services/PaymentService.php tests/Feature/PaymentTest.php
git commit -m "fix: surface DuplicateOrNumber when a concurrent insert beats the OR pre-check"
```

---

### Task 2: Lock charge rows during payment allocation

Two concurrent payments for the same enrollment can read the same "already paid" totals and over-allocate a charge. `lockForUpdate()` inside the existing transaction serializes them on MySQL/MariaDB. sqlite (tests) treats the lock as a no-op, so the test here is a behavioral regression guard for the allocation arithmetic, not a proof of the lock — it should pass before AND after the change.

**Files:**
- Modify: `app/Services/PaymentService.php:36-37`
- Test: `tests/Feature/PaymentTest.php`

**Interfaces:**
- Consumes: `PaymentService::record()` from Task 1 (signature unchanged); `LedgerEntry::allocations()` hasMany relation.
- Produces: nothing new — behavior-preserving under sequential calls.

- [ ] **Step 1: Write the regression test**

Add to `tests/Feature/PaymentTest.php`:

```php
public function test_sequential_payments_never_over_allocate_a_charge(): void
{
    $svc = app(PaymentService::class);
    $svc->record($this->enrollment, 'OR-3001', '2026-08-01', 20000.00, 'cash', $this->cashier);
    $svc->record($this->enrollment, 'OR-3002', '2026-08-02', 6000.00, 'cash', $this->cashier);

    $tuition = $this->enrollment->ledgerEntries()->where('description', 'Tuition Fee')->first();
    $books = $this->enrollment->ledgerEntries()->where('description', 'Books')->first();

    // 25,000 tuition is exactly filled across both payments; 1,000 spills to books.
    $this->assertEqualsWithDelta(25000.00, (float) $tuition->allocations()->sum('amount'), 0.001);
    $this->assertEqualsWithDelta(1000.00, (float) $books->allocations()->sum('amount'), 0.001);
}
```

- [ ] **Step 2: Run test to verify it passes (regression baseline)**

Run: `php artisan test --filter=test_sequential_payments_never_over_allocate`
Expected: PASS (this guards the arithmetic the lock protects; it must not break in Step 3).

- [ ] **Step 3: Add the lock**

(REVISED during execution — review found the original charge-row-lock-only
placement leaves a REPEATABLE READ stale-snapshot race on MySQL; the enrollment
row must be locked as the transaction's first statement. See the spec's
"Concurrency-safe payment recording" section for the reasoning.)

In `app/Services/PaymentService.php`, make the first statement inside the
`DB::transaction` closure in `record()` a locking read on the enrollment row:

```php
// Serialize recording per enrollment. This locking read must be the
// transaction's first statement: it does not establish the REPEATABLE READ
// snapshot, so every later read sees state committed after the lock is won.
Enrollment::whereKey($enrollment->id)->lockForUpdate()->get();
```

and change the charges query in `record()`:

```php
$charges = $enrollment->ledgerEntries()->active()
    ->where('type', 'charge')->orderBy('id')->lockForUpdate()->get();
```

- [ ] **Step 4: Run the full suite**

Run: `php artisan test`
Expected: PASS — everything green, including Task 1's tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PaymentService.php tests/Feature/PaymentTest.php
git commit -m "fix: lock charge rows during allocation to prevent concurrent over-allocation"
```

---

### Task 3: Formatting baseline + GitHub Actions CI

`vendor/bin/pint --test` currently fails on 17 files, so the formatting baseline lands first, then the CI workflow that gates on it.

**Files:**
- Modify: 17 files auto-fixed by Pint (models, controllers, exceptions, tests, `bootstrap/app.php` — no manual edits)
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `composer test` semantics (`php artisan test`), `phpunit.xml` sqlite config, `.env.example`.
- Produces: a `CI` workflow whose job is named `ci`; Task 4 inserts a PHPStan step into this exact file after the "Check code style" step.

- [ ] **Step 1: Apply Pint formatting**

Run: `vendor/bin/pint`
Expected: output lists the fixed files, exit code 0.

- [ ] **Step 2: Verify formatting is clean and tests still pass**

Run: `vendor/bin/pint --test && php artisan test`
Expected: Pint PASS (no findings), full test suite PASS.

- [ ] **Step 3: Commit the formatting baseline**

```bash
git add -A
git commit -m "style: apply pint formatting baseline"
```

- [ ] **Step 4: Create the workflow**

Create `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
  pull_request:

jobs:
  ci:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Install PHP dependencies
        run: composer install --prefer-dist --no-progress --no-interaction

      - name: Check code style
        run: vendor/bin/pint --test

      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm

      - name: Build frontend assets
        run: |
          npm ci
          npm run build

      - name: Run tests
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan test
```

(The asset build is required: feature tests render Blade views whose `@vite` directive needs a build manifest.)

- [ ] **Step 5: Verify the same commands locally**

Run: `vendor/bin/pint --test && npm run build && php artisan test`
Expected: all three PASS. (GitHub Actions can't run locally; this validates every command the workflow executes.)

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add GitHub Actions workflow (pint, asset build, tests)"
```

---

### Task 4: Larastan static analysis

**Files:**
- Modify: `composer.json` / `composer.lock` (via composer require)
- Create: `phpstan.neon`
- Modify: `.github/workflows/ci.yml` (insert one step)
- Modify: any `app/` files with level-5 findings (fix, don't baseline)

**Interfaces:**
- Consumes: the `ci` job from Task 3.
- Produces: `vendor/bin/phpstan analyse` exits 0 at level 5 on `app/`; CI enforces it.

- [ ] **Step 1: Install Larastan**

Run: `composer require --dev "larastan/larastan:^3.0"`
Expected: installs without dependency conflicts (Laravel 12 / PHPUnit 11 are supported).

- [ ] **Step 2: Create `phpstan.neon`**

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 5
    paths:
        - app
```

- [ ] **Step 3: Run analysis and fix every finding**

Run: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Fix each reported issue in place — no `phpstan-baseline.neon`, no `@phpstan-ignore`. Expected finding shapes in this codebase and their fixes:

- *Relation return types* (e.g. `App\Models\Enrollment::student() has no return type`): add the generic return type, e.g. `public function student(): BelongsTo` with `use Illuminate\Database\Eloquent\Relations\BelongsTo;`.
- *`auth()->id()` / `auth()->user()` possibly null* (e.g. in `RegistrationService`): these run behind the `auth` middleware; make the assumption explicit rather than suppressing — accept the user as a parameter where practical, otherwise assert non-null before use.
- *Float/string juggling on `decimal` casts* (e.g. comparing `$charge->amount`): add explicit `(float)` casts at the comparison site, matching the style already used in `PaymentService`.

If a fix would change runtime behavior (not just types), stop and flag it in the task report instead of guessing.

- [ ] **Step 4: Re-run analysis and the suite**

Run: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G && php artisan test`
Expected: `[OK] No errors` and full test suite PASS.

- [ ] **Step 5: Add the CI step**

In `.github/workflows/ci.yml`, insert immediately after the "Check code style" step:

```yaml
      - name: Static analysis
        run: vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

- [ ] **Step 6: Verify formatting, then commit**

Run: `vendor/bin/pint --test`
Expected: PASS.

```bash
git add -A
git commit -m "chore: add larastan at level 5 and fix findings"
```

---

### Task 5: Project README and CLAUDE.md

**Files:**
- Modify: `README.md` (full replacement)
- Create: `CLAUDE.md`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: docs only; no code contract.

- [ ] **Step 1: Replace `README.md`**

Full new content:

```markdown
# DGSI Cashier System

A school cashier and student-fees management system. Cashiers search for a
student, view the fee ledger, record payments against an official receipt (OR)
number, and print payment slips. Admins manage school years, fee types, fee
structures, and reports.

## Stack

Laravel 12 · Livewire 3 · Alpine.js · Tailwind CSS (TALL) · MySQL/MariaDB in
production, sqlite in-memory for tests · Deployed via Docker on Coolify (see
[docs/deploy.md](docs/deploy.md)).

## Roles

- **Admin** — everything a cashier can do, plus school years, fee types, fee
  structures, and voiding payments from any date.
- **Cashier** — student search, enrollment, payment recording, voiding
  same-day payments, promissory notes, reports, printable slips/notices.

## Domain model (short version)

A `Student` is enrolled per `SchoolYear` (an `Enrollment`). Registration copies
the grade level's `FeeStructure` items onto the enrollment as ledger `charge`
entries. A `Payment` (unique OR number per school year) is allocated
oldest-charge-first via `PaymentAllocation`; any remainder is advance credit.
Voided payments keep their rows (`voided_at`) and drop out of all totals.
`PaymentService` and `RegistrationService` own this logic — don't reimplement
it in controllers or components.

## Local development

```bash
composer setup   # install, .env, key, migrate, npm install + build
composer dev     # serve + queue + logs + vite, concurrently
```

## Testing & quality

```bash
composer test                 # phpunit (sqlite :memory:)
vendor/bin/pint --test        # formatting (CI-enforced)
vendor/bin/phpstan analyse    # larastan level 5 (CI-enforced)
```

CI runs all three plus an asset build on every push and pull request.
```

- [ ] **Step 2: Create `CLAUDE.md`**

Full content:

```markdown
# CLAUDE.md

School cashier system on the TALL stack (Laravel 12, Livewire 3, Alpine,
Tailwind). Production DB is MySQL/MariaDB; tests use sqlite `:memory:`.

## Commands

- `composer test` — full suite (PHPUnit 11, sqlite in-memory, no env setup needed)
- `php artisan test --filter=<name>` — single test
- `vendor/bin/pint` / `vendor/bin/pint --test` — format / check (CI gate)
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — larastan level 5 (CI gate)
- `composer dev` — serve + queue + pail + vite concurrently

## Architecture

- **Money logic lives in services**: `app/Services/PaymentService.php`
  (record/void payments, oldest-first allocation) and
  `app/Services/RegistrationService.php` (enroll + copy fee structure to
  ledger charges). Controllers and Livewire components must call these, never
  duplicate the logic.
- Cashier flow is Livewire (`app/Livewire/`); admin CRUD is classic
  controllers; auth is Breeze. Role gate: `EnsureRole` middleware
  (`role:admin`), roles are `admin` and `cashier` on `users.role`.
- Printables (slips, notices, statements) are plain Blade print views.

## Domain invariants

- OR numbers are unique per school year — DB unique index on
  `payments (school_year_id, or_number)`; `DuplicateOrNumber` is the domain
  exception for collisions (both pre-check and constraint-violation paths).
- Payments are allocated to charges oldest-first; unallocated remainder is
  advance credit; a charge's allocations must never exceed its amount.
- Voids never delete rows: `voided_at`/`voided_by`/`void_reason` are set and
  every total excludes voided payments. Cashiers may void same-day only;
  admins any day.
- Money is `decimal(12,2)` in the DB, floats in PHP with 0.005 epsilons —
  do not "fix" this piecemeal; it's a deliberate, tracked trade-off.
- Ledger amounts: positive = charge, negative = discount/adjustment.
- `AuditLog::record()` on every money mutation.

## Roadmap context

Phase 2 (Filament v4 big-bang rewrite of the whole UI) and Phase 3 (OR-number
assistance, discounts UI, installment plans) are specced in
`docs/superpowers/specs/2026-07-16-mvp-hardening-design.md`. Don't invest in
Breeze views — they are scheduled for deletion at the Phase 2 cutover.
```

- [ ] **Step 3: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: project README and CLAUDE.md"
```

---

## Final verification (after all tasks)

- [ ] Run: `vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && php artisan test`
  Expected: all PASS.
- [ ] `git log --oneline feature/cashier-mvp..HEAD` shows the spec commit plus one commit per task (formatting baseline separate).
