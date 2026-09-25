{{-- Dashboard, Freshdesk clone: tiles, "Today's trends" (chart + 4 KPIs), widgets, then the list of
     unresolved tickets (kept on purpose). Stats: Services\Dashboard (1 min cache). --}}
@php
    $D = '\Modules\Refresh\Services\Dashboard';
    $s = $stats['stats'];
    $kpis = [
        [__('Resolved'), $s['resolved'][0], $s['resolved'][1], (string)$s['resolved'][0], true],
        [__('Received'), $s['received'][0], $s['received'][1], (string)$s['received'][0], false],
        [__('Average first response time'), $s['frt'][0], $s['frt'][1], $D::duration($s['frt'][0]), false],
        [__('Resolved within SLA'), $s['sla'][0], $s['sla'][1], $s['sla'][0] === null ? '--' : $s['sla'][0].'%', true],
    ];
@endphp
<div class="rf-dash">
    <div class="rf-tiles">
        @foreach ($tiles as $tile)
            <a href="{{ $tile['url'] }}" class="rf-tile rf-tile-{{ $tile['key'] }} @if (!$tile['count']) rf-tile-zero @endif">
                <span class="rf-tile-label">{{ $tile['label'] }}</span>
                <span class="rf-tile-count">{{ $tile['count'] }}</span>
            </a>
        @endforeach
    </div>

    <div class="rf-card rf-trends">
        <div class="rf-trends-chart">
            <div class="rf-card-title">{{ __('Today\'s trends') }}</div>
            {!! $D::chart($stats['h_today'], $stats['h_yest'], $stats['now_h']) !!}
            <div class="rf-chart-legend"><span class="rf-lg-today">{{ __('Today') }}</span><span class="rf-lg-yest">{{ __('Yesterday') }}</span></div>
            <div class="rf-chart-axis">{{ __('Date created - Hour of the day') }}</div>
        </div>
        <div class="rf-trends-kpis">
            @foreach ($kpis as $k)
                @php
                    [$dtxt, $dir] = $D::delta($k[1], $k[2]);
                    // an increase is good for Resolved / SLA; for Received and response time, an increase is bad
                    $good = $dir === null ? null : (($dir === 'up') === $k[4]);
                @endphp
                <div class="rf-kpi">
                    <div class="rf-kpi-label">{{ $k[0] }}</div>
                    <div class="rf-kpi-value">{{ $k[3] }}@if ($dtxt)<span class="rf-kpi-delta {{ $good ? 'good' : 'bad' }}"><i class="rf-kpi-arrow {{ $dir }}"></i>{{ $dtxt }}</span>@endif</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="rf-widgets">
        <div class="rf-card rf-widget">
            <div class="rf-widget-head">
                <div><div class="rf-card-title">{{ __('Unresolved tickets') }}</div><div class="rf-widget-sub">{{ __('Browse the helpdesk') }}</div></div>
                <a href="{{ route('refresh.tickets', ['mailbox_id' => $mailbox->id, 'view' => 'unresolved']) }}">{{ __('View details') }}</a>
            </div>
            <div class="rf-widget-row rf-widget-th"><span>{{ __('Agent') }}</span><span>{{ __('refresh::labels.open') }}</span></div>
            @forelse ($stats['by_agent'] as $a)
                <a class="rf-widget-row" href="{{ route('refresh.tickets', ['mailbox_id' => $mailbox->id, 'view' => 'unresolved']) }}?{{ http_build_query(['agent' => [$a['user_id'] ?: -1]]) }}"><span>{{ $a['name'] }}</span><span class="rf-widget-n">{{ $a['n'] }}</span></a>
            @empty
                <div class="rf-widget-row"><span class="text-help">{{ __('No unresolved tickets') }}</span></div>
            @endforelse
        </div>
        <div class="rf-card rf-widget">
            <div class="rf-widget-head">
                <div><div class="rf-card-title">{{ __('Undelivered e-mails') }}</div><div class="rf-widget-sub">{{ __('Browse the helpdesk') }}</div></div>
                <a href="{{ route('refresh.tickets', ['mailbox_id' => $mailbox->id, 'view' => 'undelivered']) }}">{{ __('View details') }}</a>
            </div>
            <div class="rf-widget-row rf-widget-th"><span>{{ __('Mailbox') }}</span><span>{{ __('Undelivered') }}</span></div>
            <div class="rf-widget-row"><span>{{ $mailbox->name }}</span><span class="rf-widget-n">{{ $stats['undelivered'] }}</span></div>
        </div>
    </div>

    <div class="rf-card rf-dash-list">
        <div class="rf-dash-list-heading">
            <h3>{{ __('Unresolved tickets') }} <small>{{ $conversations->total() }}</small></h3>
            <a href="{{ $all_url }}" class="rf-btn">{{ __('View all tickets') }}</a>
        </div>
        @include('conversations/conversations_table', ['conversations' => $conversations, 'folder' => $folder, 'params' => [], 'mailbox' => $mailbox])
    </div>
</div>
