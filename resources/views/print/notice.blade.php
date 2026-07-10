@extends('print.layout')
@section('title', 'Statement of Unpaid Fees')
@section('subtitle', 'Notice of Outstanding Balance')
@section('content')
    @foreach ($enrollments as $enrollment)
        <div @if (! $loop->last) class="page-break" @endif>
            <p>Date: {{ now()->format('F d, Y') }}</p>
            <p>Dear Parent/Guardian of <strong>{{ $enrollment->student->full_name }}</strong>
               ({{ $enrollment->grade_level }} {{ $enrollment->section }}, SY {{ $enrollment->schoolYear->name }}),</p>
            <p>Our records show the following outstanding fees. Kindly settle at the Cashier's Office
               or coordinate with us for a payment arrangement.</p>
            <table>
                <thead><tr><th>Fee</th><th class="right">Assessed</th></tr></thead>
                @foreach ($enrollment->ledgerEntries->whereNull('voided_at') as $entry)
                    <tr><td>{{ $entry->description }}</td><td class="right">₱{{ number_format($entry->amount, 2) }}</td></tr>
                @endforeach
                <tr><td class="total">Total Assessed</td><td class="right total">₱{{ number_format($enrollment->totalAssessed(), 2) }}</td></tr>
                <tr><td>Less: Payments</td><td class="right">₱{{ number_format($enrollment->totalPaid(), 2) }}</td></tr>
                <tr class="total"><td>BALANCE DUE</td><td class="right">₱{{ number_format($enrollment->balance(), 2) }}</td></tr>
            </table>
            <p>Please disregard this notice if payment has been made recently. Thank you.</p>
            <p style="margin-top:2rem;">_______________________<br>Cashier / Finance Office</p>
        </div>
    @endforeach
@endsection
