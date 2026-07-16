<?php

namespace App\Services;

use App\Exceptions\DuplicateOrNumber;
use App\Exceptions\VoidNotAllowed;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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

            AuditLog::record($receivedBy, 'payment.recorded', $payment, [
                'or_number' => $orNumber, 'amount' => (string) $amount,
            ]);

            return $payment;
        });
    }

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

            AuditLog::record($by, 'payment.voided', $payment, [
                'or_number' => $payment->or_number,
                'amount' => (string) $payment->amount,
                'reason' => trim($reason),
            ]);
        });
    }
}
