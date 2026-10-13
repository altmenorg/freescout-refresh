<?php

namespace Modules\Refresh\Console;

use Illuminate\Console\Command;
use Modules\Refresh\Services\Health as HealthCheck;

/** php artisan refresh:health [--json]: exit code 0 when Refresh is healthy, 1 otherwise (Services\Health). */
class Health extends Command
{
    protected $signature = 'refresh:health {--json : Print the report as JSON}';

    protected $description = 'Checks that Refresh is complete and working (for updaters: exit code 0 or 1)';

    public function handle()
    {
        $report = HealthCheck::check();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Refresh '.$report['version'].': '.($report['ok'] ? 'healthy' : 'NOT healthy'));
            foreach ($report['checks'] as $name => $check) {
                $this->line(sprintf('  %-12s %s', $name, $check['ok'] ? 'ok' : 'FAILED - '.$check['error']));
            }
        }
        return $report['ok'] ? 0 : 1;
    }
}
