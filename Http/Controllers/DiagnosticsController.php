<?php

namespace Modules\Refresh\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Refresh\Services\Diagnostics;
use Modules\Refresh\Services\Health;

/** Server part of the diagnostics file (Manage > Settings > Refresh): the page adds what the browser sees. */
class DiagnosticsController extends Controller
{
    public function show()
    {
        return response()
            ->json(Diagnostics::report(), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'no-store');
    }

    /** Health check (Services\Health): 200 when healthy, 503 otherwise. */
    public function health()
    {
        $report = Health::check();
        return response()
            ->json($report, $report['ok'] ? 200 : 503, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'no-store');
    }
}
