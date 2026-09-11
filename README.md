# School Cashier System

A cashier and student-billing system for a private school: fee structures per school year, enrolment, payment collection against a student ledger, promissory notes for unpaid balances, and daily collection reporting.

Built with **Laravel 12**, **Livewire 3**, Alpine.js and Tailwind CSS on PHP 8.2, containerised with Docker and deployed via Coolify.

---

## Why this project is non-trivial

School billing looks like CRUD until you handle money properly. The parts that took real design:

**Payments are allocated, not just recorded.** A single payment rarely settles a single fee. `Payment` and `PaymentAllocation` are separate tables so one payment can be distributed across several outstanding fee items, in a defined order, with the remainder carried forward. Recording a payment amount alone would make any per-fee report wrong.

**The ledger is append-only.** `LedgerEntry` records charges and credits rather than storing a mutable running balance. A student's balance is derived from entries, not edited in place — so a voided payment reverses by writing a compensating entry, and history stays intact for audit.

**Voiding is reversal, not deletion.** Financial records are never destroyed. `VoidPaymentTest` covers this path explicitly.

**Only one school year may be active.** Activating a year must deactivate the others — but activating the *already-active* year must not deactivate it. That edge case shipped as a bug and is now covered by a regression test.

**Fee structures are versioned per school year.** `FeeStructure` and `FeeStructureItem` are scoped to a `SchoolYear`, so changing next year's tuition never retroactively alters what a student was billed last year.

**Every mutation is audited.** `AuditLog` records who changed what.

---

## Roles

- **Admin** — everything a cashier can do, plus school years, fee types, fee structures, and voiding payments from any date.
- **Cashier** — student search, enrolment, payment recording, voiding same-day payments, promissory notes, reports, printable slips/notices.

---

## Domain model

| Table | Role |
|---|---|
| `school_years` | Billing period; exactly one active at a time |
| `fee_types` | Catalogue of chargeable items (tuition, miscellaneous, etc.) |
| `fee_structures` / `fee_structure_items` | Per-year, per-level fee sets |
| `students` / `enrollments` | Student records; enrolment binds a student to a school year |
| `ledger_entries` | Append-only charges and credits — the source of truth for balances |
| `payments` / `payment_allocations` | Payments received, and how each is distributed across fees |
| `promissory_notes` | Committed future payment for an outstanding balance |
| `audit_logs` | Who changed what, when |

Business logic lives in `app/Services/` (`PaymentService`, `RegistrationService`) rather than in controllers, so the rules are testable independently of HTTP.

---

## Testing

21 feature tests under `tests/Feature/`, run with PHPUnit. Coverage is aimed at the financial invariants rather than at line count:

| Test | Invariant |
|---|---|
| `LedgerTest` | Balances derive correctly from ledger entries |
| `PaymentTest` | Payments allocate across fee items in the right order |
| `VoidPaymentTest` | Voiding reverses rather than deletes |
| `PromissoryNoteTest` | Notes attach to real outstanding balances |
| `FeeStructureTest` | Fee sets stay scoped to their school year |
| `SchoolYearTest` | Exactly one active year, including the re-activation edge case |
| `StudentEnrollmentTest` | Enrolment binds student to year correctly |
| `AuthRolesTest` / `AdminScreensTest` | Role boundaries hold on every admin screen |
| `ReportsTest` | Collection reports reconcile against the ledger |

```bash
php artisan test
```

---

## Running locally

```bash
git clone https://github.com/mark-john-ignacio/dgsi-cashier-system.git
cd dgsi-cashier-system

composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan migrate --seed

npm run dev
php artisan serve
```

Seeded roles are defined in the database seeders; see `tests/Feature/SeederTest.php` for what is guaranteed to exist after a fresh seed.

### Docker

```bash
docker build -t dgsi-cashier .
```

Deployment notes, including the Coolify configuration settled during the first staging deploy, are in [`docs/deploy.md`](docs/deploy.md).

---

## Documentation

- [`docs/superpowers/specs/2026-07-10-dgsi-cashier-system-design.md`](docs/superpowers/specs/2026-07-10-dgsi-cashier-system-design.md) — system design written before implementation
- [`docs/superpowers/plans/2026-07-10-dgsi-cashier-mvp.md`](docs/superpowers/plans/2026-07-10-dgsi-cashier-mvp.md) — MVP implementation plan
- [`docs/deploy.md`](docs/deploy.md) — deployment and staging notes

---

## Stack

PHP 8.2 · Laravel 12 · Livewire 3 · Alpine.js · Tailwind CSS · Vite 7 · PHPUnit 11 · Laravel Pint · Docker

## Licence

MIT
