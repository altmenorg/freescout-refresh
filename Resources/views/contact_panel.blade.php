{{-- Contact details + Recent timeline of the right panel: ticket page (properties.blade.php) and the New ticket /
     Send an e-mail pages (/refresh/contact-panel, without $conversation). Without a customer on those pages:
     Freshdesk-style empty state. --}}
    <div class="rf-rp-sec">
        <div class="rf-rp-sec-head" data-sec="coord"><i class="rf-i rf-i-contact"></i><span>{{ __('Contact details') }}</span>@if ($customer)<span class="rf-rp-sep">|</span><a href="{{ route('customers.update', ['id' => $customer->id]) }}">{{ __('Edit') }}</a>@endif<button type="button" class="rf-rp-sec-toggle"><i class="rf-i rf-i-fd-chevron-down rf-i-sm"></i></button></div>
        @if ($customer)
            <div class="rf-rp-person">
                <span class="rf-av rf-av-32" style="background: {{ $av_bg }}; border-color: {{ $av_border }}; color: {{ $av_fg }}">{{ $initial }}</span>
                <a class="rf-rp-person-name" href="{{ route('customers.conversations', ['id' => $customer->id]) }}">{{ $customer->getFullName(true) }}</a>
            </div>
            @if ($customer->job_title)
                <div class="rf-rp-kv"><label>{{ __('Job title') }}</label><div class="rf-copyable"><span>{{ $customer->job_title }}</span><button type="button" class="rf-copy" data-copy="{{ $customer->job_title }}" title="{{ __('Copy') }}"><i class="rf-i rf-i-fd-copy rf-i-sm"></i></button></div></div>
            @endif
            @if ($customer->company)
                <div class="rf-rp-kv"><label>{{ __('Company') }}</label><div class="rf-copyable"><span>{{ $customer->company }}</span><button type="button" class="rf-copy" data-copy="{{ $customer->company }}" title="{{ __('Copy') }}"><i class="rf-i rf-i-fd-copy rf-i-sm"></i></button></div></div>
            @endif
            @foreach ($emails as $email)
                <div class="rf-rp-kv"><label>{{ __('E-mail') }}</label><div class="rf-copyable"><span>{{ $email }}</span><button type="button" class="rf-copy" data-copy="{{ $email }}" title="{{ __('Copy') }}"><i class="rf-i rf-i-fd-copy rf-i-sm"></i></button></div></div>
            @endforeach
            @foreach ($phones as $phone)
                <div class="rf-rp-kv"><label>{{ $phone['label'] }}</label><div class="rf-copyable"><span>{{ $phone['value'] }}</span><button type="button" class="rf-copy" data-copy="{{ $phone['value'] }}" title="{{ __('Copy') }}"><i class="rf-i rf-i-fd-copy rf-i-sm"></i></button></div></div>
            @endforeach
            <a class="rf-rp-more" href="{{ route('customers.update', ['id' => $customer->id]) }}"><i class="rf-i rf-i-open-new-tab rf-i-sm"></i> {{ __('View more information') }}</a>
        @elseif (!empty($empty_state))
            <div class="rf-rp-empty">
                <span class="rf-rp-empty-av"><i class="rf-i rf-i-contact"></i></span>
                <div class="rf-rp-empty-title">{{ __('Choose a contact') }}</div>
                <div class="rf-rp-empty-text">{{ __('The contact details and recent conversations will appear here') }}</div>
            </div>
        @endif
    </div>
    @if (count($recent))
        <div class="rf-rp-sec">
            <div class="rf-rp-sec-head" data-sec="chrono"><i class="rf-i rf-i-recent"></i><span>{{ __('Recent timeline') }}</span><button type="button" class="rf-rp-sec-toggle"><i class="rf-i rf-i-fd-chevron-down rf-i-sm"></i></button></div>
            <div class="rf-rp-timeline">
                @foreach ($recent as $rc)
                    <a class="rf-rp-tl-item @if (!empty($conversation) && $rc->id == $conversation->id) current @endif" href="{{ $rc->url() }}">
                        <span class="rf-rp-tl-dot"><i class="rf-i rf-i-fd-email rf-i-sm"></i></span>
                        <span class="rf-rp-tl-body">
                            <span class="rf-rp-tl-subj">{{ $rc->getSubject() }}</span>
                            <span class="rf-rp-tl-num">#{{ $rc->number }}</span>
                            <span class="rf-rp-tl-meta">{{ $rc->rf_date }}<br>{{ __('Status: :status', ['status' => $rc->getStatusName()]) }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
