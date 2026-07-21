# CLAUDE.md

School cashier system on the TALL stack (Laravel 12, Livewire 3, Alpine,
Tailwind), with Filament v4 as the entire application UI. Production DB is
MySQL/MariaDB; tests use sqlite `:memory:`.

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
  ledger charges). Filament resources/pages/actions must call these, never
  duplicate the logic.
- The entire UI is the Filament v4 panel (`app/Providers/Filament/AppPanelProvider.php`,
  id `app`, path `/app`) — this replaced Breeze and the classic
  controllers/Livewire/Blade UI at the Phase 2 cutover. Resources live in
  `app/Filament/Resources/**` (SchoolYear, FeeType, FeeStructure, User,
  Student); the cashier ledger workflow is the `StudentLedger` custom page
  in `app/Filament/Pages/**` with Record Payment / Void / promissory
  actions; reports are the `DailyCollections` and `UnpaidBalances` pages.
  Role gate is via Policies (`app/Policies/**`), not middleware — roles are
  `admin` and `cashier` on `users.role`, checked through `User::isAdmin()`
  and `FilamentUser::canAccessPanel()`. There is no self-service
  password-reset or profile-edit page (Filament's `passwordReset()`/
  `profile()` are not enabled on the panel); an admin resets/edits any
  user via the Users resource.
- Printables (slips, notices, statements) are plain Blade print views,
  served by `PrintController` (`app/Http/Controllers/PrintController.php`)
  under the `print.*` routes — the only non-Filament, non-`/` routes left
  in `routes/web.php`.

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

Phase 2 (Filament v4 big-bang rewrite of the whole UI) is done — Breeze and
the classic controllers/Livewire/Blade UI were deleted at cutover. Phase 3
(OR-number assistance, discounts UI, installment plans) is next; it's
specced in `docs/superpowers/specs/2026-07-16-mvp-hardening-design.md`.
Build all new UI in the Filament panel (`app/Filament/**`) — there is no
Breeze UI left to extend.
