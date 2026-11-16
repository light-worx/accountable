@php
    $n = fn (int $c) => $c === 0 ? '–' : number_format($c / 100, 2, '.', ' ');
    $balance = $s['balance'];
    $headline = $balance < 0
        ? 'Amount owing: ' . \App\Support\Money::format(-$balance)
        : ($balance > 0 ? 'Paid in advance: ' . \App\Support\Money::format($balance) : 'Your account is up to date');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $s['name'] }} – assessment statement {{ $data['month_label'] }}</title>
<style>
    @page { margin: 36px 40px 50px 40px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #222; }
    h1 { font-size: 18px; margin: 0 0 2px 0; }
    h2 { font-size: 11px; margin: 18px 0 5px 0; padding-bottom: 2px; border-bottom: 1.5px solid #b91c1c; }
    .muted { color: #666; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 8.5px; color: #555; border-bottom: 1px solid #999; padding: 4px 5px; }
    td { padding: 4px 5px; border-bottom: 0.5px solid #ddd; }
    th.num, td.num { text-align: right; }
    td.neg { color: #b91c1c; }
    tr.current td { font-weight: bold; background: #f3f3f3; }
    .headline { font-size: 15px; font-weight: bold; margin: 10px 0 4px 0; }
    .owing { color: #b91c1c; }
    .box { border: 1px solid #ccc; padding: 7px 9px; margin-top: 10px; }
    .bad { color: #b91c1c; font-weight: bold; }
    .sum td { border: none; font-size: 11px; padding: 4px 8px 4px 0; }
</style>
</head>
<body>

<h1>Assessment statement</h1>
<div><strong>{{ $s['name'] }}</strong></div>
<div class="muted">
    From {{ $entity->name }}@if ($entity->number) (Circuit No. {{ $entity->number }})@endif
    &nbsp;·&nbsp; as at {{ $data['as_at']->format('d F Y') }}
</div>

@unless ($closed)
    <div class="box"><span class="bad">Draft:</span> {{ $data['month_label'] }} has not been closed yet, so figures may still change.</div>
@endunless

<div class="headline {{ $balance < 0 ? 'owing' : '' }}">{{ $headline }}</div>

<table class="sum">
    <tr>
        <td class="muted">Brought forward at 1 January<br><strong>{{ $n($s['brought_forward']) }}</strong></td>
        <td class="muted">Due year to date<br><strong>{{ $n($s['ytd_due']) }}</strong></td>
        <td class="muted">Paid year to date<br><strong>{{ $n($s['ytd_received']) }}</strong></td>
        <td class="muted">Annual assessment {{ $data['year'] }}<br><strong>{{ $n($s['annual']) }}</strong></td>
    </tr>
</table>

<h2>Month by month</h2>
<table>
    <tr><th>Month</th><th class="num">Due</th><th class="num">Paid</th><th class="num">Balance</th></tr>
    @foreach ($s['grid'] as $row)
        <tr class="{{ $row['number'] === $data['month'] ? 'current' : '' }}">
            <td>{{ $row['month'] }}</td>
            <td class="num">{{ $n($row['due']) }}</td>
            <td class="num">{{ $n($row['received']) }}</td>
            <td class="num {{ $row['balance'] < 0 ? 'neg' : '' }}">{{ $n($row['balance']) }}</td>
        </tr>
    @endforeach
</table>
<p class="muted">The balance carries forward month to month, starting from the brought-forward figure. A negative balance means an amount is owing.</p>

<h2>Payments received in {{ $data['month_label'] }}</h2>
<table>
    <tr><th>Date</th><th>Reference</th><th>Details</th><th class="num">Amount</th></tr>
    @forelse ($s['receipts'] as $r)
        <tr>
            <td>{{ $r['date']->format('d M Y') }}</td><td>{{ $r['reference'] }}</td>
            <td>{{ $r['description'] }}</td><td class="num">{{ $n($r['amount']) }}</td>
        </tr>
    @empty
        <tr><td colspan="4" class="muted">No payments were received this month.</td></tr>
    @endforelse
</table>

<p class="muted" style="margin-top: 16px;">
    If you have made a payment that is not shown here, please contact the circuit treasurer.
    Prepared {{ $preparedAt->format('d M Y') }} from the circuit’s records.
</p>

<script type="text/php">
    if (isset($pdf)) {
        $pdf->page_text($pdf->get_width() - 100, $pdf->get_height() - 40, "Page {PAGE_NUM} of {PAGE_COUNT}", null, 8, [0.4, 0.4, 0.4]);
    }
</script>

</body>
</html>