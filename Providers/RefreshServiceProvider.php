<?php

namespace Modules\Refresh\Providers;

use App\Conversation;
use App\Folder;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

/**
 * Freshdesk-style interface for FreeScout.
 *
 * Ticket list: SLA badges above the subject ("New", "First response overdue", "Overdue",
 * "Customer responded", "Pending"), status line below the subject ("Created 31 minutes ago •
 * First response due: in one day"), closed tickets dimmed.
 * Dashboard: Unresolved / Overdue / Due today / Open / Pending / Unassigned / New tiles, followed
 * by the list of unresolved tickets.
 * "All tickets", "New", "Overdue" views added to the folder column (module route).
 * Ticket: "Properties" panel (Freshdesk type, status, agent, tags, SLA) and conversation in
 * chronological order with the reply box at the bottom.
 * The styles (.mes-*) live in the Customization module's custom CSS.
 *
 * SLA (calendar hours, like Freshdesk's "Low" SLA): first response 24 h, resolution 72 h.
 * The SLA is paused (no overdue shown) for a "Pending" ticket.
 */
class RefreshServiceProvider extends ServiceProvider
{
    protected $defer = false;


    /** Fake folder type for the module's views (outside App\Folder::$types → no native folder highlighted). */
    const FOLDER_TYPE_VIRTUAL = 990;

    /** conversation_id => Carbon of the agent's first reply, or null if never answered (batch-preloaded). */
    protected static $first_replies = [];

    public function boot()
    {
        // dates in the user's language (FreeScout sets the application locale per user)
        Carbon::setLocale(app()->getLocale());
        $this->migrateFromModernUi();
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'refresh');
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'refresh');
        // Native views overridden (views menu instead of the folder column). Only the files present in
        // Resources/views/core replace the native ones. Re-check after every FreeScout update.
        \View::getFinder()->prependLocation(__DIR__.'/../Resources/views/core');
        // The "All tickets" link in the top bar leads to the module's view, like Freshdesk.
        \Eventy::addFilter('mailbox.url', function ($url, $mailbox) {
            return route('refresh.tickets', ['mailbox_id' => $mailbox->id, 'view' => 'all']);
        }, 20, 2);
        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');
        // FreeScout's own mailbox / folder pages -> equivalent Refresh view
        $this->app['router']->pushMiddlewareToGroup('web', \Modules\Refresh\Http\Middleware\NativeFolderRedirect::class);
        $this->overrideTranslations();
        $this->registerStylesheet();
        $this->registerListHooks();
        $this->registerDashboard();
        $this->registerConversationHooks();
        $this->registerSendDropdown();
        $this->registerJavascript();
        $this->registerPush();
        $this->registerSettings();

        // Dictionary of the user's language for the module's scripts (rfT), in <head> so that it is there before any script
        \Eventy::addAction('layout.head', function () {
            $locale = app()->getLocale();
            $file = __DIR__.'/../Resources/lang/'.preg_replace('/[^a-zA-Z_-]/', '', $locale).'.json';
            if ($locale !== 'en' && is_file($file)) {
                $dict = json_decode((string)file_get_contents($file), true);
                if (is_array($dict)) {
                    echo '<meta name="refresh-l10n" content="'.e(json_encode($dict, JSON_UNESCAPED_UNICODE)).'">'."\n";
                }
            }
        });
    }

    /** Manage > Settings > Refresh (Services\Settings: SLA, logo, installable app, push contact). */
    protected function registerSettings()
    {
        $keys = ['sla_first_response', 'sla_resolution', 'logo_url', 'app_name', 'app_short_name', 'app_icon_url', 'push_contact'];
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['refresh'] = ['title' => 'Refresh', 'icon' => 'blackboard', 'order' => 150];
            return $sections;
        }, 40);
        \Eventy::addFilter('settings.section_settings', function ($settings, $section) use ($keys) {
            if ($section != 'refresh') {
                return $settings;
            }
            foreach ($keys as $k) {
                $settings['refresh.'.$k] = \Option::get('refresh.'.$k, '');
            }
            if ($settings['refresh.sla_first_response'] === '') {
                $settings['refresh.sla_first_response'] = \Modules\Refresh\Services\Settings::DEFAULT_FIRST_RESPONSE_HOURS;
            }
            if ($settings['refresh.sla_resolution'] === '') {
                $settings['refresh.sla_resolution'] = \Modules\Refresh\Services\Settings::DEFAULT_RESOLUTION_HOURS;
            }
            return $settings;
        }, 20, 2);
        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section != 'refresh') {
                return $params;
            }
            return ['template_vars' => ['default_contact' => preg_replace('/^mailto:/', '', \Modules\Refresh\Services\Settings::pushContact())]];
        }, 20, 2);
        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section == 'refresh' ? 'refresh::settings' : $view;
        }, 20, 2);
    }

    /** PWA + Web Push (Services/WebPush): replaces the FreeScout app and its paid
     *  "Mobile Notifications" module. Hooks into the native "Mobile" channel: each agent sets their
     *  notifications in Profile → Notifications (Mobile column, enabled here), FreeScout works out who to
     *  notify, we send it. */
    protected function registerPush()
    {
        \Eventy::addFilter('notifications.mobile_available', function () {
            return true;
        });
        \Eventy::addAction('subscription.process_events', function ($notify) {
            if (empty($notify[\App\Subscription::MEDIUM_MOBILE])) {
                return;
            }
            foreach ($notify[\App\Subscription::MEDIUM_MOBILE] as $info) {
                try {
                    $conv = $info['conversation'];
                    $thread = \App\Subscription::chooseThread($info['threads']);
                    $who = '';
                    $by = $thread ? $thread->getCreatedBy() : null;
                    if ($by) {
                        $who = ($by instanceof \App\Customer) ? $by->getFullName(true) : $by->getFullName();
                    }
                    $text = $thread ? \Helper::textPreview($thread->body, 140) : '';
                    $payload = [
                        'title' => '#'.$conv->number.' '.$conv->getSubject(),
                        'body'  => trim($who.($who && $text ? ' : ' : '').$text) ?: __('New activity'),
                        'url'   => $conv->url(),
                        'tag'   => 'conv-'.$conv->id,
                    ];
                    foreach ($info['users'] as $user) {
                        \Modules\Refresh\Services\WebPush::sendToUser($user->id, $payload);
                    }
                } catch (\Throwable $e) {
                    \Log::error('[Refresh][WebPush] '.$e->getMessage());
                }
            }
        });
    }

    /** Module stylesheet (Public/css/refresh.css). FreeScout concatenates/minifies the stylesheets into a
     *  /css/builds/<hash>.css bundle whose name changes with the content: no ?v= needed (Minify requires a real
     *  file path). */
    protected function registerStylesheet()
    {
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath('refresh').'/css/icons.css';
            $styles[] = \Module::getPublicPath('refresh').'/css/refresh.css';
            // Mobile version, Freshdesk-app style: everything under @media (max-width: 767px), after the desktop sheet
            $styles[] = \Module::getPublicPath('refresh').'/css/mobile.css';
            return $styles;
        });
        // Editor toolbar: must be loaded with the page's scripts, before initReplyForm()
        \Eventy::addFilter('javascripts', function ($scripts) {
            $scripts[] = \Module::getPublicPath('refresh').'/js/editor.js';
            // Mobile version (< 768 px only): runs on DOMContentLoaded, after the provider's shell
            $scripts[] = \Module::getPublicPath('refresh').'/js/mobile.js';
            return $scripts;
        });
    }

    /* ------------------------------------------------------------------ Freshdesk vocabulary */

    /**
     * The module was first published as "Modern UI" (alias modernui): its settings, shared views and push keys are
     * taken over once, so upgrading loses nothing.
     */
    protected function migrateFromModernUi()
    {
        try {
            if (\App\Option::get('refresh_migrated', '')) {
                return;
            }
            foreach (\DB::table('options')->where('name', 'like', 'modernui%')->get() as $old) {
                $name = 'refresh'.substr($old->name, strlen('modernui'));
                if (!\DB::table('options')->where('name', $name)->exists()) {
                    \DB::table('options')->insert(['name' => $name, 'value' => $old->value]);
                }
            }
            $old_dir = storage_path('app/modernui');
            if (is_dir($old_dir) && !is_dir(storage_path('app/refresh'))) {
                @rename($old_dir, storage_path('app/refresh'));
            }
            \App\Option::set('refresh_migrated', 1);
        } catch (\Exception $e) {
            // not fatal: the module works with its default settings
        }
    }

    /**
     * Overrides some of FreeScout's own strings (Resources/lang/overrides/<locale>.php).
     * A module's JSON file cannot do it: the loader merges FreeScout's file last, so we load it first and add our lines.
     */
    protected function overrideTranslations()
    {
        $translator = $this->app['translator'];
        // 1) word replacements over every loaded string (<locale>.words.php), 2) exact strings (<locale>.php)
        foreach (glob(__DIR__.'/../Resources/lang/overrides/*.words.php') as $file) {
            $locale = basename($file, '.words.php');
            $words = require $file;
            $lines = [];
            foreach ((array)$translator->getLoader()->load($locale, '*', '*') as $key => $value) {
                if (is_string($value) && ($new = strtr($value, $words)) !== $value) {
                    $lines['*.'.$key] = $new;
                }
            }
            $translator->load('*', '*', $locale);
            $translator->addLines($lines, $locale, '*');
        }
        foreach (glob(__DIR__.'/../Resources/lang/overrides/*.php') as $file) {
            if (substr($file, -10) === '.words.php') {
                continue;
            }
            $locale = basename($file, '.php');
            $translator->load('*', '*', $locale);
            $translator->addLines(require $file, $locale, '*');
        }
    }

    /* ------------------------------------------------------------------ SLA */

    /** List views: URL key => label (definitions in Services\Views). */
    public static function views()
    {
        return \Modules\Refresh\Services\Views::labels();
    }

    /** Query for a view (no filters or sorting), see Services\Views::query(). */
    public static function viewQuery($mailbox_id, $view)
    {
        return \Modules\Refresh\Services\Views::query($mailbox_id, $view);
    }

    /** Adds "no published agent reply" to a conversations query. */
    protected static function whereNeverAnswered($query)
    {
        $prefix = \DB::getTablePrefix();
        $query->whereNotExists(function ($q) use ($prefix) {
            $q->select(\DB::raw(1))
                ->from('threads')
                ->whereRaw($prefix.'threads.conversation_id = '.$prefix.'conversations.id')
                ->where('threads.type', Thread::TYPE_MESSAGE)
                ->where('threads.state', Thread::STATE_PUBLISHED)
                ->whereNotNull('threads.created_by_user_id');
        });
    }

    /** Fake folder to display the native table outside a real folder. */
    public static function virtualFolder($mailbox_id, $count = 0)
    {
        $folder = new Folder();
        $folder->id = 0;
        $folder->type = self::FOLDER_TYPE_VIRTUAL;
        $folder->mailbox_id = $mailbox_id;
        $folder->active_count = $count;
        $folder->total_count = $count;

        return $folder;
    }

    /** Date of the agent's first reply (null if never answered), from the batch cache or a query. */
    public static function firstReply($conversation)
    {
        if (!array_key_exists($conversation->id, self::$first_replies)) {
            $first = Thread::where('conversation_id', $conversation->id)
                ->where('type', Thread::TYPE_MESSAGE)
                ->where('state', Thread::STATE_PUBLISHED)
                ->whereNotNull('created_by_user_id')
                ->min('created_at');
            self::$first_replies[$conversation->id] = $first ? Carbon::parse($first) : null;
        }

        return self::$first_replies[$conversation->id];
    }

    /** SLA state of a ticket. */
    public static function sla($conversation)
    {
        $now = Carbon::now();
        $created = Carbon::parse($conversation->created_at);
        $answered_at = self::firstReply($conversation);
        $first_due = $created->copy()->addHours(\Modules\Refresh\Services\Settings::firstResponseHours());
        $resolution_due = $created->copy()->addHours(\Modules\Refresh\Services\Settings::resolutionHours());
        $meta = is_array($conversation->meta) ? $conversation->meta : (json_decode((string)$conversation->meta, true) ?: []);
        if (!empty($meta['rf_due'])) {
            try {
                $resolution_due = Carbon::parse($meta['rf_due']);
            } catch (\Exception $e) {
            }
        }
        $status = (int)$conversation->status;

        return [
            'closed'           => in_array($status, [Conversation::STATUS_CLOSED, Conversation::STATUS_SPAM]),
            'pending'          => $status === Conversation::STATUS_PENDING,
            'answered'         => (bool)$answered_at,
            'customer_waiting' => (int)$conversation->last_reply_from === Conversation::PERSON_CUSTOMER,
            'first_due'        => $first_due,
            'first_overdue'    => !$answered_at && $now->gt($first_due),
            'resolution_due'   => $resolution_due,
            'resolution_overdue' => $now->gt($resolution_due),
        ];
    }

    /** Freshdesk badges of a ticket (HTML). */
    public static function badges($conversation)
    {
        $sla = self::sla($conversation);
        $badges = '';
        if ($sla['closed']) {
            return '';
        }
        if ($sla['pending']) {
            return '<span class="rf-badge rf-badge-pending">' . __('Pending') . '</span>';
        }
        if (!$sla['answered']) {
            if ($sla['first_overdue']) {
                $badges .= '<span class="rf-badge rf-badge-first" title="' . e(__('No agent reply after :hours h', ['hours' => \Modules\Refresh\Services\Settings::firstResponseHours()])) . '">' . __('First response overdue') . '</span>';
            } else {
                $badges .= '<span class="rf-badge rf-badge-new" title="' . e(__('No agent reply yet')) . '">' . __('New') . '</span>';
            }
        }
        if ($sla['resolution_overdue']) {
            $badges .= '<span class="rf-badge rf-badge-late" title="' . e(__('Resolution expected within :hours h', ['hours' => \Modules\Refresh\Services\Settings::resolutionHours()])) . '">' . __('Overdue') . '</span>';
        }
        if ($sla['answered'] && $sla['customer_waiting']) {
            $badges .= '<span class="rf-badge rf-badge-customer">' . __('Customer responded') . '</span>';
        }

        return $badges;
    }

    /** Freshdesk status line: "Customer responded 1 day ago • Resolution overdue by 4 days". */
    public static function statusLine($conversation)
    {
        $sla = self::sla($conversation);
        $now = Carbon::now();
        $last = $conversation->last_reply_at ? Carbon::parse($conversation->last_reply_at) : Carbon::parse($conversation->created_at);

        if ($sla['closed']) {
            // Freshdesk: "Name • Closed 3 hours ago • Resolved on time / Resolved late"
            $closed = $conversation->closed_at ? Carbon::parse($conversation->closed_at) : $last;
            $who = '';
            if ($conversation->customer_id && $conversation->customer) {
                $who = '<span class="rf-meta rf-meta-who"><i class="rf-i rf-i-fd-email rf-i-sm"></i> '.e($conversation->customer->getFullName(true)).'</span><span class="rf-meta-sep">•</span>';
            }
            $in_time = $closed->lte($sla['resolution_due']);
            return $who.'<span class="rf-meta rf-meta-closed" data-closed="'.$closed->timestamp.'">' . __('Closed for') . ' '.e($closed->diffForHumans()).'</span><span class="rf-meta-sep">•</span>'
                .'<span class="rf-meta">'.($in_time ? __('Resolved on time') : __('Resolved late')).'</span>';
        }

        if (!$sla['answered']) {
            $left = __('Created') . ' '.Carbon::parse($conversation->created_at)->diffForHumans();
        } elseif ($sla['customer_waiting']) {
            $left = __('Customer responded') . ' '.$last->diffForHumans();
        } elseif ((int)$conversation->last_reply_from === Conversation::PERSON_USER) {
            $left = __('Agent responded') . ' '.$last->diffForHumans();
        } else {
            $left = __('Updated') . ' '.$last->diffForHumans();
        }

        if ($sla['pending']) {
            $right = '<i class="rf-i rf-i-fd-hourglass rf-i-sm"></i> ' . __('Waiting on customer (SLA paused)') . '';
        } elseif (!$sla['answered']) {
            if ($sla['first_overdue']) {
                $right = '<i class="rf-i rf-i-fd-reply rf-i-sm"></i> ' . __('First response overdue by') . ' '.e($now->diffForHumans($sla['first_due'], true));
            } else {
                $right = '<i class="rf-i rf-i-fd-reply rf-i-sm"></i> ' . __('First response due in') . ' '.e($now->diffForHumans($sla['first_due'], true));
            }
        } elseif ($sla['resolution_overdue']) {
            $right = '<i class="rf-i rf-i-fd-hourglass rf-i-sm"></i> ' . __('Resolution overdue by') . ' '.e($now->diffForHumans($sla['resolution_due'], true));
        } else {
            $right = '<i class="rf-i rf-i-fd-hourglass rf-i-sm"></i> ' . __('Resolution due in') . ' '.e($now->diffForHumans($sla['resolution_due'], true));
        }

        // due date of the running SLA, for the mobile cards (the text above depends on the language)
        $due = $sla['pending'] ? null : ($sla['answered'] ? $sla['resolution_due'] : $sla['first_due']);

        $who = '';
        if ($conversation->customer_id && $conversation->customer) {
            $who = '<span class="rf-meta rf-meta-who"><i class="rf-i rf-i-fd-email rf-i-sm"></i> '.e($conversation->customer->getFullName(true)).'</span><span class="rf-meta-sep">•</span>';
        }

        return $who.'<span class="rf-meta">'.e($left).'</span><span class="rf-meta-sep">•</span><span class="rf-meta rf-meta-sla'.(($sla['first_overdue'] || $sla['resolution_overdue']) && !$sla['pending'] ? ' rf-meta-overdue' : '').'"'.($due ? ' data-due="'.$due->timestamp.'"' : '').'>'.$right.'</span>';
    }

    /* ------------------------------------------------------------------ ticket list */

    protected function registerListHooks()
    {
        // Batch-preload the agent's first reply (avoids one query per row).
        \Eventy::addFilter('conversations_table.preload_table_data', function ($conversations) {
            $ids = [];
            foreach ($conversations as $conversation) {
                $ids[] = $conversation->id;
            }
            if ($ids) {
                foreach ($ids as $id) {
                    self::$first_replies[$id] = null;
                }
                $rows = Thread::select('conversation_id', \DB::raw('MIN(created_at) as first_at'))
                    ->whereIn('conversation_id', $ids)
                    ->where('type', Thread::TYPE_MESSAGE)
                    ->where('state', Thread::STATE_PUBLISHED)
                    ->whereNotNull('created_by_user_id')
                    ->groupBy('conversation_id')
                    ->get();
                foreach ($rows as $row) {
                    self::$first_replies[$row->conversation_id] = Carbon::parse($row->first_at);
                }
            }

            return $conversations;
        });

        // SLA badges before the tags (negative priority: the Tags module writes before 5).
        \Eventy::addAction('conversations_table.before_subject', function ($conversation) {
            if (!$conversation) {
                return;
            }
            $badges = self::badges($conversation);
            if ($badges) {
                echo '<span class="rf-badges">'.$badges.'</span>';
            }
        }, -10);

        // Line separator after badges + tags (priority 500: after the Tags module which writes at 200): empty block →
        // the subject wraps to a new line; if there is neither badge nor tag, the line above takes up no height
        // (unlike a <br>).
        \Eventy::addAction('conversations_table.before_subject', function ($conversation) {
            echo '<span class="rf-subject-start"></span>';
        }, 500);

        // Ticket number after the subject (the native "Number" column is hidden in CSS).
        \Eventy::addAction('conversations_table.after_subject', function ($conversation) {
            if ($conversation) {
                // data-created: creation date (timestamp), read by the mobile version ("Created 41m ago" pill)
                echo ' <span class="rf-num" data-created="'.Carbon::parse($conversation->created_at)->timestamp.'">#'.(int)$conversation->number.'</span>';
            }
        });

        // Status line below the subject, the message preview moves under it.
        \Eventy::addAction('conversations_table.preview_prepend', function ($conversation) {
            if ($conversation) {
                echo '<span class="rf-status-line">'.self::statusLine($conversation).'</span>';
            }
        });

        \Eventy::addAction('conversations_table.row_class', function ($conversation) {
            if (!$conversation) {
                return;
            }
            $sla = self::sla($conversation);
            if ($sla['closed']) {
                echo ' conv-closed';
            } elseif (!$sla['pending'] && ($sla['first_overdue'] || $sla['resolution_overdue'])) {
                echo ' conv-overdue';
            }
            if (!$sla['closed'] && $sla['customer_waiting']) {
                echo ' conv-last-customer';
            }
            // Never answered by an agent ("New" / "First response overdue" badges): subject in bold, like Freshdesk
            if (!$sla['closed'] && !$sla['answered']) {
                echo ' rf-unanswered';
            }
        });

        // Freshdesk-style side column, before the Number column: agent / status / due stacked
        // (the native Agent, Number and Since columns are collapsed to 0 in CSS).
        \Eventy::addAction('conversations_table.col_before_conv_number', function () {
            echo '<col class="rf-col-side">';
        });
        \Eventy::addAction('conversations_table.th_before_conv_number', function () {
            echo '<th class="rf-col-side"><span>' . __('Priority · Agent · Status') . '</span></th>';
        });
        \Eventy::addAction('conversations_table.td_before_conv_number', function ($conversation) {
            if (!$conversation) {
                echo '<td class="rf-col-side"></td>';
                return;
            }
            $url = $conversation->url();
            $meta = is_string($conversation->meta) ? json_decode($conversation->meta, true) : $conversation->meta;
            $prio = (int)((is_array($meta) && !empty($meta['fd_priority'])) ? $meta['fd_priority'] : 1);
            $prios = \Modules\Refresh\Services\Views::priorities();
            $prio = isset($prios[$prio]) ? $prio : 1;
            $agent = ($conversation->user_id && $conversation->user) ? $conversation->user->getFullName() : '--';
            $status = (int)$conversation->status;
            $cid = (int)$conversation->id;
            $chev = '<i class="rf-i rf-i-fd-chevron-down rf-dd-chev"></i>';
            echo '<td class="rf-col-side"><div class="rf-side">'
                .'<span class="rf-dd rf-side-prio" data-field="priority" data-conv="'.$cid.'" data-value="'.$prio.'"><i class="rf-sq" style="background:'.$prios[$prio][1].'"></i><span class="rf-dd-label">'.e($prios[$prio][0]).'</span>'.$chev.'</span>'
                .'<span class="rf-dd rf-side-agent" data-field="agent" data-conv="'.$cid.'" data-value="'.($conversation->user_id ?: -1).'"><i class="rf-i rf-i-fd-agent rf-i-sm"></i><span class="rf-dd-label">'.e($agent).'</span>'.$chev.'</span>'
                .'<span class="rf-dd rf-side-status" data-field="status" data-conv="'.$cid.'" data-value="'.$status.'"><i class="rf-i rf-i-fd-status rf-i-sm"></i><span class="rf-dd-label">'.e($conversation->getStatusName()).'</span>'.$chev.'</span>'
                .'</div></td>';
        });

        // Name of the module's fake folder for the views (left column title).
        \Eventy::addFilter('folder.type_name', function ($name, $folder) {
            if ((int)$folder->type === self::FOLDER_TYPE_VIRTUAL) {
                $view = request()->route('view') ?: 'all';
                $views = self::views();
                return $views[$view] ?? __('Tickets');
            }
            return $name;
        }, 20, 2);
    }

    /* ------------------------------------------------------------------ dashboard */

    protected function registerDashboard()
    {
        \Eventy::addFilter('dashboard.before', function ($html) {
            $user = auth()->user();
            $mailboxes = $user->mailboxesCanView();
            $mailbox = $mailboxes->first();
            if (!$mailbox) {
                return $html;
            }

            $views = self::views();
            $tiles = [];
            $short = ['unresolved' => __('Unresolved'), 'overdue' => __('Overdue'), 'due-today' => __('Due: today'),
                'open' => __('refresh::labels.open'), 'pending' => __('Pending'), 'unassigned' => __('Unassigned')];
            foreach (['unresolved', 'overdue', 'due-today', 'open', 'pending', 'unassigned'] as $key) {
                $tiles[] = [
                    'key'   => $key,
                    'label' => $short[$key],
                    'count' => self::viewQuery($mailbox->id, $key)->count(),
                    'url'   => route('refresh.tickets', ['mailbox_id' => $mailbox->id, 'view' => $key]),
                ];
            }

            $conversations = self::viewQuery($mailbox->id, 'unresolved')
                ->orderBy('conversations.last_reply_at', 'desc')
                ->paginate(25);

            return $html.view('refresh::dashboard', [
                'stats'         => \Modules\Refresh\Services\Dashboard::stats($mailbox->id),
                'tiles'         => $tiles,
                'conversations' => $conversations,
                'folder'        => self::virtualFolder($mailbox->id, $conversations->total()),
                'mailbox'       => $mailbox,
                'all_url'       => route('refresh.tickets', ['mailbox_id' => $mailbox->id, 'view' => 'all']),
            ])->render();
        });
    }

    /* ------------------------------------------------------------------ ticket */

    protected function registerConversationHooks()
    {
        // Freshdesk-style right panels (status + properties | contact), at the top of the column (before Cobrowse).
        \Eventy::addAction('conversation.after_customer_sidebar', function ($conversation) {
            if (!$conversation) {
                return;
            }
            $V = '\Modules\Refresh\Services\Views';
            $meta = is_array($conversation->meta) ? $conversation->meta : (json_decode((string)$conversation->meta, true) ?: []);
            $priorities = $V::priorities();
            $priority = (int)($meta['fd_priority'] ?? 1);
            if (!isset($priorities[$priority])) {
                $priority = 1;
            }
            $mailbox = $conversation->mailbox;
            $customer = $conversation->customer;

            // SLA (same rule as the list): text + dated due date
            $sla = self::sla($conversation);
            $now = Carbon::now();
            if ($sla['closed']) {
                $sla_text = __('Resolved');
                $sla_date = $conversation->closed_at ? self::localDate(Carbon::parse($conversation->closed_at)) : '';
                $overdue = false;
            } elseif ($sla['pending']) {
                $sla_text = __('Waiting on customer (SLA paused)');
                $sla_date = '';
                $overdue = false;
            } elseif (!$sla['answered']) {
                $overdue = $sla['first_overdue'];
                $sla_text = $overdue
                    ? __('First response overdue by') . ' '.$now->diffForHumans($sla['first_due'], true)
                    : __('First response due in') . ' '.$now->diffForHumans($sla['first_due'], true);
                $sla_date = self::localDate($sla['first_due']);
            } else {
                $overdue = $sla['resolution_overdue'];
                $sla_text = $overdue
                    ? __('Resolution overdue by') . ' '.$now->diffForHumans($sla['resolution_due'], true)
                    : __('Resolution due in') . ' '.$now->diffForHumans($sla['resolution_due'], true);
                $sla_date = self::localDate($sla['resolution_due']);
            }

            // Contact: e-mails, phones, recent timeline
            $emails = [];
            $phones = [];
            $recent = [];
            $initial = '?';
            $av = ['#eef2ff', '#c7d2fe', '#475569'];
            if ($customer) {
                $emails = $customer->getEmailsAsArray();
                $labels = [1 => __('Work phone'), 2 => __('Landline'), 3 => __('Phone'), 4 => __('Mobile'), 5 => __('Fax'), 6 => __('Pager')];
                foreach ($customer->getPhones() as $ph) {
                    if (!empty($ph['value'])) {
                        $phones[] = ['label' => $labels[(int)($ph['type'] ?? 3)] ?? __('Phone'), 'value' => $ph['value']];
                    }
                }
                $name = $customer->getFullName(true);
                $initial = mb_strtoupper(mb_substr(trim($name) ?: '?', 0, 1));
                $hue = 0;
                foreach (preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                    $hue = ($hue * 31 + mb_ord($ch)) % 360;
                }
                $av = ['hsl('.$hue.', 60%, 92%)', 'hsl('.$hue.', 55%, 84%)', 'hsl('.$hue.', 45%, 32%)'];
                $recent = Conversation::where('customer_id', $customer->id)
                    ->where('state', Conversation::STATE_PUBLISHED)
                    ->orderBy('created_at', 'desc')->limit(5)->get();
                foreach ($recent as $rc) {
                    $rc->rf_date = self::localDate(Carbon::parse($rc->created_at));
                }
            }

            $statuses = [];
            foreach ([Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING, Conversation::STATUS_CLOSED] as $code) {
                $statuses[$code] = Conversation::statusCodeToName($code);
            }

            $author = '';
            if ($conversation->created_by_user_id && ($cu = \App\User::find($conversation->created_by_user_id))) {
                $author = $cu->getFullName();
            }
            $due_input = $sla['resolution_due']->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\\TH:i');
            echo view('refresh::properties', [
                'author'       => $author,
                'due_input'    => $due_input,
                'due_custom'   => !empty($meta['rf_due']),
                'conversation' => $conversation,
                'status_name'  => $conversation->getStatusName(),
                'sla_text'     => $sla_text,
                'sla_date'     => $sla_date,
                'sla_overdue'  => $overdue,
                'types'        => $V::types(),
                'fd_type'      => (string)($meta['fd_type'] ?? ''),
                'statuses'     => $statuses,
                'priorities'   => $priorities,
                'priority'     => $priority,
                'users'        => $mailbox ? $mailbox->usersAssignable() : collect([]),
                'customer'     => $customer,
                'emails'       => $emails,
                'phones'       => $phones,
                'recent'       => $recent,
                'initial'      => $initial,
                'av_bg'        => $av[0],
                'av_border'    => $av[1],
                'av_fg'        => $av[2],
            ])->render();
        }, 10);
    }

    /** Freshdesk-style date in the user's language: "Fri 25 Sep 2026, 12:59" (fr: "ven. 25 sept. 2026, 12:59"). */
    public static function localDate($date)
    {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $d = $date->copy()->setTimezone(config('app.timezone'));
        return __($days[(int)$d->format('w')]).' '.(int)$d->format('j').' '.__($months[(int)$d->format('n')]).' '.$d->format('Y').', '.$d->format('H:i');
    }

    /** Freshdesk entries in the send button's dropdown arrow: send then close / set pending / leave open. */
    protected function registerSendDropdown()
    {
        // Added at the END of the menu then moved to the top by the JS: main.js binds its "after send" choices to the
        // first 3 links (`.dropdown-after-send a:lt(3)`); with prepend, our entries were the ones picked up and the
        // native choices stopped working.
        \Eventy::addAction('conversation.append_send_dropdown', function ($conversation, $mailbox, $new_conversation) {
            if ($new_conversation) {
                return;
            }
            echo '<li class="divider rf-send-as-li"></li>'
                .'<li class="dropdown-header rf-send-as-li">' . __('Send and set as') . '</li>'
                .'<li class="rf-send-as-li"><a href="#" class="rf-send-as" data-status="'.Conversation::STATUS_PENDING.'">' . __('Pending') . '</a></li>'
                .'<li class="rf-send-as-li"><a href="#" class="rf-send-as" data-status="'.Conversation::STATUS_CLOSED.'">' . __('Closed') . '</a></li>'
                .'<li class="rf-send-as-li"><a href="#" class="rf-send-as" data-status="'.Conversation::STATUS_ACTIVE.'">' . __('refresh::labels.open') . '</a></li>';
        }, 10, 3);
    }

    /* ------------------------------------------------------------------ JavaScript (CSP: never inline in the views) */

    protected function registerJavascript()
    {
        \Eventy::addAction('javascript', function () {
            $mailbox_id = $this->currentMailboxId();
            $current = request()->route('view') ?: '';
            $u = auth()->user();
            $mb = $mailbox_id ?: (($u && $u->mailboxesCanView()->first()) ? $u->mailboxesCanView()->first()->id : 0);
            $rail = [
                ['url' => route('dashboard'), 'icon' => 'nav-dashboard', 'label' => __('Dashboard'), 'active' => \Route::is('dashboard')],
                ['url' => route('refresh.tickets.last'), 'icon' => 'fd-all-tickets', 'label' => __('Tickets'),
                    'active' => \Route::is('refresh.tickets') || \Route::is('mailboxes.view') || \Route::is('mailboxes.view.folder') || \Route::is('conversations.view') || \Route::is('conversations.create')],
                ['url' => route('refresh.contacts'), 'icon' => 'contact', 'label' => __('Contacts'), 'active' => \Route::is('refresh.contacts') || \Route::is('customers.*')],
            ];
            if ($mb && \Route::has('mailboxes.saved_replies')) {
                $rail[] = ['url' => route('mailboxes.saved_replies', ['id' => $mb]), 'icon' => 'canned-responses', 'label' => __('Saved Replies'), 'active' => \Route::is('mailboxes.saved_replies')];
            }
            if ($u && $u->isAdmin() && \Route::has('tags.tags')) {
                $rail[] = ['url' => route('tags.tags'), 'icon' => 'items', 'label' => __('Tags'), 'active' => \Route::is('tags.tags')];
            }
            // Entries added by other modules, with their own icon (e.g. the Cobrowse module):
            // \Eventy::addFilter('refresh.rail_items', fn($items) => [...$items, ['url' =>, 'label' =>, 'icon' => SVG URL, 'active' => bool, 'order' => int]])
            // (the "modernui.rail_items" filter of this module's first releases, then named Modern UI, still works)
            $extra = \Eventy::filter('refresh.rail_items', \Eventy::filter('modernui.rail_items', []));
            usort($extra, function ($a, $b) {
                return ($a['order'] ?? 100) <=> ($b['order'] ?? 100);
            });
            foreach ($extra as $item) {
                if (!empty($item['url']) && !empty($item['label']) && !empty($item['icon'])) {
                    $rail[] = ['url' => (string)$item['url'], 'icon_url' => (string)$item['icon'], 'label' => (string)$item['label'], 'active' => !empty($item['active'])];
                }
            }
            // Logo of the left bar: Refresh setting, else the header logo (Customization module or FreeScout's own)
            $rail_logo = \Modules\Refresh\Services\Settings::logoUrl();
            $new_ticket_url = $mb ? route('conversations.create', ['mailbox_id' => $mb]) : '';
            // Options for the list cards' dropdown menus
            $dd_opts = ['priority' => [], 'agent' => [['v' => -1, 'l' => '--']], 'status' => []];
            foreach (\Modules\Refresh\Services\Views::priorities() as $code => $pr) {
                $dd_opts['priority'][] = ['v' => $code, 'l' => $pr[0], 'c' => $pr[1]];
            }
            $mbObj = $mb ? \App\Mailbox::find($mb) : null;
            if ($mbObj) {
                foreach ($mbObj->usersAssignable() as $au) {
                    $dd_opts['agent'][] = ['v' => $au->id, 'l' => $au->getFullName()];
                }
            }
            foreach ([Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING, Conversation::STATUS_CLOSED] as $code) {
                $dd_opts['status'][] = ['v' => $code, 'l' => Conversation::statusCodeToName($code)];
            }
            // Top bar breadcrumb: [[label, url], [current label]]
            $crumbs = [];
            if (\Route::is('conversations.view')) {
                $cv = Conversation::find((int)request()->route('id'));
                if ($cv && $cv->mailbox) {
                    $reply_info = ['fromName' => $cv->mailbox->name, 'from' => $cv->mailbox->email, 'to' => $cv->customer_email];
                }
                if ($cv && $mb) {
                    // First item = last view visited (cookie), like Freshdesk; falls back to "All tickets"
                    $lv = \Modules\Refresh\Http\Controllers\TicketsController::lastView(request());
                    $crumbs = [$lv ? [$lv['t'], $lv['u']] : [__('All tickets'), route('refresh.tickets', ['mailbox_id' => $mb, 'view' => 'all'])], [(string)$cv->number]];
                }
            } elseif (\Route::is('customers.*')) {
                $cu = \App\Customer::find((int)request()->route('id'));
                if ($cu) {
                    $crumbs = [[__('All contacts'), route('refresh.contacts')], [$cu->getFullName(true)]];
                }
            }
            $is_view_route = \Route::is('refresh.tickets') ? 'true' : 'false';
            $is_conv = \Route::is('conversations.view') ? 'true' : 'false';
            $reply_info = isset($reply_info) ? $reply_info : null;
            ?>
            // Refresh translations: dictionary of the user's language, put in <head> by the module (meta refresh-l10n).
            var rfT = window.rfT = window.rfT || function (s) {
                if (!window.rfL) {
                    try { window.rfL = JSON.parse(document.querySelector('meta[name="refresh-l10n"]').getAttribute('content')); } catch (e) { window.rfL = {}; }
                }
                return window.rfL[s] || s;
            };
            // translated labels of the stylesheets (content: var(--rf-t-…)), set through the CSSOM (allowed by the CSP)
            (function (st) {
                var l = { loading: 'Loading…', cancel: 'Cancel', assign: 'Assign', status: 'Status', 'delete': 'Delete', tags: 'Tags' };
                for (var k in l) { st.setProperty('--rf-t-' + k, JSON.stringify(rfT(l[k]))); }
            })(document.documentElement.style);
            // Icon-only buttons of FreeScout's side menus (e.g. the arrow under the mailbox settings menu): show their
            // tooltip as a label, an arrow alone says nothing
            $('a.btn-sidebar').each(function () {
                var a = $(this), t = $.trim(a.attr('title') || a.attr('data-original-title') || '');
                if (t && !$.trim(a.text()) && !a.find('.rf-btn-label').length) {
                    a.addClass('rf-btn-labelled').append($('<span class="rf-btn-label"></span>').text(t));
                }
            });
            // Customer without photo: initial on a pastel color (same colors as the contact list) instead of the grey placeholder
            $('img.customer-photo[src*="default-avatar"]').each(function () {
                var name = $.trim($(this).closest('.customer-snippet').find('.customer-name').first().text()), hue = 0;
                for (var i = 0; i < name.length; i++) { hue = (hue * 31 + name.charCodeAt(i)) % 360; }
                $(this).replaceWith($('<span class="rf-av"></span>').text((name.charAt(0) || '?').toUpperCase())
                    .css({ background: 'hsl(' + hue + ', 60%, 92%)', borderColor: 'hsl(' + hue + ', 55%, 84%)', color: 'hsl(' + hue + ', 45%, 32%)' }));
            });
            // New ticket form: FreeScout shows "#Pending" until the ticket gets its number, which reads like a status
            $('.conv-new-number').each(function () { if (!/\d/.test($(this).text())) { $(this).closest('.conv-info').hide(); } });
            // Modules page: the "Active" badge shares its translation key with the ticket status, which this module
            // renames "Open" (French "Ouvert"); a module is "enabled"
            $('.module-card.active h4 .label-success').text(<?php echo json_encode(__('refresh::labels.enabled')); ?>);
            // mobile version (Public/js/mobile.js): "Account" page
            window.rfMe = <?php echo json_encode($u ? [
                'name' => $u->getFullName(), 'email' => $u->email, 'host' => request()->getHost(),
                'profile' => route('users.profile', ['id' => $u->id]), 'notifications' => route('users.notifications', ['id' => $u->id]),
                'mailbox' => $mbObj ? $mbObj->email : '',
            ] : null); ?>;
            (function () {
                var mailboxId = <?php echo (int)$mailbox_id; ?>;

                // ------------------------------------------------------------ floating messages as Freshdesk toasts
                // The CSS (refresh.css, "Freshdesk toast") handles the look; here, the close cross, on messages set
                // at load time (session flash) as well as on ones created later by showFloatingAlert().
                (function () {
                    var decorate = function () {
                        $('.alert-floating').not('.rf-toast').each(function () {
                            var a = $(this).addClass('rf-toast');
                            $('<button type="button" class="rf-toast-x" title="' + rfT('Close') + '"><i class="rf-i rf-i-cross-big"></i></button>')
                                .on('click', function () { a.remove(); })
                                .appendTo(a);
                        });
                    };
                    decorate();
                    if (window.MutationObserver) {
                        new MutationObserver(decorate).observe(document.body, { childList: true });
                    }
                })();

                // ------------------------------------------------------------ PWA + notifications
                // Module manifest (the core's has no name: site not installable), white status bar, service worker
                // (route refresh.pwa.sw, Resources/js/service-worker.js) which receives Web Push notifications even
                // with the app closed. See PushController / WebPush.
                (function () {
                    var mf = document.querySelector('link[rel="manifest"]');
                    if (mf) { mf.setAttribute('href', <?php echo json_encode(route('refresh.pwa.manifest')); ?>); }
                    if (!document.querySelector('meta[name="theme-color"]')) { $('head').append('<meta name="theme-color" content="#ffffff">'); }
                    if (!('serviceWorker' in navigator) || !window.PushManager || !window.Notification) { return; }
                    var csrf = function () { return $('meta[name="csrf-token"]').attr('content'); };
                    var keyBytes = function (b64) {
                        var pad = '='.repeat((4 - b64.length % 4) % 4);
                        var raw = window.atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
                        var out = new Uint8Array(raw.length);
                        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
                        return out;
                    };
                    var regP = navigator.serviceWorker.register(<?php echo json_encode(route('refresh.pwa.sw')); ?>, { scope: '/' });
                    var current = function () {
                        return regP.then(function (reg) { return reg.pushManager.getSubscription(); });
                    };
                    var enable = function () {
                        return Notification.requestPermission().then(function (perm) {
                            if (perm !== 'granted') { throw new Error('refus'); }
                            return $.getJSON(<?php echo json_encode(route('refresh.push.key')); ?>);
                        }).then(function (r) {
                            return regP.then(function (reg) {
                                return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(r.key) });
                            });
                        }).then(function (sub) {
                            return $.ajax({ url: <?php echo json_encode(route('refresh.push.subscribe')); ?>, type: 'POST', contentType: 'application/json',
                                headers: { 'X-CSRF-TOKEN': csrf() }, data: JSON.stringify({ subscription: sub.toJSON() }) });
                        });
                    };
                    var disable = function () {
                        return current().then(function (sub) {
                            if (!sub) { return; }
                            var ep = sub.endpoint;
                            return sub.unsubscribe().then(function () {
                                return $.post(<?php echo json_encode(route('refresh.push.unsubscribe')); ?>, { _token: csrf(), endpoint: ep });
                            });
                        });
                    };
                    window.rfPush = { current: current, enable: enable, disable: disable };

                    // Profile → Notifications (one's own): "Notifications on this device" box
                    <?php if (\Route::is('users.notifications') && auth()->check() && (int)request()->route('id') === (int)auth()->user()->id) { ?>
                    var form = $('form.user-subscriptions').first();
                    if (form.length) {
                        var box = $('<div class="rf-push-box"><div class="rf-push-head"><i class="rf-i rf-i-m-bell"></i> <strong>' + rfT('Notifications on this device') + '</strong></div>'
                            + '<p class="rf-push-state"></p><div class="rf-push-btns">'
                            + '<button type="button" class="btn btn-primary rf-push-on">' + rfT('Turn on') + '</button> '
                            + '<button type="button" class="btn btn-default rf-push-off">' + rfT('Turn off') + '</button> '
                            + '<button type="button" class="btn btn-default rf-push-test">' + rfT('Send a test') + '</button></div>'
                            + '<p class="rf-push-help">' + rfT('Notified events are the ones checked in the') + ' <strong>Mobile</strong> ' + rfT('column below. On a phone, install the app first: Chrome\'s ⋮ menu →') + ' <em>' + rfT('Install app') + '</em>.</p></div>');
                        form.before(box);
                        var refresh = function () {
                            current().then(function (sub) {
                                var denied = Notification.permission === 'denied';
                                box.find('.rf-push-state').text(denied
                                    ? rfT('Notifications are blocked for this site: allow them in the browser settings (padlock in the address bar).')
                                    : (sub ? rfT('Turned on on this device.') : rfT('Turned off on this device.')));
                                box.toggleClass('is-on', !!sub);
                                box.find('.rf-push-on').toggle(!sub && !denied);
                                box.find('.rf-push-off, .rf-push-test').toggle(!!sub);
                            });
                        };
                        refresh();
                        box.on('click', '.rf-push-on', function () {
                            enable().then(function () { if (window.showFloatingAlert) { showFloatingAlert('success', rfT('Notifications turned on')); } setTimeout(function () { window.location.reload(); }, 800); },
                                function () { if (window.showFloatingAlert) { showFloatingAlert('error', rfT('Could not turn on notifications')); } refresh(); });
                        });
                        box.on('click', '.rf-push-off', function () { disable().then(refresh, refresh); });
                        box.on('click', '.rf-push-test', function () {
                            $.post(<?php echo json_encode(route('refresh.push.test')); ?>, { _token: csrf() }, function (r) {
                                if (window.showFloatingAlert) { showFloatingAlert(r.status === 'success' ? 'success' : 'error', r.msg); }
                            }, 'json');
                        });
                    }
                    <?php } ?>

                    // App installed (fullscreen) without notifications: offer once to turn them on
                    var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
                    var asked = false;
                    try { asked = window.localStorage.getItem('rf_push_asked') === '1'; } catch (e) {}
                    if (standalone && !asked && Notification.permission === 'default') {
                        current().then(function (sub) {
                            if (sub) { return; }
                            var bar = $('<div class="rf-push-ask"><span>' + rfT('Get notifications on this phone?') + '</span>'
                                + '<button type="button" class="rf-push-ask-no">' + rfT('Later') + '</button><button type="button" class="rf-push-ask-yes">' + rfT('Turn on') + '</button></div>');
                            var done = function () { try { window.localStorage.setItem('rf_push_asked', '1'); } catch (e) {} bar.remove(); };
                            bar.on('click', '.rf-push-ask-no', done);
                            bar.on('click', '.rf-push-ask-yes', function () {
                                enable().then(function () { if (window.showFloatingAlert) { showFloatingAlert('success', rfT('Notifications turned on')); } }, function () {});
                                done();
                            });
                            $('body').append(bar);
                        });
                    }
                })();

                // ------------------------------------------------------------ Freshdesk shell
                // Left rail (65 px, gray): logo + links; the native "Manage" menu is moved there (gear icon).
                // Gray top bar: view title or breadcrumb on the left; New, Search, bell, avatar on the right.
                (function () {
                    var rail = <?php echo json_encode($rail); ?>;
                    var newUrl = <?php echo json_encode($new_ticket_url); ?>;
                    var crumbs = <?php echo json_encode($crumbs); ?>;
                    // top bar search: always "All tickets" (the rail, on the other hand, points to /tickets = last view)
                    var ticketsUrl = <?php echo json_encode($mb ? route('refresh.tickets', ['mailbox_id' => $mb, 'view' => 'all']) : '#'); ?>;
                    var nav = $('.navbar-static-top');
                    if (!nav.length || $('.rf-rail').length) { return; }
                    var r = $('<nav class="rf-rail"></nav>');
                    var brand = nav.find('.navbar-brand').first();
                    r.append($('<a class="rf-rail-logo"></a>').attr('href', rail[0].url).append($('<img alt="">').attr('src', <?php echo json_encode($rail_logo); ?>)));
                    var list = $('<div class="rf-rail-links"></div>');
                    var railIcon = function (it) {
                        var i = $('<i class="rf-i"></i>');
                        if (it.icon_url) {
                            i[0].style.setProperty('--rf-i', 'url("' + String(it.icon_url).replace(/"/g, '%22') + '")'); // icon shipped by the module
                        } else {
                            i.addClass('rf-i-' + (it.icon || 'items'));
                        }
                        return i;
                    };
                    var railHrefs = {};
                    var addRailLink = function (it) {
                        var key = String(it.url).replace(/[?#].*$/, '').replace(/\/$/, '');
                        if (railHrefs[key]) { return; }
                        railHrefs[key] = true;
                        list.append(
                            $('<a class="rf-rail-link" data-toggle="tooltip" data-placement="right"></a>')
                                .attr('href', it.url).attr('title', it.label).toggleClass('active', !!it.active)
                                .append(railIcon(it))
                        );
                    };
                    $.each(rail, function (i, it) { addRailLink(it); });
                    // Links that modules add to FreeScout's top menu (menu.append) without knowing Refresh:
                    // same entry in the left bar, generic icon (the top menu is hidden by the theme).
                    nav.find('.navbar-nav').first().children('li').not('.dropdown').children('a[href]').each(function () {
                        var href = this.href;
                        if (!href || /\/mailbox\//.test(href) || href === window.location.origin + '/') { return; }
                        addRailLink({ url: href, label: $.trim($(this).text()), icon: 'grid', active: $(this).parent().hasClass('active') });
                    });
                    r.append(list);
                    // "Manage" (settings, users, mailboxes…) → gear icon at the bottom of the rail
                    var manage = nav.find('.navbar-nav > li.dropdown').filter(function () {
                        return $(this).find('> a').text().indexOf('<?php echo addslashes(__('Manage')); ?>') !== -1;
                    }).first();
                    if (manage.length) {
                        var gear = $('<div class="dropdown dropup rf-rail-manage"></div>');
                        gear.append('<a href="#" class="rf-rail-link dropdown-toggle" data-toggle="dropdown" title="Administration"><i class="rf-i rf-i-settings"></i></a>');
                        gear.append(manage.find('> ul.dropdown-menu').first().addClass('rf-rail-menu'));
                        // an icon in front of each entry (FreeScout's own, then the usual module ones by address)
                        var railMenuIcons = [
                            [/\/app-settings/, 'settings'], [/\/mailboxes$/, 'inbox'], [/\/tags/, 'items'], [/\/users$/, 'groups'],
                            [/\/modules/, 'grid'], [/translations/, 'multilingual'], [/\/(app-)?logs/, 'recent'], [/\/system/, 'info']
                        ];
                        gear.find('.rf-rail-menu > li > a').each(function () {
                            var a = $(this), path = (a.attr('href') || '').replace(/^https?:\/\/[^\/]+/, '').split('?')[0], icon = 'folder';
                            $.each(railMenuIcons, function (i, m) { if (m[0].test(path)) { icon = m[1]; return false; } });
                            a.prepend('<i class="rf-i rf-i-' + icon + '"></i>');
                        });
                        r.append($('<div class="rf-rail-bottom"></div>').append(gear));
                        manage.remove();
                    }
                    $('body').prepend(r).addClass('rf-shell');
                    // Top bar: view title (moved from the page) or ticket breadcrumb
                    var head = $('<div class="rf-head-left"></div>');
                    var viewbar = $('.rf-viewbar').first();
                    if (viewbar.length) {
                        head.append(viewbar);
                    } else if (crumbs.length) {
                        var cr = $('<div class="rf-crumb"></div>');
                        $.each(crumbs, function (i, c) {
                            if (i) { cr.append('<i class="rf-i rf-i-chevron-right rf-i-sm"></i>'); }
                            cr.append(c[1] ? $('<a></a>').attr('href', c[1]).text(c[0]) : $('<span></span>').text(c[0]));
                        });
                        if ($('#conv-toolbar').length) {
                            head.append($('<button type="button" class="rf-sqbtn rf-tb-views"><i class="rf-i rf-i-fd-views"></i></button>').attr('title', rfT('Views')));
                        }
                        head.append(cr);
                    } else if (<?php echo \Route::is('dashboard') ? 'true' : 'false'; ?>) {
                        head.append('<div class="rf-head-title">' + rfT('My dashboard') + '</div>');
                    } else {
                        // title of the page: its heading, else the title of its side menu (search…), else the new ticket
                        // form, else the browser title minus the " - App name" suffix
                        var h = $('.heading, .section-heading, h1.page-title').first();
                        var headText = h.length ? $.trim(h.clone().children().remove().end().text()) : '';
                        if (!headText) { headText = $.trim($('.sidebar-2col .sidebar-title').first().clone().children().remove().end().text()); }
                        if (!headText && $('.conv-new-number').length) { headText = rfT('New ticket'); }
                        if (!headText && document.title) { headText = $.trim(document.title.split(' - ')[0]); }
                        if (headText) { head.append($('<div class="rf-head-title"></div>').text(headText)); }
                    }
                    nav.find('.navbar-header').after(head);
                    // Right side of the top bar: New + Search (labeled button that opens the native search)
                    var right = nav.find('.navbar-right').first();
                    if (right.length) {
                        var btns = $('<li class="rf-head-btns"></li>');
                        if (newUrl) {
                            // "New ⌄" like Freshdesk: e-mail ticket, phone ticket
                            btns.append('<div class="dropdown rf-new-dd"><a class="rf-hbtn dropdown-toggle" href="#" data-toggle="dropdown"><i class="rf-i rf-i-fd-new"></i> ' + rfT('New') + ' <i class="rf-i rf-i-fd-dropdown-arrow"></i></a>'
                                + '<ul class="dropdown-menu dropdown-menu-right">'
                                + '<li><a href="' + newUrl + '"><i class="rf-i rf-i-fd-email rf-i-sm"></i> ' + rfT('Ticket (e-mail)') + '</a></li>'
                                + '<li><a href="' + newUrl + '?rf_phone=1"><i class="rf-i rf-i-fd-phone rf-i-sm"></i> ' + rfT('Phone ticket') + '</a></li>'
                                + '</ul></div>');
                        }
                        btns.append('<a class="rf-hbtn rf-hsearch" href="#"><i class="rf-i rf-i-search"></i> ' + rfT('Search') + '</a>');
                        right.prepend(btns);
                        // Freshdesk search: field in the top bar, Enter = all tickets filtered on the text (any period)
                        var sbox = $('<form class="rf-hsearch-box" method="get"><i class="rf-i rf-i-search"></i><input type="text" name="q" placeholder="' + rfT('Search tickets (subject, message, customer, #)') + '" autocomplete="off"><input type="hidden" name="created" value="any"><button type="button" class="rf-hsearch-close" title="' + rfT('Close') + '"><i class="rf-i rf-i-close rf-i-sm"></i></button></form>');
                        sbox.attr('action', ticketsUrl);
                        nav.find('.navbar-collapse').prepend(sbox);
                        // Instant suggestions while typing (8 tickets), Enter = full list
                        var sugg = $('<div class="rf-hsearch-sugg"></div>').appendTo(sbox);
                        var st = null, lastQ = '';
                        sbox.on('input', 'input[name=q]', function () {
                            var q = $.trim($(this).val());
                            clearTimeout(st);
                            if (q.length < 2) { sugg.empty().hide(); return; }
                            st = setTimeout(function () {
                                lastQ = q;
                                $.getJSON('<?php echo url('/refresh/search'); ?>', { q: q }, function (r) {
                                    if (q !== lastQ) { return; }
                                    sugg.empty();
                                    if (!r.items || !r.items.length) { sugg.append('<div class="rf-sugg-empty">' + rfT('No tickets') + '</div>').show(); return; }
                                    $.each(r.items, function (i, it) {
                                        var a = $('<a class="rf-sugg-item"></a>').attr('href', it.url).toggleClass('closed', !!it.closed);
                                        a.append('<i class="rf-i rf-i-fd-all-tickets"></i>');
                                        var t = $('<span class="rf-sugg-text"></span>');
                                        t.append($('<span class="rf-sugg-subj"></span>').text(it.subject + ' #' + it.number));
                                        t.append($('<span class="rf-sugg-meta"></span>').text(it.customer + ' • ' + it.status));
                                        sugg.append(a.append(t));
                                    });
                                    sugg.append($('<a class="rf-sugg-all"></a>').attr('href', ticketsUrl + '?created=any&q=' + encodeURIComponent(q)).text(rfT('See all results for “:text”').replace(':text', function () { return q; })));
                                    sugg.show();
                                });
                            }, 250);
                        });
                        // Click outside the field: suggestions AND field closed, like Freshdesk (the "Search" button reopens it)
                        $(document).on('mousedown', function (e) {
                            if (!$('body').hasClass('rf-searching') || $(e.target).closest('.rf-hsearch-box, .rf-hsearch').length) { return; }
                            sugg.hide();
                            $('body').removeClass('rf-searching');
                        });
                        btns.on('click', '.rf-hsearch', function (e) {
                            e.preventDefault();
                            $('body').addClass('rf-searching');
                            sbox.find('input[name=q]').val(<?php echo json_encode((string)request('q', '')); ?>).focus();
                        });
                        sbox.on('click', '.rf-hsearch-close', function () { $('body').removeClass('rf-searching'); });
                        sbox.on('keydown', 'input', function (e) { if (e.keyCode === 27) { $('body').removeClass('rf-searching'); } });
                        $(document).on('keydown', function (e) {
                            // "/" opens the search (Freshdesk shortcut), outside input fields
                            if (e.key === '/' && !$(e.target).is('input, textarea, select, [contenteditable], [contenteditable] *')) {
                                e.preventDefault();
                                btns.find('.rf-hsearch').trigger('click');
                            }
                        });
                    }
                    if ($.fn.tooltip) { r.find('[data-toggle="tooltip"]').tooltip({ container: 'body' }); }
                })();
                var current = <?php echo json_encode($current); ?>;
                var isViewRoute = <?php echo $is_view_route; ?>;

                // Views menu: view search, collapsible sections (remembered), panel collapse (≡ button in the top bar).
                var store = {
                    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
                    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
                };
                $('.rf-views-filter').on('input', function () {
                    var q = $.trim($(this).val()).toLowerCase();
                    $('.rf-views .rf-v[data-label]').each(function () {
                        $(this).toggle(!q || $(this).attr('data-label').indexOf(q) !== -1);
                    });
                    $('.rf-views-sec').each(function () {
                        $(this).toggle(!q || $(this).find('.rf-v:visible').length > 0);
                    });
                });
                $('.rf-views-sec').each(function () {
                    if (store.get('rf_sec_' + $(this).attr('data-sec')) === '0') { $(this).addClass('collapsed'); }
                });
                $(document).on('click', '.rf-views-sec-head', function () {
                    var sec = $(this).closest('.rf-views-sec').toggleClass('collapsed');
                    store.set('rf_sec_' + sec.attr('data-sec'), sec.hasClass('collapsed') ? '0' : '1');
                });
                if (store.get('rf_views_hidden') === '1') { $('body').addClass('rf-views-hidden'); }
                $(document).on('click', '.rf-toggle-views', function () {
                    $('body').toggleClass('rf-views-hidden');
                    store.set('rf_views_hidden', $('body').hasClass('rf-views-hidden') ? '1' : '0');
                });

                // Filters panel: collapse remembered in a cookie (read server-side to avoid a flash on load).
                $(document).on('click', '.rf-toggle-filters', function () {
                    var layout = $('.rf-list-layout').toggleClass('rf-filters-closed');
                    document.cookie = 'rf_filters_closed=' + (layout.hasClass('rf-filters-closed') ? '1' : '') + ';path=/;max-age=31536000';
                    if (!layout.hasClass('rf-filters-closed')) { $('.rf-f-q').focus(); }
                });
                var initFilters = function () {
                    if ($.fn.select2) {
                        $('.rf-multi').not('.select2-hidden-accessible').each(function () {
                            $(this).select2({ placeholder: $(this).attr('data-placeholder') || '', width: '100%', allowClear: false });
                        });
                    }
                };
                initFilters();
                // Shared saved views: save the current combination (view + filters + sort), delete from the menu.
                $(document).on('click', '.rf-save-view', function () {
                    var b = $(this);
                    var name = window.prompt(rfT('Name of the shared view (visible to the whole team):'), '');
                    if (!name || !$.trim(name)) { return; }
                    var form = b.closest('form');
                    var query = form.length ? form.serialize() : window.location.search.replace(/^\?/, '');
                    $.post('<?php echo url('/refresh/views'); ?>', { _token: $('meta[name="csrf-token"]').attr('content'), label: name, view: b.attr('data-view'), mailbox_id: b.attr('data-mailbox'), query: query }, function (r) {
                        if (r && r.url) { window.location.href = r.url; }
                    }, 'json').fail(function () { if (window.showFloatingAlert) { showFloatingAlert('error', rfT('Could not save')); } });
                });
                $(document).on('click', '.rf-v-del', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!window.confirm(rfT('Delete this shared view for the whole team?'))) { return; }
                    $.post('<?php echo url('/refresh/views'); ?>/' + $(this).attr('data-id') + '/delete', { _token: $('meta[name="csrf-token"]').attr('content') }, function () {
                        window.location.reload();
                    }, 'json');
                });
                // Layout: card view / table view (cookie read server-side)
                $(document).on('click', '.rf-set-layout', function (e) {
                    e.preventDefault();
                    var l = $(this).attr('data-layout');
                    document.cookie = 'rf_layout=' + (l === 'table' ? 'table' : '') + ';path=/;max-age=31536000';
                    $('.rf-list-layout').toggleClass('rf-layout-table', l === 'table');
                    $('.rf-layout-dd .rf-sort-current').html((l === 'table' ? rfT('Table view') : rfT('Card view')) + ' <i class="rf-i rf-i-fd-chevron-down rf-i-sm"></i>');
                    $('.rf-layout-dd li').removeClass('active').find('[data-layout="' + l + '"]').parent().addClass('active');
                });

                // Freshdesk-style bulk actions: the native bar moves INTO the toolbar (no more floating bar)
                // and replaces "Sort by" as long as at least one ticket is checked; plus a direct "Close" button.
                var closeMerge = function () {
                    $('.rf-merge-panel, .rf-merge-overlay').remove();
                };
                $(document).on('keydown', function (e) { if (e.keyCode === 27) { closeMerge(); } });
                var syncBulk = function () {
                    var tbar = $('.rf-toolbar').first();
                    var n = $('.conv-checkbox:checked').length;
                    tbar.toggleClass('rf-selecting', n > 0);
                    tbar.find('.rf-bulk-merge').toggle(n > 1);
                    $('.rf-toggle-all').prop('checked', n > 0 && n === $('.conv-checkbox').length);
                };
                $(document).on('change', '.conv-checkbox, #toggle-all', function () { setTimeout(syncBulk, 0); });
                // re-run after every ajax view change (the native bar arrives fresh with the list)
                var initBulk = function () {
                var bulk = $('#conversations-bulk-actions');
                var tbar = $('.rf-toolbar').first();
                if (bulk.length && tbar.length) {
                    bulk.removeClass('affix affix-top affix-bottom text-center').removeAttr('data-spy');
                    tbar.find('.rf-cb-all').after(bulk);
                    var grp = bulk.find('> .btn-group').first();
                    var closeLink = grp.find('.conv-status a[data-status="<?php echo Conversation::STATUS_CLOSED; ?>"]').first();
                    if (closeLink.length && !grp.find('.rf-bulk-close').length) {
                        var closeBtn = $('<button type="button" class="btn btn-default rf-bulk-close"><i class="rf-i rf-i-fd-close rf-i-sm"></i> ' + rfT('Close') + '</button>');
                        grp.find('> .btn-group').first().after(closeBtn);
                        closeBtn.on('click', function () { closeLink.trigger('click'); });
                    }
                    // "Merge" once 2 tickets are checked: Freshdesk-style side panel ("Merge tickets") — one card per
                    // ticket (⊖ to remove it, clickable "Primary" badge, oldest by default), Cancel / Continue.
                    // Continue → native conversation_merge action (ConversationsController::ajax), several tickets at once.
                    if (!grp.find('.rf-bulk-merge').length) {
                        var mergeBtn = $('<button type="button" class="btn btn-default rf-bulk-merge"><i class="rf-i rf-i-fd-merge rf-i-sm"></i> ' + rfT('Merge') + '</button>');
                        grp.append(mergeBtn);
                        mergeBtn.on('click', function () {
                            var rows = $('.conv-checkbox:checked').closest('tr');
                            if (rows.length < 2) { return; }
                            closeMerge();
                            var items = rows.map(function () {
                                var tr = $(this);
                                var p = tr.find('td.conv-subject a > p').first();
                                return {
                                    id: tr.find('.conv-checkbox').val(),
                                    num: parseInt($.trim(tr.find('.rf-num').first().text()).replace('#', ''), 10) || 0,
                                    subj: $.trim(p.clone().children().remove().end().text()),
                                    meta: $.trim(tr.find('.rf-status-line').first().text()).replace(/\s*•\s*/g, ' • ').replace(/\s+/g, ' ')
                                };
                            }).get().sort(function (a, b) { return a.num - b.num; });
                            var primary = items[0].id;

                            var panel = $('<aside class="rf-merge-panel" role="dialog" aria-label="' + rfT('Merge tickets') + '"></aside>');
                            panel.append('<button type="button" class="rf-merge-x" title="' + rfT('Close') + '"><i class="rf-i rf-i-cross-big"></i></button>');
                            panel.append('<div class="rf-merge-head"><i class="rf-i rf-i-fd-merge"></i><span>' + rfT('Merge tickets') + '</span></div>');
                            var help = $('<p class="rf-merge-help"></p>');
                            panel.append(help);
                            var list = $('<div class="rf-merge-cards"></div>');
                            panel.append(list);
                            var foot = $('<div class="rf-merge-foot"><button type="button" class="btn btn-default rf-merge-cancel">' + rfT('Cancel') + '</button> <button type="button" class="btn btn-primary rf-merge-go">' + rfT('Continue') + '</button></div>');
                            panel.append(foot);

                            var render = function () {
                                help.text(items.length + ' ' + rfT('ticket(s) selected (the conversations of the other tickets will be added to the primary ticket).'));
                                list.empty();
                                $.each(items, function (i, it) {
                                    var isP = String(it.id) === String(primary);
                                    var card = $('<div class="rf-merge-card"></div>').toggleClass('is-primary', isP).attr('data-id', it.id);
                                    card.append($('<button type="button" class="rf-merge-rm" title="' + rfT('Remove from merge') + '">&minus;</button>').prop('disabled', isP || items.length <= 2));
                                    var body = $('<div class="rf-merge-body"></div>');
                                    body.append($('<div class="rf-merge-num"></div>').text('#' + it.num));
                                    body.append($('<div class="rf-merge-subj"></div>').text(it.subj));
                                    if (it.meta) { body.append($('<div class="rf-merge-meta"></div>').text(it.meta)); }
                                    card.append(body);
                                    card.append($('<button type="button" class="rf-merge-star" title="' + rfT('Set as primary ticket') + '"><i class="rf-i rf-i-fd-check-circle"></i><span>' + rfT('Primary') + '</span></button>'));
                                    list.append(card);
                                });
                            };
                            render();

                            panel.on('click', '.rf-merge-star', function () {
                                primary = $(this).closest('.rf-merge-card').attr('data-id');
                                render();
                            });
                            panel.on('click', '.rf-merge-rm', function () {
                                var id = $(this).closest('.rf-merge-card').attr('data-id');
                                items = $.grep(items, function (it) { return String(it.id) !== String(id); });
                                render();
                            });
                            panel.on('click', '.rf-merge-x, .rf-merge-cancel', closeMerge);
                            panel.on('click', '.rf-merge-go', function () {
                                var others = $.map(items, function (it) { return String(it.id) === String(primary) ? null : it.id; });
                                if (!others.length) { return; }
                                $(this).prop('disabled', true).text(rfT('Merging…'));
                                fsAjax({ action: 'conversation_merge', conversation_id: primary, merge_conversation_id: others },
                                    laroute.route('conversations.ajax'),
                                    function (r) {
                                        if (isAjaxSuccess(r)) { window.location.reload(); } else { closeMerge(); showAjaxError(r); }
                                    });
                            });
                            $('body').append($('<div class="rf-merge-overlay"></div>').on('click', closeMerge)).append(panel);
                            setTimeout(function () { panel.addClass('open'); }, 10);
                        });
                    }
                    syncBulk();
                }
                };
                initBulk();

                // Toolbar "select all" checkbox → native checkbox (hidden along with the table header).
                $(document).on('change', '.rf-toggle-all', function () {
                    var native = $('#toggle-all');
                    if (native.length && native.prop('checked') !== this.checked) { native.trigger('click'); }
                });

                // Card dropdown menus (priority / agent / status), like Freshdesk: editable without opening the ticket.
                var ddOpts = <?php echo json_encode($dd_opts); ?>;
                var ddMenu = null;
                var closeDd = function () { if (ddMenu) { ddMenu.remove(); ddMenu = null; } };
                $(document).on('mousedown', function (e) { if (ddMenu && !$(e.target).closest('.rf-dd-menu, .rf-dd').length) { closeDd(); } });
                var bindDd = function () {
                    $('.rf-dd').not('.rf-dd-bound').addClass('rf-dd-bound').on('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var el = $(this), field = el.attr('data-field');
                        closeDd();
                        var opts = ddOpts[field] || [];
                        ddMenu = $('<ul class="dropdown-menu rf-dd-menu"></ul>');
                        $.each(opts, function (i, o) {
                            var li = $('<li></li>').toggleClass('active', String(o.v) === String(el.attr('data-value')));
                            var a = $('<a href="#"></a>').attr('data-v', o.v);
                            if (o.c) { a.append($('<i class="rf-sq"></i>').css('background', o.c)); }
                            a.append(document.createTextNode(o.l));
                            ddMenu.append(li.append(a));
                        });
                        var off = el.offset();
                        ddMenu.css({ display: 'block', position: 'absolute', top: off.top + el.outerHeight() + 2, left: off.left, zIndex: 1060 }).appendTo('body');
                        ddMenu.on('click', 'a', function (ev) {
                            ev.preventDefault();
                            ev.stopPropagation();
                            var v = $(this).attr('data-v'), label = $(this).text(), color = $(this).find('.rf-sq').css('background-color');
                            closeDd();
                            if (String(v) === String(el.attr('data-value'))) { return; }
                            var done = function () {
                                el.attr('data-value', v).find('.rf-dd-label').text(label);
                                if (color) { el.find('.rf-sq').css('background', color); }
                                if (field === 'status') { el.closest('tr').toggleClass('conv-closed', String(v) === '3'); }
                                if (window.showFloatingAlert) { showFloatingAlert('success', rfT('Ticket updated')); }
                            };
                            var fail = function () { if (window.showFloatingAlert) { showFloatingAlert('error', rfT('Could not update')); } };
                            if (field === 'priority') {
                                $.ajax({ url: '<?php echo url('/refresh/conversation'); ?>/' + el.attr('data-conv') + '/properties', type: 'POST', dataType: 'json',
                                    data: { _token: $('meta[name="csrf-token"]').attr('content'), priority: v } }).done(done).fail(fail);
                            } else {
                                var data = field === 'agent'
                                    ? { action: 'conversation_change_user', user_id: v, conversation_id: el.attr('data-conv') }
                                    : { action: 'conversation_change_status', status: v, conversation_id: el.attr('data-conv') };
                                fsAjax(data, laroute.route('conversations.ajax'), function (r) {
                                    if (window.loaderHide) { loaderHide(); }
                                    if (r && r.status === 'success') { done(); } else { fail(); }
                                }, true, fail);
                            }
                        });
                    });
                };
                bindDd();
                window.rfBindDd = bindDd; // mobile version: rows added by infinite scroll (Public/js/mobile.js)

                // View switching without a page reload, like Freshdesk: a click in the views panel only replaces the
                // list and the title (header bar), then re-runs the list init (native + module). Previous / Next replay
                // the view via ajax. The controller always remembers the last view (rf_last_view cookie): the ajax response sets it.
                // The slightest mismatch (unexpected page, error) falls back to a normal navigation.
                if (isViewRoute && window.history && window.history.pushState && window.DOMParser) {
                    var navXhr = null;
                    var loadView = function (url, push) {
                        if (navXhr) { navXhr.abort(); }
                        $('body').addClass('rf-view-loading');
                        navXhr = $.ajax({ url: url, dataType: 'html' }).done(function (html) {
                            var doc = new DOMParser().parseFromString(html, 'text/html');
                            var layout = doc.querySelector('.rf-list-layout');
                            var vb = doc.querySelector('.rf-viewbar');
                            if (!layout || !vb || !$('.rf-list-layout').length) { window.location.href = url; return; }
                            closeMerge();
                            closeDd();
                            $('.rf-list-layout').first().replaceWith(document.adoptNode(layout));
                            $('.rf-viewbar').first().replaceWith(document.adoptNode(vb));
                            document.title = doc.title;
                            $('body').attr('data-rf-view', doc.body.getAttribute('data-rf-view') || '');
                            // views panel: active view and counts refreshed
                            var fresh = {};
                            $(doc).find('.rf-views a.rf-v').each(function () { fresh[this.getAttribute('href')] = $(this); });
                            $('.rf-views a.rf-v').each(function () {
                                var f = fresh[this.getAttribute('href')];
                                if (!f) { return; }
                                var a = $(this).toggleClass('active', f.hasClass('active'));
                                a.find('.rf-v-count').remove();
                                if (f.find('.rf-v-count').length) { a.find('.rf-v-label').after(f.find('.rf-v-count').first().clone()); }
                            });
                            if (push) { window.history.pushState({ rfView: 1 }, '', url); }
                            initFilters();
                            initBulk();
                            bindDd();
                            conversationsTableInit();
                            viewMailboxInit();
                        }).fail(function (x, st) {
                            if (st !== 'abort') { window.location.href = url; }
                        }).always(function () {
                            $('body').removeClass('rf-view-loading');
                        });
                    };
                    $(document).on('click', '.rf-views a.rf-v', function (e) {
                        // middle-click / Ctrl / Shift: new tab as usual; trash icon of a shared view: no navigation
                        if (e.isDefaultPrevented() || e.which > 1 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
                        if (this.hostname !== window.location.hostname || !/\/mailbox\/\d+\/tickets\//.test(this.pathname)) { return; }
                        e.preventDefault();
                        loadView(this.href, true);
                    });
                    window.history.replaceState({ rfView: 1 }, '', window.location.href);
                    window.addEventListener('popstate', function (e) {
                        if (e.state && e.state.rfView) { loadView(window.location.href, false); }
                    });
                }

                // Customer column reduced to an initial avatar (the name moves to the status line, like Freshdesk).
                var avatarTimer = null;
                var decorate = function () {
                    $('.table-conversations td.conv-customer > a').each(function () {
                        var a = $(this);
                        if (a.hasClass('rf-av-done')) { return; }
                        var name = $.trim(a.contents().filter(function () { return this.nodeType === 3; }).text());
                        if (!name) { name = $.trim(a.find('.conv-email').text()); }
                        var initial = (name.charAt(0) || '?').toUpperCase();
                        var hash = 0;
                        for (var i = 0; i < name.length; i++) { hash = (hash * 31 + name.charCodeAt(i)) % 360; }
                        // Avatar + message counter in a positioned container (the counter sits at the bottom right).
                        var wrap = $('<span class="rf-av-wrap"></span>').append(
                            $('<span class="rf-av"></span>').text(initial)
                                .css({ background: 'hsl(' + hash + ', 60%, 92%)', borderColor: 'hsl(' + hash + ', 55%, 84%)', color: 'hsl(' + hash + ', 45%, 32%)' })
                        );
                        wrap.append(a.find('.conv-counter'));
                        a.addClass('rf-av-done').prepend(wrap);
                        a.closest('table').addClass('rf-avatars');
                    });
                };
                decorate();
                if (window.MutationObserver) {
                    new MutationObserver(function () {
                        clearTimeout(avatarTimer);
                        avatarTimer = setTimeout(decorate, 60);
                    }).observe(document.body, { childList: true, subtree: true });
                }

                // New phone ticket ("New" menu): switches the native form to phone mode
                if (/[?&]rf_phone=1/.test(window.location.search)) {
                    setTimeout(function () { $('#phone-conv-switch').trigger('click'); }, 50);
                }

                if (!<?php echo $is_conv; ?>) {
                    return;
                }
                $('body').addClass('rf-conv');

                // Independent scrolling (see refresh.css): only #conv-layout-main scrolls. In column-reverse, scrollTop 0 = bottom:
                // it opens at the top like Freshdesk (unless there's a #thread-… anchor), and redoes it after images load as long as the agent hasn't scrolled.
                var convMain = document.getElementById('conv-layout-main');
                if (convMain) {
                    var convTouched = false;
                    var convToTop = function () {
                        if (!convTouched && !/^#thread-/.test(window.location.hash)) {
                            convMain.scrollTop = -convMain.scrollHeight;
                        }
                    };
                    $(convMain).one('wheel touchstart keydown mousedown', function () { convTouched = true; });
                    convToTop();
                    $(window).on('load', convToTop);
                    // Replaces the native code (main.js), which animated html/body: the window no longer scrolls.
                    window.maybeScrollToReplyBlock = function () {
                        var block = $('.conv-reply-block:visible:first');
                        if (block.length) {
                            block[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        }
                    };
                }
                var me = <?php echo json_encode(auth()->user() ? auth()->user()->getFullName() : ''); ?>;

                // ------------------------------------------------------------ Freshdesk-style toolbar
                // [views panel] [☆] [Reply] [Note] [Forward] [Close] [⋮] … [‹ ›] — relays to the native actions.
                var toolbar = $('#conv-toolbar');
                if (toolbar.length) {
                    var starNative = $('#conv-subject .conv-star').first();
                    var tb = $('<div class="rf-tb"></div>');
                    tb.append('<button type="button" class="rf-sqbtn rf-tb-star' + (starNative.hasClass('glyphicon-star') ? ' on' : '') + '" title="' + rfT('Star') + '"><i class="rf-i rf-i-fd-watching"></i></button>');
                    tb.append('<button type="button" class="rf-btn rf-tb-act" data-native=".conv-reply"><i class="rf-i rf-i-fd-reply rf-i-sm"></i>' + rfT('Reply') + '</button>');
                    tb.append('<button type="button" class="rf-btn rf-tb-act" data-native=".conv-add-note"><i class="rf-i rf-i-fd-note rf-i-sm"></i>Note</button>');
                    tb.append('<button type="button" class="rf-btn rf-tb-act" data-native=".conv-forward"><i class="rf-i rf-i-forward rf-i-sm"></i>' + rfT('Forward') + '</button>');
                    var closed = $('#conv-status .conv-status li.active a').attr('data-status') === '<?php echo Conversation::STATUS_CLOSED; ?>';
                    tb.append('<button type="button" class="rf-btn rf-tb-close' + (closed ? ' rf-reopen' : '') + '"><i class="rf-i rf-i-fd-close rf-i-sm"></i>' + (closed ? rfT('Reopen') : rfT('Close')) + '</button>');
                    // ⋮ = native "More actions" menu (Follow, Forward, Merge, Print…) + Delete
                    var more = toolbar.find('.conv-actions > .dropdown.conv-action').filter(function () { return $(this).find('.glyphicon-option-horizontal').length; }).first();
                    if (more.length) {
                        more.addClass('rf-tb-more');
                        more.find('.glyphicon-option-horizontal').removeClass('glyphicon glyphicon-option-horizontal conv-action').addClass('rf-sqbtn').html('<i class="rf-i rf-i-fd-more"></i>');
                        var editLi = $('<li><a href="#" class="rf-tb-editsubj"><i class="glyphicon glyphicon-pencil"></i> ' + rfT('Edit ticket details') + '</a></li>');
                        var printLi = more.find('ul.dropdown-menu > li').filter(function () { return $.trim($(this).text()) === '<?php echo addslashes(__('Print')); ?>'; }).first();
                        if (printLi.length) { printLi.before(editLi); } else { more.find('ul.dropdown-menu').append(editLi); }
                        editLi.on('click', 'a', function (e) { e.preventDefault(); if (window.rfEditSubject) { window.rfEditSubject(); } });
                        var del = toolbar.find('.conv-actions .conv-delete').first();
                        if (del.length) {
                            var li = $('<li><a href="#" class="rf-tb-delete"><i class="glyphicon glyphicon-trash"></i> ' + rfT('Delete') + '</a></li>');
                            more.find('ul.dropdown-menu').append('<li class="divider"></li>').append(li);
                            li.on('click', 'a', function (e) { e.preventDefault(); del.trigger('click'); });
                        }
                        tb.append(more);
                    }
                    var right = $('<div class="rf-tb-right"></div>');
                    var nextPrev = toolbar.find('.conv-next-prev').first();
                    if (nextPrev.length) {
                        nextPrev.find('a').first().html('<i class="rf-i rf-i-chevron-left rf-i-sm"></i>').removeClass('glyphicon glyphicon-menu-left').addClass('rf-pager-btn');
                        nextPrev.find('a').last().html('<i class="rf-i rf-i-chevron-right rf-i-sm"></i>').removeClass('glyphicon glyphicon-menu-right').addClass('rf-pager-btn');
                        right.append($('<span class="rf-pager"></span>').append(nextPrev.find('a')));
                    }
                    tb.append(right);
                    toolbar.prepend(tb);
                    tb.on('click', '.rf-tb-act', function () {
                        var n = $($(this).attr('data-native')).first();
                        if (n.length) { n.trigger('click'); }
                    });
                    tb.on('click', '.rf-tb-star', function () {
                        starNative.trigger('click');
                        $(this).toggleClass('on');
                    });
                    // Close = close then open the next open ticket; Reopen = reopen in place, without reloading.
                    tb.on('click', '.rf-tb-close', function () {
                        // state re-read on every click: "Update" (ajax) may have changed the status since the page loaded
                        closed = $('#conv-status .conv-status li.active a').attr('data-status') === '<?php echo Conversation::STATUS_CLOSED; ?>';
                        var code = closed ? '<?php echo Conversation::STATUS_ACTIVE; ?>' : '<?php echo Conversation::STATUS_CLOSED; ?>';
                        var data = { action: 'conversation_change_status', status: code, conversation_id: getGlobalAttr('conversation_id') };
                        if (closed) { data.x_embed = 1; } else { data.after_send = <?php echo \App\MailboxUser::AFTER_SEND_NEXT; ?>; }
                        var reopening = closed;
                        fsAjax(data, laroute.route('conversations.ajax'), function (r) {
                            if (window.loaderHide) { loaderHide(); }
                            if (!r || r.status !== 'success') { if (window.showAjaxError) { showAjaxError(r); } return; }
                            if (!reopening) { window.location.href = r.redirect_url || window.location.href; return; }
                            $('#conv-status .conv-status li').removeClass('active').children('a[data-status="' + code + '"]').parent().addClass('active');
                            var sel = $('.rf-rp .rf-rp-status-select');
                            sel.val(code).attr('data-initial', code);
                            $('.rf-rp .rf-rp-status-name').text($.trim(sel.find('option:selected').text()));
                            tb.find('.rf-tb-close').removeClass('rf-reopen').html('<i class="rf-i rf-i-fd-close rf-i-sm"></i>' + rfT('Close') + '');
                            var rpEl = $('.rf-rp');
                            if (rpEl.length) { $.post(rpEl.attr('data-save-url'), { _token: $('meta[name="csrf-token"]').attr('content'), clear_flash: 1 }); }
                            if (window.showFloatingAlert) { showFloatingAlert('success', rfT('Ticket reopened')); }
                        }, true);
                    });
                    // Views menu within the ticket: open/closed state remembered (like rf_views_hidden on the list)
                    try { if (window.localStorage.getItem('rf_conv_views') === '1') { $('body').addClass('rf-views-shown'); } } catch (e) {}
                    $(document).on('click', '.rf-tb-views', function () {
                        $('body').toggleClass('rf-views-shown');
                        try { window.localStorage.setItem('rf_conv_views', $('body').hasClass('rf-views-shown') ? '1' : '0'); } catch (e) {}
                    });
                }

                // ------------------------------------------------------------ subject: edit on demand, like Freshdesk
                // Native code (main.js) switches the subject into edit mode on the slightest click, with no way to cancel. Click neutralized (capture phase);
                // edit via "Edit ticket details" (⋯ menu, "e" key; ✎ on mobile), ✕ or Escape to cancel.
                // capture phase: the native handler (main.js) no longer receives the click on the subject
                document.addEventListener('click', function (ev) {
                    if ($(ev.target).closest('.conv-subjtext').length) { ev.stopPropagation(); }
                }, true);
                // "Edit ticket" window (desktop): full-width subject, Cancel / Save (native update_subject ajax)
                window.rfEditSubject = function () {
                    if ($('.rf-subj-modal').length) { return; }
                    var cur = $.trim($('.conv-subjtext > span:first').text());
                    var m = $('<div class="rf-subj-modal"><div class="rf-subj-dlg" role="dialog" aria-label="' + rfT('Edit ticket') + '">'
                        + '<div class="rf-subj-head">' + rfT('Edit ticket') + '<button type="button" class="rf-subj-x" aria-label="' + rfT('Close') + '">&times;</button></div>'
                        + '<div class="rf-subj-body"><label for="rf-subj-in">' + rfT('Subject') + '<span class="rf-req">*</span></label><input type="text" id="rf-subj-in" class="rf-input" maxlength="998"></div>'
                        + '<div class="rf-subj-foot"><button type="button" class="rf-btn rf-subj-cancel">' + rfT('Cancel') + '</button><button type="button" class="rf-btn rf-subj-ok">' + rfT('Save') + '</button></div>'
                        + '</div></div>');
                    var inp = m.find('#rf-subj-in').val(cur);
                    var close = function () { m.remove(); $(document).off('keydown.messubj'); };
                    var save = function () {
                        var val = $.trim(inp.val());
                        if (!val) { inp.trigger('focus'); return; }
                        if (val === cur) { close(); return; }
                        var ok = m.find('.rf-subj-ok').prop('disabled', true);
                        fsAjax({ action: 'update_subject', conversation_id: getGlobalAttr('conversation_id'), value: val }, laroute.route('conversations.ajax'), function (r) {
                            if (r && r.status === 'success') {
                                $('.conv-subjtext > span:first').text(val);
                                $('#conv-subj-value').val(val);
                                close();
                            } else {
                                ok.prop('disabled', false);
                                if (window.showAjaxError) { showAjaxError(r); }
                            }
                        }, true);
                    };
                    m.on('click', '.rf-subj-x, .rf-subj-cancel', close);
                    m.on('click', '.rf-subj-ok', save);
                    m.on('mousedown', function (e) { if (e.target === m[0]) { close(); } });
                    $(document).on('keydown.messubj', function (e) {
                        if (e.which === 27) { close(); } else if (e.which === 13 && e.target === inp[0]) { e.preventDefault(); save(); }
                    });
                    $('body').append(m);
                    inp.trigger('focus').select();
                };

                // ------------------------------------------------------------ title: channel icon + subject
                var subj = $('#conv-subject .conv-subjtext').first();
                if (subj.length && !subj.find('.rf-chan').length) {
                    subj.before('<span class="rf-chan"><i class="rf-i rf-i-fd-email"></i></span>');
                }

                // ------------------------------------------------------------ Freshdesk-style thread items
                // "Name replied • 3 days ago (Tue, Sep 22, 2026 1:43 PM)", round initial avatar outside the card.
                // Full FreeScout date ("Sept 20, 2026 21:29") → Freshdesk format ("Sun, Sep 20, 2026 21:29")
                var localDate = function (txt) {
                    var m = /([A-Za-z]+)\.? (\d{1,2}), (\d{4}) (\d{1,2}:\d{2})/.exec(txt || '');
                    if (!m) { return txt || ''; }
                    var mi = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 }[m[1].slice(0, 3).toLowerCase()];
                    if (mi === undefined) { return txt; }
                    var d = new Date(parseInt(m[3], 10), mi, parseInt(m[2], 10));
                    var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    return rfT(days[d.getDay()]) + ' ' + d.getDate() + ' ' + rfT(months[mi]) + ' ' + m[3] + ' ' + m[4];
                };
                var threads = $('#conv-layout-main > .thread').not('.thread-type-draft');
                var firstCustomer = threads.filter('.thread-type-customer').last(); // column-reverse: the last one in the DOM is the oldest
                threads.each(function () {
                    var t = $(this);
                    if (t.hasClass('rf-th-done')) { return; }
                    t.addClass('rf-th-done');
                    var person = t.find('.thread-person').first();
                    var nameEl = person.find('a').first().length ? person.find('a').first() : person;
                    var name = $.trim(nameEl.text());
                    if (name === '<?php echo addslashes(__('you')); ?>') { name = me; nameEl.text(me); }
                    var action = rfT('replied');
                    if (t.hasClass('thread-type-note')) {
                        action = t.find('.thread-header').text().indexOf('<?php echo addslashes(__('forwarded')); ?>') !== -1 ? rfT('forwarded') : rfT('added a private note');
                    } else if (t.hasClass('thread-type-customer') && t.is(firstCustomer)) {
                        action = rfT('reported by e-mail');
                    }
                    var dateEl = t.find('.thread-date').first();
                    var when = $.trim(dateEl.text());
                    var full = localDate(dateEl.attr('data-original-title') || dateEl.attr('title') || '');
                    var line = $('<span class="rf-th-line"></span>');
                    line.append($('<span class="rf-th-action"></span>').text(action));
                    if (when) {
                        line.append('<span class="rf-th-dot">•</span>');
                        line.append($('<span class="rf-th-when"></span>').text(when + (full ? ' (' + full + ')' : '')));
                    }
                    person.after(line);
                    // avatar
                    var initial = (name.charAt(0) || '?').toUpperCase();
                    var hash = 0;
                    for (var i = 0; i < name.length; i++) { hash = (hash * 31 + name.charCodeAt(i)) % 360; }
                    var photo = t.find('.thread-photo').first();
                    if (photo.length) {
                        photo.empty().append($('<span class="rf-av rf-av-32"></span>').text(initial).css({
                            background: 'hsl(' + hash + ', 60%, 92%)', borderColor: 'hsl(' + hash + ', 55%, 84%)', color: 'hsl(' + hash + ', 45%, 32%)'
                        }));
                    }
                });

                // ------------------------------------------------------------ quoted history collapsed behind "•••" (Freshdesk)
                // The first quote block of an e-mail (blockquote, Gmail / Outlook / Thunderbird quote header, "On … wrote:")
                // and everything after it is hidden; the button shows/hides it.
                $('#conv-layout-main > .thread .thread-body').each(function () {
                    var body = $(this);
                    if (body.find('.rf-quote').length) { return; }
                    var start = body.find('.gmail_quote, .gmail_extra, blockquote, .moz-cite-prefix, #appendonsend, #divRplyFwdMsg, .yahoo_quoted, div[style*="border-top:solid #e6eaf0"]').first();
                    if (!start.length) {
                        // plain-text quote header: "Le 20 sept. 2026 à 21:24, X a écrit :" (French) / "On … wrote:" (English)
                        body.find('div, p, span').each(function () {
                            var t = $.trim($(this).clone().children().remove().end().text());
                            if (/^(Le |On ).{6,140}(a écrit|wrote)\s*:?$/i.test(t) || /^-{2,}\s*(Message d'origine|Original Message)/i.test(t)) { start = $(this); return false; }
                        });
                    }
                    if (!start.length) { return; }
                    // we collapse the quote and everything after it, at its own level (without going back up: the reply and the
                    // quote are often in the same block); safeguard: there must still be visible text above it
                    var rest = start.nextAll().addBack();
                    if (!rest.length || $.trim(rest.text()).length < 20) { return; }
                    var total = $.trim(body.text()).length, hidden = $.trim(rest.text()).length;
                    if (total - hidden < 2) { return; }
                    var box = $('<div class="rf-quote" style="display:none"></div>');
                    start.before(box);
                    box.append(rest);
                    var btn = $('<button type="button" class="rf-quote-toggle" title="' + rfT('Show quoted history') + '">•••</button>');
                    box.before(btn);
                    btn.on('click', function () { box.toggle(); });
                });

                // ------------------------------------------------------------ editor modes (Reply / Note / Forward)
                // Current mode read from the native block: '.conv-reply' | '.conv-add-note' | '.conv-forward' | '' (closed).
                var rfMode = function () {
                    var b = $('.conv-reply-block').first();
                    if (!b.length || b.hasClass('hidden')) { return ''; }
                    return b.hasClass('conv-forward-block') ? '.conv-forward' : (b.hasClass('conv-note-block') ? '.conv-add-note' : '.conv-reply');
                };
                // Switching the open editor tab, like Freshdesk. The native code forbids it ("would create several drafts"):
                // empty editor → we close it and reopen it in the right mode; otherwise the native draft-discard confirmation, then switch.
                var rfCcInit = null;
                var rfSyncHead = null; // set by skinEditor, called again on every mode change
                var rfSwitchMode = function (sel) {
                    var cur = rfMode();
                    if (!cur) { $(sel).first().trigger('click'); return; }
                    if (cur === sel) { $('#body').summernote('focus'); return; }
                    var b = $('.conv-reply-block').first();
                    var go = function () {
                        if (cur === '.conv-forward') {
                            // undoes showForwardForm (main.js): free-text recipient hidden, original Cc restored
                            b.find(':input[name="subtype"]').val('');
                            b.find(':input[name="to"]').removeClass('hidden');
                            b.find('#to_email').addClass('hidden parsley-exclude').next('.select2').hide();
                            if (rfCcInit && rfCcInit.length) { b.find('#cc').val(rfCcInit).trigger('change'); }
                        }
                        b.removeClass('inactive conv-forward-block');
                        $('.conv-action').removeClass('inactive');
                        $(sel).first().trigger('click');
                    };
                    var text = $('<div>').html($('#body').summernote('code') || '').text().replace(/\s+/g, '');
                    if (!text && cur === '.conv-forward') {
                        // Forwarding automatically saves a draft (loadAttachments → saveDraft, deferred) with the thread's attachments:
                        // we wait for its id (3 s max) and delete it without confirmation, since the agent hasn't written anything.
                        var tries = 0;
                        var waitDraft = function () {
                            var id = b.find(':input[name="thread_id"]').val();
                            // no attachments carried over → no draft (saveDraft doesn't save an empty body): switch immediately
                            if (!id && $('.attachments-upload li').length && tries++ < 12) { setTimeout(waitDraft, 250); return; }
                            if (id) {
                                fsAjax({ action: 'discard_draft', thread_id: id }, laroute.route('conversations.ajax'), function () {
                                    $('#thread-' + id).remove();
                                    loaderHide();
                                }, true);
                            }
                            b.addClass('hidden');
                            $('#conv-subject').removeClass('action-visible');
                            go();
                        };
                        waitDraft();
                        return;
                    }
                    if (!text && !$('.attachments-upload li').length && !b.find(':input[name="thread_id"]').val()) {
                        b.addClass('hidden');
                        $('#conv-subject').removeClass('action-visible');
                        go();
                        return;
                    }
                    var t;
                    var obs = new MutationObserver(function () {
                        if (b.hasClass('hidden')) { obs.disconnect(); clearTimeout(t); go(); }
                    });
                    obs.observe(b.get(0), { attributes: true, attributeFilter: ['class'] });
                    t = setTimeout(function () { obs.disconnect(); }, 60000); // agent cancels the confirmation
                    discardDraft();
                };
                // mobile version (Public/js/mobile.js): reply bar, full-screen editor, modes menu
                window.rfMode = rfMode;
                window.rfSwitchMode = rfSwitchMode;

                // ------------------------------------------------------------ reply area at the bottom (Freshdesk card)
                var reply = $('.conv-action-wrapper');
                var main = $('#conv-layout-main');
                if (reply.length && main.length && !$('.rf-reply-card').length) {
                    var card = $(
                        '<div class="rf-reply-card">' +
                        '<div class="rf-reply-tabs">' +
                        '<button type="button" class="rf-reply-tab active" data-native=".conv-reply"><i class="rf-i rf-i-email rf-i-sm"></i>' + rfT('Reply') + '</button>' +
                        '<button type="button" class="rf-reply-tab" data-native=".conv-add-note"><i class="rf-i rf-i-fd-note rf-i-sm"></i>Note</button>' +
                        '<button type="button" class="rf-reply-tab" data-native=".conv-forward"><i class="rf-i rf-i-forward rf-i-sm"></i>' + rfT('Forward') + '</button>' +
                        '</div>' +
                        '<div class="rf-reply-placeholder" data-native=".conv-reply">' + rfT('Type your reply here…') + '</div>' +
                        '</div>'
                    );
                    reply.prepend(card);
                    // logged-in agent's avatar to the left of the reply card, like Freshdesk
                    var mh = 0;
                    for (var k = 0; k < me.length; k++) { mh = (mh * 31 + me.charCodeAt(k)) % 360; }
                    reply.prepend($('<span class="rf-av rf-av-32 rf-reply-av"></span>').text((me.charAt(0) || '?').toUpperCase()).css({
                        background: 'hsl(' + mh + ', 60%, 92%)', borderColor: 'hsl(' + mh + ', 55%, 84%)', color: 'hsl(' + mh + ', 45%, 32%)'
                    }));
                    main.append(reply);
                    card.on('click', '[data-native]', function () {
                        rfSwitchMode($(this).attr('data-native'));
                    });
                    // Note mode indicator, to the right of the tabs (Freshdesk: "Not visible to the contact")
                    card.find('.rf-reply-tabs').append('<span class="rf-reply-private"><i class="rf-i rf-i-lock rf-i-sm"></i>' + rfT('Not visible to the contact') + '</span>');
                    // the open editor hides the "Type your reply here…" line
                    var block = reply.find('.conv-reply-block').get(0);
                    var syncPh = function () {
                        var mode = rfMode();
                        card.toggleClass('editing', !!mode);
                        reply.toggleClass('rf-mode-note', mode === '.conv-add-note').toggleClass('rf-mode-fwd', mode === '.conv-forward');
                        card.find('.rf-reply-tab').removeClass('active').filter('[data-native="' + (mode || '.conv-reply') + '"]').addClass('active');
                        // pre-filled Cc (line visible right when opening): the native code only initializes select2 on a "Cc/Bcc" click
                        if (rfSyncHead) { rfSyncHead(); }
                        if (mode && window.initRecipientSelector) { setTimeout(function () { initRecipientSelector(); }, 0); }
                    };
                    if (block && window.MutationObserver) {
                        new MutationObserver(syncPh).observe(block, { attributes: true, attributeFilter: ['class'] });
                    }
                    syncPh();
                }

                // ------------------------------------------------------------ per-message actions (Freshdesk: 2 icons on hover)
                // The native "thread options" menu (▾: Edit, New ticket, Clone, Sent e-mails, Original, Print)
                // is hidden in CSS and replaced with Forward + Split. Split = native "New ticket" (thread's new-conv link,
                // pre-filled with this message; the message also stays in the original ticket, unlike Freshdesk which moves it).
                var rfThreadTools = function () {
                    // draft: buttons turned into icons (CSS "Draft in thread") → tooltips
                    $('#conv-layout-main .draft-actions .edit-draft-trigger').attr('title', rfT('Edit draft'));
                    $('#conv-layout-main .draft-actions .discard-draft-trigger').attr('title', rfT('Delete draft'));
                    $('#conv-layout-main .thread').each(function () {
                        var th = $(this);
                        if (th.children('.rf-th-tools').length) { return; }
                        var split = th.find('.thread-options .dropdown-menu a.new-conv').filter(function () {
                            return /new-ticket/.test($(this).attr('href') || '');
                        }).first();
                        if (th.hasClass('thread-type-note') || !th.find('.thread-options').length) { return; }
                        var tools = $('<div class="rf-th-tools"></div>');
                        tools.append($('<button type="button" class="rf-th-tool" title="' + rfT('Forward') + '"><i class="rf-i rf-i-forward rf-i-sm"></i></button>')
                            .on('click', function () { rfSwitchMode('.conv-forward'); }));
                        // Split (Freshdesk): not on the first message of the ticket = last .thread of the page (newest to oldest)
                        var firstMsg = $('#conv-layout-main .thread').not('.thread-type-lineitem').last();
                        if (split.length && th.attr('data-thread_id') && th[0] !== firstMsg[0]) {
                            tools.append($('<button type="button" class="rf-th-tool rf-th-split" title="' + rfT('Split ticket') + '"><i class="rf-i rf-i-split rf-i-sm"></i></button>')
                                .attr('data-thread', th.attr('data-thread_id')));
                        }
                        th.append(tools);
                    });
                };
                // "Split ticket": same confirmation dialog as Freshdesk (Split this message into a new ticket? Cancel / Split),
                // then the message moves to a new ticket (SplitController), which is opened.
                $(document).on('click', '.rf-th-split', function () {
                    var tid = $(this).attr('data-thread');
                    $('.rf-split-overlay').remove();
                    var ov = $('<div class="rf-split-overlay"></div>');
                    var dlg = $('<div class="rf-split-dlg" role="dialog"></div>').attr('aria-label', rfT('Split ticket'));
                    dlg.append($('<button type="button" class="rf-split-x"><i class="rf-i rf-i-cross-big"></i></button>').attr('title', rfT('Close')));
                    dlg.append($('<div class="rf-split-title"></div>').text(rfT('Split ticket')));
                    dlg.append($('<p class="rf-split-text"></p>').text(rfT('Split this message into a new ticket?')));
                    dlg.append($('<div class="rf-split-foot"></div>').append(
                        $('<button type="button" class="btn btn-default rf-split-cancel"></button>').text(rfT('Cancel')), ' ',
                        $('<button type="button" class="btn btn-primary rf-split-go"></button>').text(rfT('Split'))));
                    var close = function () { ov.remove(); $(document).off('keydown.musplit'); };
                    ov.append(dlg).appendTo('body');
                    ov.on('click', function (e) { if (e.target === ov[0]) { close(); } });
                    dlg.on('click', '.rf-split-x, .rf-split-cancel', close);
                    $(document).on('keydown.musplit', function (e) { if (e.keyCode === 27) { close(); } });
                    dlg.on('click', '.rf-split-go', function () {
                        $(this).prop('disabled', true).text(rfT('Splitting…'));
                        $.ajax({ url: '<?php echo url('/refresh/thread'); ?>/' + tid + '/split', type: 'POST', dataType: 'json',
                            data: { _token: $('meta[name="csrf-token"]').attr('content') } })
                            .done(function (r) {
                                if (r && r.status === 'success' && r.url) { window.location.href = r.url; return; }
                                close();
                                if (window.showFloatingAlert) { showFloatingAlert('error', (r && r.msg) || rfT('Split failed')); }
                            })
                            .fail(function () {
                                close();
                                if (window.showFloatingAlert) { showFloatingAlert('error', rfT('Split failed')); }
                            });
                    });
                    setTimeout(function () { dlg.find('.rf-split-go').focus(); }, 0);
                });
                rfThreadTools();
                if (window.MutationObserver && document.getElementById('conv-layout-main')) {
                    // thread reloaded via AJAX after sending
                    new MutationObserver(rfThreadTools).observe(document.getElementById('conv-layout-main'), { childList: true });
                }

                // ------------------------------------------------------------ Freshdesk-style editor
                // From / To header (+ Cc, Bcc links), signature below the text, formatting bar below the body,
                // bottom bar: [Aa] [paperclip] [saved replies] … [Saved] [trash] [Send ▾].
                // Everything is moved, nothing is recreated: the buttons keep their native handlers (summernote, main.js).
                var edInfo = <?php echo json_encode($reply_info); ?>;
                window.rfReplyInfo = edInfo; // mobile version: "Name To recipient" of messages
                var skinEditor = function () {
                    var form = $('.conv-action-wrapper .form-reply').first();
                    var ed = form.find('.note-editor').first();
                    if (!ed.length || ed.hasClass('rf-ed') || document.readyState !== 'complete') { return; }
                    ed.addClass('rf-ed');
                    rfCcInit = form.find('#cc').val();

                    // Header
                    var head = $('<div class="rf-ed-head"></div>');
                    var alias = form.find('.conv-from-alias');
                    if (alias.length) {
                        head.append(alias);
                    } else if (edInfo) {
                        var deVal = $('<span class="rf-ed-val"></span>').append($('<strong></strong>').text(edInfo.fromName), ' ', $('<span class="rf-ed-muted"></span>').text('(' + edInfo.from + ')'));
                        head.append($('<div class="rf-ed-row"><span class="rf-ed-lbl">' + rfT('From') + '</span></div>').append(deVal));
                    }
                    var toNative = form.find('.conv-recipient-to');
                    var toRow = $('<div class="rf-ed-row rf-ed-to"><span class="rf-ed-lbl">' + rfT('To') + '</span></div>').append($('<span class="rf-ed-val"></span>').text(edInfo ? edInfo.to : ''));
                    var ccLinks = $('<span class="rf-ed-cclinks"><a href="#" data-row="#cc">Cc</a><a href="#" data-row="#bcc">' + rfT('Bcc') + '</a></span>');
                    head.append(toRow, toNative, form.find('#cc').closest('.form-group'), form.find('#bcc').closest('.form-group'));
                    form.find('.cc-toggler').addClass('rf-ed-hidden');
                    form.children('input[type="hidden"]').last().after(head);
                    var toNativeHidden = toNative.hasClass('hidden'); // single-customer ticket: native line hidden by default
                    var syncHead = function () {
                        // Forward: the native "To" line (free-text input, #to_email) replaces our read-only line;
                        // the native code fills #to_email but leaves its line hidden when the ticket has only one customer.
                        var fwd = $('.conv-reply-block').first().hasClass('conv-forward-block');
                        if (fwd) {
                            toNative.removeClass('hidden');
                            // the native code clears Cc / Bcc on forward: empty lines collapsed, the Cc / Bcc links stay (like Freshdesk)
                            head.find('#cc, #bcc').each(function () {
                                if (!($(this).val() || []).length) { $(this).closest('.form-group').addClass('hidden'); }
                            });
                        } else {
                            if (toNativeHidden) { toNative.addClass('hidden'); }
                            // back out of forward: Cc / Bcc restored → their lines reappear
                            head.find('#cc, #bcc').each(function () {
                                if (($(this).val() || []).length) { $(this).closest('.form-group').removeClass('hidden'); }
                            });
                        }
                        toRow.toggle(!fwd);
                        (fwd ? toNative : toRow).append(ccLinks);
                        ccLinks.find('a').each(function () {
                            $(this).toggle(head.find($(this).attr('data-row')).closest('.form-group').hasClass('hidden'));
                        });
                    };
                    // "Clear" empties the Cc / Bcc line and collapses it
                    head.find('#cc, #bcc').closest('.form-group').each(function () {
                        var g = $(this);
                        g.append($('<a href="#" class="rf-ed-clear">' + rfT('Clear') + '</a>').on('click', function (e) {
                            e.preventDefault();
                            g.find('select').val(null).trigger('change');
                            g.addClass('hidden');
                            syncHead();
                        }));
                    });
                    ccLinks.on('click', 'a', function (e) {
                        e.preventDefault();
                        var row = $(this).attr('data-row');
                        var toggler = $('#toggle-cc');
                        if (toggler.length) {
                            // the native code opens BOTH Cc and Bcc (and initializes select2): we close the other one if it's empty
                            toggler.trigger('click');
                            head.find(row === '#cc' ? '#bcc' : '#cc').each(function () {
                                if (!($(this).val() || []).length) { $(this).closest('.form-group').addClass('hidden'); }
                            });
                        }
                        head.find(row).closest('.form-group').removeClass('hidden');
                        syncHead();
                        head.find(row).nextAll('.select2').first().find('.select2-search__field').trigger('focus');
                    });
                    if (window.MutationObserver) {
                        new MutationObserver(syncHead).observe(toNative.get(0) || head.get(0), { attributes: true, attributeFilter: ['class'] });
                    }
                    syncHead();
                    rfSyncHead = syncHead;

                    // Signature right below the text, as in the Freshdesk body
                    ed.find('.note-editing-area').after(ed.find('#editor_signature'));

                    // Bottom bar
                    var sb = ed.find('.note-statusbar');
                    var tools = $('<div class="rf-ed-tools"></div>');
                    // "Aa": formatting bar shown/hidden, choice remembered
                    try { if (window.localStorage.getItem('rf_ed_notb') === '1') { ed.addClass('rf-ed-notb'); } } catch (e) {}
                    tools.append($('<button type="button" class="rf-ed-ic" title="' + rfT('Formatting options') + '"><i class="rf-i rf-i-fd-formatting"></i></button>').on('click', function () {
                        ed.toggleClass('rf-ed-notb');
                        try { window.localStorage.setItem('rf_ed_notb', ed.hasClass('rf-ed-notb') ? '1' : '0'); } catch (e) {}
                    }));
                    tools.append(ed.find('.note-btn-attachment').first().addClass('rf-ed-ic').html('<i class="rf-i rf-i-fd-attach"></i>'));
                    var saved = ed.find('.dropdown-saved-replies').first().parent();
                    if (saved.length) {
                        saved.find('.dropdown-toggle').first().addClass('rf-ed-ic').html('<i class="rf-i rf-i-fd-canned"></i>').attr('title', rfT('Saved Replies'));
                        saved.find('.dropdown-menu').first().removeClass('dropdown-menu-right');
                        saved.addClass('dropup rf-ed-saved');
                        tools.append(saved);
                    }
                    sb.prepend(tools);
                    var actions = ed.find('.note-actions').first();
                    actions.find('.glyphicon-trash').parent().addClass('rf-ed-ic rf-ed-trash').html('<i class="rf-i rf-i-fd-trash-fd"></i>');
                    sb.find('.btn-group-send').before(actions);
                    sb.find('.btn-send-text').html('<i class="rf-i rf-i-fd-send"></i>' + rfT('Send') + '');
                    sb.find('.btn-send-menu').html('<i class="rf-i rf-i-fd-dropdown-arrow"></i>');

                    // Send menu: our statuses first (the native handlers are already attached to the 3 first native links)
                    var menu = sb.find('.dropdown-after-send');
                    var mine = menu.children('.rf-send-as-li');
                    menu.prepend(mine.not('.divider'));
                    mine.filter('.divider').insertAfter(mine.not('.divider').last());
                };
                // Not before "load": initReplyForm (main.js) must have initialized summernote and bound its send links.
                if (document.readyState === 'complete') {
                    setTimeout(skinEditor, 0);
                } else {
                    $(window).on('load', skinEditor);
                }
                if (block && window.MutationObserver) {
                    new MutationObserver(skinEditor).observe(block, { attributes: true, attributeFilter: ['class'] });
                }

                // Freshdesk keyboard shortcuts on the ticket: r = Reply, n = Note, f = Forward (outside input fields)
                $(document).on('keydown', function (e) {
                    if (e.ctrlKey || e.metaKey || e.altKey || $(e.target).is('input, textarea, select, [contenteditable], [contenteditable] *')) { return; }
                    if ((e.key || '').toLowerCase() === 'e' && window.rfEditSubject) { e.preventDefault(); window.rfEditSubject(); return; }
                    var map = { r: '.conv-reply', n: '.conv-add-note', f: '.conv-forward' };
                    var sel = map[(e.key || '').toLowerCase()];
                    if (sel && $(sel).length) {
                        e.preventDefault();
                        rfSwitchMode(sel);
                    }
                });

                // Send arrow menu opens upward (the reply area is at the bottom of the page)
                $('.btn-group-send').addClass('dropup');

                // "Send and close / Send and pending / Send and stay open": sets the visible form's status then sends.
                // Like Freshdesk: we stay on the ticket after sending (agents' default setting = stay), except
                // "Send and close" which moves to the next open ticket (native after_send, read by getRedirectUrl).
                $(document).on('click', '.rf-send-as', function (e) {
                    e.preventDefault();
                    var form = $(this).closest('form');
                    if (!form.length) { form = $('.form-reply:visible').first(); }
                    var status = $(this).attr('data-status');
                    form.find('select[name="status"]').val(status).trigger('change');
                    $('#after_send').val(status === '<?php echo Conversation::STATUS_CLOSED; ?>' ? '<?php echo \App\MailboxUser::AFTER_SEND_NEXT; ?>' : '<?php echo \App\MailboxUser::AFTER_SEND_STAY; ?>');
                    var btn = form.find('.btn-reply-submit:visible').first();
                    if (!btn.length) { btn = $('.btn-reply-submit:visible').first(); }
                    btn.trigger('click');
                });

                // ------------------------------------------------------------ right-hand panels
                var rp = $('.rf-rp').first();
                if (rp.length) {
                    // "Author  Name" below the title when the ticket was created by an agent (Freshdesk)
                    var author = rp.attr('data-author');
                    if (author && !$('.rf-author').length) {
                        $('#conv-subject .conv-subj-block').append($('<div class="rf-author"><span>' + rfT('Author') + '</span> <strong></strong></div>').find('strong').text(author).end());
                    }
                    // Collapsing of both panels (icon at the top of the status panel), remembered
                    try { if (window.localStorage.getItem('rf_rp_collapsed') === '1') { $('body').addClass('rf-rp-collapsed'); } } catch (e) {}
                    $(document).on('click', '.rf-rp-collapse', function () {
                        $('body').toggleClass('rf-rp-collapsed');
                        try { window.localStorage.setItem('rf_rp_collapsed', $('body').hasClass('rf-rp-collapsed') ? '1' : '0'); } catch (e) {}
                    });
                    // Collapsible contact panel sections (chevron), state remembered — like Freshdesk
                    rp.find('.rf-rp-sec-head[data-sec]').each(function () {
                        var h = $(this);
                        try { if (window.localStorage.getItem('rf_sec_' + h.attr('data-sec')) === '0') { h.closest('.rf-rp-sec').addClass('collapsed'); } } catch (e) {}
                    });
                    rp.on('click', '.rf-rp-sec-head', function (e) {
                        if ($(e.target).closest('a').length) { return; }
                        var sec = $(this).closest('.rf-rp-sec').toggleClass('collapsed');
                        try { window.localStorage.setItem('rf_sec_' + $(this).attr('data-sec'), sec.hasClass('collapsed') ? '0' : '1'); } catch (er) {}
                    });

                    // SLA block pencil icon: edit the resolution due date
                    rp.on('click', '.rf-rp-sla-edit', function () { rp.find('.rf-rp-due-form').toggle(); });
                    rp.on('click', '.rf-rp-due-save, .rf-rp-due-reset', function () {
                        var val = $(this).hasClass('rf-rp-due-reset') ? '' : rp.find('.rf-rp-due-input').val();
                        $.ajax({ url: rp.attr('data-save-url'), type: 'POST', dataType: 'json',
                            data: { _token: $('meta[name="csrf-token"]').attr('content'), due: val } })
                            .done(function () { window.location.reload(); })
                            .fail(function () { if (window.showFloatingAlert) { showFloatingAlert('error', rfT('Invalid date')); } });
                    });
                    // Freshdesk-style Tags field: a single input area with tags as pills (× to remove), suggestions
                    // while typing, and "Create '…'" for a new tag. Saved immediately via the Tags module API (add / remove),
                    // the way its native dropdown (field + ✓) used to, which opened mis-positioned in the panel and is now hidden.
                    var tagsBox = rp.find('.rf-rp-tags');
                    var nativeTags = $('#conv_tags');
                    var addTag = toolbar.find('.conv-add-tags').closest('.dropdown');
                    var tagConv = window.getGlobalAttr ? getGlobalAttr('conversation_id') : '';
                    if ($.fn.select2 && window.laroute && window.fsAjax && tagConv && (nativeTags.length || addTag.length)) {
                        nativeTags.hide();
                        addTag.hide();
                        var tagSel = $('<select class="rf-tag-select" multiple></select>');
                        nativeTags.find('.tag-name').each(function () {
                            var tn = $.trim($(this).text());
                            if (tn) { tagSel.append($('<option selected></option>').val(tn).text(tn)); }
                        });
                        tagsBox.append(tagSel);
                        tagSel.select2({
                            width: '100%',
                            multiple: true,
                            tags: true,
                            minimumInputLength: 1,
                            tokenSeparators: [','],
                            placeholder: rfT('Search tags to add'),
                            containerCssClass: 'rf-tag-box',
                            dropdownCssClass: 'rf-tag-dd',
                            ajax: {
                                url: laroute.route('tags.ajax'),
                                dataType: 'json',
                                delay: 200,
                                data: function (params) { return { q: params.term, action: 'autocomplete', page: params.page || 1 }; }
                            },
                            createTag: function (params) {
                                var t = $.trim(params.term);
                                return t ? { id: t, text: t, newOption: true } : null;
                            },
                            templateResult: function (d) {
                                return $('<span></span>').text(d.newOption ? rfT('Create “:text”').replace(':text', function () { return d.text; }) : d.text);
                            },
                            language: {
                                inputTooShort: function () { return rfT('Type at least 1 character…'); },
                                searching: function () { return rfT('Searching…'); },
                                noResults: function () { return rfT('No tags'); },
                                errorLoading: function () { return rfT('Search failed'); }
                            }
                        });
                        var tagFail = function (r) { if (window.showAjaxError) { showAjaxError(r); } };
                        tagSel.on('select2:select', function (e) {
                            var name = e.params.data.id;
                            fsAjax({ action: 'add', tag_names: [name], conversation_id: tagConv }, laroute.route('tags.ajax'), function (r) {
                                if (!r || r.status !== 'success') {
                                    tagSel.find('option').filter(function () { return this.value === name; }).remove();
                                    tagSel.trigger('change');
                                    tagFail(r);
                                }
                            }, true);
                        });
                        tagSel.on('select2:unselect', function (e) {
                            var name = e.params.data.id;
                            // the option created on the fly disappears with its pill (otherwise it would stay offered, unselected)
                            tagSel.find('option').filter(function () { return this.value === name; }).remove();
                            fsAjax({ action: 'remove', tag_name: name, conversation_id: tagConv }, laroute.route('tags.ajax'), function (r) {
                                if (!r || r.status !== 'success') { tagFail(r); }
                            }, true);
                        });
                        // removing a pill shouldn't open the suggestions list
                        tagSel.on('select2:unselecting', function () {
                            tagSel.one('select2:opening', function (ev) { ev.preventDefault(); });
                        });
                    } else {
                        // fallback: former placement (native chips + module's add menu)
                        if (nativeTags.length) { tagsBox.append(nativeTags); }
                        if (addTag.length) {
                            addTag.addClass('rf-rp-addtag');
                            addTag.find('.conv-add-tags').removeClass('glyphicon glyphicon-tag conv-action').html('<i class="rf-i rf-i-plus rf-i-sm"></i> ' + rfT('Add tag') + '');
                            tagsBox.append(addTag);
                        }
                    }
                    // Cobrowse (in-house module) → bottom of the contact column
                    var cob = $('#conv-layout-customer').find('.cobrowse-block, .rf-cobrowse, [class*="cobrowse"]').filter(function () { return !$(this).closest('.rf-rp').length; }).first();
                    if (cob.length) { rp.find('.rf-rp-cobrowse').append(cob); }
                    // one-click copy
                    rp.on('click', '.rf-copy', function () {
                        var v = $(this).attr('data-copy');
                        if (navigator.clipboard) { navigator.clipboard.writeText(v); }
                        var b = $(this).addClass('copied');
                        setTimeout(function () { b.removeClass('copied'); }, 1200);
                    });
                    // priority pill + "Update" button enabled as soon as a field changes
                    var fields = rp.find('.rf-rp-type, .rf-rp-status-select, .rf-rp-priority, .rf-rp-user');
                    var dirty = function () {
                        var d = false;
                        fields.each(function () { if (String($(this).val()) !== String($(this).attr('data-initial'))) { d = true; } });
                        rp.find('.rf-rp-save').prop('disabled', !d);
                    };
                    rp.on('change', '.rf-rp-priority', function () {
                        rp.find('.rf-rp-prio-sq').css('background', $(this).find('option:selected').attr('data-color'));
                    });
                    fields.on('change', dirty);
                    // "Update" entirely via ajax, without reloading the page (like Freshdesk): a reload would interrupt an
                    // in-progress edit and save the draft. Agent and status go through the native actions (conversation_change_*),
                    // called directly instead of clicking the native menus (which reload); then type + priority (module),
                    // which clears the native "Status updated" flash message along the way. Sole exception:
                    // switching to Closed saves then opens the next open ticket (native after_send = 2), status changed last.
                    rp.on('click', '.rf-rp-save', function () {
                        var btn = $(this).prop('disabled', true).text(rfT('Saving…'));
                        var st = rp.find('.rf-rp-status-select'), us = rp.find('.rf-rp-user');
                        var convId = rp.attr('data-conversation-id');
                        var nativeAjax = function (data) {
                            var d = $.Deferred();
                            fsAjax(data, laroute.route('conversations.ajax'), function (r) {
                                if (window.loaderHide) { loaderHide(); }
                                if (r && r.status === 'success') { d.resolve(r); } else { d.reject(r); }
                            }, true, function (r) { d.reject(r); });
                            return d.promise();
                        };
                        var step = $.Deferred().resolve().promise();
                        if (String(us.val()) !== String(us.attr('data-initial'))) {
                            step = step.then(function () {
                                return nativeAjax({ action: 'conversation_change_user', user_id: us.val(), conversation_id: convId, x_embed: 1 }).then(function () {
                                    $('#conv-assignee .conv-user li').removeClass('active').children('a[data-user_id="' + us.val() + '"]').parent().addClass('active');
                                });
                            });
                        }
                        var statusChanged = String(st.val()) !== String(st.attr('data-initial'));
                        var closing = statusChanged && String(st.val()) === '<?php echo Conversation::STATUS_CLOSED; ?>';
                        var nextUrl = '';
                        step = step.then(function () {
                            return $.ajax({
                                url: rp.attr('data-save-url'), type: 'POST', dataType: 'json',
                                data: { _token: $('meta[name="csrf-token"]').attr('content'), type: rp.find('.rf-rp-type').val(), priority: rp.find('.rf-rp-priority').val(), clear_flash: 1 }
                            });
                        });
                        if (statusChanged) {
                            step = step.then(function () {
                                var data = { action: 'conversation_change_status', status: st.val(), conversation_id: convId };
                                if (closing) { data.after_send = <?php echo \App\MailboxUser::AFTER_SEND_NEXT; ?>; } else { data.x_embed = 1; }
                                return nativeAjax(data).then(function (r) {
                                    if (closing) { nextUrl = (r && r.redirect_url) || ''; return; }
                                    $('#conv-status .conv-status li').removeClass('active').children('a[data-status="' + st.val() + '"]').parent().addClass('active');
                                    rp.find('.rf-rp-status-name').text($.trim(st.find('option:selected').text()));
                                    $('.rf-tb-close').removeClass('rf-reopen').html('<i class="rf-i rf-i-fd-close rf-i-sm"></i>' + rfT('Close') + '');
                                    // native "Status updated" flash: unnecessary, the toast below is enough
                                    return $.post(rp.attr('data-save-url'), { _token: $('meta[name="csrf-token"]').attr('content'), clear_flash: 1 });
                                });
                            });
                        }
                        step.then(function () {
                            if (closing) { window.location.href = nextUrl || window.location.href; return; }
                            fields.each(function () { $(this).attr('data-initial', $(this).val()); });
                            btn.text(rfT('Update')); // stays disabled until the next change (dirty)
                            if (window.showFloatingAlert) { showFloatingAlert('success', rfT('Properties updated')); }
                        }, function (r) {
                            btn.prop('disabled', false).text(rfT('Update'));
                            if (window.showFloatingAlert) { showFloatingAlert('error', (r && r.msg) || rfT('Could not save')); }
                        });
                    });
                }
            })();
            <?php
        });
    }

    /** Current mailbox based on the route (mailbox page, module view or ticket). */
    protected function currentMailboxId()
    {
        $route = request()->route();
        if (!$route) {
            return 0;
        }
        $id = $route->parameter('mailbox_id');
        if ($id) {
            return (int)$id;
        }
        if (\Route::is('mailboxes.view') || \Route::is('mailboxes.view.folder')) {
            return (int)$route->parameter('id');
        }
        if (\Route::is('conversations.view')) {
            $conversation = Conversation::find((int)$route->parameter('id'));
            return $conversation ? (int)$conversation->mailbox_id : 0;
        }
        return 0;
    }

    public function register()
    {
    }

    public function provides()
    {
        return [];
    }
}
