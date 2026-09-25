@extends('layouts.app')

@section('title', __('All contacts').' ('.$contacts->total().')')
@section('content_class', 'rf-contacts-page')

@section('content')
    <div class="rf-viewbar">
        <h1 class="rf-viewbar-title">{{ __('All contacts') }}</h1>
        <span class="rf-pill">{{ $contacts->total() }}</span>
    </div>
    <div class="rf-contacts">
        <div class="rf-toolbar">
            <label class="rf-cb-all rf-cb-contacts" title="{{ __('Select all') }}"><input type="checkbox" class="rf-contacts-all"><span></span></label>
            <span class="rf-sort-label">{{ __('Select all') }}</span>
            <span class="rf-tb-sep"></span>
            <form method="get" class="rf-contacts-search">
                <i class="rf-i rf-i-search"></i><input type="text" name="q" value="{{ $q }}" placeholder="{{ __('Search contacts: all') }}">
            </form>
            <div class="rf-toolbar-right">
                <a class="rf-btn" href="{{ route('refresh.contacts.export') }}?{{ http_build_query(request()->except('page')) }}"><i class="rf-i rf-i-download"></i> {{ __('Export') }}</a>
                <span class="rf-range">{{ __(':from - :to of :total', ['from' => $contacts->firstItem() ?: 0, 'to' => $contacts->lastItem() ?: 0, 'total' => $contacts->total()]) }}</span>
                <span class="rf-pager">
                    <a class="rf-pager-btn @if ($contacts->onFirstPage()) disabled @endif" href="{{ $contacts->onFirstPage() ? '#' : $contacts->previousPageUrl() }}"><i class="rf-i rf-i-chevron-left rf-i-sm"></i></a><a class="rf-pager-btn @if (!$contacts->hasMorePages()) disabled @endif" href="{{ $contacts->hasMorePages() ? $contacts->nextPageUrl() : '#' }}"><i class="rf-i rf-i-chevron-right rf-i-sm"></i></a>
                </span>
            </div>
        </div>
        <table class="rf-ctable">
            <thead>
                <tr><th class="rf-ct-cb"></th><th>{{ __('Contact') }}</th><th>Titre</th><th>Entreprise</th><th>Adresse e-mail</th><th>{{ __('Mobile phone') }}</th><th>{{ __('Work phone') }}</th><th class="rf-ct-menu"></th></tr>
            </thead>
            <tbody>
                @forelse ($contacts as $c)
                    @php
                        $name = $c->getFullName(true);
                        $initial = mb_strtoupper(mb_substr(trim($name) ?: '?', 0, 1));
                        $hue = 0;
                        foreach (preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) as $ch) { $hue = ($hue * 31 + mb_ord($ch)) % 360; }
                        list($mobile, $work) = \Modules\Refresh\Http\Controllers\ContactsController::phones($c);
                    @endphp
                    <tr>
                        <td class="rf-ct-cb"><label class="rf-cb-all"><input type="checkbox" class="rf-contact-cb" value="{{ $c->id }}"><span></span></label></td>
                        <td class="rf-ct-name">
                            <span class="rf-av rf-av-28" style="background: hsl({{ $hue }}, 60%, 92%); border-color: hsl({{ $hue }}, 55%, 84%); color: hsl({{ $hue }}, 45%, 32%)">{{ $initial }}</span>
                            <a href="{{ route('customers.conversations', ['id' => $c->id]) }}">{{ $name }}</a>
                        </td>
                        <td>@if ($c->job_title){{ $c->job_title }}@else<span class="rf-empty">–</span>@endif</td>
                        <td>@if ($c->company){{ $c->company }}@else<span class="rf-empty">–</span>@endif</td>
                        <td>@if ($c->rf_email){{ $c->rf_email }}@else<span class="rf-empty">–</span>@endif</td>
                        <td>@if ($mobile){{ $mobile }}@else<span class="rf-empty">–</span>@endif</td>
                        <td>@if ($work){{ $work }}@else<span class="rf-empty">–</span>@endif</td>
                        <td class="rf-ct-menu">
                            <div class="dropdown">
                                <a href="#" class="rf-ct-more dropdown-toggle" data-toggle="dropdown"><i class="rf-i rf-i-fd-more"></i></a>
                                <ul class="dropdown-menu dropdown-menu-right">
                                    <li><a href="{{ route('customers.conversations', ['id' => $c->id]) }}">{{ __('View tickets') }}</a></li>
                                    <li><a href="{{ route('customers.update', ['id' => $c->id]) }}">{{ __('Edit contact') }}</a></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="rf-ct-empty">{{ __('No contacts') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
