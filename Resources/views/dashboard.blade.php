{{-- Dashboard, Freshdesk clone: tiles, "Today's trends" (chart + 4 KPIs), widgets, then the list of
     unresolved tickets (kept on purpose). Stats: Services\Dashboard (1 min cache). --}}
@php
    $D = '\Modules\ModernUi\Services\Dashboard';
    $s = $stats['stats'];
    $kpis = [
        [__('Resolved'), $s['resolved'][0], $s['resolved'][1], (string)$s['resolved'][0], true],
        [__('Received'), $s['received'][0], $s['received'][1], (string)$s['received'][0], false],
        [__('Average first response time'), $s['frt'][0], $s['frt'][1], $D::duration($s['frt'][0]), false],
        [__('Resolved within SLA'), $s['sla'][0], $s['sla'][1], $s['sla'][0] === null ? '--' : $s['sla'][0].'%', true],
    ];
@endphp
<div class="mu-dash">
    <div class="mu-tiles">
        @foreach ($tiles as $tile)
            <a href="{{ $tile['url'] }}" class="mu-tile mu-tile-{{ $tile['key'] }} @if (!$tile['count']) mu-tile-zero @endif">
                <span class="mu-tile-label">{{ $tile['label'] }}</span>
                <span class="mu-tile-count">{{ $tile['count'] }}</span>
            </a>
        @endforeach
    </div>

    <div class="mu-card mu-trends">
        <div class="mu-trends-chart">
            <div class="mu-card-title">{{ __('Today\'s trends') }}</div>
            {!! $D::chart($stats['h_today'], $stats['h_yest'], $stats['now_h']) !!}
            <div class="mu-chart-legend"><span class="mu-lg-today">{{ __('Today') }}</span><span class="mu-lg-yest">{{ __('Yesterday') }}</span></div>
            <div class="mu-chart-axis">{{ __('Date created - Hour of the day') }}</div>
        </div>
        <div class="mu-trends-kpis">
            @foreach ($kpis as $k)
                @php
                    [$dtxt, $dir] = $D::delta($k[1], $k[2]);
                    // an increase is good for Resolved / SLA; for Received and response time, an increase is bad
                    $good = $dir === null ? null : (($dir === 'up') === $k[4]);
                @endphp
                <div class="mu-kpi">
                    <div class="mu-kpi-label">{{ $k[0] }}</div>
                    <div class="mu-kpi-value">{{ $k[3] }}@if ($dtxt)<span class="mu-kpi-delta {{ $good ? 'good' : 'bad' }}"><i class="mu-kpi-arrow {{ $dir }}"></i>{{ $dtxt }}</span>@endif</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mu-widgets">
        <div class="mu-card mu-widget">
            <div class="mu-widget-head">
                <div><div class="mu-card-title">{{ __('Unresolved tickets') }}</div><div class="mu-widget-sub">{{ __('Browse the helpdesk') }}</div></div>
                <a href="{{ route('modernui.tickets', ['mailbox_id' => $mailbox->id, 'view' => 'unresolved']) }}">{{ __('View details') }}</a>
            </div>
            <div class="mu-widget-row mu-widget-th"><span>{{ __('Agent') }}</span><span>{{ __('modernui::labels.open') }}</span></div>
            @forelse ($stats['by_agent'] as $a)
                <a class="mu-widget-row" href="{{ route('modernui.tickets', ['mailbox_id' => $mailbox->id, 'view' => 'unresolved']) }}?{{ http_build_query(['agent' => [$a['user_id'] ?: -1]]) }}"><span>{{ $a['name'] }}</span><span class="mu-widget-n">{{ $a['n'] }}</span></a>
            @empty
                <div class="mu-widget-row"><span class="text-help">{{ __('No unresolved tickets') }}</span></div>
            @endforelse
        </div>
        <div class="mu-card mu-widget">
            <div class="mu-widget-head">
                <div><div class="mu-card-title">{{ __('Undelivered e-mails') }}</div><div class="mu-widget-sub">{{ __('Browse the helpdesk') }}</div></div>
                <a href="{{ route('modernui.tickets', ['mailbox_id' => $mailbox->id, 'view' => 'undelivered']) }}">{{ __('View details') }}</a>
            </div>
            <div class="mu-widget-row mu-widget-th"><span>{{ __('Mailbox') }}</span><span>{{ __('Undelivered') }}</span></div>
            <div class="mu-widget-row"><span>{{ $mailbox->name }}</span><span class="mu-widget-n">{{ $stats['undelivered'] }}</span></div>
        </div>
    </div>

    <div class="mu-card mu-dash-list">
        <div class="mu-dash-list-heading">
            <h3>{{ __('Unresolved tickets') }} <small>{{ $conversations->total() }}</small></h3>
            <a href="{{ $all_url }}" class="mu-btn">{{ __('View all tickets') }}</a>
        </div>
        @include('conversations/conversations_table', ['conversations' => $conversations, 'folder' => $folder, 'params' => [], 'mailbox' => $mailbox])
    </div>
</div>
