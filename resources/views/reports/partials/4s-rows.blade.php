@foreach ($rows as $row)
    @if ($row['type'] === 'heading')
        <tr class="head"><td class="code">{{ $row['code'] }}</td><td colspan="{{ $prior ? 3 : 2 }}">{{ $row['label'] }}</td></tr>
    @elseif ($row['type'] === 'line')
        <tr>
            <td class="code">{{ $row['code'] }}</td>
            <td>{{ $row['label'] }}</td>
            <td class="num">{{ $n($row['amount']) }}</td>
            @if ($prior)<td class="num muted">{{ $n($row['prior']) }}</td>@endif
        </tr>
        @if (! empty($row['sources']))
            <tr class="src">
                <td></td>
                <td colspan="{{ $prior ? 3 : 2 }}">made up of:
                    @foreach ($row['sources'] as $source){{ $source['name'] }} {{ $n($source['amount']) }}@if (! $loop->last) + @endif @endforeach
                </td>
            </tr>
        @endif
    @else
        <tr class="sub">
            <td></td>
            <td class="right">{{ $row['label'] }}</td>
            <td class="num">{{ $n($row['amount']) }}</td>
            @if ($prior)<td class="num muted">{{ $n($row['prior']) }}</td>@endif
        </tr>
    @endif
@endforeach