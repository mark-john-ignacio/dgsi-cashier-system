# DGSI Cashier System — MVP Design

**Date:** 2026-07-10
**Project:** dgsi-app — Cashier/Payments System for Dei Gratia School (private school, Philippines)
**Status:** Approved design, pending implementation plan

## 1. Purpose & Context

Dei Gratia School currently handles tuition collection and auditing manually. School staff (Gelle) confirmed the pain points:

- Payments are recorded manually; auditing is tedious.
- Before Quarter Exams, staff manually check who has unpaid balances and send paper notices/reminders.
- Payments are often partial; some parents settle via promissory notes.
- Fees are based on Tuition Fee (TF) + other fees set at enrollment, per grade level.

**Goal:** a system the cashier uses day-to-day to record payments, see balances instantly, and produce audit-ready reports. This is for **real use**, not a demo.

**Chosen scope (Approach A):** Cashier-first, with a minimal built-in student/fee registry ("enrollment-lite"). A full Enrollment module is a later phase that will reuse the same student records.

**Explicitly out of scope for MVP:** enrollment workflows (requirements checklists, old/new student rules), RFID/SMS attendance, SMS/email notifications, parent portal, discount management UI beyond manual adjustment lines, offline mode, BIR official receipt generation.

## 2. Architecture

- **Stack:** Laravel 12, MySQL, Blade templates with Livewire for interactive screens. No SPA.
- **Hosting:** Deployed on the developer's Oracle VM running Coolify (Git-based deploys, MySQL container, HTTPS).
- **Users & roles:**
  - `cashier` — record payments, manage students, void same-day payments.
  - `admin` — everything, plus reports, fee structures, voiding older payments (for Gelle / the directress).
- **Backups:** nightly automated MySQL dumps via Coolify, plus a periodic copy stored off the VM. The database is the school's money record; one copy on one VM is insufficient.
- **Unreliable-internet plan:** the school issues handwritten official receipts (ORs) today and continues to do so. If the connection is down, the cashier writes the paper OR as usual and back-encodes it later. The payment form therefore accepts the actual OR number and an editable payment date. No offline-sync in MVP.

## 3. Data Model

Six core tables plus auth/audit:

| Table | Purpose |
|---|---|
| `school_years` | e.g. "2026–2027"; exactly one active. |
| `students` | Student no., name, guardian name & contact, status (enrolled/withdrawn). Grade level & section are stored per school year on the enrollment/registration record so history survives year-to-year. |
| `fee_types` | Reusable list: Tuition, Registration, Books, Uniform, etc. |
| `fee_structures` | Per grade level + school year: which fee types and amounts (e.g. Grade 3, SY 2026–27: Tuition ₱25,000; Books ₱3,500). |
| `student_ledgers` | Per-student assessment lines. When a student is registered into a school year, the fee structure is **copied** onto their ledger (not referenced), so later price changes never rewrite what existing students owe. Discounts/scholarships are negative adjustment lines here. |
| `payments` | OR number, payment date, amount, method, receiving cashier, and allocation of the amount to ledger line(s). |
| `promissory_notes` | Student, amount, due date, notes, status (pending/fulfilled/broken). |
| audit trail | Who did what, when — for payments, voids, and edits to students/fees. |

**Core invariant:** for any student, `balance = sum(ledger charges) − sum(applied non-voided payments)`. Every list, notice, and report derives from this equation.

**Immutability rule:** saved payments and ledger adjustments are never edited or deleted. Corrections are made by **voiding** (reversal entry with required reason and actor), keeping the original visible.

**Open questions to confirm with the school (do not block implementation; ledger design supports all answers):**

- Are there discounts (siblings, early-bird, scholars)? → handled as adjustment lines; a dedicated UI can come later.
- Is tuition billed as one annual amount or monthly/quarterly installments? → affects how ledger lines are itemized and how "overdue" is presented; MVP assumes annual line items until confirmed.
- How are records kept today (Excel? paper)? → determines whether an Excel/CSV student import is an early fast-follow. MVP ships manual encoding; the design leaves room for import.
- Which payment methods are accepted (cash / GCash / bank deposit)?

## 4. Screens & Workflow

1. **Dashboard** — today's collection total, payment count, prominent student search.
2. **Student search/list** — search by name/student no., filter by grade/section and by "with balance". Rows show running balance (visual flag for overdue vs. promissory-pending). This is the "sino pa ang di bayad" view.
3. **Student ledger page** — one student's assessed fees, payments, promissory notes, current balance. Actions: Record Payment, Add Promissory Note, Print Notice, Print Statement.
4. **Record Payment form** (most-used screen):
   - OR number entered manually (matches the pre-printed BIR OR booklet); duplicate-checked, never system-generated.
   - Payment date defaults to today, editable for back-encoding.
   - Amount + allocation to fee line(s); default allocation is oldest-unpaid-first, adjustable.
   - Payment method.
   - On save: printable acknowledgment slip. The paper OR remains the legal receipt.
5. **Students admin** — add/edit students, register into the active school year (copies fee structure to ledger). This is the enrollment-lite piece.
6. **Fees admin** (admin-only) — manage fee types and per-grade fee structures per school year.
7. **Reports** — see §5.

**Typical day:** parent arrives → cashier searches child → ledger shows balance → payment recorded with OR number from booklet → balance updates. Pre-exam: open "with balance" list per grade → print notices in bulk.

## 5. Reports & Printing

Every report is printable and exportable to Excel.

1. **Daily Collection Report** — payments for a date/range: OR no., student, amount, method, cashier; totals per method + grand total. Must reconcile 1:1 with the paper OR booklet and cash drawer. Voided payments shown struck-through.
2. **Unpaid Balances Report** — per grade/section; distinguishes plain unpaid from promissory-covered. The pre-Quarter-Exam audit list.
3. **Notice/Reminder generator** — printable notices (school header, itemized outstanding fees, total, due-date line), single or batch (one per page, grouped per class) for distribution via class advisers.
4. **Statement of Account** — one student's full ledger, printable on demand.

**Printing:** print-friendly HTML via the browser print dialog (`@media print` CSS). No server-side PDF generation in MVP ("Save as PDF" in the print dialog covers file needs). Excel exports via a Laravel export package.

## 6. Money-Integrity & Error Handling

- **Void, never delete/edit:** voids require a reason, record the actor, and appear on daily reports.
- **Duplicate OR numbers** blocked per school year with an immediate warning.
- **Overpayment** warns and requires confirmation; confirmed overpayment is recorded as credit/advance.
- **Attribution everywhere:** payments, voids, and student/fee edits log who and when.
- **Role limits:** cashier may void only same-day payments; older voids and fee-structure edits are admin-only.
- **Friendly failure:** form state survives a failed save (connection drop); clear user-facing error messages; never an unhandled error page.

## 7. Testing

- **Feature tests on money paths:** record payment, partial payment, allocation, void, duplicate-OR rejection, overpayment handling, balance math.
- **Golden-rule property:** `assessed − paid = balance` must hold after any sequence of operations exercised in tests.
- **Seeders** with realistic fake students across grade levels for demos and development.

## 8. Future Phases (not in MVP)

1. Enrollment module (plugs into the same `students` table).
2. Excel/CSV student import (likely early fast-follow, pending answer on current record-keeping).
3. Discounts management UI.
4. Emailed/SMS notices; parent-facing balance portal (candidate React project).
5. Offline mode — only if outages prove frequent and painful in practice.
