{{-- Ticket's right-hand panels, Freshdesk clone: [status + SLA + PROPERTIES] | [Contact details + Recent timeline + Cobrowse].
     Type and priority are saved by the module (the "Update" button); status and agent trigger the native actions;
     the native tags (#conv_tags, Tags module) are moved in JS into the Tags field. --}}
<div class="rf-rp" data-author="{{ $author }}" data-conversation-id="{{ $conversation->id }}" data-save-url="{{ route('refresh.properties', ['id' => $conversation->id]) }}">
    <div class="rf-rp-props">
        <div class="rf-rp-scroll">
            <div class="rf-rp-status">
                <span class="rf-rp-status-name">{{ $status_name }}</span>
                <button type="button" class="rf-rp-collapse" title="{{ __('Collapse panels') }}"><i class="rf-i rf-i-collapse"></i></button>
            </div>
            <div class="rf-rp-sla @if ($sla_overdue) rf-rp-sla-late @endif">
                <i class="rf-i rf-i-{{ $sla_overdue ? 'fd-alert' : 'fd-hourglass' }}"></i>
                <div>
                    <div>{{ $sla_text }}</div>
                    @if ($sla_date)<div class="rf-rp-sla-date">{{ $sla_date }}@if ($due_custom) <span class="rf-rp-due-custom">{{ __('(changed)') }}</span>@endif</div>@endif
                    <div class="rf-rp-due-form" style="display:none">
                        <input type="datetime-local" class="rf-input rf-rp-due-input" value="{{ $due_input }}">
                        <div class="rf-rp-due-btns">
                            <button type="button" class="rf-btn-primary rf-rp-due-save">{{ __('Save') }}</button>
                            @if ($due_custom)<button type="button" class="rf-btn rf-rp-due-reset">{{ __('Automatic due date') }}</button>@endif
                        </div>
                    </div>
                </div>
                <button type="button" class="rf-rp-sla-edit" title="{{ __('Change due date') }}"><i class="rf-i rf-i-fd-edit rf-i-sm"></i></button>
            </div>
            <div class="rf-rp-title">{{ __('Properties') }}</div>
            <div class="rf-rp-body">
                <div class="rf-rp-f rf-rp-f-tags">
                    <label>{{ __('Tags') }}</label>
                    <div class="rf-rp-tags"></div>
                </div>
                <div class="rf-rp-f">
                    <label>{{ __('Type') }}</label>
                    <select class="rf-select rf-rp-type" data-initial="{{ $fd_type }}">
                        <option value="">--</option>
                        @foreach ($types as $t)
                            <option value="{{ $t }}" @if ($t === $fd_type) selected @endif>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-rp-f">
                    <label>{{ __('Status') }} <span class="rf-req">*</span></label>
                    <select class="rf-select rf-rp-status-select" data-initial="{{ $conversation->status }}">
                        @foreach ($statuses as $code => $name)
                            <option value="{{ $code }}" @if ($conversation->status == $code) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rf-rp-f">
                    <label>{{ __('Priority') }}</label>
                    <div class="rf-rp-prio-wrap">
                        <i class="rf-sq rf-rp-prio-sq" style="background: {{ $priorities[$priority][1] }}"></i>
                        <select class="rf-select rf-rp-priority" data-initial="{{ $priority }}">
                            @foreach ($priorities as $code => $p)
                                <option value="{{ $code }}" data-color="{{ $p[1] }}" @if ($code == $priority) selected @endif>{{ $p[0] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="rf-rp-f">
                    <label>{{ __('Agent') }}</label>
                    <select class="rf-select rf-rp-user" data-initial="{{ $conversation->user_id ?: -1 }}">
                        <option value="-1" @if (!$conversation->user_id) selected @endif>--</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @if ($conversation->user_id == $u->id) selected @endif>{{ $u->getFullName() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        <div class="rf-rp-foot">
            <button type="button" class="rf-btn-primary rf-rp-save" disabled>{{ __('Update') }}</button>
        </div>
    </div>

    <div class="rf-rp-contact">
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
            @endif
        </div>
        @if (count($recent))
            <div class="rf-rp-sec">
                <div class="rf-rp-sec-head" data-sec="chrono"><i class="rf-i rf-i-recent"></i><span>{{ __('Recent timeline') }}</span><button type="button" class="rf-rp-sec-toggle"><i class="rf-i rf-i-fd-chevron-down rf-i-sm"></i></button></div>
                <div class="rf-rp-timeline">
                    @foreach ($recent as $rc)
                        <a class="rf-rp-tl-item @if ($rc->id == $conversation->id) current @endif" href="{{ $rc->url() }}">
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
        <div class="rf-rp-cobrowse"></div>
    </div>
</div>
