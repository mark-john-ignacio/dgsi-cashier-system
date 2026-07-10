@extends('print.layout')
@section('title', 'Statement of Account — '.$enrollment->student->full_name)
@section('subtitle', 'Statement of Account — SY '.$enrollment->schoolYear->name)
@section('content')
    <p><strong>{{ $enrollment->student->full_name }}</strong> ({{ $enrollment->student->student_no }})
       — {{ $enrollment->grade_level }} {{ $enrollment->section }}</p>
    <table>
        <thead><tr><th>Assessment</th><th class="right">Amount</th></tr></thead>
        @foreach ($enrollment->ledgerEntries as $entry)
            <tr @if ($entry->voided_at) class="void" @endif>
                <td>{{ $entry->description }}</td><td class="right">₱{{ number_format($entry->amount, 2) }}</td></tr>
        @endforeach
        <tr class="total"><td>Total Assessed</td><td class="right">₱{{ number_format($enrollment->totalAssessed(), 2) }}</td></tr>
    </table>
    <table>
        <thead><tr><th>OR No.</th><th>Date</th><th>Method</th><th class="right">Amount</th></tr></thead>
        @forelse ($enrollment->payments as $payment)
            <tr @if ($payment->isVoided()) class="void" @endif>
                <td>{{ $payment->or_number }}{{ $payment->isVoided() ? ' (VOIDED)' : '' }}</td>
                <td>{{ $payment->payment_date->format('M d, Y') }}</td>
                <td>{{ strtoupper($payment->method) }}</td>
                <td class="right">₱{{ number_format($payment->amount, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No payments recorded.</td></tr>
        @endforelse
        <tr class="total"><td colspan="3">Total Paid</td><td class="right">₱{{ number_format($enrollment->totalPaid(), 2) }}</td></tr>
        <tr class="total"><td colspan="3">BALANCE</td><td class="right">₱{{ number_format($enrollment->balance(), 2) }}</td></tr>
    </table>
@endsection
