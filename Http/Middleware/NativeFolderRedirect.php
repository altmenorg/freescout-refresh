<?php

namespace Modules\Refresh\Http\Middleware;

use Closure;
use Modules\Refresh\Services\Views;

/**
 * FreeScout's own mailbox pages (/mailbox/1, /mailbox/1/<folder>) open the equivalent Refresh view instead.
 * They are still reached from FreeScout itself: "next ticket" after closing the last one of a folder, "Send and close",
 * old bookmarks. Folders without an equivalent view (added by other modules) keep the native page.
 */
class NativeFolderRedirect
{
    public function handle($request, Closure $next)
    {
        $route = $request->route();
        if (!$route || !$request->isMethod('GET') || $request->ajax() || !auth()->check()
            || !in_array($route->getName(), ['mailboxes.view', 'mailboxes.view.folder'])) {
            return $next($request);
        }
        $mailbox_id = (int)$route->parameter('id');
        $view = 'unresolved';
        if ($route->getName() === 'mailboxes.view.folder') {
            $folder = \App\Folder::find((int)$route->parameter('folder_id'));
            $view = $folder ? Views::viewForFolderType($folder->type) : null;
        }
        if (!$view || !$mailbox_id) {
            return $next($request);
        }

        return redirect()->route('refresh.tickets', ['mailbox_id' => $mailbox_id, 'view' => $view]);
    }
}
