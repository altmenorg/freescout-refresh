@extends('layouts.app')

@section('title', __('All contacts').' ('.$contacts->total().')')
@section('content_class', 'mu-contacts-page')

@section('content')
    <div class="mu-viewbar">
        <h1 class="mu-viewbar-title">{{ __('All contacts') }}</h1>
        <span class="mu-pill">{{ $contacts->total() }}</span>
    </div>
    <div class="mu-contacts">
        <div class="mu-toolbar">
            <label class="mu-cb-all mu-cb-contacts" title="{{ __('Select all') }}"><input type="checkbox" class="mu-contacts-all"><span></span></label>
            <span class="mu-sort-label">{{ __('Select all') }}</span>
            <span class="mu-tb-sep"></span>
            <form method="get" class="mu-contacts-search">
                <i class="mu-i mu-i-search"></i><input type="text" name="q" value="{{ $q }}" placeholder="{{ __('Search contacts: all') }}">
            </form>
            <div class="mu-toolbar-right">
                <a class="mu-btn" href="{{ route('modernui.contacts.export') }}?{{ http_build_query(request()->except('page')) }}"><i class="mu-i mu-i-download"></i> {{ __('Export') }}</a>
                <span class="mu-range">{{ __(':from - :to of :total', ['from' => $contacts->firstItem() ?: 0, 'to' => $contacts->lastItem() ?: 0, 'total' => $contacts->total()]) }}</span>
                <span class="mu-pager">
                    <a class="mu-pager-btn @if ($contacts->onFirstPage()) disabled @endif" href="{{ $contacts->onFirstPage() ? '#' : $contacts->previousPageUrl() }}"><i class="mu-i mu-i-chevron-left mu-i-sm"></i></a><a class="mu-pager-btn @if (!$contacts->hasMorePages()) disabled @endif" href="{{ $contacts->hasMorePages() ? $contacts->nextPageUrl() : '#' }}"><i class="mu-i mu-i-chevron-right mu-i-sm"></i></a>
                </span>
            </div>
        </div>
        <table class="mu-ctable">
            <thead>
                <tr><th class="mu-ct-cb"></th><th>{{ __('Contact') }}</th><th>Titre</th><th>Entreprise</th><th>Adresse e-mail</th><th>{{ __('Mobile phone') }}</th><th>{{ __('Work phone') }}</th><th class="mu-ct-menu"></th></tr>
            </thead>
            <tbody>
                @forelse ($contacts as $c)
                    @php
                        $name = $c->getFullName(true);
                        $initial = mb_strtoupper(mb_substr(trim($name) ?: '?', 0, 1));
                        $hue = 0;
                        foreach (preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) as $ch) { $hue = ($hue * 31 + mb_ord($ch)) % 360; }
                        list($mobile, $work) = \Modules\ModernUi\Http\Controllers\ContactsController::phones($c);
                    @endphp
                    <tr>
                        <td class="mu-ct-cb"><label class="mu-cb-all"><input type="checkbox" class="mu-contact-cb" value="{{ $c->id }}"><span></span></label></td>
                        <td class="mu-ct-name">
                            <span class="mu-av mu-av-28" style="background: hsl({{ $hue }}, 60%, 92%); border-color: hsl({{ $hue }}, 55%, 84%); color: hsl({{ $hue }}, 45%, 32%)">{{ $initial }}</span>
                            <a href="{{ route('customers.conversations', ['id' => $c->id]) }}">{{ $name }}</a>
                        </td>
                        <td>@if ($c->job_title){{ $c->job_title }}@else<span class="mu-empty">–</span>@endif</td>
                        <td>@if ($c->company){{ $c->company }}@else<span class="mu-empty">–</span>@endif</td>
                        <td>@if ($c->mu_email){{ $c->mu_email }}@else<span class="mu-empty">–</span>@endif</td>
                        <td>@if ($mobile){{ $mobile }}@else<span class="mu-empty">–</span>@endif</td>
                        <td>@if ($work){{ $work }}@else<span class="mu-empty">–</span>@endif</td>
                        <td class="mu-ct-menu">
                            <div class="dropdown">
                                <a href="#" class="mu-ct-more dropdown-toggle" data-toggle="dropdown"><i class="mu-i mu-i-fd-more"></i></a>
                                <ul class="dropdown-menu dropdown-menu-right">
                                    <li><a href="{{ route('customers.conversations', ['id' => $c->id]) }}">{{ __('View tickets') }}</a></li>
                                    <li><a href="{{ route('customers.update', ['id' => $c->id]) }}">{{ __('Edit contact') }}</a></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="mu-ct-empty">{{ __('No contacts') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
