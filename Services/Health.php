<?php

namespace Modules\Refresh\Services;

/**
 * Health check: is this installation of Refresh complete and working? For an updater that has just replaced the module
 * (decide on a rollback), or for an admin after an update. Read-only, fast, no network.
 * - php artisan refresh:health [--json]   exit code 0 when healthy, 1 otherwise (FreeScout's scheduler, a script)
 * - GET /refresh/health                   same report in JSON, admins only, HTTP 200 or 503
 */
class Health
{
    /** Classes the module loads on every page or on its own pages. */
    const CLASSES = [
        Settings::class, Views::class, Dashboard::class, Diagnostics::class,
        \Modules\Refresh\Http\Controllers\TicketsController::class,
        \Modules\Refresh\Http\Controllers\ContactsController::class,
        \Modules\Refresh\Http\Controllers\PropertiesController::class,
        \Modules\Refresh\Http\Controllers\NewTicketController::class,
        \Modules\Refresh\Http\Controllers\SavedViewsController::class,
        \Modules\Refresh\Http\Controllers\SplitController::class,
        \Modules\Refresh\Http\Controllers\DiagnosticsController::class,
    ];

    const VIEWS = ['refresh::tickets', 'refresh::properties', 'refresh::contact_panel', 'refresh::contacts', 'refresh::dashboard', 'refresh::settings'];

    /** Served through FreeScout's public/modules/refresh link: a broken link leaves FreeScout without its interface. */
    const ASSETS = ['css/icons.css', 'css/refresh.css', 'css/mobile.css', 'js/diagnostics.js', 'js/editor.js', 'js/new.js', 'js/mobile.js'];

    /** FreeScout routes the module's pages and scripts cannot work without (Tags and Saved Replies are optional). */
    const CORE_ROUTES = ['conversations.ajax', 'conversations.view', 'conversations.create', 'customers.update', 'mailboxes.view'];

    public static function check()
    {
        $checks = [];
        $run = function ($name, callable $test) use (&$checks) {
            try {
                $error = $test();
                $checks[$name] = $error ? ['ok' => false, 'error' => $error] : ['ok' => true];
            } catch (\Throwable $e) {
                $checks[$name] = ['ok' => false, 'error' => get_class($e).': '.Diagnostics::redact($e->getMessage())];
            }
        };

        $run('module_json', function () {
            $json = json_decode((string)@file_get_contents(__DIR__.'/../module.json'), true);
            return !empty($json['version']) ? null : 'module.json missing or unreadable';
        });
        $run('classes', function () {
            $missing = array_values(array_filter(static::CLASSES, function ($c) {
                return !class_exists($c);
            }));
            return $missing ? 'missing: '.implode(', ', $missing) : null;
        });
        $run('views', function () {
            $missing = array_values(array_filter(static::VIEWS, function ($v) {
                return !\View::exists($v);
            }));
            return $missing ? 'missing: '.implode(', ', $missing) : null;
        });
        $run('routes', function () {
            $routes = \Route::getRoutes();
            $names = ['refresh.tickets', 'refresh.tickets.last', 'refresh.contacts', 'refresh.search', 'refresh.views.store',
                'refresh.split', 'refresh.new_ticket', 'refresh.contact_panel', 'refresh.properties', 'refresh.diagnostics'];
            $missing = array_values(array_filter(array_merge($names, static::CORE_ROUTES), function ($n) use ($routes) {
                return !$routes->getByName($n);
            }));
            return $missing ? 'missing: '.implode(', ', $missing) : null;
        });
        $run('assets', function () {
            $missing = array_values(array_filter(static::ASSETS, function ($a) {
                return !is_file(public_path('modules/refresh/'.$a));
            }));
            return $missing ? 'not served (public/modules/refresh): '.implode(', ', $missing) : null;
        });
        $run('database', function () {
            \DB::table('conversations')->select('id')->limit(1)->get();
            Views::usedTypes();
            return null;
        });

        $ok = !in_array(false, array_column($checks, 'ok'), true);
        $json = json_decode((string)@file_get_contents(__DIR__.'/../module.json'), true) ?: [];
        return [
            'module' => 'Refresh',
            'version' => $json['version'] ?? null,
            'ok' => $ok,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
        ];
    }
}
