<?php

namespace Modules\Refresh\Http\Controllers;

use App\Conversation;
use App\Thread;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * "Split ticket" Freshdesk-style: the chosen message LEAVES its ticket to open a new ticket (same mailbox,
 * same customer, same subject), instead of FreeScout's native "New ticket" which opens a pre-filled form and leaves
 * the message duplicated in the original ticket. A note in the original ticket keeps track of the split.
 */
class SplitController extends Controller
{
    public function split(Request $request, $thread_id)
    {
        $user = auth()->user();
        $thread = Thread::find($thread_id);
        $conv = $thread ? $thread->conversation : null;

        if (!$thread || !$conv) {
            return response()->json(['status' => 'error', 'msg' => __('Message not found')]);
        }
        if (!$user->can('view', $conv)) {
            return response()->json(['status' => 'error', 'msg' => __('Access denied')]);
        }
        if (!in_array($thread->type, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE]) || $thread->state != Thread::STATE_PUBLISHED) {
            return response()->json(['status' => 'error', 'msg' => __('This message cannot be split')]);
        }
        // Like Freshdesk: no split on the first message (the original ticket would stay empty).
        $first = $conv->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('created_at')->orderBy('id')
            ->first();
        if ($first && $first->id == $thread->id) {
            return response()->json(['status' => 'error', 'msg' => __('The first message of a ticket cannot be split')]);
        }

        $is_customer = ($thread->type == Thread::TYPE_CUSTOMER);
        $counted = ($thread->type != Thread::TYPE_NOTE); // threads_count counts neither notes nor log lines

        $new = \DB::transaction(function () use ($conv, $thread, $user, $is_customer, $counted) {
            $new = $conv->replicate(['number']); // the number is assigned on creation (Conversation::boot)
            $new->status = Conversation::STATUS_ACTIVE;
            $new->state = Conversation::STATE_PUBLISHED;
            $new->threads_count = $counted ? 1 : 0;
            $new->customer_id = ($is_customer && $thread->customer_id) ? $thread->customer_id : $conv->customer_id;
            // author of the new ticket = author of the message (the customer for a customer message), like Freshdesk
            $new->created_by_user_id = $is_customer ? null : ($thread->created_by_user_id ?: $user->id);
            $new->created_by_customer_id = $is_customer ? ($thread->created_by_customer_id ?: $new->customer_id) : null;
            $new->source_via = $is_customer ? Conversation::PERSON_CUSTOMER : Conversation::PERSON_USER;
            $new->source_type = $thread->source_type ?: Conversation::SOURCE_TYPE_WEB;
            $new->has_attachments = (bool)$thread->has_attachments;
            $new->closed_at = null;
            $new->closed_by_user_id = null;
            // meta specific to the original ticket (imported Freshdesk number…) is dropped; type and priority are kept
            $new->meta = null;
            foreach (['fd_type', 'fd_priority'] as $key) {
                if ($conv->getMeta($key) !== null) {
                    $new->setMeta($key, $conv->getMeta($key));
                }
            }
            $new->last_reply_at = $thread->created_at;
            $new->last_reply_from = $is_customer ? Conversation::PERSON_CUSTOMER : Conversation::PERSON_USER;
            $new->last_customer_reply_at = $is_customer ? $thread->created_at : null;
            $new->user_updated_at = date('Y-m-d H:i:s');
            $new->setPreview($thread->body);
            $new->updateFolder();
            $new->save();

            $thread->conversation_id = $new->id;
            $thread->first = true;
            $thread->setMeta(Thread::META_PREV_CONVERSATION, $conv->id);
            $thread->save();

            if ($counted && $conv->threads_count > 0) {
                $conv->threads_count--;
            }
            $conv->save();

            // trace in the original ticket (internal note, no notification: Thread::create does not trigger a send)
            Thread::create($conv, Thread::TYPE_NOTE,
                '' . __('A message was moved to the new ticket') . ' <a href="'.e($new->url()).'">#'.$new->number.'</a> ' . __('(split by') . ' '.e($user->getFullName()).').',
                [
                    'created_by_user_id' => $user->id,
                    'user_id'     => $conv->user_id,
                    'source_via'  => Thread::PERSON_USER,
                    'source_type' => Thread::SOURCE_TYPE_WEB,
                    'customer_id' => $conv->customer_id,
                ]);

            // preview of the original ticket: its last remaining message, set AFTER the note (otherwise ThreadObserver makes the note the preview)
            $conv = Conversation::find($conv->id);
            $last = $conv->threads()
                ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
                ->where('state', Thread::STATE_PUBLISHED)
                ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
                ->first();
            if ($last) {
                $conv->setPreview($last->body);
                $conv->save();
            }

            return $new;
        });

        $conv->mailbox->updateFoldersCounters();
        \Session::flash('flash_success_floating', __('Ticket split: new ticket #').$new->number);

        return response()->json(['status' => 'success', 'url' => $new->url(), 'number' => $new->number]);
    }
}
