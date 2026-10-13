<form class="form-horizontal margin-top margin-bottom" method="POST" action="" autocomplete="off">
    {{ csrf_field() }}

    <h3 class="subheader">{{ __('Service levels (SLA)') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('First response') }}</label>
        <div class="col-sm-6">
            <div class="input-group input-sized">
                <input type="number" min="1" max="2160" name="settings[refresh.sla_first_response]" value="{{ $settings['refresh.sla_first_response'] }}" class="form-control" />
                <span class="input-group-addon">{{ __('hours') }}</span>
            </div>
            <p class="form-help">{{ __('Time for the first agent reply. Used by the badges, views and dashboard. Calendar hours, paused while a ticket is Pending.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Resolution') }}</label>
        <div class="col-sm-6">
            <div class="input-group input-sized">
                <input type="number" min="1" max="8760" name="settings[refresh.sla_resolution]" value="{{ $settings['refresh.sla_resolution'] }}" class="form-control" />
                <span class="input-group-addon">{{ __('hours') }}</span>
            </div>
            <p class="form-help">{{ __('Time to resolve a ticket. Agents can change the due date of a ticket by hand.') }}</p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Appearance') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Logo') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[refresh.logo_url]" value="{{ $settings['refresh.logo_url'] }}" class="form-control input-sized-lg" placeholder="https://…/logo.png" />
            <p class="form-help">{{ __('Optional. Image shown at the top of the left bar (square, about 40 px). Empty: the logo of the Customization module, or FreeScout\'s.') }}</p>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>

<div id="rf-diag" class="form-horizontal margin-bottom" data-url="{{ route('refresh.diagnostics') }}">
    <h3 class="subheader">{{ __('Diagnostics') }}</h3>
    <div class="form-group">
        <div class="col-sm-8 col-sm-offset-2">
            <p class="form-help">{{ __('For a bug report on GitHub: versions, settings and test results of what Refresh depends on, with the last errors seen by this browser and in FreeScout\'s log. No ticket, customer, e-mail address, domain name or tag name. Check it before attaching it to an issue: issues are public.') }}</p>
        </div>
    </div>
    <div class="form-group margin-top">
        <div class="col-sm-8 col-sm-offset-2">
            <button type="button" class="btn btn-default rf-diag-make">{{ __('Create the diagnostics file') }}</button>
            <div class="rf-diag-out" style="display:none">
                <pre class="rf-diag-pre" style="max-height:420px;overflow:auto;margin:12px 0;font-size:12px;white-space:pre-wrap;word-break:break-word"></pre>
                <button type="button" class="btn btn-primary rf-diag-download">{{ __('Download') }}</button>
                <button type="button" class="btn btn-default rf-diag-copy">{{ __('Copy') }}</button>
                <button type="button" class="btn btn-link rf-diag-clear">{{ __('Clear the errors recorded by this browser') }}</button>
            </div>
        </div>
    </div>
</div>
