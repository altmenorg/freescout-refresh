<?php

namespace Modules\Refresh\Services;

/**
 * Refresh settings (Manage > Settings > Refresh), stored as FreeScout options, with neutral defaults.
 */
class Settings
{
    const DEFAULT_FIRST_RESPONSE_HOURS = 24;
    const DEFAULT_RESOLUTION_HOURS = 72;

    public static function get($key, $default = null)
    {
        $v = \Option::get('refresh.'.$key, null);
        return ($v === null || $v === '') ? $default : $v;
    }

    /** Hover cards of the ticket lists (first message, contact details): on unless turned off in the settings. */
    public static function hoverCards()
    {
        return (string)self::get('hover_cards', '1') !== '0';
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

    /**
     * Ticket types set by the admin (one per line), in their order: trimmed, without duplicates, 100 characters at
     * most (the length kept in conversations.meta.fd_type). Null while the setting has never been saved.
     */
    public static function types()
    {
        $raw = \Option::get('refresh.types', null);
        if ($raw === null) {
            return null;
        }
        $types = [];
        foreach (preg_split('/\R/u', (string)$raw) as $line) {
            $t = mb_substr(trim($line), 0, 100);
            if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $types), true)) {
                $types[] = $t;
            }
        }
        return $types;
    }

    /**
     * Logo of the left bar: setting, else the header logo of the Customization module, else FreeScout's icon in
     * Refresh's indigo (FreeScout's own header logo is white, made for its blue top bar: nearly invisible on the light
     * left bar; its blue icon clashed with the indigo accent).
     */
    public static function logoUrl()
    {
        $default = asset('img/logo-brand.svg');
        $logo = self::get('logo_url') ?: \Eventy::filter('layout.header_logo', $default);
        return $logo === $default ? asset('modules/refresh/img/logo.svg') : $logo;
    }
}
