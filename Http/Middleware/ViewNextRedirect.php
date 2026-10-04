<?php

namespace Modules\Refresh\Http\Middleware;

use App\Conversation;
use App\MailboxUser;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Refresh\Http\Controllers\TicketsController;
use Modules\Refresh\Services\Views;

/**
 * "Next ticket" after closing one (Send and close, Close button, properties panel: native after_send = next).
 * FreeScout picks the next open ticket of the ticket's own folder (e.g. Unassigned), in that folder's order, and the
 * folder itself when there is none left. With Refresh the agent works in a view: the next ticket is the one that
 * followed in that view (rf_last_view cookie, same filters and sort), as it was listed BEFORE closing (with the
 * "Last modified" sort, the ticket just answered would otherwise jump to the top). Not closed, nearest before when none
 * follows (like FreeScout), back to the view when the view is done. A ticket opened from elsewhere (search…) keeps
 * FreeScout's choice.
 */
class ViewNextRedirect
{
    const MAX_IDS = 5000; // safety limit on the size of the remembered list

    public function handle($request, Closure $next)
    {
        $route = $request->route();
        if (!$route || $route->getName() !== 'conversations.ajax' || !$request->isMethod('POST') || !auth()->check()
            || (int)$request->input('after_send') !== MailboxUser::AFTER_SEND_NEXT) {
            return $next($request);
        }
        $conversation = Conversation::find((int)$request->input('conversation_id'));
        $list = $conversation ? $this->viewList($request, $conversation) : null;

        $response = $next($request);

        if (!$list || !($response instanceof JsonResponse)) {
            return $response;
        }
        $data = $response->getData(true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success' || empty($data['redirect_url'])) {
            return $response;
        }
        $data['redirect_url'] = $this->nextUrl($list, $conversation->id);

        return $response->setData($data);
    }

    /** Ids of the agent's last view, in its order, if the ticket is in it: ['ids' => [...], 'url' => view address]. */
    protected function viewList(Request $request, Conversation $conversation)
    {
        $last = TicketsController::lastView($request);
        if (!$last || !preg_match('#^/mailbox/(\d+)/tickets/([a-z0-9-]+)(\?(.*))?$#', $last['u'], $m)
            || (int)$m[1] !== (int)$conversation->mailbox_id || !isset(Views::definitions()[$m[2]])) {
            return null;
        }
        $params = [];
        parse_str($m[4] ?? '', $params);
        $view_request = Request::create($last['u'], 'GET', $params, $request->cookies->all());

        $query = Views::query($conversation->mailbox_id, $m[2], auth()->user());
        Views::applyFilters($query, Views::readFilters($view_request, $m[2]));
        list($sort, $order) = TicketsController::sortFor($view_request);
        Views::applySort($query, $sort, $order);
        $ids = array_map('intval', $query->limit(self::MAX_IDS)->pluck('conversations.id')->all());

        return in_array((int)$conversation->id, $ids, true) ? ['ids' => $ids, 'url' => $last['u']] : null;
    }

    /** Following ticket of the list that is still open or pending (re-read after the action), else the view. */
    protected function nextUrl(array $list, $current_id)
    {
        $ids = $list['ids'];
        $i = array_search((int)$current_id, $ids, true);
        $candidates = array_merge(array_slice($ids, $i + 1), array_reverse(array_slice($ids, 0, $i)));
        if ($candidates) {
            $open = array_flip(Conversation::whereIn('id', $candidates)
                ->where('state', Conversation::STATE_PUBLISHED)
                ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
                ->pluck('id')->all());
            foreach ($candidates as $id) {
                if (isset($open[$id])) {
                    return route('conversations.view', ['id' => $id]);
                }
            }
        }

        return url($list['url']);
    }
}
