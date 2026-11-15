@php
    $n = fn (int $c) => $c === 0 ? '–' : number_format($c / 100, 2, '.', ' ');
    $prior = $data['prior_has_data'];
    $year = $data['year'];
    $form = $data['form'];
    $kind = $entity->type->getLabel();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $entity->name }} – {{ $form['title'] }} capture guide {{ $year }}</title>
<style>
    @page { margin: 30px 36px 44px 36px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #222; }
    h1 { font-size: 17px; margin: 0 0 2px 0; }
    h2 { font-size: 11px; margin: 14px 0 4px 0; padding-bottom: 2px; border-bottom: 1.5px solid #b91c1c; }
    .muted { color: #666; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 8px; color: #555; border-bottom: 1px solid #999; padding: 3px 4px; }
    td { padding: 3px 4px; border-bottom: 0.5px solid #ddd; }
    th.num, td.num { text-align: right; }
    td.code { color: #666; width: 40px; }
    td.right { text-align: right; }
    tr.head td { background: #ececec; font-weight: bold; border-bottom: 0.5px solid #bbb; }
    tr.sub td { font-weight: bold; border-top: 0.5px solid #888; }
    tr.total td { font-weight: bold; border-top: 1px solid #333; border-bottom: 1px solid #333; background: #f3f3f3; }
    .box { border: 1px solid #ccc; padding: 6px 8px; margin-top: 8px; }
    .ok { color: #166534; font-weight: bold; }
    .bad { color: #b91c1c; font-weight: bold; }
    .warn { color: #b45309; }
    .newpage { page-break-before: always; }
    .sign { margin-top: 22px; border: 1px solid #999; padding: 10px; }
</style>
</head>
<body>

<h1>{{ $entity->name }}</h1>
<div class="muted">
    @if ($entity->parent) {{ $entity->parent->name }} &nbsp;·&nbsp; @endif
    @if ($entity->number) {{ $kind }} No. {{ $entity->number }} &nbsp;·&nbsp; @endif
    <strong>{{ $form['title'] }} capture guide – year ended 31 December {{ $year }}</strong>
</div>
<div class="muted" style="margin-top: 2px;">
    For recording on the MCO system. Amounts are in rand. Sub-totals and totals are shown so you can check what the MCO screen calculates.
    This is an internal record, not the official form.
</div>

<div class="box">
    @if ($data['ready'])
        <span class="ok">No blocking problems found.</span>
    @else
        <span class="bad">Needs attention before this is captured.</span>
    @endif
    @foreach ($data['issues'] as $issue)
        <div class="{{ $issue['level'] === 'error' ? 'bad' : 'warn' }}">• {{ $issue['text'] }}</div>
    @endforeach
</div>

<h2>Section 3 – Income</h2>
<table>
    <tr><th>Line</th><th>Description</th><th class="num">{{ $year }}</th>@if ($prior)<th class="num">{{ $year - 1 }}</th>@endif</tr>
    @include('reports.partials.4s-rows', ['rows' => $data['income']['rows'], 'n' => $n, 'prior' => $prior])
    <tr class="total">
        <td></td><td class="right">TOTAL INCOME FOR THE PERIOD</td>
        <td class="num">{{ $n($data['income']['total']) }}</td>
        @if ($prior)<td class="num">{{ $n($data['income']['prior']) }}</td>@endif
    </tr>
</table>

<div class="newpage"></div>
<h2>Section 4 – Expenditure</h2>
<table>
    <tr><th>Line</th><th>Description</th><th class="num">{{ $year }}</th>@if ($prior)<th class="num">{{ $year - 1 }}</th>@endif</tr>
    @include('reports.partials.4s-rows', ['rows' => $data['expenditure']['rows'], 'n' => $n, 'prior' => $prior])
    <tr class="total">
        <td></td><td class="right">TOTAL EXPENDITURE FOR THE PERIOD</td>
        <td class="num">{{ $n($data['expenditure']['total']) }}</td>
        @if ($prior)<td class="num">{{ $n($data['expenditure']['prior']) }}</td>@endif
    </tr>
    <tr class="total">
        <td></td><td class="right">SURPLUS / (DEFICIT) FOR THE PERIOD</td>
        <td class="num">{{ $n($data['surplus']) }}</td>
        @if ($prior)<td class="num">{{ $n($data['income']['prior'] - $data['expenditure']['prior']) }}</td>@endif
    </tr>
</table>

<div class="newpage"></div>
<h2>“Other / specify” detail pages</h2>
@forelse ($data['continuation'] as $block)
    <table style="margin-bottom: 8px;">
        <tr><th colspan="2">{{ $block['code'] }} – {{ $block['title'] }}</th><th class="num">Amount</th></tr>
        @foreach ($block['items'] as $item)
            <tr><td class="code"></td><td>{{ $item['name'] }}</td><td class="num">{{ $n($item['amount']) }}</td></tr>
        @endforeach
        <tr class="sub"><td></td><td class="right">Total carried to line {{ $block['code'] }}</td><td class="num">{{ $n($block['total']) }}</td></tr>
    </table>
@empty
    <p class="muted">Nothing to specify.</p>
@endforelse

<table style="margin-top: 6px;">
    <tr><th colspan="4">{{ $form['gifts_heading'] }}</th><th class="num">Amount</th></tr>
    <tr><th>Name</th><th>PF No.</th><th colspan="2">Description</th><th></th></tr>
    @forelse ($data['gifts'] as $row)
        <tr><td>{{ $row['name'] }}</td><td>{{ $row['pf'] ?: '—' }}</td><td colspan="2">{{ $row['description'] }}</td><td class="num">{{ $n($row['amount']) }}</td></tr>
    @empty
        <tr><td colspan="5" class="muted">None.</td></tr>
    @endforelse
</table>

<div class="newpage"></div>
<h2>Section 5 – Monies held as specific funds</h2>
<table>
    <tr>
        <th>Name of fund</th><th class="num">At 1 Jan</th><th class="num">Income</th>
        <th class="num">Expenditure</th><th class="num">Moved between funds</th><th class="num">At 31 Dec</th>
    </tr>
    @forelse ($data['funds'] as $row)
        <tr>
            <td>{{ $row['name'] }}</td><td class="num">{{ $n($row['opening']) }}</td><td class="num">{{ $n($row['receipts']) }}</td>
            <td class="num">{{ $n($row['payments']) }}</td><td class="num">{{ $n($row['transfers']) }}</td><td class="num">{{ $n($row['closing']) }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No specific funds.</td></tr>
    @endforelse
</table>
<p class="muted">Check with the MCO guidelines how transfers between funds should be shown; the form has no separate column for them.</p>

<h2>Section 6 – Investments</h2>
<table>
    <tr>
        <th>Investment account</th><th>Account no.</th><th class="num">At 1 Jan</th>
        <th class="num">Deposits / interest</th><th class="num">Withdrawals / charges</th><th class="num">At 31 Dec</th>
    </tr>
    @forelse ($data['investments'] as $row)
        <tr>
            <td>{{ $row['name'] }}</td><td>{{ $row['account_number'] ?? '' }}</td><td class="num">{{ $n($row['opening']) }}</td>
            <td class="num">{{ $n($row['deposits']) }}</td><td class="num">{{ $n($row['withdrawals']) }}</td><td class="num">{{ $n($row['closing']) }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No investment accounts.</td></tr>
    @endforelse
</table>

<h2>Section 8 – Pastoral allowances paid by the {{ strtolower($kind) }}</h2>
<table>
    <tr><th>Line</th><th>Name</th><th>PF No.</th><th>Type of allowance</th><th class="num">Amount</th></tr>
    @forelse ($data['pastoral']['rows'] as $row)
        <tr>
            <td class="code">{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['pf'] ?: '—' }}</td>
            <td>{{ $row['description'] }}</td><td class="num">{{ $n($row['amount']) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No pastoral payments recorded.</td></tr>
    @endforelse
    <tr class="total"><td colspan="4" class="right">Total</td><td class="num">{{ $n($data['pastoral']['total']) }}</td></tr>
    <tr><td colspan="4" class="right">{{ $form['pastoral_label'] }}</td><td class="num">{{ $n($data['pastoral']['reference_total']) }}</td></tr>
    <tr><td colspan="4" class="right">Difference</td><td class="num">{{ $n($data['pastoral']['total'] - $data['pastoral']['reference_total']) }}</td></tr>
</table>

<h2>Reference figures at 31 December {{ $year }} (for page 1)</h2>
<table>
    <tr><td>Bank balance (1.1) – total of bank accounts</td><td class="num">{{ $n($data['reference']['bank']) }}</td></tr>
    <tr><td>Cash / petty cash on hand (1.9)</td><td class="num">{{ $n($data['reference']['cash']) }}</td></tr>
    <tr><td>Investments (1.3)</td><td class="num">{{ $n($data['reference']['investments']) }}</td></tr>
    <tr><td>Monies held as specific funds (1.4)</td><td class="num">{{ $n($data['reference']['specific_funds']) }}</td></tr>
    <tr><td>General Fund</td><td class="num">{{ $n($data['reference']['general_fund']) }}</td></tr>
</table>
<p class="muted">
    Loans (1.2, 1.5), creditors (1.6), debtors (1.7) and assessments owing (1.8) are not recorded in this system yet,
    so the full page 1 and the Section 2 accumulated-funds calculation are not produced here.
    @if ($form['code'] === '4C')
        The signed Section 2 certificate and the Section 10 consolidation of societies' returns are not produced yet either.
    @endif
</p>

<div class="sign">
    Captured on the MCO system by ________________________ &nbsp;&nbsp; Date ______________ &nbsp;&nbsp;
    Audited / approved at Circuit Quarterly Meeting on ______________
</div>

<p class="muted" style="margin-top: 10px;">
    Prepared {{ $preparedAt->format('d M Y H:i') }} by {{ $preparedBy }} from the cash book.
</p>

<script type="text/php">
    if (isset($pdf)) {
        $pdf->page_text(490, 805, "Page {PAGE_NUM} of {PAGE_COUNT}", null, 8, [0.4, 0.4, 0.4]);
    }
</script>

</body>
</html>