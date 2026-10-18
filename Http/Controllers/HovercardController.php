<?php

namespace Modules\Refresh\Http\Controllers;

use App\Conversation;
use App\Customer;
use App\Thread;
use App\Http\Controllers\Controller;
use Modules\Refresh\Providers\RefreshServiceProvider as Ui;

/**
 * Hover cards of the ticket list, like Freshdesk (Public/js/hovercards.js): the first message of a ticket when the
 * pointer rests on its subject, the contact's details (copyable e-mails and phones) on the contact's name.
 * Read only, JSON, with FreeScout's own permissions (view on the conversation or the customer).
 */
class HovercardController extends Controller
{
    /** Longest preview sent, in characters: enough to read the request, small enough to stay a tooltip. */
    const PREVIEW_MAX = 1500;

    public function conversation($id)
    {
        $conversation = Conversation::find((int)$id);
        if (!$conversation || !auth()->user()->can('view', $conversation)) {
            return response()->json(['status' => 'error'], 403);
        }
        // First message of the ticket: customer message, agent message (ticket opened by an agent) or note (phone
        // ticket); drafts excluded (a draft new ticket has no published thread: its draft is shown instead)
        $types = [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE];
        $thread = Thread::where('conversation_id', $conversation->id)->whereIn('type', $types)
            ->where('state', Thread::STATE_PUBLISHED)->orderBy('id')->first()
            ?: Thread::where('conversation_id', $conversation->id)->whereIn('type', $types)->orderBy('id')->first();
        if (!$thread) {
            return response()->json(['status' => 'success', 'empty' => true]);
        }

        $text = self::plainText((string)$thread->body);
        $cut = mb_strlen($text) > self::PREVIEW_MAX;
        $by = $thread->getCreatedBy();

        return response()->json([
            'status'      => 'success',
            'from'        => $by ? $by->getFullName(true) : '',
            'date'        => Ui::localDate(\Carbon\Carbon::parse($thread->created_at)),
            'note'        => (int)$thread->type === Thread::TYPE_NOTE,
            'draft'       => (int)$thread->state === Thread::STATE_DRAFT,
            'text'        => $cut ? rtrim(mb_substr($text, 0, self::PREVIEW_MAX)).'…' : $text,
            'attachments' => $thread->has_attachments ? $thread->attachments()->count() : 0,
        ]);
    }

    /**
     * Message body as plain text. Not Thread::getBodyAsText(): its converter drops encoded apostrophes
     * ("qu&#039;il" became "quil"). Block ends become line breaks, entities are decoded, blank lines collapsed.
     */
    protected static function plainText($html)
    {
        $html = preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6]|blockquote)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', str_replace("\r", '', $text));
        $text = preg_replace("/ *\n */", "\n", $text);
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    public function customer($id)
    {
        $customer = Customer::find((int)$id);
        if (!$customer || !auth()->user()->can('view', $customer)) {
            return response()->json(['status' => 'error'], 403);
        }
        $vars = Ui::contactPanelVars($customer);

        return response()->json([
            'status'    => 'success',
            'name'      => $customer->getFullName(true),
            'initial'   => $vars['initial'],
            'av'        => [$vars['av_bg'], $vars['av_border'], $vars['av_fg']],
            'job_title' => (string)$customer->job_title,
            'company'   => (string)$customer->company,
            'emails'    => array_values($vars['emails']),
            'phones'    => $vars['phones'],
            'tickets'   => Conversation::where('customer_id', $customer->id)->where('state', Conversation::STATE_PUBLISHED)->count(),
            'url'       => route('customers.conversations', ['id' => $customer->id]),
        ]);
    }
}
