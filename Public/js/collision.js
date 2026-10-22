/* Refresh: agent collision detection on a ticket, like Freshdesk.
   - "Name is replying" / "Name is viewing this ticket": bar above the conversation, from FreeScout's own realtime
     viewers (the small avatars of #conv-viewers, which the module hides);
   - a message or a note added by someone else while the agent is on the ticket: an alert with "Update", which brings
     it in without reloading the page or touching the editor (rfTicketSync in the provider);
   - sending a reply while such a message is not shown yet: asks first ("Show the new messages" / "Send anyway").
   FreeScout's realtime events (RealtimeConvView, RealtimeConvNewThread) say when something changed;
   CollisionController@activity says what, so a missed event only delays the alert (polled every 30 s) and the
   check before sending is always made. ES5. */
(function () {
    if (!window.jQuery) {
        return;
    }
    var $ = window.jQuery;
    var T = function (s) { return window.rfT ? window.rfT(s) : s; };
    var POLL = 30000;
    var MESSAGE_TYPES = '.thread-type-customer, .thread-type-message, .thread-type-note';

    $(function () {
        var main = $('#conv-layout-main');
        var conversationId = window.getGlobalAttr ? getGlobalAttr('conversation_id') : null;
        // ticket pages only (a draft opened from the Drafts folder shows the create form)
        if (!main.length || !conversationId || $('#form-create').length || !window.rfCollisionUrl) {
            return;
        }
        var url = window.rfCollisionUrl.replace('{id}', encodeURIComponent(conversationId));

        // Newest message shown. Only full syncs move it (page load, reply sent, "Update"): the page then holds every
        // message up to it, so a message of someone else written at the same time as one's own is never skipped.
        var newestShown = function () {
            var max = 0;
            main.children('.thread').filter(MESSAGE_TYPES).each(function () {
                var m = /^thread-(\d+)$/.exec(this.id || '');
                if (m && +m[1] > max) { max = +m[1]; }
            });
            return max;
        };
        var known = newestShown();
        var pending = [];      // messages of others not shown yet: [{id, type, name}]
        var dismissed = 0;     // newest pending id when the alert was closed with ×
        var inflight = null, timer = null;

        var presence = $('<div class="rf-presence" style="display:none"></div>');
        var alertBar = $('<div class="rf-collide" role="status" style="display:none"></div>');
        main.before(presence, alertBar);

        var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
        var avatar = function (v) {
            if (v.photo) {
                return '<img class="rf-presence-av" src="' + esc(v.photo) + '" alt="">';
            }
            return '<span class="rf-presence-av rf-av">' + esc(window.rfInitials ? rfInitials(v.name) : (v.name || '?').charAt(0)) + '</span>';
        };

        var renderPresence = function (viewers) {
            if (!viewers.length) {
                presence.hide().empty();
                return;
            }
            var replying = $.grep(viewers, function (v) { return v.replying; }).length > 0;
            var html = '<span class="rf-presence-avs">';
            $.each(viewers.slice(0, 3), function (i, v) { html += avatar(v); });
            html += '</span><span class="rf-presence-text">';
            html += $.map(viewers, function (v) {
                return esc(T(v.replying ? ':name is replying' : ':name is viewing this ticket').replace(':name', v.name));
            }).join('<span class="rf-presence-sep"> · </span>');
            html += '</span>';
            presence.toggleClass('rf-presence-replying', replying).html(html).show();
        };

        // "Marie Dupont replied to the customer" / "2 new messages since you opened this ticket"
        var summary = function () {
            if (pending.length > 1) {
                return T(':count new messages since you opened this ticket').replace(':count', pending.length);
            }
            var t = pending[0];
            var text = t.type === 'customer' ? ':name replied' : (t.type === 'note' ? ':name added a note' : ':name replied to the customer');
            return T(text).replace(':name', t.name || T('Someone'));
        };

        var renderAlert = function () {
            var newest = pending.length ? pending[pending.length - 1].id : 0;
            if (!pending.length || newest <= dismissed) {
                alertBar.hide().empty();
                return;
            }
            alertBar.html('<i class="rf-i rf-i-fd-refresh rf-i-sm"></i><span class="rf-collide-text">' + esc(summary()) + '</span>'
                + '<button type="button" class="rf-collide-update">' + esc(T('Update ticket')) + '</button>'
                + '<button type="button" class="rf-toast-x rf-collide-x" title="' + esc(T('Close')) + '"><i class="rf-i rf-i-fd-close"></i></button>').show();
        };

        var check = function () {
            if (inflight) {
                return inflight;
            }
            inflight = $.ajax({ url: url, data: { after: known }, dataType: 'json', cache: false, timeout: 5000 })
                .done(function (r) {
                    if (!r || r.status !== 'success') {
                        return;
                    }
                    renderPresence(r.viewers || []);
                    // shown = in the conversation itself (FreeScout's hidden "View new message" block holds a copy too)
                    pending = $.grep(r.threads || [], function (t) { return !main.children('#thread-' + t.id).length; });
                    renderAlert();
                })
                .always(function () { inflight = null; });
            return inflight;
        };
        var checkSoon = function (delay) {
            clearTimeout(timer);
            timer = setTimeout(check, delay);
        };

        // after a full sync, everything up to the newest message is on the page
        var synced = function () {
            known = Math.max(known, newestShown());
            pending = $.grep(pending, function (t) { return t.id > known; });
            renderAlert();
        };
        $(document).on('rf:ticket-updated', function () { synced(); checkSoon(300); });

        var update = function () {
            var button = alertBar.find('.rf-collide-update').prop('disabled', true);
            var d = $.Deferred();
            var fail = function () {
                button.prop('disabled', false);
                // a reload would close the editor: only when nothing is being written
                if (!window.fs_reply_changed) {
                    window.location.reload();
                } else if (window.showFloatingAlert) {
                    showFloatingAlert('error', T('The ticket could not be updated. Reload the page to see the new messages.'));
                }
                d.reject();
            };
            if (typeof window.rfTicketSync !== 'function') {
                fail();
                return d.promise();
            }
            // FreeScout's own preview of the new message ("View new message"), hidden by refresh.css: replaced by the sync
            main.children('.thread-type-new').remove();
            window.rfTicketSync().done(function () { synced(); d.resolve(); }).fail(fail);
            return d.promise();
        };
        alertBar.on('click', '.rf-collide-update', function () { update(); });
        alertBar.on('click', '.rf-collide-x', function () {
            dismissed = pending.length ? pending[pending.length - 1].id : 0;
            renderAlert();
        });

        // FreeScout's realtime viewers changed (avatar added, faded out, now replying)
        var viewersBox = document.getElementById('conv-viewers');
        if (window.MutationObserver) {
            if (viewersBox) {
                new MutationObserver(function () { checkSoon(400); })
                    .observe(viewersBox, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
            }
            // FreeScout's realtime new message: it prepends a "View new message" block to the conversation
            new MutationObserver(function (records) {
                for (var i = 0; i < records.length; i++) {
                    for (var j = 0; j < records[i].addedNodes.length; j++) {
                        if (/\bthread-type-new\b/.test(records[i].addedNodes[j].className || '')) {
                            checkSoon(150);
                            return;
                        }
                    }
                }
            }).observe(main[0], { childList: true });
        }
        setInterval(function () {
            if (!document.hidden) { check(); }
        }, POLL);
        $(document).on('visibilitychange', function () {
            if (!document.hidden) { checkSoon(100); }
        });
        check();

        // ------------------------------------------------------------ before sending a reply
        var ask = function (onSend, onCancel) {
            var sent = false;
            var dialog = $('<div class="modal fade rf-collide-ask" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">'
                + '<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="' + esc(T('Close')) + '"><span aria-hidden="true">&times;</span></button>'
                + '<h4 class="modal-title">' + esc(T('New message on this ticket')) + '</h4></div>'
                + '<div class="modal-body"><p>' + esc(summary()) + '. ' + esc(T('Your reply may no longer be up to date.')) + '</p></div>'
                + '<div class="modal-footer">'
                + '<button type="button" class="btn btn-default rf-collide-send">' + esc(T('Send anyway')) + '</button>'
                + '<button type="button" class="btn btn-primary rf-collide-show" data-dismiss="modal">' + esc(T('Show the new messages')) + '</button>'
                + '</div></div></div></div>');
            dialog.on('click', '.rf-collide-send', function () {
                sent = true;
                dialog.modal('hide');
            });
            dialog.on('hidden.bs.modal', function () {
                dialog.remove();
                if (sent) { onSend(); } else { onCancel(); }
            });
            $('body').append(dialog);
            dialog.modal('show');
        };

        var fsAjax = window.fsAjax;
        if (typeof fsAjax !== 'function') {
            return;
        }
        window.fsAjax = function (data) {
            var self = this, args = arguments;
            if (typeof data !== 'string' || !/(^|&)action=send_reply(&|$)/.test(data) || /(^|&)is_note=1(&|$)/.test(data)
                || (window.isChatMode && isChatMode())) {
                return fsAjax.apply(self, args);
            }
            // a failed or slow check never blocks the reply: it is sent with what is known
            check().always(function () {
                if (!pending.length) {
                    fsAjax.apply(self, args);
                    return;
                }
                ask(function () {
                    fsAjax.apply(self, args);
                }, function () {
                    window.fs_processing_send_reply = false;
                    $('.btn-reply-submit').button('reset');
                    update();
                });
            });
        };
    });
})();
