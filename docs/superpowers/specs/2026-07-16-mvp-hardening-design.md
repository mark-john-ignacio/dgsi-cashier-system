# MVP Hardening — Design (Phase 1 of 3)

Date: 2026-07-16
Status: approved decomposition; Phase 1 spec

## Context

The cashier MVP is already on the TALL stack (Laravel 12, Livewire 3, Alpine, Tailwind,
Breeze auth). Payments, ledger allocation, voiding, promissory notes, reports, and role
gating all exist with a solid feature-test suite. This cycle hardens the money path and
project infrastructure before a larger UI migration.

## Approved decomposition

1. **Phase 1 — Hardening** (this spec): concurrency-safe payment recording, CI,
   static analysis, project docs. UI-independent; survives the Phase 2 rewrite.
2. **Phase 2 — Filament v4 big-bang rewrite** (separate spec): the whole app moves into
   one Filament panel for both roles. Resources for school years, fee types, fee
   structures, students + enrollments, users. Cashier flow (search → ledger → record
   payment → void → slip) as custom panel pages reusing `PaymentService` and
   `RegistrationService`. Reports as Filament pages with table exports, including
   per-cashier and per-method daily breakdowns. Filament login replaces Breeze;
   self-registration disabled; admin manages users. Printable slips/notices/statements
   remain plain Blade print routes. Breeze views deleted at cutover. Built on a branch
   and swapped in one merge (user chose big-bang over incremental).
3. **Phase 3 — Cashier features** (separate spec, built on Filament): OR-number
   assistance (per-cashier booklet ranges, next-number suggestion, sequence
   validation), discounts/scholarships as audited negative ledger adjustments,
   installment due-date plans with past-due (not just any-balance) reporting.

## Phase 1 scope

### 1. Concurrency-safe payment recording (`app/Services/PaymentService.php`)

**Problem A:** `record()` reads each charge's already-paid total, then writes
allocations. Two concurrent payments for the same enrollment can both read the same
totals and over-allocate a charge.

**Fix:** add `->lockForUpdate()` to the charges query inside the existing
`DB::transaction`, serializing concurrent recordings per enrollment's charge rows.

**Problem B:** the duplicate-OR check is `exists()` then insert. The DB already has a
unique index on `payments (school_year_id, or_number)`, so a race cannot corrupt data,
but it surfaces as an unhandled `QueryException` (HTTP 500) instead of the friendly
`DuplicateOrNumber` message.

**Fix:** catch `Illuminate\Database\UniqueConstraintViolationException` around the
payment insert and rethrow as `DuplicateOrNumber`. The advisory `exists()` pre-check
stays as the fast path for the common case.

**Not changing:** float-vs-integer money representation. The DB columns are
`decimal(12,2)`; PHP-side integer-centavos conversion is deliberately out of scope for
this phase (revisit before Phase 3's installment math).

### 2. Double-submit protection — verified existing, no change

`resources/views/livewire/record-payment.blade.php` already disables the submit button
with `wire:loading.attr="disabled"`. With fix 1B, the DB unique constraint is the
server-side backstop if a duplicate still gets through. No work beyond the regression
tests below.

### 3. Continuous integration (`.github/workflows/ci.yml`)

On push and pull_request:

- PHP 8.3, composer install (cached).
- `vendor/bin/pint --test` (formatting gate).
- `npm ci && npm run build` (feature tests render Blade views using `@vite`, which
  requires a built manifest).
- `php artisan test` against sqlite (`:memory:`), mirroring `phpunit.xml`.

### 4. Static analysis

Add `larastan/larastan` (dev dependency) with a `phpstan.neon` at level 5 covering
`app/`. Fix all findings so the baseline is empty. Add the phpstan run to the CI
workflow after Pint.

### 5. Project documentation

- Replace the stock Laravel `README.md` with project docs: what the system is, roles
  (admin, cashier), local setup (`composer setup`, `composer dev`), testing
  (`composer test`), link to `docs/deploy.md`.
- Add `CLAUDE.md`: stack summary, architecture map (services own money logic; Livewire
  components for cashier flow; controllers for CRUD), domain invariants (OR numbers
  unique per school year; voids keep rows and are excluded via `voided_at`; allocations
  are oldest-charge-first; unallocated remainder = advance credit), test and lint
  commands.

## Error handling

- `DuplicateOrNumber` remains the single exception type callers handle for OR
  collisions, whether caught by pre-check or constraint violation; the Livewire
  component's existing `addError('or_number', …)` path is unchanged.
- `lockForUpdate()` on sqlite (tests) is a no-op; correctness under test is asserted
  behaviorally, and the lock protects MySQL/MariaDB in production.

## Testing

- New test: inserting a payment that bypasses the pre-check (direct DB insert of the
  colliding OR, then `record()`) still yields `DuplicateOrNumber`, not a raw
  `QueryException`.
- New test: allocations never exceed a charge's amount after sequential payments that
  sum past a charge boundary (guards the allocation arithmetic the lock protects).
- Existing suite (`PaymentTest`, `VoidPaymentTest`, `RecordPaymentUiTest`, etc.) stays
  green; CI enforces this from now on.

## Out of scope (Phase 1)

- Off-host database backups — needs a storage destination decision (e.g. S3 bucket)
  before it can be implemented honestly.
- Everything listed under Phases 2 and 3.
