<?php

namespace Modules\Refresh\Services;

/**
 * Diagnostics file for bug reports (Manage > Settings > Refresh > Diagnostics): versions, settings, routes and
 * read-only self-tests of what Refresh depends on. It ends up attached to a public GitHub issue, so it holds no
 * ticket, customer, e-mail address, domain name or tag name: only versions, counts and test results, and the
 * error lines of the log are redacted. Every section is computed on its own: one that fails is reported as such.
 */
class Diagnostics
{
    /** Routes of FreeScout and its modules that the module's scripts call. */
    const ROUTES = [
        'conversations.ajax', 'conversations.view', 'conversations.create', 'customers.update', 'mailboxes.view',
        'mailboxes.saved_replies', 'tags.ajax', 'tags.tags',
    ];

    public static function report()
    {
        $report = ['format' => 1, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')];
        foreach (['refresh', 'freescout', 'php', 'database', 'modules', 'routes', 'tags', 'log'] as $section) {
            try {
                $report[$section] = self::$section();
            } catch (\Throwable $e) {
                $report[$section] = ['error' => get_class($e).': '.self::redact($e->getMessage())];
            }
        }
        return $report;
    }

    protected static function refresh()
    {
        $json = json_decode((string)@file_get_contents(__DIR__.'/../module.json'), true) ?: [];
        return [
            'version' => $json['version'] ?? null,
            'sla_first_response_hours' => Settings::firstResponseHours(),
            'sla_resolution_hours' => Settings::resolutionHours(),
            'custom_logo' => \Option::get('refresh.logo_url', '') !== '',
        ];
    }

    protected static function freescout()
    {
        $url = (string)config('app.url');
        return [
            'version' => config('app.version'),
            'locale' => config('app.locale'),
            'user_locale' => app()->getLocale(),
            'timezone' => config('app.timezone'),
            'https' => stripos($url, 'https://') === 0,
            'subdirectory' => \Helper::getSubdirectory() !== '',
            'debug' => (bool)config('app.debug'),
        ];
    }

    protected static function php()
    {
        $ext = [];
        foreach (['mbstring', 'intl', 'imap', 'iconv', 'curl', 'gd', 'json', 'dom', 'pdo_mysql', 'pdo_pgsql'] as $e) {
            $ext[$e] = extension_loaded($e);
        }
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'extensions' => $ext,
        ];
    }

    protected static function database()
    {
        $conn = \DB::connection();
        $out = [
            'driver' => $conn->getDriverName(),
            'server_version' => $conn->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
            'table_prefix' => $conn->getTablePrefix() !== '',
        ];
        // Case sensitivity of tag names: the Tags module searches with LIKE and attaches by exact name
        if ($out['driver'] === 'mysql' && \Schema::hasTable('tags')) {
            $col = $conn->select('SHOW FULL COLUMNS FROM `'.$conn->getTablePrefix().'tags` WHERE Field = ?', ['name']);
            $out['tags_name_collation'] = $col ? ($col[0]->Collation ?? null) : null;
        }
        return $out;
    }

    protected static function modules()
    {
        $list = [];
        foreach (\Module::all() as $module) {
            $list[] = [
                'name' => $module->getName(),
                'version' => $module->get('version'),
                'active' => (bool)$module->active(),
            ];
        }
        usort($list, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $list;
    }

    protected static function routes()
    {
        $out = [];
        foreach (self::ROUTES as $name) {
            $route = \Route::getRoutes()->getByName($name);
            $out[$name] = $route ? array_values(array_diff($route->methods(), ['HEAD'])) : false;
        }
        return $out;
    }

    /** Read-only replay of the Tags module's own queries (autocomplete: name LIKE %q%; add: exact normalized name). */
    protected static function tags()
    {
        $class = '\Modules\Tags\Entities\Tag';
        if (!class_exists($class)) {
            return ['installed' => false];
        }
        $out = ['installed' => true, 'total' => $class::count()];
        $names = $class::select('id', 'name')->orderBy('id')->limit(5000)->get();
        $upper = 0;
        $not_normalized = 0;
        $sample = null;
        foreach ($names as $tag) {
            $name = (string)$tag->name;
            if ($name !== mb_strtolower($name)) {
                $upper++;
            }
            if (method_exists($class, 'normalizeName') && $class::normalizeName($name) !== $name) {
                $not_normalized++;
            }
            if (!$sample && mb_strlen($name) >= 3 && mb_strtolower($name) !== mb_strtoupper($name)) {
                $sample = $tag;
            }
        }
        $out['checked'] = count($names);
        $out['with_capital_letters'] = $upper;
        $out['not_normalized'] = $not_normalized;
        if ($sample) {
            $q = mb_substr((string)$sample->name, 0, 3);
            $found = function ($term) use ($class, $sample) {
                return $class::where('name', 'like', '%'.$term.'%')->where('id', $sample->id)->exists();
            };
            $out['search'] = [
                'same_case' => $found($q),
                'lower_case' => $found(mb_strtolower($q)),
                'upper_case' => $found(mb_strtoupper($q)),
            ];
            $out['exact_name_lower_case'] = $class::where('name', mb_strtolower((string)$sample->name))->where('id', $sample->id)->exists();
            $t = microtime(true);
            $class::select('name')->where('name', 'like', '%'.$q.'%')->limit(20)->get();
            $out['autocomplete_ms'] = (int)round((microtime(true) - $t) * 1000);
        }
        return $out;
    }

    /** The last error lines of FreeScout's log, first line of each entry only, redacted. */
    protected static function log()
    {
        $files = glob(storage_path('logs').'/laravel*.log') ?: [];
        if (!$files) {
            return ['file' => false];
        }
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $file = $files[0];
        $size = filesize($file);
        $fh = fopen($file, 'rb');
        if (!$fh) {
            return ['file' => true, 'readable' => false];
        }
        fseek($fh, max(0, $size - 262144));
        $tail = (string)stream_get_contents($fh);
        fclose($fh);

        $entries = [];
        foreach (preg_split('/\R/', $tail) as $line) {
            if (preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $m)) {
                // without the context Laravel appends ({"userId":…,"email":…,"exception":…})
                $entries[] = $m[1].' '.$m[2].': '.mb_substr(self::redact(preg_replace('/ \{".*$/s', '', $m[3])), 0, 400);
            }
        }
        return [
            'file' => true,
            'last_modified' => gmdate('Y-m-d\TH:i:s\Z', filemtime($file)),
            'errors_in_tail' => count($entries),
            'last_errors' => array_slice($entries, -15),
        ];
    }

    /** Removes what could identify the installation or its customers. */
    public static function redact($text)
    {
        $text = str_replace(base_path(), '<freescout>', (string)$text);
        $text = preg_replace('/\(SQL: (\w+).*$/s', '(SQL: $1 …)', $text);
        $text = preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', '<url>', $text);
        $text = preg_replace('/[^\s@<>()\'"]+@[^\s@<>()\'"]+\.[a-z]{2,}/i', '<email>', $text);
        $text = preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b/', '<ip>', $text);
        $text = preg_replace('/\'[^\']*\'|"[^"]*"/', '"…"', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
