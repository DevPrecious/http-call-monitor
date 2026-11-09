@php
    use HttpCallMonitor\HostStats;
    use Illuminate\Support\Carbon;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>HTTP Call Monitor</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f6f6f7; --panel: #fff; --text: #1b1c20; --muted: #686c74; --line: #e3e4e8;
            --bad: #b42318; --warn: #8a5a00;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #131417; --panel: #1b1c20; --text: #ececef; --muted: #9b9fa8; --line: #2d2f35;
                --bad: #ff8a80; --warn: #e6b450;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 1040px; margin: 0 auto; padding: 32px 20px 56px; }
        header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        h1 { margin: 0; font-size: 20px; font-weight: 600; }
        h2 { margin: 32px 0 10px; font-size: 14px; font-weight: 600; }
        nav { display: flex; gap: 4px; }
        nav a { padding: 4px 10px; border-radius: 6px; color: var(--muted); text-decoration: none; }
        nav a:hover { color: var(--text); }
        nav a[aria-current] { background: var(--panel); color: var(--text); box-shadow: inset 0 0 0 1px var(--line); }
        .panel { overflow-x: auto; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 9px 14px; text-align: left; white-space: nowrap; border-bottom: 1px solid var(--line); }
        tr:last-child td { border-bottom: 0; }
        th { color: var(--muted); font-size: 12px; font-weight: 500; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .bad { color: var(--bad); font-weight: 600; }
        .warn { color: var(--warn); font-weight: 600; }
        .detail { white-space: normal; min-width: 240px; word-break: break-word; }
        .error { display: block; color: var(--muted); font-size: 12px; }
        .empty { margin: 0; padding: 28px 14px; color: var(--muted); text-align: center; }
        .note { margin: 10px 0 0; color: var(--muted); font-size: 12px; }
    </style>
</head>
<body>
<main>
    <header>
        <h1>HTTP Call Monitor</h1>
        <nav aria-label="Time window">
            @foreach ($windows as $value => $label)
                <a href="?hours={{ $value }}" @if ($value === $hours) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </header>

    <h2>Third-party APIs</h2>
    <div class="panel">
        @if ($hosts === [])
            <p class="empty">No calls recorded in this time window.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th scope="col">Host</th>
                        <th scope="col" class="num">Calls</th>
                        <th scope="col" class="num">Failure rate</th>
                        <th scope="col" class="num">4xx</th>
                        <th scope="col" class="num">5xx</th>
                        <th scope="col" class="num">No response</th>
                        <th scope="col" class="num">p50</th>
                        <th scope="col" class="num">p95</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($hosts as $host)
                        <tr>
                            <td>{{ $host->host }}</td>
                            <td class="num">{{ number_format($host->calls) }}</td>
                            <td @class(['num', 'bad' => $host->failureRate() >= 5, 'warn' => $host->failureRate() >= 1 && $host->failureRate() < 5])>{{ number_format($host->failureRate(), 1) }}%</td>
                            <td class="num">{{ number_format($host->clientErrors) }}</td>
                            <td class="num">{{ number_format($host->serverErrors) }}</td>
                            <td class="num">{{ number_format($host->noResponse) }}</td>
                            <td class="num">{{ HostStats::formatLatency($host->p50) }}</td>
                            <td class="num">{{ HostStats::formatLatency($host->p95) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <p class="note">Failure rate counts 5xx responses and calls that got no response. 4xx responses are listed separately.</p>

    <h2>Recent failures</h2>
    <div class="panel">
        @if ($failures === [])
            <p class="empty">No failures in this time window.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Result</th>
                        <th scope="col">Request</th>
                        <th scope="col" class="num">Duration</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($failures as $failure)
                        @php $time = Carbon::parse($failure->created_at); @endphp
                        <tr>
                            <td title="{{ $time->toDateTimeString() }}">{{ $time->diffForHumans() }}</td>
                            <td class="bad">{{ $failure->status ?? 'No response' }}</td>
                            <td class="detail">
                                {{ $failure->method }} {{ $failure->host }}{{ $failure->path }}
                                @if ($failure->error)
                                    <span class="error">{{ $failure->error }}</span>
                                @endif
                            </td>
                            <td class="num">{{ HostStats::formatLatency((int) $failure->duration_ms) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</main>
</body>
</html>
