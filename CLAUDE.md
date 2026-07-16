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
- `AuditLog::record()` on payment record/void today — extend it to any new money-mutating path you add (registration charges are currently not audited).

## Roadmap context

Phase 2 (Filament v4 big-bang rewrite of the whole UI) and Phase 3 (OR-number
assistance, discounts UI, installment plans) are specced in
`docs/superpowers/specs/2026-07-16-mvp-hardening-design.md`. Don't invest in
Breeze views — they are scheduled for deletion at the Phase 2 cutover.
