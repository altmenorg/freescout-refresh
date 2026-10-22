/**
 * Forward within the ticket, like Freshdesk (Services\Forward, setting "Forward within the ticket").
 * FreeScout's forward form is kept as it is (recipients, Cc / Bcc, attachments, draft); only its sending is sent
 * to the module (window.rfForwardUrl) instead of the native send_reply, which would create a "Fwd:" ticket.
 * window.rfForwardReply ({thread, email}, set by the Reply button of a received reply): the forward answers that
 * reply, as long as its author is still among the recipients.
 */
(function () {
    'use strict';

    $(function () {
        var fsAjax = window.fsAjax;
        if (typeof fsAjax !== 'function' || !window.rfForwardUrl) {
            return;
        }
        window.fsAjax = function (data, url) {
            var args = Array.prototype.slice.call(arguments);
            var subtype = (window.Vars && Vars.subtype_forward) ? Vars.subtype_forward : 1;
            var conv = typeof getGlobalAttr === 'function' ? getGlobalAttr('conversation_id') : '';
            if (typeof data === 'string' && /(^|&)action=send_reply(&|$)/.test(data) && !/(^|&)is_note=1(&|$)/.test(data)
                && new RegExp('(^|&)subtype=' + subtype + '(&|$)').test(data) && conv) {
                var reply = window.rfForwardReply;
                if (reply && reply.email && data.indexOf('to_email%5B%5D=' + encodeURIComponent(reply.email)) !== -1) {
                    args[0] = data + '&rf_reply_to=' + encodeURIComponent(reply.thread);
                }
                args[1] = window.rfForwardUrl.replace('{id}', encodeURIComponent(conv));
            }
            return fsAjax.apply(this, args);
        };
    });
})();
