<?php

namespace Modules\Refresh\Http\Controllers;

use App\Conversation;
use App\Thread;
use App\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Agent collision detection, like Freshdesk (Public/js/collision.js): who else has the ticket open or is replying,
 * and the messages added since the agent's page was loaded. Read only, JSON, with FreeScout's own permissions.
 * FreeScout's realtime events (RealtimeConvView, RealtimeConvNewThread) tell the page when to ask; this answer is
 * the reference, so that a missed event never lets a reply go out unaware of a newer message.
 */
class CollisionController extends Controller
{
    /** A viewer not seen for longer is gone (FreeScout refreshes its record every 15 s while the ticket is open). */
    const VIEWER_TTL = 30;

    public function activity(Request $request, $id)
    {
        $conversation = Conversation::find((int)$id);
        $me = auth()->user();
        if (!$conversation || !$me->can('view', $conversation)) {
            return response()->json(['status' => 'error'], 403);
        }

        // Published messages and notes newer than the page, except the agent's own (a customer message always counts)
        $after = (int)$request->input('after');
        $threads = [];
        if ($after > 0) {
            $rows = Thread::where('conversation_id', $conversation->id)
                ->where('id', '>', $after)
                ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE])
                ->where('state', Thread::STATE_PUBLISHED)
                ->where(function ($q) use ($me) {
                    // a note without agent (reply to a forward, Services\Forward) counts too: NULL != id is not true in SQL
                    $q->where('type', Thread::TYPE_CUSTOMER)->orWhere('created_by_user_id', '!=', $me->id)->orWhereNull('created_by_user_id');
                })
                ->orderBy('id')->limit(20)->get();
            foreach ($rows as $thread) {
                $by = $thread->getCreatedBy();
                $threads[] = [
                    'id'   => $thread->id,
                    'type' => (int)$thread->type === Thread::TYPE_CUSTOMER ? 'customer' : ((int)$thread->type === Thread::TYPE_NOTE ? 'note' : 'message'),
                    'name' => $by ? $by->getFullName(true) : '',
                ];
            }
        }

        return response()->json([
            'status'  => 'success',
            'threads' => $threads,
            'viewers' => self::viewers($conversation->id, $me->id),
        ]);
    }

    /**
     * Other agents on the ticket, replying ones first, from FreeScout's own record (cache "conv_view", kept up to date
     * by its realtime viewers: user id => ['r' => replying, 't' => last seen]).
     */
    public static function viewers($conversation_id, $except_user_id)
    {
        $cache = \Cache::get('conv_view');
        if (empty($cache[$conversation_id]) || !is_array($cache[$conversation_id])) {
            return [];
        }
        $min = \Carbon\Carbon::now()->subSeconds(self::VIEWER_TTL)->toDateTimeString();
        $seen = [];
        foreach ($cache[$conversation_id] as $user_id => $record) {
            // no date: older FreeScout versions, the record is cleaned up by freescout:check-conv-viewers
            if ((int)$user_id === (int)$except_user_id || (!empty($record['t']) && $record['t'] < $min)) {
                continue;
            }
            $seen[(int)$user_id] = !empty($record['r']);
        }
        if (!$seen) {
            return [];
        }
        $viewers = [];
        foreach (User::whereIn('id', array_keys($seen))->get() as $user) {
            $viewers[] = [
                'id'       => $user->id,
                'name'     => $user->getFullName(),
                'photo'    => $user->getPhotoUrl(false),
                'replying' => $seen[$user->id],
            ];
        }
        usort($viewers, function ($a, $b) {
            return ($b['replying'] <=> $a['replying']) ?: strcmp($a['name'], $b['name']);
        });

        return $viewers;
    }
}
