@php
    $n = fn (int $c) => $c === 0 ? '–' : number_format($c / 100, 2, '.', ' ');
    $t = $data['totals'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $entity->name }} – society assessments {{ $data['month_label'] }}</title>
<style>
    @page { margin: 30px 30px 44px 30px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #222; }
    h1 { font-size: 17px; margin: 0 0 2px 0; }
    h2 { font-size: 11px; margin: 14px 0 4px 0; padding-bottom: 2px; border-bottom: 1.5px solid #b91c1c; }
    .muted { color: #666; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 7.5px; color: #555; border-bottom: 1px solid #999; padding: 3px 4px; }
    td { padding: 3px 4px; border-bottom: 0.5px solid #ddd; }
    th.num, td.num { text-align: right; }
    td.neg { color: #b91c1c; font-weight: bold; }
    tr.total td { font-weight: bold; border-top: 1px solid #333; border-bottom: 1px solid #333; background: #f3f3f3; }
    .box { border: 1px solid #ccc; padding: 6px 8px; margin-top: 8px; }
    .ok { color: #166534; }
    .bad { color: #b91c1c; font-weight: bold; }
    .warn { color: #b45309; }
</style>
</head>
<body>

<h1>{{ $entity->name }}</h1>
<div class="muted">
    @if ($entity->parent) {{ $entity->parent->name }} &nbsp;·&nbsp; @endif
    @if ($entity->number) Circuit No. {{ $entity->number }} &nbsp;·&nbsp; @endif
    <strong>Society assessments – up to {{ $data['month_label'] }}</strong>
</div>

<div class="box">
    @if ($closed)
        <span class="ok">This month has been closed.</span>
    @else
        <span class="bad">This month is not yet closed</span> – figures may still change.
    @endif
    Budget version {{ $data['budget']['version'] }}, approved {{ $data['budget']['approved_at']?->format('d M Y') }}.
    <br>Balance: a negative amount means the society owes the circuit; a positive amount means it has paid ahead.
    @foreach ($data['issues'] as $issue)
        <div class="warn">• {{ $issue['text'] }}</div>
    @endforeach
</div>

<h2>Summary by society</h2>
<table>
    <tr>
        <th>Society</th><th class="num">Annual assessment</th><th class="num">Brought forward</th>
        <th class="num">Due this month</th><th class="num">Paid this month</th>
        <th class="num">Due year to date</th><th class="num">Paid year to date</th>
        <th class="num">Balance</th><th>Statement email</th>
    </tr>
    @foreach ($data['societies'] as $s)
        <tr>
            <td>{{ $s['name'] }}</td>
            <td class="num">{{ $n($s['annual']) }}</td>
            <td class="num">{{ $n($s['brought_forward']) }}</td>
            <td class="num">{{ $n($s['month_due']) }}</td>
            <td class="num">{{ $n($s['month_received']) }}</td>
            <td class="num">{{ $n($s['ytd_due']) }}</td>
            <td class="num">{{ $n($s['ytd_received']) }}</td>
            <td class="num {{ $s['balance'] < 0 ? 'neg' : '' }}">{{ $n($s['balance']) }}</td>
            <td class="{{ $s['emails'] ? 'muted' : 'bad' }}">{{ $s['emails'] ? 'on file' : 'MISSING' }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td>Total</td>
        <td class="num">{{ $n($t['annual']) }}</td>
        <td class="num">{{ $n($t['brought_forward']) }}</td>
        <td class="num">{{ $n($t['month_due']) }}</td>
        <td class="num">{{ $n($t['month_received']) }}</td>
        <td class="num">{{ $n($t['ytd_due']) }}</td>
        <td class="num">{{ $n($t['ytd_received']) }}</td>
        <td class="num">{{ $n($t['balance']) }}</td>
        <td></td>
    </tr>
</table>

<p class="muted" style="margin-top: 14px;">
    Prepared {{ $preparedAt->format('d M Y H:i') }} by {{ $preparedBy }} from the cash book and the approved budget.
</p>

<script type="text/php">
    if (isset($pdf)) {
        $pdf->page_text($pdf->get_width() - 100, $pdf->get_height() - 37, "Page {PAGE_NUM} of {PAGE_COUNT}", null, 8, [0.4, 0.4, 0.4]);
    }
</script>

</body>
</html>