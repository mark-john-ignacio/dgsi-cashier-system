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
