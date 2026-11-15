@php
    $m = fn (int $cents) => \App\Support\Money::format($cents);
    $n = fn (int $c) => $c === 0 ? '–' : number_format($c / 100, 2, '.', ' ');
    $showYtd = $data['show_ytd'];
    $b = $data['budget'] !== null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $entity->name }} – {{ $title }}</title>
<style>
    @page { margin: 30px 36px 44px 36px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #222; }
    h1 { font-size: 17px; margin: 0 0 2px 0; }
    h2 { font-size: 11px; margin: 16px 0 4px 0; padding-bottom: 2px; border-bottom: 1.5px solid #b91c1c; }
    .muted { color: #666; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 8px; color: #555; border-bottom: 1px solid #999; padding: 3px 4px; }
    td { padding: 3px 4px; border-bottom: 0.5px solid #ddd; }
    th.num, td.num { text-align: right; }
    td.code { color: #888; width: 34px; }
    td.neg { color: #b91c1c; }
    tr.total td { font-weight: bold; border-top: 1px solid #333; border-bottom: 1px solid #333; background: #f3f3f3; }
    .box { border: 1px solid #ccc; padding: 6px 8px; margin-top: 8px; }
    .ok { color: #166534; }
    .bad { color: #b91c1c; font-weight: bold; }
    .sum td { font-size: 11px; border: none; padding: 4px 8px; }
</style>
</head>
<body>

<h1>{{ $entity->name }}</h1>
<div class="muted">
    @if ($entity->parent) {{ $entity->parent->name }} &nbsp;·&nbsp; @endif
    @if ($entity->number) No. {{ $entity->number }} &nbsp;·&nbsp; @endif
    Management accounts: <strong>{{ $title }}</strong>
</div>

<div class="box">
    @if ($closed === true)
        <span class="ok">This month has been closed.</span>
    @elseif ($closed === false)
        <span class="bad">This month is not yet closed</span> – figures may still change.
    @endif
    @if ($data['balanced'])
        <span class="ok">Bank and cash balances agree with fund balances.</span>
    @else
        <span class="bad">Bank and cash balances do NOT agree with fund balances – please check opening balances.</span>
    @endif
    <br>
    @if ($b)
        Budget: version {{ $data['budget']['version'] }}, approved {{ $data['budget']['approved_at']?->format('d M Y') }}.
        Variance: positive is better than budget, negative is worse.
    @else
        <span class="muted">No approved budget for this year.</span>
    @endif
</div>

<h2>Summary</h2>
<table class="sum">
    <tr>
        <td>Money held at start<br><strong>{{ $m($data['accounts']['totals']['opening']) }}</strong></td>
        <td>Receipts<br><strong>{{ $m($data['accounts']['totals']['receipts']) }}</strong></td>
        <td>Payments<br><strong>{{ $m($data['accounts']['totals']['payments']) }}</strong></td>
        <td>Money held at end<br><strong>{{ $m($data['accounts']['totals']['closing']) }}</strong></td>
    </tr>
</table>

<h2>Receipts (money in)</h2>
@include('reports.partials.statement-section', ['block' => $data['income'], 'kind' => 'income', 'totalLabel' => 'Total receipts'])

<h2>Payments (money out)</h2>
@include('reports.partials.statement-section', ['block' => $data['expenditure'], 'kind' => 'expense', 'totalLabel' => 'Total payments', 'surplus' => $data['surplus']])

<h2>Where the money is (bank, cash &amp; investments)</h2>
<table>
    <tr>
        <th>Account</th><th class="num">Opening</th><th class="num">Receipts</th>
        <th class="num">Payments</th><th class="num">Moved</th><th class="num">Closing</th>
    </tr>
    @foreach ($data['accounts']['rows'] as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td class="num">{{ $m($row['opening']) }}</td><td class="num">{{ $m($row['receipts']) }}</td>
            <td class="num">{{ $m($row['payments']) }}</td><td class="num">{{ $m($row['transfers']) }}</td>
            <td class="num">{{ $m($row['closing']) }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td>Total</td>
        @foreach (['opening', 'receipts', 'payments', 'transfers', 'closing'] as $k)
            <td class="num">{{ $m($data['accounts']['totals'][$k]) }}</td>
        @endforeach
    </tr>
</table>

<h2>Fund balances (whose money it is)</h2>
<table>
    <tr>
        <th>Fund</th><th class="num">Opening</th><th class="num">Income</th>
        <th class="num">Expenditure</th><th class="num">Moved</th><th class="num">Closing</th>
    </tr>
    @foreach ($data['funds']['rows'] as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td class="num">{{ $m($row['opening']) }}</td><td class="num">{{ $m($row['receipts']) }}</td>
            <td class="num">{{ $m($row['payments']) }}</td><td class="num">{{ $m($row['transfers']) }}</td>
            <td class="num">{{ $m($row['closing']) }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td>Total</td>
        @foreach (['opening', 'receipts', 'payments', 'transfers', 'closing'] as $k)
            <td class="num">{{ $m($data['funds']['totals'][$k]) }}</td>
        @endforeach
    </tr>
</table>

<p class="muted" style="margin-top: 14px;">
    Prepared {{ $preparedAt->format('d M Y H:i') }} by {{ $preparedBy }} from the cash book.
    Figures are taken directly from recorded entries; corrections appear as reversing entries.
</p>

<script type="text/php">
    if (isset($pdf)) {
        $pdf->page_text($pdf->get_width() - 100, $pdf->get_height() - 37, "Page {PAGE_NUM} of {PAGE_COUNT}", null, 8, [0.4, 0.4, 0.4]);
    }
</script>

</body>
</html>