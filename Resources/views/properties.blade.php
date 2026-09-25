{{-- Ticket's right-hand panels, Freshdesk clone: [status + SLA + PROPERTIES] | [Contact details + Recent timeline + Cobrowse].
     Type and priority are saved by the module (the "Update" button); status and agent trigger the native actions;
     the native tags (#conv_tags, Tags module) are moved in JS into the Tags field. --}}
<div class="mu-rp" data-author="{{ $author }}" data-conversation-id="{{ $conversation->id }}" data-save-url="{{ route('modernui.properties', ['id' => $conversation->id]) }}">
    <div class="mu-rp-props">
        <div class="mu-rp-scroll">
            <div class="mu-rp-status">
                <span class="mu-rp-status-name">{{ $status_name }}</span>
                <button type="button" class="mu-rp-collapse" title="{{ __('Collapse panels') }}"><i class="mu-i mu-i-collapse"></i></button>
            </div>
            <div class="mu-rp-sla @if ($sla_overdue) mu-rp-sla-late @endif">
                <i class="mu-i mu-i-{{ $sla_overdue ? 'fd-alert' : 'fd-hourglass' }}"></i>
                <div>
                    <div>{{ $sla_text }}</div>
                    @if ($sla_date)<div class="mu-rp-sla-date">{{ $sla_date }}@if ($due_custom) <span class="mu-rp-due-custom">{{ __('(changed)') }}</span>@endif</div>@endif
                    <div class="mu-rp-due-form" style="display:none">
                        <input type="datetime-local" class="mu-input mu-rp-due-input" value="{{ $due_input }}">
                        <div class="mu-rp-due-btns">
                            <button type="button" class="mu-btn-primary mu-rp-due-save">{{ __('Save') }}</button>
                            @if ($due_custom)<button type="button" class="mu-btn mu-rp-due-reset">{{ __('Automatic due date') }}</button>@endif
                        </div>
                    </div>
                </div>
                <button type="button" class="mu-rp-sla-edit" title="{{ __('Change due date') }}"><i class="mu-i mu-i-fd-edit mu-i-sm"></i></button>
            </div>
            <div class="mu-rp-title">{{ __('Properties') }}</div>
            <div class="mu-rp-body">
                <div class="mu-rp-f mu-rp-f-tags">
                    <label>{{ __('Tags') }}</label>
                    <div class="mu-rp-tags"></div>
                </div>
                <div class="mu-rp-f">
                    <label>{{ __('Type') }}</label>
                    <select class="mu-select mu-rp-type" data-initial="{{ $fd_type }}">
                        <option value="">--</option>
                        @foreach ($types as $t)
                            <option value="{{ $t }}" @if ($t === $fd_type) selected @endif>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mu-rp-f">
                    <label>{{ __('Status') }} <span class="mu-req">*</span></label>
                    <select class="mu-select mu-rp-status-select" data-initial="{{ $conversation->status }}">
                        @foreach ($statuses as $code => $name)
                            <option value="{{ $code }}" @if ($conversation->status == $code) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mu-rp-f">
                    <label>{{ __('Priority') }}</label>
                    <div class="mu-rp-prio-wrap">
                        <i class="mu-sq mu-rp-prio-sq" style="background: {{ $priorities[$priority][1] }}"></i>
                        <select class="mu-select mu-rp-priority" data-initial="{{ $priority }}">
                            @foreach ($priorities as $code => $p)
                                <option value="{{ $code }}" data-color="{{ $p[1] }}" @if ($code == $priority) selected @endif>{{ $p[0] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mu-rp-f">
                    <label>{{ __('Agent') }}</label>
                    <select class="mu-select mu-rp-user" data-initial="{{ $conversation->user_id ?: -1 }}">
                        <option value="-1" @if (!$conversation->user_id) selected @endif>--</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @if ($conversation->user_id == $u->id) selected @endif>{{ $u->getFullName() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        <div class="mu-rp-foot">
            <button type="button" class="mu-btn-primary mu-rp-save" disabled>{{ __('Update') }}</button>
        </div>
    </div>

    <div class="mu-rp-contact">
        <div class="mu-rp-sec">
            <div class="mu-rp-sec-head" data-sec="coord"><i class="mu-i mu-i-contact"></i><span>{{ __('Contact details') }}</span>@if ($customer)<span class="mu-rp-sep">|</span><a href="{{ route('customers.update', ['id' => $customer->id]) }}">{{ __('Edit') }}</a>@endif<button type="button" class="mu-rp-sec-toggle"><i class="mu-i mu-i-fd-chevron-down mu-i-sm"></i></button></div>
            @if ($customer)
                <div class="mu-rp-person">
                    <span class="mu-av mu-av-32" style="background: {{ $av_bg }}; border-color: {{ $av_border }}; color: {{ $av_fg }}">{{ $initial }}</span>
                    <a class="mu-rp-person-name" href="{{ route('customers.conversations', ['id' => $customer->id]) }}">{{ $customer->getFullName(true) }}</a>
                </div>
                @if ($customer->job_title)
                    <div class="mu-rp-kv"><label>{{ __('Job title') }}</label><div class="mu-copyable"><span>{{ $customer->job_title }}</span><button type="button" class="mu-copy" data-copy="{{ $customer->job_title }}" title="{{ __('Copy') }}"><i class="mu-i mu-i-fd-copy mu-i-sm"></i></button></div></div>
                @endif
                @if ($customer->company)
                    <div class="mu-rp-kv"><label>{{ __('Company') }}</label><div class="mu-copyable"><span>{{ $customer->company }}</span><button type="button" class="mu-copy" data-copy="{{ $customer->company }}" title="{{ __('Copy') }}"><i class="mu-i mu-i-fd-copy mu-i-sm"></i></button></div></div>
                @endif
                @foreach ($emails as $email)
                    <div class="mu-rp-kv"><label>{{ __('E-mail') }}</label><div class="mu-copyable"><span>{{ $email }}</span><button type="button" class="mu-copy" data-copy="{{ $email }}" title="{{ __('Copy') }}"><i class="mu-i mu-i-fd-copy mu-i-sm"></i></button></div></div>
                @endforeach
                @foreach ($phones as $phone)
                    <div class="mu-rp-kv"><label>{{ $phone['label'] }}</label><div class="mu-copyable"><span>{{ $phone['value'] }}</span><button type="button" class="mu-copy" data-copy="{{ $phone['value'] }}" title="{{ __('Copy') }}"><i class="mu-i mu-i-fd-copy mu-i-sm"></i></button></div></div>
                @endforeach
                <a class="mu-rp-more" href="{{ route('customers.update', ['id' => $customer->id]) }}"><i class="mu-i mu-i-open-new-tab mu-i-sm"></i> {{ __('View more information') }}</a>
            @endif
        </div>
        @if (count($recent))
            <div class="mu-rp-sec">
                <div class="mu-rp-sec-head" data-sec="chrono"><i class="mu-i mu-i-recent"></i><span>{{ __('Recent timeline') }}</span><button type="button" class="mu-rp-sec-toggle"><i class="mu-i mu-i-fd-chevron-down mu-i-sm"></i></button></div>
                <div class="mu-rp-timeline">
                    @foreach ($recent as $rc)
                        <a class="mu-rp-tl-item @if ($rc->id == $conversation->id) current @endif" href="{{ $rc->url() }}">
                            <span class="mu-rp-tl-dot"><i class="mu-i mu-i-fd-email mu-i-sm"></i></span>
                            <span class="mu-rp-tl-body">
                                <span class="mu-rp-tl-subj">{{ $rc->getSubject() }}</span>
                                <span class="mu-rp-tl-num">#{{ $rc->number }}</span>
                                <span class="mu-rp-tl-meta">{{ $rc->mu_date }}<br>{{ __('Status: :status', ['status' => $rc->getStatusName()]) }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="mu-rp-cobrowse"></div>
    </div>
</div>
