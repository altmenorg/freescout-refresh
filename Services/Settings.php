<?php

namespace Modules\ModernUi\Services;

/**
 * Modern UI settings (Manage > Settings > Modern UI), stored as FreeScout options, with neutral defaults.
 */
class Settings
{
    const DEFAULT_FIRST_RESPONSE_HOURS = 24;
    const DEFAULT_RESOLUTION_HOURS = 72;

    public static function get($key, $default = null)
    {
        $v = \Option::get('modernui.'.$key, null);
        return ($v === null || $v === '') ? $default : $v;
    }

    /** SLA: hours to the first agent reply (calendar hours, paused while "Pending"). */
    public static function firstResponseHours()
    {
        return max(1, (int)self::get('sla_first_response', self::DEFAULT_FIRST_RESPONSE_HOURS));
    }

    /** SLA: hours to resolution. */
    public static function resolutionHours()
    {
        return max(1, (int)self::get('sla_resolution', self::DEFAULT_RESOLUTION_HOURS));
    }

    /** Logo of the left bar: setting, else the header logo (Customization module or FreeScout's own). */
    public static function logoUrl()
    {
        return self::get('logo_url') ?: \Eventy::filter('layout.header_logo', asset('img/logo-brand.svg'));
    }

    /** Name of the installable app (PWA). */
    public static function appName()
    {
        return (string)self::get('app_name', config('app.name') ?: 'FreeScout');
    }

    public static function appShortName()
    {
        return mb_substr((string)self::get('app_short_name', self::appName()), 0, 20);
    }

    /**
     * Icons of the installable app: a square PNG (512 px recommended) from the settings, else FreeScout's own icons.
     * @return array manifest "icons" entries
     */
    public static function appIcons()
    {
        $custom = self::get('app_icon_url');
        if ($custom) {
            return [
                ['src' => $custom, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $custom, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ];
        }
        return [
            ['src' => asset('android-chrome-192x192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => asset('android-chrome-256x256.png'), 'sizes' => '256x256', 'type' => 'image/png', 'purpose' => 'any'],
        ];
    }

    /** Icon shown in push notifications. */
    public static function notificationIcon()
    {
        return self::get('app_icon_url') ?: asset('android-chrome-192x192.png');
    }

    /** Contact given to push services (Google, Mozilla, Apple) in the VAPID claims: setting, else the first admin. */
    public static function pushContact()
    {
        $email = self::get('push_contact');
        if (!$email) {
            $email = \App\User::where('role', \App\User::ROLE_ADMIN)->where('status', \App\User::STATUS_ACTIVE)->orderBy('id')->value('email');
        }
        return 'mailto:'.($email ?: 'admin@localhost');
    }
}
