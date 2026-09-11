# Filament v4 Rewrite — Design (Phase 2 of 3)

Date: 2026-07-17
Status: approved design
Predecessor: `docs/superpowers/specs/2026-07-16-mvp-hardening-design.md` (Phase 1, merged as PR #2)

## Context and decisions carried forward

Phase 1 hardened the money path and infrastructure. This phase executes the roadmap's
approved decision: the **whole app** moves into **one Filament v4 panel** for both
roles, built as a **big-bang rewrite** on branch `feat/filament-rewrite` and swapped in
a single merge. Newly decided in this brainstorm:

- **One panel, role-gated** (not separate admin/cashier panels): both roles share
  `/app`; admin-only surfaces are hidden/denied via policies.
- **Approach B**: Filament resources for CRUD; **custom Filament pages** for the
  cashier flow (ledger + payment recording). No embedding of the old Livewire
  components; no forcing the cashier flow into resource CRUD pages.

Unchanged from the roadmap: Filament login replaces Breeze; self-registration
disabled; admin manages users; printable slips/notices/statements remain plain Blade
print routes; Breeze deleted at cutover; `PaymentService`/`RegistrationService` remain
the only owners of money logic.

## Architecture

### 1. Stack & installation

- `composer require filament/filament:"^4.0"`; one panel provider (`app/Providers/
  Filament/AppPanelProvider.php`), path `/app`, using Filament's login page.
- **Version strategy (added 2026-07-17):** Filament v5 (released 2026-01-16) requires
  Livewire 4 + Tailwind 4, so the rewrite builds on v4 (keeps the Livewire 3 Breeze UI
  alive until cutover), then upgrades to v5 via the official upgrade script as a final,
  severable task after the old UI is deleted. If the upgrade misbehaves, Phase 2 merges
  on v4 and the upgrade is backlogged.
- `User::canAccessPanel()` returns true for roles `admin` and `cashier`.
- Tailwind resolves to v4 using the `@tailwindcss/vite` plugin already present in
  package.json; the stray `tailwindcss ^3.x` dependency and `tailwind.config.js` are
  removed/migrated as part of the asset build change. Filament's default theme — no
  custom theme this phase.
- Print views (`resources/views/print/*`) keep their standalone layout and CSS,
  served by auth-protected non-panel routes.

### 2. Resources

| Resource | Access | Notes |
|---|---|---|
| SchoolYearResource | admin | CRUD + "Activate" table action (existing activate semantics: activating one deactivates others; activating the active year is a no-op) |
| FeeTypeResource | admin | simple CRUD |
| FeeStructureResource | admin | per school year + grade level; items as a repeater (fee type, amount `decimal(12,2)`) |
| UserResource | admin | NEW capability: create/edit users, role select (admin/cashier), password set/reset |
| StudentResource | both | searchable table (name, student number), create/edit, "Register for school year" action → `RegistrationService::register()` (grade level + optional section; errors like missing fee structure surface as Filament notifications), per-enrollment link to the ledger page |

### 3. Cashier flow — custom pages

- **`StudentLedger` page** at `/app/ledger/{enrollment}`: charges with per-charge
  paid/unpaid (allocation sums excluding voided payments), payments list (OR number,
  date, amount, method, received-by, void status/reason), promissory notes, running
  balance and advance credit. Read via existing models; no money logic re-implemented.
- **Record Payment** (header action, Filament form): OR number, payment date
  (≤ today), amount (> 0), method (cash/gcash/bank), overpay-confirmation checkbox
  required when amount exceeds balance (same 0.005 epsilon semantics as today).
  Calls `PaymentService::record()`; `DuplicateOrNumber` maps to a field error on the
  OR input with the exact message `OR number {orNumber} is already used this school
  year.`; success redirects to the slip print route.
- **Void payment** (table action on the payments list): requires reason; calls
  `PaymentService::void()`; `VoidNotAllowed` surfaces as a notification. Action
  visibility follows the void policy (below).
- **Promissory notes**: create (amount, promised date, note) and status update
  (pending → kept/broken), mirroring current `StudentLedgerController` behavior.
- **Print links**: slip (per payment), notice and statement (per enrollment) open the
  Blade print routes in a new tab.

### 4. Authorization

- Policies replace `role:admin` middleware: `SchoolYear`, `FeeType`, `FeeStructure`,
  `User` policies deny non-admins (resources also hidden from cashier navigation).
- `PaymentPolicy@void`: admin always; cashier only when `created_at` is today —
  mirrors the service rule; the service check remains the enforcement of record.
- `EnsureRole` middleware, Breeze auth controllers/routes, and the old route groups
  are deleted at cutover.

### 5. Dashboard & reports

- Dashboard widgets: today's collections (total ₱, payment count, per-method split)
  and a latest-payments table widget. Replaces `DashboardController` + welcome
  student-search entry point (search lives in StudentResource).
- **DailyCollections page**: date picker (default today), payments table, totals per
  cashier and per method, CSV export preserving the current CSV column format.
- **UnpaidBalances page**: enrollments with balance > 0, CSV export, link to batch
  notices print route.
- The HTML/CSV endpoints in `ReportController` are ported into these pages; the
  controller and its routes are deleted at cutover. Print-oriented endpoints
  (notice, statement, batch notices, slip) move to a slim `PrintController`.

### 6. Cutover & deletion (single merge)

Deleted: Breeze auth controllers/views/components/routes, `DashboardController`,
`StudentController`, `FeeTypeController`, `FeeStructureController`,
`SchoolYearController`, `ReportController` (HTML/CSV parts), `StudentLedgerController`,
old Livewire components (`RecordPayment`, `StudentSearch`) and their views, profile
pages (Filament handles profile), `EnsureRole`, `laravel/breeze` composer dep.
Kept: print views + slim `PrintController`, all models/services/migrations, audit
logging. `routes/web.php` ends up: `/` → redirect `/app`, print routes (auth), and
whatever Filament registers itself.

### 7. Testing & CI

- Service/unit tests unchanged and must stay green.
- Feature tests ported to Filament equivalents via `Livewire::test()`: payment form
  validation + duplicate-OR error + overpay confirm; void rules per role; register
  action; resource access denial for cashiers; report page totals + CSV; dashboard
  widget figures; print routes still render.
- CI pipeline unchanged (pint → phpstan level 5 → build → tests) and must pass with
  Filament installed; no phpstan baseline/ignores introduced.
- Deployment note: `php artisan filament:optimize` (or `icons:cache`) added to the
  Docker build stage alongside existing optimizations; verify `docs/deploy.md` still
  holds and update it in the same branch.

## Error handling

- All service exceptions (`DuplicateOrNumber`, `VoidNotAllowed`, registration
  `RuntimeException`) surface as Filament field errors or danger notifications —
  never a 500 or silent failure.
- Panel access for unknown/other roles: denied by `canAccessPanel()`.

## Out of scope (Phase 3 and later)

- OR-number assistance, discounts/scholarships UI, installment plans (Phase 3).
- Custom Filament theme/branding; money-representation refactor; backups.
