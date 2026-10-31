<?php

namespace Modules\Refresh\Services;

use App\Conversation;

/**
 * FreeScout Interop (protocol 1.0.0): Refresh's side of the contract shared with sibling modules such as RefreshGlobal.
 * Contract from Refresh's side: docs/EXTENDING.md; machine-readable declaration: interop.json; shared browser bridge:
 * Public/js/fs-interop.js (unchanged copy of github.com/altmenorg/fs-interop, fs-interop.lock.json).
 *
 * Server side (public Eventy filters, never another module's classes):
 *   fsinterop.sla.targets         answered: Refresh's SLA targets (schema sla-targets 1.0)
 *   fsinterop.dashboard.owner     asked before rendering "My dashboard": another owner = Refresh skips its block
 *   fsinterop.modules             answered: Refresh's summary (id, version, protocol, capabilities)
 *   fsinterop.navigation.context  answered: the surfaces Refresh renders on the page being built
 * Browser side: <meta name="refresh-interop"> (module, capabilities, page), read by Public/js/interop.js, which registers
 * Refresh with the bridge and renders the intents other modules declare. Nothing here changes a page when no other
 * module takes part.
 */
class Interop
{
    const ID = 'refresh';
    const PROTOCOL = '1.0.0';

    /** Capabilities Refresh implements (same list as interop.json). */
    const CAPABILITIES = [
        'navigation'         => '1.0',
        'navigation-context' => '1.0',
        'ticket-toolbar'     => '1.0',
        'swipe-actions'      => '1.0',
        'conversation-ui'    => '1.0',
        'dashboard-handoff'  => '1.0',
    ];

    /** Route names => context of fsinterop.navigation.context (same values as the other participants). */
    const ROUTE_CONTEXTS = [
        'dashboard'             => 'dashboard',
        'mailboxes.view'        => 'mailbox',
        'mailboxes.view.folder' => 'mailbox',
        'refresh.tickets'       => 'mailbox',
        'conversations.view'    => 'conversation',
    ];

    /** True once Refresh's "My dashboard" block was rendered during this request (claim "dashboard@mailbox"). */
    protected static $dashboardRendered = false;

    public static function registerHooks()
    {
        // SLA targets: an answer already given by another module is kept (Eventy passes a null value on as '')
        \Eventy::addFilter('fsinterop.sla.targets', function ($targets, $context = []) {
            return $targets ?: self::slaTargets();
        }, 20, 2);

        \Eventy::addFilter('fsinterop.modules', function ($modules) {
            $modules = is_array($modules) ? $modules : [];
            $modules[self::ID] = [
                'id'           => self::ID,
                'name'         => 'Refresh',
                'version'      => self::version(),
                'protocol'     => self::PROTOCOL,
                'capabilities' => self::CAPABILITIES,
                'manifest'     => 'interop.json',
            ];
            return $modules;
        }, 20, 1);

        \Eventy::addFilter('fsinterop.navigation.context', function ($context) {
            return self::claimSurfaces($context);
        }, 20, 1);

        // Browser side: read by Public/js/interop.js while it loads (registration at script evaluation)
        \Eventy::addAction('layout.head', function () {
            try {
                if (!auth()->check()) {
                    return;
                }
                echo '<meta name="refresh-interop" content="'.e(json_encode(self::browserConfig(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'">'."\n";
            } catch (\Throwable $e) {
                // optional: without it, Refresh simply does not take part in the contract on this page
            }
        });
    }

    /** Refresh's version (module.json). */
    public static function version()
    {
        $json = json_decode((string)@file_get_contents(__DIR__.'/../module.json'), true) ?: [];
        return (string)($json['version'] ?? '');
    }

    /** Answer to fsinterop.sla.targets (schema sla-targets 1.0), from Refresh's settings. */
    public static function slaTargets()
    {
        return [
            'schema'               => '1.0',
            'source_module'        => self::ID,
            'first_response_hours' => Settings::firstResponseHours(),
            'resolution_hours'     => Settings::resolutionHours(),
        ];
    }

    /**
     * Should Refresh render its "My dashboard" block for this user? Asks fsinterop.dashboard.owner (schema
     * dashboard-owner 1.0): no answer, or "refresh" → yes; another module id → that module renders the dashboard.
     */
    public static function ownsDashboard($user)
    {
        try {
            $owner = \Eventy::filter('fsinterop.dashboard.owner', null, ['schema' => '1.0', 'page' => 'dashboard', 'user_id' => $user ? (int)$user->id : null]);
        } catch (\Throwable $e) {
            \Log::warning('[Refresh] [interop] fsinterop.dashboard.owner: '.$e->getMessage());
            return true;
        }
        return !is_string($owner) || $owner === '' || $owner === self::ID;
    }

    /** Refresh's block was rendered on this page (the dashboard filter calls it). */
    public static function dashboardRendered()
    {
        self::$dashboardRendered = true;
    }

    /**
     * Claims of fsinterop.navigation.context: the surfaces Refresh renders in the page's context. An existing claim is
     * never overwritten; the starting fields are left alone.
     */
    public static function claimSurfaces($context)
    {
        if (!is_array($context)) {
            return $context;
        }
        $claims = ['navigation@*'];
        switch ((string)($context['context'] ?? '')) {
            case 'mailbox':
                $claims[] = 'ticket-list@mailbox';
                break;
            case 'conversation':
                $claims[] = 'ticket-toolbar@conversation';
                break;
            case 'dashboard':
                if (self::$dashboardRendered) {
                    $claims[] = 'dashboard@mailbox';
                }
                break;
        }
        if (!isset($context['owners']) || !is_array($context['owners'])) {
            $context['owners'] = [];
        }
        foreach ($claims as $key) {
            if (!isset($context['owners'][$key])) {
                $context['owners'][$key] = self::ID;
            }
        }
        return $context;
    }

    /**
     * Content of <meta name="refresh-interop">: the registration, the surfaces Refresh renders on this page (keys of
     * its own claims, read from the same rules as fsinterop.navigation.context) and the ticket of a ticket page.
     */
    public static function browserConfig()
    {
        $route = (string)\Route::currentRouteName();
        $context = self::ROUTE_CONTEXTS[$route] ?? 'other';
        // Blade renders the page content before the layout: whether "My dashboard" was rendered is known here
        $surfaces = array_keys(self::claimSurfaces(['context' => $context, 'owners' => []])['owners']);
        $page = ['context' => $context, 'surfaces' => $surfaces, 'userId' => (int)auth()->id(), 'mailboxId' => null, 'conversationId' => null, 'deleted' => false];
        if ($context === 'mailbox') {
            $id = request()->route('mailbox_id') ?: request()->route('id');
            $page['mailboxId'] = is_numeric($id) ? (int)$id : null;
        } elseif ($context === 'conversation') {
            $conversation = Conversation::find((int)request()->route('id'));
            if ($conversation) {
                $page['conversationId'] = (int)$conversation->id;
                $page['mailboxId'] = (int)$conversation->mailbox_id;
                $page['deleted'] = (int)$conversation->state === (int)Conversation::STATE_DELETED;
            }
        }
        return [
            'module'       => ['id' => self::ID, 'version' => self::version()],
            'protocol'     => self::PROTOCOL,
            'capabilities' => self::CAPABILITIES,
            'page'         => $page,
        ];
    }
}
