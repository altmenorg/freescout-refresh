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

    <h3 class="subheader">{{ __('Mobile app and push notifications') }}</h3>

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <p class="text-help">{{ __('On a phone, open FreeScout in Chrome (or Safari) and choose "Install app" / "Add to Home Screen": FreeScout opens full screen like an app. Each agent then turns on notifications in Profile › Notifications (Mobile column).') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('App name') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[refresh.app_name]" value="{{ $settings['refresh.app_name'] }}" class="form-control input-sized-lg" placeholder="{{ config('app.name') }}" maxlength="45" />
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Short name') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[refresh.app_short_name]" value="{{ $settings['refresh.app_short_name'] }}" class="form-control input-sized" maxlength="20" />
            <p class="form-help">{{ __('Shown under the icon on the home screen (12 characters or less displays best).') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('App icon') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[refresh.app_icon_url]" value="{{ $settings['refresh.app_icon_url'] }}" class="form-control input-sized-lg" placeholder="https://…/icon-512.png" />
            <p class="form-help">{{ __('Optional. Square PNG, 512 × 512 px, with a margin around the drawing. Empty: FreeScout\'s icon. Phones pick up a change within a day.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Contact e-mail') }}</label>
        <div class="col-sm-6">
            <input type="email" name="settings[refresh.push_contact]" value="{{ $settings['refresh.push_contact'] }}" class="form-control input-sized-lg" placeholder="{{ $default_contact }}" />
            <p class="form-help">{{ __('Given to the push services (Google, Apple, Mozilla) as the sender of the notifications. Empty: the first administrator.') }}</p>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>
