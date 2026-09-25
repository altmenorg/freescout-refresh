<?php

namespace Modules\ModernUi\Services;

use App\Conversation;
use App\Folder;
use App\Thread;
use Carbon\Carbon;

/**
 * Ticket views, Freshdesk-style: definitions (views menu), queries, counters, side-panel filters and sorts.
 *
 * Calendar SLA (same rule as the provider): first response 24h, resolution 72h, paused while "Pending".
 * Freshdesk type and priority live in conversations.meta (JSON): fd_type (label), fd_priority (1 low … 4 urgent).
 */
class Views
{

    /** Priorities (fd_priority, same codes as Freshdesk): code => [label, dot color]. */
    public static function priorities()
    {
        return [
            1 => [__('Low'), '#94a3b8'],
            2 => [__('Medium'), '#38bdf8'],
            3 => [__('High'), '#f59e0b'],
            4 => [__('Urgent'), '#e11d48'],
        ];
    }

    /** Known Freshdesk types (imported) + ones added since, sorted. */
    public static function types()
    {
        return \Cache::remember('modernui_types', 10, function () {
            $rows = \DB::select("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.fd_type')) AS t FROM conversations WHERE meta LIKE '%fd_type%'");
            $types = [];
            foreach ($rows as $row) {
                if ($row->t && $row->t !== 'null') {
                    $types[] = $row->t;
                }
            }
            sort($types, SORT_NATURAL | SORT_FLAG_CASE);
            return $types;
        });
    }

    /**
     * View definitions: URL key => [label, Crayons icon, section, show counter].
     * Order = menu order.
     */
    public static function definitions()
    {
        return [
            // "Default" section (same views as Freshdesk, in the same order)
            'created'               => [__('Tickets I created'), 'fd-created', 'default', false],
            'my-open'         => [__('My new and open tickets'), 'fd-new-open', 'default', true],
            'watching'            => [__('Tickets I\'m watching'), 'fd-watching', 'default', true],
            'undelivered'          => [__('All undelivered messages'), 'fd-undelivered', 'default', true],
            'all'                => [__('All tickets'), 'fd-all-tickets', 'default', false],
            'unresolved'         => [__('All unresolved tickets'), 'fd-unresolved', 'default', true],
            // Working views (with counters)
            'unassigned'        => [__('Unassigned'), 'fd-agent', 'work', true],
            'new'            => [__('New'), 'new', 'work', true],
            'overdue'           => [__('Overdue'), 'fd-alert', 'work', true],
            'due-today' => [__('Due today'), 'fd-calendar', 'work', true],
            'open'             => [__('modernui::labels.open'), 'fd-status', 'work', true],
            'pending'          => [__('Pending'), 'fd-hourglass', 'work', true],
            'starred'             => [__('Starred'), 'fd-star-filled', 'work', true],
            // Bottom of menu
            'trash'           => [__('Trash'), 'fd-trash', 'bottom', false],
            'spam'         => [__('Spam'), 'fd-spam', 'bottom', true],
        ];
    }

    /** Modern UI view equivalent to a native FreeScout folder type, or null (folder added by another module). */
    public static function viewForFolderType($type)
    {
        $map = [
            \App\Folder::TYPE_UNASSIGNED => 'unassigned',
            \App\Folder::TYPE_MINE       => 'my-open',
            \App\Folder::TYPE_STARRED    => 'starred',
            \App\Folder::TYPE_ASSIGNED   => 'unresolved',
            \App\Folder::TYPE_CLOSED     => 'all',
            \App\Folder::TYPE_SPAM       => 'spam',
            \App\Folder::TYPE_DELETED    => 'trash',
        ];
        return $map[(int)$type] ?? null;
    }

    public static function labels()
    {
        $labels = [];
        foreach (self::definitions() as $key => $def) {
            $labels[$key] = $def[0];
        }
        return $labels;
    }

    /** Base query of a view (no panel filters, no sort). */
    public static function query($mailbox_id, $view, $user = null)
    {
        $user = $user ?: auth()->user();
        $now = Carbon::now();
        $q = Conversation::where('conversations.mailbox_id', $mailbox_id);

        if ($view === 'trash') {
            return $q->where('conversations.state', Conversation::STATE_DELETED);
        }
        $q->where('conversations.state', Conversation::STATE_PUBLISHED);
        if ($view === 'spam') {
            return $q->where('conversations.status', Conversation::STATUS_SPAM);
        }
        $q->where('conversations.status', '!=', Conversation::STATUS_SPAM);

        $unresolved = [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING];
        switch ($view) {
            case 'all':
                break;
            case 'created':
                $q->where('conversations.created_by_user_id', $user->id);
                break;
            case 'my-open':
                $q->where('conversations.user_id', $user->id)->whereIn('conversations.status', $unresolved);
                break;
            case 'watching':
                $q->whereIn('conversations.id', function ($s) use ($user) {
                    $s->select('conversation_id')->from('followers')->where('user_id', $user->id);
                });
                break;
            case 'undelivered':
                $q->whereIn('conversations.id', function ($s) {
                    $s->select('conversation_id')->from('threads')
                        ->whereIn('send_status', [\App\SendLog::STATUS_SEND_ERROR, \App\SendLog::STATUS_DELIVERY_ERROR]);
                });
                break;
            case 'unresolved':
                $q->whereIn('conversations.status', $unresolved);
                break;
            case 'open':
                $q->where('conversations.status', Conversation::STATUS_ACTIVE);
                break;
            case 'pending':
                $q->where('conversations.status', Conversation::STATUS_PENDING);
                break;
            case 'unassigned':
                $q->whereIn('conversations.status', $unresolved)->whereNull('conversations.user_id');
                break;
            case 'new':
                $q->where('conversations.status', Conversation::STATUS_ACTIVE);
                self::whereNeverAnswered($q);
                break;
            case 'overdue':
                $q->where('conversations.status', Conversation::STATUS_ACTIVE)
                    ->where(function ($w) use ($now) {
                        $w->where('conversations.created_at', '<', $now->copy()->subHours(\Modules\ModernUi\Services\Settings::resolutionHours()))
                            ->orWhere(function ($w2) use ($now) {
                                $w2->where('conversations.created_at', '<', $now->copy()->subHours(\Modules\ModernUi\Services\Settings::firstResponseHours()));
                                self::whereNeverAnswered($w2);
                            });
                    });
                break;
            case 'due-today':
                $q->where('conversations.status', Conversation::STATUS_ACTIVE)
                    ->whereBetween('conversations.created_at', [
                        $now->copy()->subHours(\Modules\ModernUi\Services\Settings::resolutionHours()),
                        $now->copy()->endOfDay()->subHours(\Modules\ModernUi\Services\Settings::resolutionHours()),
                    ]);
                break;
            case 'starred':
                $ids = Conversation::getUserStarredConversationIds($mailbox_id, $user->id);
                $q->whereIn('conversations.id', $ids ?: [0]);
                break;
        }
        if ($user->canSeeOnlyAssignedConversations()) {
            $q->where('conversations.user_id', $user->id);
        }

        return $q;
    }

    /** Menu counters (views flagged "counter" + saved views), cached 30s per user. */
    public static function counts($mailbox_id, $user = null)
    {
        $user = $user ?: auth()->user();
        $ver = (int)\Cache::get('modernui_counts_ver', 0);
        return \Cache::remember('modernui_counts_'.$ver.'_'.$mailbox_id.'_'.$user->id, 0.5, function () use ($mailbox_id, $user) {
            $counts = [];
            foreach (self::definitions() as $key => $def) {
                if ($def[3]) {
                    $counts[$key] = self::query($mailbox_id, $key, $user)->count();
                }
            }
            foreach (self::savedViews() as $sv) {
                $q = self::query($mailbox_id, $sv['view'], $user);
                self::applyFilters($q, self::readFilters(\Illuminate\Http\Request::create('/', 'GET', $sv['query']), $sv['view']));
                $counts['sv:'.$sv['id']] = $q->count();
            }
            return $counts;
        });
    }

    /** Invalidates counters for all users (bumps the key version). */
    public static function forgetCounts()
    {
        \Cache::forever('modernui_counts_ver', (int)\Cache::get('modernui_counts_ver', 0) + 1);
    }

    /** Shared saved views (JSON option). */
    public static function savedViews()
    {
        $raw = \App\Option::get('modernui_saved_views', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $out = [];
        foreach ((array)$raw as $sv) {
            if (is_array($sv) && !empty($sv['id']) && !empty($sv['view']) && isset(self::definitions()[$sv['view']])) {
                $sv['query'] = isset($sv['query']) && is_array($sv['query']) ? $sv['query'] : [];
                $out[] = $sv;
            }
        }
        return $out;
    }

    public static function savedView($id)
    {
        foreach (self::savedViews() as $sv) {
            if ($sv['id'] === $id) {
                return $sv;
            }
        }
        return null;
    }

    public static function savedViewUrl($mailbox_id, $sv)
    {
        return route('modernui.tickets', ['mailbox_id' => $mailbox_id, 'view' => $sv['view']])
            .'?'.http_build_query(array_merge($sv['query'], ['sv' => $sv['id']]));
    }

    protected static function whereNeverAnswered($query)
    {
        $prefix = \DB::getTablePrefix();
        $query->whereNotExists(function ($q) use ($prefix) {
            $q->select(\DB::raw(1))->from('threads')
                ->whereRaw($prefix.'threads.conversation_id = '.$prefix.'conversations.id')
                ->where('threads.type', Thread::TYPE_MESSAGE)
                ->where('threads.state', Thread::STATE_PUBLISHED)
                ->whereNotNull('threads.created_by_user_id');
        });
    }

    /* ------------------------------------------------------------------ Filters panel */

    /** Period presets (key => [label, hours back or null = all time]). */
    public static function periods()
    {
        return [
            'any'  => [__('Any time'), null],
            '5m'    => [__('Last 5 minutes'), 5 / 60],
            '15m'   => [__('Last 15 minutes'), 0.25],
            '30m'   => [__('Last 30 minutes'), 0.5],
            '1h'    => [__('Last hour'), 1],
            '4h'    => [__('Last 4 hours'), 4],
            '12h'   => [__('Last 12 hours'), 12],
            '24h'   => [__('Last 24 hours'), 24],
            'today'   => [__('Today'), 'today'],
            'yesterday'  => [__('Yesterday'), 'yesterday'],
            '7d'    => [__('Last 7 days'), 24 * 7],
            '30d'   => [__('Last 30 days'), 24 * 30],
            '60d'   => [__('Last 60 days'), 24 * 60],
            '180d'  => [__('Last 180 days'), 24 * 180],
        ];
    }

    /** Due-date presets (Resolution due before / First response due before). */
    public static function dues()
    {
        return [
            'any'    => __('Any time'),
            'overdue'  => __('Overdue'),
            'today'     => __('Today'),
            'tomorrow'  => __('Tomorrow'),
            '8h'      => __('In the next 8 hours'),
            '4h'      => __('In the next 4 hours'),
            '2h'      => __('In the next 2 hours'),
            '1h'      => __('In the next hour'),
            '30m'     => __('In the next 30 minutes'),
        ];
    }

    /** Default filters of a view (Freshdesk: "All tickets" = created in the last 30 days). */
    public static function defaultFilters($view)
    {
        return ['created' => $view === 'all' ? '30d' : 'any'];
    }

    /** Reads filters from the HTTP request (cleaned arrays). */
    public static function readFilters($request, $view)
    {
        $defaults = self::defaultFilters($view);
        $arr = function ($key) use ($request) {
            $v = $request->input($key, []);
            if (!is_array($v)) {
                $v = [$v];
            }
            return array_values(array_filter($v, function ($x) {
                return $x !== '' && $x !== null;
            }));
        };
        return [
            'q'        => trim((string)$request->input('q', '')),
            'agent'    => $arr('agent'),
            'status'   => $arr('status'),
            'priority' => $arr('priority'),
            'type'     => $arr('type'),
            'tag'      => $arr('tag'),
            'customer' => trim((string)$request->input('customer', '')),
            'created'  => (string)$request->input('created', $defaults['created']),
            'closed'   => (string)$request->input('closed', 'any'),
            'res_due'  => (string)$request->input('res_due', 'any'),
            'fr_due'   => (string)$request->input('fr_due', 'any'),
        ];
    }

    /** Number of active filters (excluding the view's defaults) — "Filters (n)" badge. */
    public static function activeCount($filters, $view)
    {
        $defaults = self::defaultFilters($view);
        $n = 0;
        foreach (['agent', 'status', 'priority', 'type', 'tag'] as $k) {
            if ($filters[$k]) {
                $n++;
            }
        }
        foreach (['q', 'customer'] as $k) {
            if ($filters[$k] !== '') {
                $n++;
            }
        }
        if ($filters['created'] !== 'any') {
            $n++;
        }
        foreach (['closed', 'res_due', 'fr_due'] as $k) {
            if ($filters[$k] !== 'any') {
                $n++;
            }
        }
        return $n;
    }

    /** Applies the panel filters to a view query. */
    public static function applyFilters($q, $f)
    {
        $now = Carbon::now();
        $prefix = \DB::getTablePrefix();

        if ($f['q'] !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $f['q']).'%';
            $num = preg_replace('/\D/', '', $f['q']);
            $q->where(function ($w) use ($like, $num, $f) {
                $w->where('conversations.subject', 'like', $like)
                    ->orWhere('conversations.preview', 'like', $like)
                    ->orWhere('conversations.customer_email', 'like', $like)
                    ->orWhereIn('conversations.customer_id', function ($s) use ($like) {
                        $s->select('id')->from('customers')
                            ->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                    })
                    ->orWhereIn('conversations.id', function ($s) use ($like) {
                        $s->select('conversation_id')->from('threads')->where('body', 'like', $like);
                    });
                if ($num !== '' && $num === ltrim($f['q'], '#')) {
                    $w->orWhere('conversations.number', (int)$num);
                }
            });
        }
        if ($f['agent']) {
            $ids = array_map('intval', $f['agent']);
            $q->where(function ($w) use ($ids) {
                $real = array_values(array_filter($ids, function ($i) {
                    return $i > 0;
                }));
                if ($real) {
                    $w->whereIn('conversations.user_id', $real);
                }
                if (in_array(-1, $ids)) {
                    $w->orWhereNull('conversations.user_id');
                }
            });
        }
        if ($f['status']) {
            $q->whereIn('conversations.status', array_map('intval', $f['status']));
        }
        if ($f['priority']) {
            $p = array_map('intval', $f['priority']);
            $q->where(function ($w) use ($p) {
                $w->whereRaw("CAST(JSON_EXTRACT(conversations.meta, '$.fd_priority') AS UNSIGNED) IN (".implode(',', $p).')');
                if (in_array(1, $p)) {
                    // No priority = "Low" (Freshdesk default)
                    $w->orWhereRaw("(conversations.meta IS NULL OR JSON_EXTRACT(conversations.meta, '$.fd_priority') IS NULL)");
                }
            });
        }
        if ($f['type']) {
            $placeholders = implode(',', array_fill(0, count($f['type']), '?'));
            $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(conversations.meta, '$.fd_type')) IN ($placeholders)", $f['type']);
        }
        if ($f['tag']) {
            $tags = array_map('intval', $f['tag']);
            $q->whereIn('conversations.id', function ($s) use ($tags) {
                $s->select('conversation_id')->from('conversation_tag')->whereIn('tag_id', $tags);
            });
        }
        if ($f['customer'] !== '') {
            $like = '%'.$f['customer'].'%';
            $q->where(function ($w) use ($like) {
                $w->where('conversations.customer_email', 'like', $like)
                    ->orWhereIn('conversations.customer_id', function ($s) use ($like) {
                        $s->select('id')->from('customers')->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                    });
            });
        }
        self::applyPeriod($q, 'conversations.created_at', $f['created']);
        self::applyPeriod($q, 'conversations.closed_at', $f['closed']);
        self::applyDue($q, $f['res_due'], \Modules\ModernUi\Services\Settings::resolutionHours(), false);
        self::applyDue($q, $f['fr_due'], \Modules\ModernUi\Services\Settings::firstResponseHours(), true);

        return $q;
    }

    protected static function applyPeriod($q, $column, $key)
    {
        $periods = self::periods();
        if (!isset($periods[$key]) || $periods[$key][1] === null) {
            return;
        }
        $v = $periods[$key][1];
        $now = Carbon::now();
        if ($v === 'today') {
            $q->where($column, '>=', $now->copy()->startOfDay());
        } elseif ($v === 'yesterday') {
            $q->whereBetween($column, [$now->copy()->subDay()->startOfDay(), $now->copy()->startOfDay()]);
        } else {
            $q->where($column, '>=', $now->copy()->subMinutes((int)round($v * 60)));
        }
    }

    /** Due date computed from creation: due = created_at + $hours. So we filter on created_at. */
    protected static function applyDue($q, $key, $hours, $first_response)
    {
        if ($key === 'any' || !isset(self::dues()[$key])) {
            return;
        }
        $now = Carbon::now();
        $q->where('conversations.status', Conversation::STATUS_ACTIVE);
        if ($first_response) {
            self::whereNeverAnswered($q);
        }
        $created_for = function ($due) use ($hours) {
            return $due->copy()->subHours($hours);
        };
        switch ($key) {
            case 'overdue':
                $q->where('conversations.created_at', '<', $created_for($now));
                break;
            case 'today':
                $q->whereBetween('conversations.created_at', [$created_for($now->copy()->startOfDay()), $created_for($now->copy()->endOfDay())]);
                break;
            case 'tomorrow':
                $q->whereBetween('conversations.created_at', [$created_for($now->copy()->addDay()->startOfDay()), $created_for($now->copy()->addDay()->endOfDay())]);
                break;
            default:
                $mins = ['8h' => 480, '4h' => 240, '2h' => 120, '1h' => 60, '30m' => 30][$key];
                $q->whereBetween('conversations.created_at', [$created_for($now), $created_for($now->copy()->addMinutes($mins))]);
        }
    }

    /* ------------------------------------------------------------------ sorts ("Sort by" menu) */

    public static function sorts()
    {
        return [
            'created'  => __('Date created'),
            'updated'  => __('Last modified'),
            'customer' => __('Last customer response'),
            'due'      => __('Resolution due date'),
            'priority' => __('Priority'),
            'status'   => __('Status'),
        ];
    }

    public static function applySort($q, $sort, $order)
    {
        $dir = $order === 'asc' ? 'asc' : 'desc';
        switch ($sort) {
            case 'updated':
                $q->orderBy('conversations.updated_at', $dir);
                break;
            case 'customer':
                $q->orderByRaw('conversations.last_customer_reply_at IS NULL')->orderBy('conversations.last_customer_reply_at', $dir);
                break;
            case 'due':
                $q->orderBy('conversations.created_at', $dir === 'desc' ? 'asc' : 'desc'); // soonest due = created earliest
                break;
            case 'priority':
                $q->orderByRaw("COALESCE(CAST(JSON_EXTRACT(conversations.meta, '$.fd_priority') AS UNSIGNED), 1) $dir");
                break;
            case 'status':
                $q->orderBy('conversations.status', $dir);
                break;
            default:
                $q->orderBy('conversations.created_at', $dir);
        }
        return $q->orderBy('conversations.id', $dir);
    }
}
