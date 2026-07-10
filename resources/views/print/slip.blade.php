@extends('print.layout')
@section('title', 'Payment Acknowledgment — OR '.$payment->or_number)
@section('subtitle', 'Payment Acknowledgment Slip (official receipt: OR No. '.$payment->or_number.')')
@section('content')
    <table>
        <tr><td>Student</td><td>{{ $payment->enrollment->student->full_name }} ({{ $payment->enrollment->student->student_no }})</td></tr>
        <tr><td>Grade & Section</td><td>{{ $payment->enrollment->grade_level }} {{ $payment->enrollment->section }}</td></tr>
        <tr><td>School Year</td><td>{{ $payment->enrollment->schoolYear->name }}</td></tr>
        <tr><td>Payment Date</td><td>{{ $payment->payment_date->format('F d, Y') }}</td></tr>
        <tr><td>Method</td><td>{{ strtoupper($payment->method) }}</td></tr>
        <tr><td>Received By</td><td>{{ $payment->receivedBy->name }}</td></tr>
    </table>
    <table>
        <thead><tr><th>Applied To</th><th class="right">Amount</th></tr></thead>
        @foreach ($payment->allocations as $allocation)
            <tr><td>{{ $allocation->ledgerEntry->description }}</td><td class="right">₱{{ number_format($allocation->amount, 2) }}</td></tr>
        @endforeach
        @if ($payment->unallocatedAmount() > 0.005)
            <tr><td>Advance / Credit</td><td class="right">₱{{ number_format($payment->unallocatedAmount(), 2) }}</td></tr>
        @endif
        <tr class="total"><td>Total {{ $payment->isVoided() ? '(VOIDED)' : '' }}</td><td class="right">₱{{ number_format($payment->amount, 2) }}</td></tr>
    </table>
    <p>Remaining balance: <strong>₱{{ number_format($payment->enrollment->balance(), 2) }}</strong></p>
@endsection
