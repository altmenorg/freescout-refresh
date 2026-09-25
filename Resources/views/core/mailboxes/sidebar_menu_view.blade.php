{{--
    Overrides resources/views/mailboxes/sidebar_menu_view.blade.php (Refresh module, via View::prependLocation).
    Clone of the Freshdesk views panel: view search, collapsible "Shared" / "Default" / "Work" sections,
    active entry with pale blue background + border, Crayons icons (Freshworks), counters kept on purpose.
    Re-check on every FreeScout update (the native file may change).
--}}
@php
    $rf_defs = \Modules\Refresh\Services\Views::definitions();
    $rf_counts = \Modules\Refresh\Services\Views::counts($mailbox->id);
    $rf_current = \Route::is('refresh.tickets') ? (request()->route('view') ?: 'all') : '';
    $rf_saved = \Modules\Refresh\Services\Views::savedViews();
    $rf_sv = (string)request()->input('sv', '');
    if ($rf_sv !== '') {
        $rf_current = 'sv:'.$rf_sv;
    }
    // Native folders -> equivalent view, to highlight the right entry outside the module's own views
    if (!$rf_current && isset($folder) && $folder) {
        $rf_current = \Modules\Refresh\Services\Views::viewForFolderType($folder->type) ?: '';
    }
    $rf_url = function ($key) use ($mailbox) {
        return route('refresh.tickets', ['mailbox_id' => $mailbox->id, 'view' => $key]);
    };
    $rf_sections = [
        'shared'  => __('Shared'),
        'default' => __('Default'),
        'work'    => __('Tracking'),
    ];
@endphp
<div class="rf-views">
    <div class="rf-views-search">
        <i class="rf-i rf-i-search"></i>
        <input type="text" class="rf-views-filter" placeholder="{{ __('Search views') }}">
    </div>
    @foreach ($rf_sections as $sec => $sec_label)
        <div class="rf-views-sec" data-sec="{{ $sec }}">
            <button type="button" class="rf-views-sec-head">{{ $sec_label }}<i class="rf-i rf-i-fd-chevron-down rf-i-sm"></i></button>
            <div class="rf-views-list">
                @foreach ($rf_defs as $key => $def)
                    @if ($def[2] === $sec)
                        <a href="{{ $rf_url($key) }}" class="rf-v @if ($rf_current === $key) active @endif" data-label="{{ mb_strtolower($def[0]) }}" title="{{ $def[0] }}">
                            <i class="rf-i rf-i-{{ $def[1] }}"></i><span class="rf-v-label">{{ $def[0] }}</span>@if ($def[3] && !empty($rf_counts[$key]))<span class="rf-v-count">{{ $rf_counts[$key] }}</span>@endif
                        </a>
                    @endif
                @endforeach
                @if ($sec === 'shared')
                    @foreach ($rf_saved as $sv)
                        <a href="{{ \Modules\Refresh\Services\Views::savedViewUrl($mailbox->id, $sv) }}" class="rf-v rf-v-saved @if ($rf_current === 'sv:'.$sv['id']) active @endif" data-label="{{ mb_strtolower($sv['label']) }}">
                            <i class="rf-i rf-i-fd-all-tickets"></i><span class="rf-v-label">{{ $sv['label'] }}</span>@if (!empty($rf_counts['sv:'.$sv['id']]))<span class="rf-v-count">{{ $rf_counts['sv:'.$sv['id']] }}</span>@endif
                            @if (Auth::user()->isAdmin() || (int)$sv['user_id'] === (int)Auth::id())<button type="button" class="rf-v-del" data-id="{{ $sv['id'] }}" title="{{ __('Delete view') }}"><i class="rf-i rf-i-close rf-i-sm"></i></button>@endif
                        </a>
                    @endforeach
                @endif
            </div>
        </div>
    @endforeach
    <div class="rf-views-sep"></div>
    <div class="rf-views-list rf-views-bottom">
        @foreach ($rf_defs as $key => $def)
            @if ($def[2] === 'bottom')
                <a href="{{ $rf_url($key) }}" class="rf-v @if ($rf_current === $key) active @endif" data-label="{{ mb_strtolower($def[0]) }}" title="{{ $def[0] }}">
                    <i class="rf-i rf-i-{{ $def[1] }}"></i><span class="rf-v-label">{{ $def[0] }}</span>@if ($def[3] && !empty($rf_counts[$key]))<span class="rf-v-count">{{ $rf_counts[$key] }}</span>@endif
                </a>
            @endif
        @endforeach
    </div>

    @if (\Eventy::filter('mailbox.show_buttons', true, $mailbox))
        <div class="rf-views-sep"></div>
        <div class="rf-views-list rf-views-tools">
            @if (Auth::user()->can('viewMailboxMenu', Auth::user()))
                <div class="dropdown dropup">
                    <a href="#" class="rf-v dropdown-toggle" data-toggle="dropdown"><i class="rf-i rf-i-settings"></i><span class="rf-v-label">{{ __('Mailbox Settings') }}</span></a>
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
