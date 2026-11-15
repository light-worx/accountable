@php
    // Renders the number cells of one row (a line, a total or the surplus). Positive variance = better than budget.
    $cells = function (array $row, string $sign) use ($b, $showYtd, $n) {
        $v = fn (int $actual, int $budget) => $sign === 'income' ? $actual - $budget : $budget - $actual;
        $td = fn (int $c, bool $variance = false) => '<td class="num' . ($variance && $c < 0 ? ' neg' : '') . '">' . e($n($c)) . '</td>';

        $html = $td($row['period']);

        if ($b) {
            $html .= $td($row['budget']) . $td($v($row['period'], $row['budget']), true);
        }

        if ($showYtd) {
            $html .= $td($row['ytd']);

            if ($b) {
                $html .= $td($row['budget_ytd']) . $td($v($row['ytd'], $row['budget_ytd']), true) . $td($row['budget_annual']);
            }
        }

        return $html;
    };

    $colspan = 2 + 1 + ($b ? 2 : 0) + ($showYtd ? 1 + ($b ? 3 : 0) : 0);
@endphp
<table>
    <tr>
        <th></th><th>Type</th>
        <th class="num">{{ $showYtd ? 'This month' : 'Actual' }}</th>
        @if ($b)<th class="num">Budget</th><th class="num">Variance</th>@endif
        @if ($showYtd)
            <th class="num">Year to date</th>
            @if ($b)<th class="num">YTD budget</th><th class="num">YTD variance</th><th class="num">Annual budget</th>@endif
        @endif
    </tr>
    @forelse ($block['lines'] as $line)
        <tr>
            <td class="code">{{ $line['code'] }}</td><td>{{ $line['name'] }}</td>
            {!! $cells($line, $kind) !!}
        </tr>
    @empty
        <tr><td></td><td class="muted" colspan="{{ $colspan - 1 }}">None in this period.</td></tr>
    @endforelse
    <tr class="total">
        <td></td><td>{{ $totalLabel }}</td>
        {!! $cells($block, $kind) !!}
    </tr>
    @isset($surplus)
        <tr class="total">
            <td></td><td>Surplus / (deficit) for the period</td>
            {!! $cells($surplus, 'income') !!}
        </tr>
    @endisset
</table>