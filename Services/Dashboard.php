<?php

namespace Modules\Refresh\Services;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;

/**
 * Dashboard stats, Freshdesk-style: today's trends (tickets received per hour, today / yesterday),
 * Resolved, Received, Average first response time, Resolution within SLA (72h), changes vs yesterday,
 * unresolved tickets by agent, undelivered emails. Calendar hours (application timezone).
 */
class Dashboard
{
    public static function stats($mailbox_id)
    {
        return \Cache::remember('refresh_dash_'.$mailbox_id, 1, function () use ($mailbox_id) {
            $tz = config('app.timezone');
            $today = Carbon::now($tz)->startOfDay();
            $yesterday = $today->copy()->subDay();
            $toUtc = function ($c) { return $c->copy()->setTimezone('UTC'); };

            $base = function () use ($mailbox_id) {
                return Conversation::where('mailbox_id', $mailbox_id)
                    ->where('state', Conversation::STATE_PUBLISHED)
                    ->where('status', '!=', Conversation::STATUS_SPAM);
            };

            // Tickets received per hour
            $hours = function ($from) use ($base, $toUtc, $tz) {
                $h = array_fill(0, 24, 0);
                foreach ($base()->whereBetween('created_at', [$toUtc($from), $toUtc($from->copy()->endOfDay())])->pluck('created_at') as $c) {
                    $h[(int)Carbon::parse($c)->setTimezone($tz)->format('G')]++;
                }
                return $h;
            };
            $h_today = $hours($today);
            $h_yest = $hours($yesterday);
            // today's future hours are not plotted
            $now_h = (int)Carbon::now($tz)->format('G');

            $received = function ($from) use ($base, $toUtc) {
                return $base()->whereBetween('created_at', [$toUtc($from), $toUtc($from->copy()->endOfDay())])->count();
            };
            $resolved = function ($from) use ($base, $toUtc) {
                return $base()->where('status', Conversation::STATUS_CLOSED)
                    ->whereBetween('closed_at', [$toUtc($from), $toUtc($from->copy()->endOfDay())])->count();
            };
            $slaPct = function ($from) use ($base, $toUtc) {
                $rows = $base()->where('status', Conversation::STATUS_CLOSED)
                    ->whereBetween('closed_at', [$toUtc($from), $toUtc($from->copy()->endOfDay())])
                    ->get(['created_at', 'closed_at', 'meta']);
                if (!count($rows)) {
                    return null;
                }
                $ok = 0;
                foreach ($rows as $r) {
                    $meta = is_array($r->meta) ? $r->meta : (json_decode((string)$r->meta, true) ?: []);
                    $due = !empty($meta['rf_due']) ? Carbon::parse($meta['rf_due']) : Carbon::parse($r->created_at)->addHours(\Modules\Refresh\Services\Settings::resolutionHours());
                    if (Carbon::parse($r->closed_at)->lte($due)) {
                        $ok++;
                    }
                }
                return (int)round($ok * 100 / count($rows));
            };
            // Average first response time (minutes): agent first replies sent that day
            $frt = function ($from) use ($mailbox_id, $toUtc) {
                $prefix = \DB::getTablePrefix();
                $rows = \DB::select(
                    "SELECT c.created_at AS c_at, MIN(t.created_at) AS r_at FROM {$prefix}conversations c
                     JOIN {$prefix}threads t ON t.conversation_id = c.id AND t.type = ? AND t.state = ? AND t.created_by_user_id IS NOT NULL
                     WHERE c.mailbox_id = ? GROUP BY c.id, c.created_at
                     HAVING r_at BETWEEN ? AND ?",
                    [Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED, $mailbox_id, $toUtc($from)->toDateTimeString(), $toUtc($from->copy()->endOfDay())->toDateTimeString()]
                );
                if (!count($rows)) {
                    return null;
                }
                $sum = 0;
                foreach ($rows as $r) {
                    $sum += max(0, Carbon::parse($r->r_at)->diffInMinutes(Carbon::parse($r->c_at)));
                }
                return (int)round($sum / count($rows));
            };

            $stats = [
                'resolved' => [$resolved($today), $resolved($yesterday)],
                'received' => [$received($today), $received($yesterday)],
                'frt'      => [$frt($today), $frt($yesterday)],
                'sla'      => [$slaPct($today), $slaPct($yesterday)],
            ];

            // Unresolved by agent
            $by_agent = [];
            $rows = $base()->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
                ->select('user_id', \DB::raw('COUNT(*) AS n'))->groupBy('user_id')->get();
            foreach ($rows as $r) {
                $name = __('Unassigned');
                if ($r->user_id && ($u = \App\User::find($r->user_id))) {
                    $name = $u->getFullName();
                }
                $by_agent[] = ['name' => $name, 'n' => (int)$r->n, 'user_id' => (int)$r->user_id];
            }
            usort($by_agent, function ($a, $b) { return $b['n'] - $a['n']; });

            $undelivered = Views::query($mailbox_id, 'undelivered')->count();

            return [
                'h_today'     => $h_today,
                'h_yest'      => $h_yest,
                'now_h'       => $now_h,
                'stats'       => $stats,
                'by_agent'    => $by_agent,
                'undelivered' => $undelivered,
            ];
        });
    }

    /** % change vs yesterday: [text, direction]; direction = up / down / null. */
    public static function delta($today, $yest)
    {
        if ($today === null || $yest === null || $yest == 0) {
            return ['', null];
        }
        $pct = round(abs($today - $yest) * 100 / $yest, 2);
        return [str_replace('.', ',', (string)$pct).'%', $today >= $yest ? 'up' : 'down'];
    }

    public static function duration($minutes)
    {
        if ($minutes === null) {
            return '--';
        }
        if ($minutes < 60) {
            return $minutes.'m';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h < 24) {
            return $h.'h '.$m.'m';
        }
        return intdiv($h, 24).'j '.($h % 24).'h';
    }

    /** SVG trends chart (today: solid dark blue line, yesterday: light blue), 0-23h axes. */
    public static function chart($today, $yest, $now_h)
    {
        $w = 900; $h = 240; $l = 36; $r = 12; $t = 16; $b = 30;
        $max = max(1, max($today), max($yest));
        $step = ($w - $l - $r) / 23;
        $y = function ($v) use ($h, $t, $b, $max) { return round($h - $b - ($v / $max) * ($h - $t - $b), 1); };
        $x = function ($i) use ($l, $step) { return round($l + $i * $step, 1); };
        $svg = '<svg class="rf-chart" viewBox="0 0 '.$w.' '.$h.'">';
        // horizontal grid
        $ticks = min(4, $max);
        for ($k = 0; $k <= $ticks; $k++) {
            $v = round($max * $k / max(1, $ticks));
            $yy = $y($v);
            $svg .= '<line x1="'.$l.'" x2="'.($w - $r).'" y1="'.$yy.'" y2="'.$yy.'" class="rf-chart-grid"/>';
            $svg .= '<text x="'.($l - 10).'" y="'.($yy + 4).'" class="rf-chart-lbl" text-anchor="end">'.$v.'</text>';
        }
        for ($i = 0; $i < 24; $i++) {
            $svg .= '<text x="'.$x($i).'" y="'.($h - 8).'" class="rf-chart-lbl" text-anchor="middle">'.$i.'</text>';
        }
        $line = function ($vals, $cls, $upto) use ($x, $y) {
            $pts = [];
            $dots = '';
            for ($i = 0; $i <= $upto; $i++) {
                $pts[] = $x($i).','.$y($vals[$i]);
                $dots .= '<circle cx="'.$x($i).'" cy="'.$y($vals[$i]).'" r="3.5" class="'.$cls.'-dot"/>';
                $dots .= '<text x="'.$x($i).'" y="'.($y($vals[$i]) - 8).'" class="rf-chart-val" text-anchor="middle">'.$vals[$i].'</text>';
            }
            return '<polyline points="'.implode(' ', $pts).'" class="'.$cls.'"/>'.$dots;
        };
        $svg .= $line($yest, 'rf-chart-yest', 23);
        $svg .= $line($today, 'rf-chart-today', $now_h);
        $svg .= '</svg>';
        return $svg;
    }
}
