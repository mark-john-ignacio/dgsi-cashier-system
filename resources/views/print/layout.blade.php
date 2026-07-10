<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 13px; margin: 2rem; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 0; }
        .sub { text-align: center; color: #555; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; text-align: left; }
        .right { text-align: right; }
        .total { font-weight: bold; }
        .void { text-decoration: line-through; color: #999; }
        .no-print { margin-top: 1.5rem; }
        @media print { .no-print { display: none; } body { margin: 0.5rem; } }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <h1>DEI GRATIA SCHOOL, INC.</h1>
    <p class="sub">@yield('subtitle')</p>
    @yield('content')
    <div class="no-print"><button onclick="window.print()">Print</button></div>
</body>
</html>
