{{--
    Overrides resources/views/mailboxes/sidebar_menu_view.blade.php (ModernUi module, via View::prependLocation).
    Clone of the Freshdesk views panel: view search, collapsible "Shared" / "Default" / "Work" sections,
    active entry with pale blue background + border, Crayons icons (Freshworks), counters kept on purpose.
    Re-check on every FreeScout update (the native file may change).
--}}
@php
    $mu_defs = \Modules\ModernUi\Services\Views::definitions();
    $mu_counts = \Modules\ModernUi\Services\Views::counts($mailbox->id);
    $mu_current = \Route::is('modernui.tickets') ? (request()->route('view') ?: 'all') : '';
    $mu_saved = \Modules\ModernUi\Services\Views::savedViews();
    $mu_sv = (string)request()->input('sv', '');
    if ($mu_sv !== '') {
        $mu_current = 'sv:'.$mu_sv;
    }
    // Native folders -> equivalent view, to highlight the right entry outside the module's own views
    if (!$mu_current && isset($folder) && $folder) {
        $mu_current = \Modules\ModernUi\Services\Views::viewForFolderType($folder->type) ?: '';
    }
    $mu_url = function ($key) use ($mailbox) {
        return route('modernui.tickets', ['mailbox_id' => $mailbox->id, 'view' => $key]);
    };
    $mu_sections = [
        'shared'  => __('Shared'),
        'default' => __('Default'),
        'work'    => __('Tracking'),
    ];
@endphp
<div class="mu-views">
    <div class="mu-views-search">
        <i class="mu-i mu-i-search"></i>
        <input type="text" class="mu-views-filter" placeholder="{{ __('Search views') }}">
    </div>
    @foreach ($mu_sections as $sec => $sec_label)
        <div class="mu-views-sec" data-sec="{{ $sec }}">
            <button type="button" class="mu-views-sec-head">{{ $sec_label }}<i class="mu-i mu-i-fd-chevron-down mu-i-sm"></i></button>
            <div class="mu-views-list">
                @foreach ($mu_defs as $key => $def)
                    @if ($def[2] === $sec)
                        <a href="{{ $mu_url($key) }}" class="mu-v @if ($mu_current === $key) active @endif" data-label="{{ mb_strtolower($def[0]) }}" title="{{ $def[0] }}">
                            <i class="mu-i mu-i-{{ $def[1] }}"></i><span class="mu-v-label">{{ $def[0] }}</span>@if ($def[3] && !empty($mu_counts[$key]))<span class="mu-v-count">{{ $mu_counts[$key] }}</span>@endif
                        </a>
                    @endif
                @endforeach
                @if ($sec === 'shared')
                    @foreach ($mu_saved as $sv)
                        <a href="{{ \Modules\ModernUi\Services\Views::savedViewUrl($mailbox->id, $sv) }}" class="mu-v mu-v-saved @if ($mu_current === 'sv:'.$sv['id']) active @endif" data-label="{{ mb_strtolower($sv['label']) }}">
                            <i class="mu-i mu-i-fd-all-tickets"></i><span class="mu-v-label">{{ $sv['label'] }}</span>@if (!empty($mu_counts['sv:'.$sv['id']]))<span class="mu-v-count">{{ $mu_counts['sv:'.$sv['id']] }}</span>@endif
                            @if (Auth::user()->isAdmin() || (int)$sv['user_id'] === (int)Auth::id())<button type="button" class="mu-v-del" data-id="{{ $sv['id'] }}" title="{{ __('Delete view') }}"><i class="mu-i mu-i-close mu-i-sm"></i></button>@endif
                        </a>
                    @endforeach
                @endif
            </div>
        </div>
    @endforeach
    <div class="mu-views-sep"></div>
    <div class="mu-views-list mu-views-bottom">
        @foreach ($mu_defs as $key => $def)
            @if ($def[2] === 'bottom')
                <a href="{{ $mu_url($key) }}" class="mu-v @if ($mu_current === $key) active @endif" data-label="{{ mb_strtolower($def[0]) }}" title="{{ $def[0] }}">
                    <i class="mu-i mu-i-{{ $def[1] }}"></i><span class="mu-v-label">{{ $def[0] }}</span>@if ($def[3] && !empty($mu_counts[$key]))<span class="mu-v-count">{{ $mu_counts[$key] }}</span>@endif
                </a>
            @endif
        @endforeach
    </div>

    @if (\Eventy::filter('mailbox.show_buttons', true, $mailbox))
        <div class="mu-views-sep"></div>
        <div class="mu-views-list mu-views-tools">
            @if (Auth::user()->can('viewMailboxMenu', Auth::user()))
                <div class="dropdown dropup">
                    <a href="#" class="mu-v dropdown-toggle" data-toggle="dropdown"><i class="mu-i mu-i-settings"></i><span class="mu-v-label">{{ __('Mailbox Settings') }}</span></a>
                    <ul class="dropdown-menu" role="menu">
                        @include("mailboxes/settings_menu", ['is_dropdown' => true])
                    </ul>
                </div>
            @endif
            @action('mailbox.sidebar.buttons', $mailbox)
        </div>
    @endif
    @action('mailbox.after_sidebar_buttons')
</div>
