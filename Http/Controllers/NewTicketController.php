<?php

namespace Modules\Refresh\Http\Controllers;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Refresh\Providers\RefreshServiceProvider as Ui;
use Modules\Refresh\Services\Views;

/**
 * "New ticket" page (Freshdesk): the agent creates the ticket on behalf of the contact. The description is the
 * CONTACT's message and nothing is e-mailed to them (FreeScout's own form can only create a ticket whose first message
 * goes out by e-mail, or a phone ticket whose description is an internal note). The page is FreeScout's own create form,
 * restyled by the provider script; its "Create" button posts here instead of the native send_reply.
 * Also serves the right-hand contact panel of the New ticket / Send an e-mail pages.
 */
class NewTicketController extends Controller
{
    /** Set while store() creates the ticket: the "autoreply.should_send" filter (provider) refuses the auto-reply. */
    public static $creating = false;

    /** Contact details + recent timeline of the chosen contact (empty state without one). */
    public function contactPanel(Request $request)
    {
        $mailbox = Mailbox::find((int)$request->input('mailbox_id'));
        if (!$mailbox || !$mailbox->userHasAccess(auth()->id())) {
            abort(403);
        }
        $customer = null;
        $email = trim((string)$request->input('email', ''));
        if ($email !== '') {
            $customer = Customer::getByEmail($email);
        }
        if ($customer && !auth()->user()->can('view', $customer)) {
            $customer = null;
        }

        return view('refresh::contact_panel', Ui::contactPanelVars($customer) + ['empty_state' => true])->render();
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $mailbox = Mailbox::find((int)$request->input('mailbox_id'));
        if (!$mailbox || !$mailbox->userHasAccess($user->id)) {
            return response()->json(['status' => 'error', 'msg' => __('Not enough permissions')]);
        }

        // Contact: an existing one (or a new e-mail typed in the Contact field), or the "Add a new contact" fields
        $email = '';
        $to = $request->input('to', []);
        if (is_array($to) && count($to)) {
            $email = trim((string)reset($to));
        }
        $new_contact = trim((string)$request->input('rf_contact_email', '')) !== '';
        if ($new_contact) {
            $email = trim((string)$request->input('rf_contact_email'));
        }
        if ($email === '' || !\MailHelper::sanitizeEmails([$email])) {
            return response()->json(['status' => 'error', 'msg' => __('Please choose a contact')]);
        }
        $subject = trim((string)$request->input('subject', ''));
        if ($subject === '') {
            return response()->json(['status' => 'error', 'msg' => __('Please enter a subject')]);
        }
        $body = (string)$request->input('body', '');
        if (trim(strip_tags($body, '<img>')) === '') {
            return response()->json(['status' => 'error', 'msg' => __('Please enter a message')]);
        }

        $customer = Customer::getByEmail($email);
        if (!$customer) {
            $name = trim((string)$request->input('rf_contact_name', ''));
            $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];
            $customer = Customer::create($email, ['first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '']);
            if (!$customer) {
                return response()->json(['status' => 'error', 'msg' => __('Invalid e-mail address')]);
            }
            $phone = trim((string)$request->input('rf_contact_phone', ''));
            if ($phone !== '') {
                $customer->addPhone($phone, Customer::PHONE_TYPE_WORK);
                $customer->save();
            }
        }

        $status = (int)$request->input('status', Conversation::STATUS_ACTIVE);
        if (!in_array($status, [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING, Conversation::STATUS_CLOSED], true)) {
            $status = Conversation::STATUS_ACTIVE;
        }
        $assignee = (int)$request->input('user_id', -1);
        $assignee = ($assignee > 0 && $mailbox->usersAssignable()->contains('id', $assignee)) ? $assignee : null;

        // Attachments uploaded by the editor (native upload, linked to the thread below)
        $attachments = (new \App\Http\Controllers\ConversationsController())->processReplyAttachments($request);

        self::$creating = true;
        try {
            $created = Conversation::create([
                'type'        => Conversation::TYPE_EMAIL, // the agent's replies will go to the contact by e-mail
                'subject'     => $subject,
                'mailbox_id'  => $mailbox->id,
                'source_type' => Conversation::SOURCE_TYPE_WEB,
                'source_via'  => Conversation::PERSON_CUSTOMER,
                'status'      => $status,
                'user_id'     => $assignee,
            ], [[
                'type' => Thread::TYPE_CUSTOMER,
                'body' => $body,
                'cc'   => (array)$request->input('cc', []),
            ]], $customer);
        } finally {
            self::$creating = false;
        }
        // ['conversation' => …, 'thread' => …], or false when the thread could not be created
        $conversation = is_array($created) ? ($created['conversation'] ?? null) : null;
        if (!$conversation) {
            return response()->json(['status' => 'error', 'msg' => __('Error occurred')]);
        }

        // Created by the agent (FreeScout shows it), status, type / priority / tags
        $conversation->created_by_user_id = $user->id;
        $conversation->status = $status;
        if ($status == Conversation::STATUS_CLOSED) {
            $conversation->closed_by_user_id = $user->id;
            $conversation->closed_at = date('Y-m-d H:i:s');
        }
        $conversation->updateFolder();
        $conversation->save();
        $thread = $conversation->threads()->first();
        if ($thread) {
            $thread->source_type = Thread::SOURCE_TYPE_WEB;
            $thread->created_by_user_id = $user->id;
            if (!empty($attachments['attachments'])) {
                Attachment::whereIn('id', $attachments['attachments'])->whereNull('thread_id')->update(['thread_id' => $thread->id]);
                if ($attachments['has_attachments']) {
                    $thread->has_attachments = true;
                    $conversation->has_attachments = true;
                    $conversation->save();
                }
            }
            $thread->save();
        }
        self::saveExtras($conversation, $request);
        $mailbox->updateFoldersCounters();
        Views::forgetCounts();

        // Unsent draft of the same form (drafts of new conversations: deleted, the ticket replaces it)
        $draft_id = (int)$request->input('conversation_id');
        if ($draft_id && $draft_id != $conversation->id) {
            $draft = Conversation::find($draft_id);
            if ($draft && $draft->state == Conversation::STATE_DRAFT && $draft->created_by_user_id == $user->id) {
                $draft->deleteThreads();
                $draft->delete();
                $mailbox->updateFoldersCounters(\App\Folder::TYPE_DRAFTS);
            }
        }

        \Session::flash('flash_success_floating', __('Ticket created'));

        return response()->json([
            'status'       => 'success',
            'redirect_url' => $request->input('rf_another')
                ? route('conversations.create', ['mailbox_id' => $mailbox->id]).'?rf_mode=ticket'
                : route('conversations.view', ['id' => $conversation->id]),
        ]);
    }

    /** Type, priority (conversation meta, like the properties panel) and tags of a ticket created from these pages. */
    public static function saveExtras(Conversation $conversation, Request $request)
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : (json_decode((string)$conversation->meta, true) ?: []);
        $type = trim((string)$request->input('rf_type', ''));
        if ($type !== '') {
            $meta['fd_type'] = mb_substr($type, 0, 100);
        }
        $priority = (int)$request->input('rf_priority', 1);
        $meta['fd_priority'] = isset(Views::priorities()[$priority]) ? $priority : 1;
        $conversation->meta = $meta;
        $conversation->save();
        \Cache::forget('refresh_types');

        $tags = $request->input('rf_tags', []);
        if (is_array($tags) && count($tags) && class_exists('\Modules\Tags\Entities\Tag')) {
            foreach ($tags as $tag) {
                $tag = trim((string)$tag);
                if ($tag !== '') {
                    \Modules\Tags\Entities\Tag::attachByName($tag, $conversation->id);
                }
            }
        }
    }
}
