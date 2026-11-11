<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>آمار Punch</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=vazirmatn:400,500,700" rel="stylesheet" />
    <style>
        :root {
            --bg: #f4f6f2;
            --card: #ffffff;
            --ink: #1c2418;
            --muted: #5f6b57;
            --line: #e2e8dc;
            --green-0: #ebedf0;
            --green-1: #9be9a8;
            --green-2: #40c463;
            --green-3: #30a14e;
            --green-4: #216e39;
            --online: #1a7f37;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Vazirmatn, Tahoma, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(48,161,78,.12), transparent 40%),
                radial-gradient(circle at bottom right, rgba(33,110,57,.08), transparent 35%),
                var(--bg);
            color: var(--ink);
            min-height: 100vh;
        }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
        h1 { margin: 0; font-size: 1.75rem; }
        .sub { color: var(--muted); margin: .4rem 0 1.75rem; }
        .grid { display: grid; gap: 1.25rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 1.25rem;
            box-shadow: 0 10px 30px rgba(28,36,24,.04);
        }
        h2 { margin: 0 0 1rem; font-size: 1.05rem; }
        table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        th, td { padding: .7rem .55rem; border-bottom: 1px solid var(--line); text-align: right; }
        th { color: var(--muted); font-weight: 500; }
        .badge {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .15rem .55rem; border-radius: 999px; font-size: .78rem;
            background: #eef8ef; color: var(--online);
        }
        .badge.off { background: #f2f2f0; color: var(--muted); }
        .dot { width: .45rem; height: .45rem; border-radius: 50%; background: currentColor; }
        .heatmap-wrap { overflow-x: auto; padding-bottom: .5rem; }
        .heatmap {
            display: grid;
            grid-auto-flow: column;
            grid-template-rows: repeat(7, 12px);
            gap: 3px;
            width: max-content;
            direction: ltr;
        }
        .cell {
            width: 12px; height: 12px; border-radius: 2px;
            background: var(--green-0);
        }
        .lvl-1 { background: var(--green-1); }
        .lvl-2 { background: var(--green-2); }
        .lvl-3 { background: var(--green-3); }
        .lvl-4 { background: var(--green-4); }
        .legend { display: flex; align-items: center; gap: .35rem; color: var(--muted); font-size: .8rem; margin-top: .85rem; direction: ltr; justify-content: flex-end; }
        .log { list-style: none; margin: 0; padding: 0; display: grid; gap: .65rem; }
        .log li {
            display: grid; grid-template-columns: 8.5rem 1fr; gap: .75rem;
            padding: .65rem .75rem; border-radius: 10px; background: #f8faf6;
        }
        .log time { color: var(--muted); font-size: .85rem; }
        .pill {
            display: inline-block; padding: .1rem .45rem; border-radius: 6px;
            font-size: .75rem; margin-left: .35rem;
        }
        .pill.in { background: #e7f6ea; color: #1a7f37; }
        .pill.out { background: #f3eee6; color: #8a5a00; }
        .pill.auto { background: #eeeef8; color: #4a4a8a; }
        @media (max-width: 720px) {
            .log li { grid-template-columns: 1fr; }
            table { font-size: .82rem; }
        }
    </style>
</head>
<body>
@php
    $people = $dashboard['people'] ?? [];
    $todaySessions = $dashboard['today_sessions'] ?? [];
    $activityLog = $dashboard['activity_log'] ?? [];
    $heatmap = $dashboard['heatmap']['weeks'] ?? [];
@endphp
<div class="wrap">
    <h1>آمار کار تیم</h1>
    <p class="sub">به‌روز شده: {{ $dashboard['generated_at'] ?? '—' }} — Asia/Tehran</p>

    <div class="grid">
        <section class="card">
            <h2>خلاصه افراد</h2>
            <div style="overflow-x:auto">
                <table>
                    <thead>
                    <tr>
                        <th>نام</th>
                        <th>وضعیت</th>
                        <th>امروز</th>
                        <th>هفته</th>
                        <th>ماه</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($people as $person)
                        <tr>
                            <td>
                                {{ $person['display_name'] }}
                                @if (!empty($person['telegram_username']))
                                    <span style="color:var(--muted);font-size:.8rem">{{ '@'.$person['telegram_username'] }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($person['is_working'])
                                    <span class="badge"><span class="dot"></span> آنلاین</span>
                                @else
                                    <span class="badge off"><span class="dot"></span> آفلاین</span>
                                @endif
                            </td>
                            <td>{{ $person['today_label'] }}</td>
                            <td>{{ $person['week_label'] }}</td>
                            <td>{{ $person['month_label'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">هنوز جلسه‌ای ثبت نشده.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Activity</h2>
            <div class="heatmap-wrap">
                <div class="heatmap" title="ساعات کار روزانه">
                    @foreach ($heatmap as $week)
                        @foreach ($week as $day)
                            <div
                                class="cell lvl-{{ $day['level'] }}"
                                title="{{ $day['date'] }} — {{ number_format($day['seconds'] / 3600, 1) }}h"
                            ></div>
                        @endforeach
                    @endforeach
                </div>
            </div>
            <div class="legend">
                Less
                <span class="cell"></span>
                <span class="cell lvl-1"></span>
                <span class="cell lvl-2"></span>
                <span class="cell lvl-3"></span>
                <span class="cell lvl-4"></span>
                More
            </div>
        </section>

        <section class="card">
            <h2>جلسات امروز</h2>
            <div style="overflow-x:auto">
                <table>
                    <thead>
                    <tr>
                        <th>نام</th>
                        <th>ورود</th>
                        <th>خروج</th>
                        <th>مدت</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($todaySessions as $session)
                        <tr>
                            <td>{{ $session['name'] }}</td>
                            <td>{{ $session['in'] }}</td>
                            <td>{{ $session['out'] }}@if($session['auto_closed']) (auto)@endif</td>
                            <td>{{ $session['duration'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">جلسه‌ای برای امروز نیست.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>لاگ اکتیویتی</h2>
            <ul class="log">
                @forelse ($activityLog as $item)
                    <li>
                        <time>{{ $item['at'] }}</time>
                        <div>
                            <strong>{{ $item['name'] }}</strong>
                            <span class="pill {{ $item['type'] }}">{{ $item['label'] }}</span>
                            <span style="color:var(--muted)">{{ $item['detail'] }}</span>
                        </div>
                    </li>
                @empty
                    <li><div>لاگی وجود ندارد.</div></li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
</body>
</html>
