<?php

namespace Modules\ModernUi\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ModernUi\Providers\ModernUiServiceProvider as Ui;
use Modules\ModernUi\Services\Views;

/**
 * List views Freshdesk-style (/mailbox/{id}/tickets/{view}): views menu, "Sort by", "1 - 30 of N" pagination,
 * Filters panel (GET, so a filtered view can be bookmarked or shared).
 * Reuses the native conversations table (badges, checkboxes, bulk actions) with a fake folder.
 */
class TicketsController extends Controller
{
    const PER_PAGE = 30; // Freshdesk: 30 tickets per page

    public function index(Request $request, $mailbox_id, $view = 'all')
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);
        $this->authorize('view', $mailbox);
        $user = auth()->user();

        $defs = Views::definitions();
        if (!isset($defs[$view])) {
            abort(404);
        }

        $filters = Views::readFilters($request, $view);
        // Remembered sort (one-year cookie): the last "Sort by" choice applies to other views, like Freshdesk.
        // The URL still takes priority, so a shared link keeps its sort.
        if ($request->has('sort')) {
            $sort = (string)$request->input('sort');
            $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
            \Cookie::queue('mu_sort', $sort.':'.$order, 525600);
        } else {
            $saved = explode(':', (string)$request->cookie('mu_sort', ''));
            $sort = $saved[0] !== '' ? $saved[0] : 'created';
            $order = (isset($saved[1]) && $saved[1] === 'asc') ? 'asc' : 'desc';
        }
        if (!isset(Views::sorts()[$sort])) {
            $sort = 'created';
        }

        $query = Views::query($mailbox->id, $view, $user);
        Views::applyFilters($query, $filters);
        Views::applySort($query, $sort, $order);
        $conversations = $query->paginate(self::PER_PAGE)->appends($request->except('page'));

        $folder = Ui::virtualFolder($mailbox->id, $conversations->total());

        $tags = [];
        if (class_exists('\Modules\Tags\Entities\Tag')) {
            $tags = \Modules\Tags\Entities\Tag::orderBy('name')->get();
        }

        // Last viewed view (address without the page number, and title): /tickets and a ticket's breadcrumb lead back here.
        $query = $request->except('page');
        $lastUrl = '/mailbox/'.$mailbox->id.'/tickets/'.$view.($query ? '?'.http_build_query($query) : '');
        $lastTitle = ($lsv = Views::savedView((string)$request->input('sv'))) ? $lsv['label'] : $defs[$view][0];
        \Cookie::queue('mu_last_view', json_encode(['u' => $lastUrl, 't' => $lastTitle]), 525600);

        return view('modernui::tickets', [
            'mailbox'       => $mailbox,
            'folders'       => $mailbox->getAssesibleFolders(),
            'folder'        => $folder,
            'conversations' => $conversations,
            'view'          => $view,
            'view_title'    => ($sv = Views::savedView((string)$request->input('sv'))) ? $sv['label'] : $defs[$view][0],
            'saved_view'    => $sv,
            'filters'       => $filters,
            'filters_count' => Views::activeCount($filters, $view),
            'sort'          => $sort,
            'order'         => $order,
            'sorts'         => Views::sorts(),
            'periods'       => Views::periods(),
            'dues'          => Views::dues(),
            'priorities'    => Views::priorities(),
            'types'         => Views::types(),
            'tags'          => $tags,
            'users'         => $mailbox->usersAssignable(),
            'statuses'      => [
                Conversation::STATUS_ACTIVE  => __('modernui::labels.open'),
                Conversation::STATUS_PENDING => __('Pending'),
                Conversation::STATUS_CLOSED  => __('Closed'),
            ],
        ]);
    }

    /** /tickets: last viewed tickets view (mu_last_view cookie), otherwise "All tickets" of the first mailbox. */
    public function last(Request $request)
    {
        $last = self::lastView($request);
        if ($last) {
            return redirect($last['u']);
        }
        $mailbox = auth()->user()->mailboxesCanView()->first();
        return $mailbox ? redirect()->route('modernui.tickets', ['mailbox_id' => $mailbox->id, 'view' => 'all']) : redirect('/');
    }

    /** Last remembered view ['u' => local address, 't' => title], or null (cookie missing or invalid). */
    public static function lastView(Request $request)
    {
        $last = json_decode((string)$request->cookie('mu_last_view', ''), true);
        if (!is_array($last) || empty($last['u']) || !preg_match('#^/mailbox/\d+/tickets/[a-z0-9-]+(\?.*)?$#', $last['u'])) {
            return null;
        }
        return ['u' => $last['u'], 't' => (string)($last['t'] ?? __('All tickets'))];
    }

    /** CSV export of the filtered view (toolbar "Export" button, like Freshdesk). ";" separator for French-locale Excel. */
    public function export(Request $request, $mailbox_id, $view)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);
        $this->authorize('view', $mailbox);
        if (!isset(Views::definitions()[$view])) {
            abort(404);
        }
        $filters = Views::readFilters($request, $view);
        $query = Views::query($mailbox->id, $view, auth()->user());
        Views::applyFilters($query, $filters);
        Views::applySort($query, $request->input('sort', 'created'), $request->input('order') === 'asc' ? 'asc' : 'desc');
        $prios = Views::priorities();
        $filename = 'tickets-'.$view.'-'.date('Y-m-d').'.csv';

        return response()->stream(function () use ($query, $prios) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, [__('Number'), __('Subject'), __('Status'), __('Priority'), __('Type'), __('Agent'), __('Customer'), __('E-mail'), __('Tags'), __('Created on'), __('Last response'), __('Closed on')], ';');
            $query->with(['user', 'customer'])->chunk(500, function ($rows) use ($out, $prios) {
                $ids = $rows->pluck('id')->all();
                $tags = [];
                if ($ids && \Schema::hasTable('conversation_tag')) {
                    foreach (\DB::table('conversation_tag')->join('tags', 'tags.id', '=', 'conversation_tag.tag_id')
                        ->whereIn('conversation_tag.conversation_id', $ids)->get(['conversation_tag.conversation_id', 'tags.name']) as $t) {
                        $tags[$t->conversation_id][] = $t->name;
                    }
                }
                foreach ($rows as $c) {
                    $meta = is_array($c->meta) ? $c->meta : (json_decode((string)$c->meta, true) ?: []);
                    $p = (int)($meta['fd_priority'] ?? 1);
                    fputcsv($out, [
                        $c->number,
                        $c->getSubject(),
                        $c->getStatusName(),
                        $prios[$p][0] ?? __('Low'),
                        $meta['fd_type'] ?? '',
                        $c->user ? $c->user->getFullName() : '',
                        $c->customer ? $c->customer->getFullName() : '',
                        $c->customer_email,
                        implode(', ', $tags[$c->id] ?? []),
                        $c->created_at ? $c->created_at->format('d/m/Y H:i') : '',
                        $c->last_reply_at ? \Carbon\Carbon::parse($c->last_reply_at)->format('d/m/Y H:i') : '',
                        $c->closed_at ? \Carbon\Carbon::parse($c->closed_at)->format('d/m/Y H:i') : '',
                    ], ';');
                }
            });
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** Header search suggestions: the 8 most recent tickets matching the text (JSON). */
    public function quickSearch(Request $request)
    {
        $q = trim((string)$request->input('q', ''));
        $user = auth()->user();
        $mailbox = $user->mailboxesCanView()->first();
        if (mb_strlen($q) < 2 || !$mailbox) {
            return response()->json(['items' => []]);
        }
        $filters = Views::readFilters(\Illuminate\Http\Request::create('/', 'GET', ['q' => $q, 'created' => 'any']), 'all');
        $query = Views::query($mailbox->id, 'all', $user);
        Views::applyFilters($query, $filters);
        $rows = $query->with('customer')->orderBy('conversations.last_reply_at', 'desc')->limit(8)->get();
        $items = [];
        foreach ($rows as $c) {
            $items[] = [
                'number'   => $c->number,
                'subject'  => $c->getSubject(),
                'customer' => $c->customer ? $c->customer->getFullName(true) : $c->customer_email,
                'status'   => $c->getStatusName(),
                'closed'   => in_array((int)$c->status, [\App\Conversation::STATUS_CLOSED, \App\Conversation::STATUS_SPAM]),
                'url'      => $c->url(),
            ];
        }
        return response()->json(['items' => $items]);
    }
}
