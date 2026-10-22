<?php

namespace Modules\Refresh\Services;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Thread;

/**
 * Forward inside the ticket, like Freshdesk (setting "Forward within the ticket", off by default).
 *
 * FreeScout's Forward creates a new "Fwd:" conversation per recipient, where the recipient's reply lands. Here the
 * forward stays in the ticket as a private note (meta rf_fwd) and is e-mailed to the recipient by FreeScout's own
 * job (SendReplyToCustomer), adjusted through its hooks: "Fwd:" subject, Message-ID of the note, no threading on the
 * customer's messages. The recipient's reply comes back into the same ticket as a private note too (meta rf_fwd_in):
 * the conversation's customer does not change, and nothing of it reaches the customer's e-mails (notes never do).
 * An agent can answer that reply from the ticket (Reply on the note: same mechanism, rf_fwd.reply_to).
 */
class Forward
{
    const META_OUT = 'rf_fwd';
    const META_IN = 'rf_fwd_in';

    public static function enabled()
    {
        return (string)Settings::get('forward_in_ticket', '0') === '1';
    }

    public static function isOut($thread)
    {
        return $thread && $thread->type == Thread::TYPE_NOTE && is_array($thread->getMeta(self::META_OUT));
    }

    public static function isIn($thread)
    {
        return $thread && $thread->type == Thread::TYPE_NOTE && is_array($thread->getMeta(self::META_IN));
    }

    /**
     * Message-ID of a forward note: the format of an agent reply (FreeScout's getMessageId() returns nothing for a
     * note), so that FetchEmails links the recipient's reply to this note (prefix, thread id and hash checked there).
     */
    public static function messageId($thread, $mailbox)
    {
        return \MailHelper::MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER.'-'.$thread->id.'-'.\MailHelper::getMessageIdHash($thread->id)
            .'@'.$mailbox->getEmailDomain();
    }

    /**
     * Saves the forward note and queues its e-mail. Same request as FreeScout's forward form (to_email[], cc, bcc,
     * body, thread_id of the forward draft, attachments, conv_history); rf_reply_to: id of a received reply (rf_fwd_in
     * note) being answered. Returns ['status' => 'success'|'error', 'msg', 'redirect_url'].
     */
    public static function send($request, Conversation $conversation, $user)
    {
        $mailbox = $conversation->mailbox;
        $to = $mailbox->removeMailboxEmailsFromList(Conversation::sanitizeEmails($request->input('to_email', [])));
        if (!$to) {
            return ['status' => 'error', 'msg' => __('Please specify a recipient.')];
        }
        $body = (string)$request->input('body', '');
        if (trim(strip_tags($body, '<img>')) === '') {
            return ['status' => 'error', 'msg' => __('Please enter a message')];
        }
        $body = Thread::replaceBase64ImagesWithAttachments($body);

        // Reply to a received reply: the note must be one of this ticket
        $reply_to = null;
        if ($request->input('rf_reply_to')) {
            $reply_to = Thread::find((int)$request->input('rf_reply_to'));
            if (!$reply_to || $reply_to->conversation_id != $conversation->id || !self::isIn($reply_to)) {
                $reply_to = null;
            }
        }

        // The forward form saves a draft as soon as it opens (with the ticket's attachments): it becomes the note
        $thread = null;
        if ($request->input('thread_id')) {
            $thread = Thread::find((int)$request->input('thread_id'));
            if (!$thread || $thread->conversation_id != $conversation->id) {
                return ['status' => 'error', 'msg' => __('Incorrect thread')];
            }
            if ($thread->state != Thread::STATE_DRAFT) {
                return ['status' => 'error', 'msg' => __('Message has been already sent. Please discard this draft.')];
            }
        }
        $attachments = (new \App\Http\Controllers\ConversationsController())->processReplyAttachments($request, $thread->id ?? null);

        $now = date('Y-m-d H:i:s');
        if (!$thread) {
            $thread = new Thread();
            $thread->conversation_id = $conversation->id;
        }
        $thread->type = Thread::TYPE_NOTE;
        $thread->subtype = null; // not FreeScout's forward (which expects a child conversation)
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->status = $conversation->status;
        $thread->user_id = $conversation->user_id;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_user_id = $user->id;
        $thread->edited_by_user_id = null;
        $thread->edited_at = null;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->created_at = $now;
        $thread->body = $body;
        $thread->setTo($to);
        $thread->setCc($request->input('cc'));
        $thread->setBcc($request->input('bcc'));
        $thread->has_attachments = $attachments['has_attachments'];
        $thread->setMeta(self::META_OUT, ['reply_to' => $reply_to ? $reply_to->id : null]);
        // FreeScout always quotes the conversation in a forward ("full" unless "last" was chosen)
        $history = (string)$request->input('conv_history', '');
        $thread->setMeta(Thread::META_CONVERSATION_HISTORY, $history === 'last' ? 'last' : 'full');
        $thread->save();
        if ($attachments['attachments']) {
            Attachment::whereIn('id', $attachments['attachments'])
                ->where(function ($q) use ($thread) {
                    $q->whereNull('thread_id')->orWhere('thread_id', $thread->id);
                })
                ->update(['thread_id' => $thread->id]);
        }

        // a note does not count in threads_count (ThreadObserver), a draft neither: nothing to add
        $conversation->user_updated_at = $now;
        $conversation->save();
        $mailbox->updateFoldersCounters();

        // Quoted below the message: the received reply being answered, else the ticket's messages (no notes)
        if ($reply_to) {
            $quoted = collect([$reply_to]);
        } else {
            $quoted = $conversation->getThreads(null, null, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
                ->filter(function ($t) use ($thread) {
                    return $t->id != $thread->id;
                });
        }
        $threads = collect([$thread])->merge($quoted);
        $recipient = Customer::create($to[0]);
        \App\Jobs\SendReplyToCustomer::dispatch($conversation, $threads, $recipient)->onQueue('emails');

        return ['status' => 'success', 'msg' => '', 'redirect_url' => $conversation->url()];
    }

    /** Hooks on FreeScout's sending and fetching (registered only when the setting is on). */
    public static function registerHooks()
    {
        \Eventy::addFilter('email.reply_to_customer.subject', function ($subject, $conversation, $thread) {
            if (!self::isOut($thread)) {
                return $subject;
            }
            $reply_to = self::replyTo($thread);
            if ($reply_to) {
                $s = (string)($reply_to->getMeta(self::META_IN)['subject'] ?? '');
                $s = $s !== '' ? $s : 'Fwd: '.$conversation->subject;
                return preg_match('/^re\s*:/i', $s) ? $s : 'Re: '.$s;
            }
            return 'Fwd: '.$conversation->subject;
        }, 20, 3);

        // Message-ID of the note, and no In-Reply-To / References to the customer's messages: the recipient's mail
        // client starts a new thread, and their reply points to this note only (FetchEmails tries every reference)
        \Eventy::addAction('email.reply_to_customer.swiftmessage', function ($swiftmessage, $from_alias, $thread, $mailbox) {
            if (!self::isOut($thread)) {
                return;
            }
            $swiftmessage->setId(self::messageId($thread, $mailbox));
            $headers = $swiftmessage->getHeaders();
            foreach (['In-Reply-To', 'References', 'Thread-Index', 'Thread-Topic'] as $name) {
                $headers->removeAll($name);
            }
            $reply_to = self::replyTo($thread);
            if ($reply_to && $reply_to->message_id) {
                $headers->addTextHeader('In-Reply-To', '<'.$reply_to->message_id.'>');
                $headers->addTextHeader('References', '<'.$reply_to->message_id.'>');
            }
        }, 20, 4);

        // Reply to a forward (or to a received reply): saved as a note of the same ticket, instead of FreeScout's
        // customer message, which would make the recipient the ticket's customer
        \Eventy::addFilter('fetch_emails.should_save_thread', function ($save, $data) {
            if ($save === false || empty($data['message_from_customer']) || empty($data['prev_thread'])) {
                return $save;
            }
            $prev = $data['prev_thread'];
            if (!self::isOut($prev) && !self::isIn($prev)) {
                return $save;
            }
            try {
                return self::saveReply($prev, $data) ? false : $save;
            } catch (\Throwable $e) {
                \Helper::logException($e, '[Refresh] Forward reply');
                // FreeScout's own save: the message is not lost
                return $save;
            }
        }, 20, 2);

        // Name of the author of a received reply (a note normally has an agent as author)
        \Eventy::addFilter('thread.action_person', function ($person, $thread) {
            if ($person === '' && self::isIn($thread)) {
                return (string)($thread->getMeta(self::META_IN)['name'] ?? $thread->from);
            }
            return $person;
        }, 20, 2);

        // Marker read by the provider's script (author, action, Reply button) and the recipients of a forward
        \Eventy::addAction('thread.before_body', function ($thread) {
            if (self::isOut($thread)) {
                $to = implode(', ', $thread->getToArray());
                $cc = implode(', ', $thread->getCcArray());
                echo '<div class="rf-fwd" data-kind="out">'
                    .'<div><strong>'.e(__('To')).':</strong> '.e($to).'</div>'
                    .($cc !== '' ? '<div><strong>'.e(__('Cc')).':</strong> '.e($cc).'</div>' : '')
                    .'</div>';
            } elseif (self::isIn($thread)) {
                $meta = $thread->getMeta(self::META_IN);
                $author = $thread->created_by_customer;
                echo '<div class="rf-fwd" data-kind="in" data-name="'.e($meta['name'] ?? '').'" data-email="'.e($thread->from).'"'
                    .' data-url="'.e($author ? preg_replace('#/edit/?$#', '/', $author->url()) : '').'">'
                    .'<div><strong>'.e(__('From')).':</strong> '.e($thread->from).'</div>'
                    .'</div>';
            }
        }, 20, 1);
    }

    protected static function replyTo($thread)
    {
        $id = $thread->getMeta(self::META_OUT)['reply_to'] ?? null;
        return $id ? Thread::find($id) : null;
    }

    /** Saves a fetched reply as a note of the forward's ticket (attachments as FetchEmails saves them). */
    protected static function saveReply($prev, $data)
    {
        $conversation = $prev->conversation;
        if (!$conversation) {
            return false;
        }
        $fetcher = new \App\Console\Commands\FetchEmails();
        $sender = Customer::create($data['from']);
        $now = date('Y-m-d H:i:s');
        $prev_has_attachments = $conversation->has_attachments;

        // A reply reopens the ticket, as a customer's reply does (not a spam)
        if (!$conversation->isActive() && !$conversation->isSpam()) {
            $conversation->status = \Eventy::filter('conversation.status_changing', Conversation::STATUS_ACTIVE, $conversation);
        }

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id = $conversation->user_id;
        $thread->type = Thread::TYPE_NOTE;
        $thread->status = $conversation->status;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->message_id = $data['message_id'];
        $thread->headers = $fetcher->headerToStr($data['message']->getHeader());
        $thread->body = $data['body'];
        $thread->from = $data['from'];
        $thread->setTo($data['to']);
        $thread->setCc($data['cc']);
        $thread->setBcc($data['bcc']);
        $thread->source_via = Thread::PERSON_CUSTOMER;
        $thread->source_type = Thread::SOURCE_TYPE_EMAIL;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_customer_id = $sender ? $sender->id : null;
        $thread->created_at = $now;
        $thread->updated_at = $now;
        $thread->setMeta(self::META_IN, [
            'name'    => $sender ? $sender->getFullName(true) : $data['from'],
            'subject' => (string)$data['subject'],
            'forward' => self::isOut($prev) ? $prev->id : ($prev->getMeta(self::META_IN)['forward'] ?? null),
        ]);
        $thread->save();

        $saved = $fetcher->saveAttachments($data['attachments'], $thread->id);
        if ($saved) {
            $thread->body = $fetcher->replaceCidsWithAttachmentUrls($thread->body, $saved, $conversation, $prev_has_attachments);
            foreach ($saved as $s) {
                if (!$s['attachment']->embedded) {
                    $thread->has_attachments = true;
                    $conversation->has_attachments = true;
                    break;
                }
            }
        }
        $thread->body = Thread::replaceBase64ImagesWithAttachments($thread->body);
        $thread->save();

        $conversation->updateFolder();
        $conversation->save();
        $conversation->mailbox->updateFoldersCounters();

        // Agents are notified as for a customer's reply ("… added a note")
        event(new \App\Events\CustomerReplied($conversation, $thread));

        return true;
    }
}
