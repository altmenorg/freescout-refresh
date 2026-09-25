@extends('layouts.app')

@section('body_attrs')@parent data-mailbox_id="{{ $mailbox->id }}" data-rf-view="{{ $view }}"@endsection

@section('title', $view_title.' ('.$conversations->total().') - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu_view')
@endsection

@php
    $base = route('refresh.tickets', ['mailbox_id' => $mailbox->id, 'view' => $view]);
    $keep = request()->except(['page', 'sort', 'order']);
    $sortUrl = function ($s, $o) use ($base, $keep) {
        return $base.'?'.http_build_query(array_merge($keep, ['sort' => $s, 'order' => $o]));
    };
    $first = $conversations->firstItem() ?: 0;
    $last = $conversations->lastItem() ?: 0;
    $sel = function ($list, $value) { return in_array((string)$value, array_map('strval', $list)) ? 'selected' : ''; };
@endphp

@section('content')
    <div class="alerts">
        @php
            $flashes = \Helper::maybeShowSendingProblemsAlert();
        @endphp
        @include('partials/flash_messages')
    </div>

    {{-- View bar (Freshdesk clone): views button, title, star, counter --}}
    <div class="rf-viewbar">
        <button type="button" class="rf-sqbtn rf-toggle-views" title="{{ __('Views') }}"><i class="rf-i rf-i-fd-views"></i></button>
        <h1 class="rf-viewbar-title">{{ $view_title }}</h1>
        <span class="rf-pill">{{ $conversations->total() }}</span>
    </div>

    <div class="rf-list-layout @if (!empty($_COOKIE['rf_filters_closed'])) rf-filters-closed @endif @if (($_COOKIE['rf_layout'] ?? '') === 'table') rf-layout-table @endif">
        <div class="rf-list-main">
            {{-- Toolbar: select all, Sort by, pagination, Filters --}}
            <div class="rf-toolbar">
                <label class="rf-cb-all" title="{{ __('Select all') }}"><input type="checkbox" class="rf-toggle-all"><span></span></label>
                <div class="dropdown rf-sort">
                    <span class="rf-sort-label">{{ __('Sort by:') }}</span>
                    <a href="#" class="dropdown-toggle rf-sort-current" data-toggle="dropdown">{{ $sorts[$sort] }} <i class="rf-i rf-i-fd-chevron-down rf-i-sm"></i></a>
                    <ul class="dropdown-menu">
                        @foreach ($sorts as $key => $label)
                            <li class="@if ($key == $sort) active @endif"><a href="{{ $sortUrl($key, $order) }}">{{ $label }}</a></li>
                        @endforeach
                        <li class="divider"></li>
                        <li class="@if ($order == 'asc') active @endif"><a href="{{ $sortUrl($sort, 'asc') }}">{{ __('Ascending') }}</a></li>
                        <li class="@if ($order == 'desc') active @endif"><a href="{{ $sortUrl($sort, 'desc') }}">{{ __('Descending') }}</a></li>
                    </ul>
                </div>
                <div class="rf-toolbar-right">
                    <div class="dropdown rf-sort rf-layout-dd">
                        <span class="rf-sort-label">{{ __('Layout:') }}</span>
                        <a href="#" class="dropdown-toggle rf-sort-current" data-toggle="dropdown">{{ ($_COOKIE['rf_layout'] ?? '') === 'table' ? __('Table view') : __('Card view') }} <i class="rf-i rf-i-fd-chevron-down rf-i-sm"></i></a>
                        <ul class="dropdown-menu dropdown-menu-right">
                            <li class="@if (($_COOKIE['rf_layout'] ?? '') !== 'table') active @endif"><a href="#" class="rf-set-layout" data-layout="card">{{ __('Card view') }}</a></li>
                            <li class="@if (($_COOKIE['rf_layout'] ?? '') === 'table') active @endif"><a href="#" class="rf-set-layout" data-layout="table">{{ __('Table view') }}</a></li>
                        </ul>
                    </div>
                    <a class="rf-btn" href="{{ route('refresh.tickets.export', ['mailbox_id' => $mailbox->id, 'view' => $view]) }}?{{ http_build_query(request()->except('page')) }}"><i class="rf-i rf-i-download"></i> {{ __('Export') }}</a>
                    <span class="rf-range">{{ __(':from - :to of :total', ['from' => $first, 'to' => $last, 'total' => $conversations->total()]) }}</span>
                    <span class="rf-pager">
                        <a class="rf-pager-btn @if ($conversations->onFirstPage()) disabled @endif" href="{{ $conversations->onFirstPage() ? '#' : $conversations->previousPageUrl() }}" title="{{ __('Previous page') }}"><i class="rf-i rf-i-chevron-left rf-i-sm"></i></a><a class="rf-pager-btn @if (!$conversations->hasMorePages()) disabled @endif" href="{{ $conversations->hasMorePages() ? $conversations->nextPageUrl() : '#' }}" title="{{ __('Next page') }}"><i class="rf-i rf-i-chevron-right rf-i-sm"></i></a>
                    </span>
                    <button type="button" class="rf-btn rf-toggle-filters"><i class="rf-i rf-i-filter"></i> {{ __('Filters') }} @if ($filters_count)({{ $filters_count }})@endif</button>
                </div>
            </div>

            <div class="rf-list-scroll">
                @include('conversations/conversations_table')
            </div>
        </div>

        {{-- Filters panel (Freshdesk clone) --}}
        <form class="rf-filters" method="get" action="{{ $base }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="order" value="{{ $order }}">
            <div class="rf-filters-head">
                <span class="rf-filters-title">{{ __('Filters') }}</span>
                @if ($filters_count)<a href="{{ $base }}" class="rf-filters-reset">{{ __('Clear filters') }}</a>@endif
            </div>
            <div class="rf-filters-body">
                <div class="rf-f">
                    <div class="rf-search"><i class="rf-i rf-i-search"></i><input type="text" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('Search (subject, message, customer, #)') }}" class="rf-f-q"></div>
                </div>
                <div class="rf-f">
                    <label>{{ __('Agent includes') }}</label>
                    <select name="agent[]" multiple class="rf-multi" data-placeholder="{{ __('Any agent') }}">
                        <option value="-1" {{ $sel($filters['agent'], -1) }}>{{ __('Unassigned') }}</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" {{ $sel($filters['agent'], $u->id) }}>{{ $u->getFullName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-f">
                    <label>{{ __('Tags include') }}</label>
                    <select name="tag[]" multiple class="rf-multi" data-placeholder="{{ __('Any tag') }}">
                        @foreach ($tags as $t)
                            <option value="{{ $t->id }}" {{ $sel($filters['tag'], $t->id) }}>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-f">
                    <label>{{ __('Created') }}</label>
                    <select name="created" class="rf-select">
                        @foreach ($periods as $k => $p)
                            <option value="{{ $k }}" @if ($filters['created'] == $k) selected @endif>{{ $p[0] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-f">
                    <label>{{ __('Closed at') }}</label>
                    <select name="closed" class="rf-select">
                        @foreach ($periods as $k => $p)
                            <option value="{{ $k }}" @if ($filters['closed'] == $k) selected @endif>{{ $p[0] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-f">
                    <label>{{ __('Resolution due by') }}</label>
                    <select name="res_due" class="rf-select">
                        @foreach ($dues as $k => $label)
                            <option value="{{ $k }}" @if ($filters['res_due'] == $k) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-f">
                    <label>{{ __('First response due by') }}</label>
                    <select name="fr_due" class="rf-select">
                        @foreach ($dues as $k => $label)
                            <option value="{{ $k }}" @if ($filters['fr_due'] == $k) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-f">
                    <label>{{ __('Status includes') }}</label>
                    <select name="status[]" multiple class="rf-multi" data-placeholder="{{ __('Any status') }}">
                        @foreach ($statuses as $code => $label)
                            <option value="{{ $code }}" {{ $sel($filters['status'], $code) }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-f">
                    <label>{{ __('Priority includes') }}</label>
                    <select name="priority[]" multiple class="rf-multi" data-placeholder="{{ __('Any priority') }}">
                        @foreach ($priorities as $code => $p)
                            <option value="{{ $code }}" {{ $sel($filters['priority'], $code) }}>{{ $p[0] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-f">
                    <label>{{ __('Type includes') }}</label>
                    <select name="type[]" multiple class="rf-multi" data-placeholder="{{ __('Any type') }}">
                        @foreach ($types as $t)
                            <option value="{{ $t }}" {{ $sel($filters['type'], $t) }}>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-f">
                    <label>{{ __('Customer') }}</label>
                    <input type="text" name="customer" value="{{ $filters['customer'] }}" class="rf-input" placeholder="{{ __('Name or e-mail') }}">
                </div>
            </div>
            <div class="rf-filters-foot">
                <button type="submit" class="rf-btn-primary">{{ __('Apply') }}</button>
                <button type="button" class="rf-btn rf-save-view" data-view="{{ $view }}" data-mailbox="{{ $mailbox->id }}">{{ __('Save as shared view') }}</button>
            </div>
        </form>
    </div>
@endsection

@section('javascript')
    @parent
    viewMailboxInit();
@endsection
