/* Refresh: hover cards of the ticket list, like Freshdesk.
   - pointer resting on a ticket's subject (~0.8 s): its first message (HovercardController@conversation);
   - pointer on the contact's name of the status line (~0.4 s): the contact's details, e-mails and phones copyable
     in one click, link to the contact's tickets (HovercardController@customer).
   The card stays open while the pointer moves onto it (to copy an address) and closes when it leaves both, on
   Escape or on scroll. Computer only: nothing on touch screens or in the mobile version (< 768 px). Answers are
   kept for the page (one request per ticket or contact). ES5, delegated events: the list is reloaded by ajax
   when the view changes. */
(function () {
    if (!window.jQuery || !window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        return;
    }
    var $ = window.jQuery;
    var T = function (s) { return window.rfT ? window.rfT(s) : s; };
    var DELAY = { conversation: 750, customer: 400 };
    // turned off by the agent in the list's Layout menu (cookie, like the layout itself)
    var off = function () { return /(?:^|;\s*)rf_hover=off(?:;|$)/.test(document.cookie); };
    var cache = {};
    var card = null, owner = null, openTimer = null, closeTimer = null, pending = null;

    var url = function (kind, id) {
        return window.rfHover && window.rfHover[kind] && !off() ? window.rfHover[kind] + '/' + encodeURIComponent(id) : null;
    };

    var close = function () {
        clearTimeout(openTimer);
        openTimer = null;
        clearTimeout(closeTimer);
        if (pending) { pending.abort(); pending = null; }
        if (card) { card.remove(); card = null; }
        owner = null;
    };
    var closeSoon = function () {
        clearTimeout(closeTimer);
        closeTimer = setTimeout(close, 200);
    };

    var place = function (el) {
        var r = el.getBoundingClientRect();
        var w = card.outerWidth(), h = card.outerHeight();
        var vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
        var left = Math.max(8, Math.min(r.left, vw - w - 8));
        var top = r.bottom + 6;
        if (top + h > vh - 8 && r.top - h - 6 > 8) {
            top = r.top - h - 6; // no room below: above the element
        }
        card.css({ left: left, top: Math.max(8, top) });
    };

    var copyButton = function (value) {
        return $('<button type="button" class="rf-hc-copy"></button>').attr({ 'data-copy': value, title: T('Copy') })
            .append('<i class="rf-i rf-i-fd-copy rf-i-sm"></i>');
    };

    var renderConversation = function (d) {
        var body = $('<div class="rf-hc-body"></div>');
        if (d.empty) {
            return body.append($('<div class="rf-hc-muted"></div>').text(T('No message')));
        }
        var head = $('<div class="rf-hc-head"></div>');
        head.append($('<strong></strong>').text(d.from || ''));
        if (d.note) { head.append(' ').append($('<span class="rf-hc-tag"></span>').text(T('Note'))); }
        if (d.draft) { head.append(' ').append($('<span class="rf-hc-tag"></span>').text(T('Draft'))); }
        head.append($('<span class="rf-hc-date"></span>').text(d.date || ''));
        body.append(head);
        body.append($('<div class="rf-hc-text"></div>').text(d.text || ''));
        if (d.attachments) {
            body.append($('<div class="rf-hc-muted"></div>').append('<i class="rf-i rf-i-fd-attach rf-i-sm"></i> ')
                .append(document.createTextNode(d.attachments + ' ' + T(d.attachments > 1 ? 'attachments' : 'attachment'))));
        }
        return body;
    };

    var renderCustomer = function (d) {
        var body = $('<div class="rf-hc-body rf-hc-contact"></div>');
        var who = $('<div class="rf-hc-who"></div>');
        who.append($('<span class="rf-av"></span>').text(d.initial || '?')
            .css({ background: d.av[0], borderColor: d.av[1], color: d.av[2] }));
        var id = $('<div class="rf-hc-id"></div>').append($('<strong></strong>').text(d.name || ''));
        var sub = [d.job_title, d.company].filter(function (x) { return x; }).join(' · ');
        if (sub) { id.append($('<div class="rf-hc-muted"></div>').text(sub)); }
        body.append(who.append(id));
        var rows = $('<div class="rf-hc-rows"></div>');
        $.each(d.emails || [], function (i, e) {
            rows.append($('<div class="rf-hc-row"></div>').append('<i class="rf-i rf-i-fd-email rf-i-sm"></i>')
                .append($('<a></a>').attr('href', 'mailto:' + e).text(e)).append(copyButton(e)));
        });
        $.each(d.phones || [], function (i, p) {
            rows.append($('<div class="rf-hc-row"></div>').attr('title', p.label).append('<i class="rf-i rf-i-fd-phone rf-i-sm"></i>')
                .append($('<span></span>').text(p.value)).append(copyButton(p.value)));
        });
        body.append(rows);
        body.append($('<a class="rf-hc-more"></a>').attr('href', d.url)
            .text(T('View all tickets') + ' (' + (d.tickets || 0) + ')'));
        return body;
    };

    var show = function (kind, id, el) {
        close();
        owner = el;
        card = $('<div class="rf-hovercard" role="tooltip"></div>').addClass('rf-hc-' + kind)
            .append($('<div class="rf-hc-body rf-hc-muted"></div>').text(T('Loading…')))
            .appendTo('body');
        place(el);
        var fill = function (d) {
            if (!card || owner !== el) { return; }
            card.empty().append(kind === 'conversation' ? renderConversation(d) : renderCustomer(d));
            place(el);
        };
        var key = kind + ':' + id;
        if (cache[key]) {
            fill(cache[key]);
            return;
        }
        pending = $.ajax({ url: url(kind, id), dataType: 'json', global: false, timeout: 15000 })
            .done(function (d) {
                if (d && d.status === 'success') {
                    cache[key] = d;
                    fill(d);
                } else {
                    close();
                }
            })
            .fail(function (xhr, status) {
                if (status !== 'abort') { close(); }
            })
            .always(function () { pending = null; });
    };

    var arm = function (kind, id, el) {
        clearTimeout(closeTimer);
        if (owner === el) { return; }
        clearTimeout(openTimer);
        openTimer = setTimeout(function () { openTimer = null; show(kind, id, el); }, DELAY[kind]);
    };

    $(function () {
        // Ticket subject: the conversation id is the row's checkbox value. The subject's paragraph spans the whole
        // cell: only the pointer over its text counts (not the blank space up to the side column)
        var overText = function (el, x, y) {
            var r = document.createRange();
            r.selectNodeContents(el);
            var rects = r.getClientRects();
            for (var i = 0; i < rects.length; i++) {
                if (x >= rects[i].left - 4 && x <= rects[i].right + 4 && y >= rects[i].top - 2 && y <= rects[i].bottom + 2) { return true; }
            }
            return false;
        };
        $(document).on('mousemove', '.table-conversations td.conv-subject a > p:not(.conv-preview)', function (e) {
            if (window.innerWidth < 768 || !url('conversation', 0)) { return; }
            if (!overText(this, e.clientX, e.clientY)) {
                if (owner !== this) { clearTimeout(openTimer); openTimer = null; } else { closeSoon(); }
                return;
            }
            if (owner === this) { clearTimeout(closeTimer); return; }
            if (openTimer) { return; }
            var id = $(this).closest('tr').find('.conv-checkbox').val();
            if (id) { arm('conversation', id, this); }
        });
        // Contact name of the status line (data-customer, RefreshServiceProvider::statusLine)
        $(document).on('mouseenter', '.table-conversations .rf-meta-who[data-customer]', function () {
            if (window.innerWidth < 768 || !url('customer', 0)) { return; }
            arm('customer', $(this).attr('data-customer'), this);
        });
        $(document).on('mouseleave', '.table-conversations td.conv-subject a > p:not(.conv-preview), .table-conversations .rf-meta-who[data-customer]', function () {
            clearTimeout(openTimer);
            openTimer = null;
            if (card) { closeSoon(); }
        });
        // The card itself keeps itself open
        $(document).on('mouseenter', '.rf-hovercard', function () { clearTimeout(closeTimer); });
        $(document).on('mouseleave', '.rf-hovercard', closeSoon);
        $(document).on('click', '.rf-hovercard', function (e) { e.stopPropagation(); });
        $(document).on('click', '.rf-hc-copy', function (e) {
            e.preventDefault();
            var b = $(this), v = b.attr('data-copy');
            var done = function () {
                b.addClass('copied').attr('title', T('Copied'));
                setTimeout(function () { b.removeClass('copied').attr('title', T('Copy')); }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(v).then(done);
            } else {
                var ta = $('<textarea></textarea>').val(v).appendTo('body').select();
                document.execCommand('copy');
                ta.remove();
                done();
            }
        });
        // Layout menu: hover previews on / off for this agent (this browser)
        $(document).on('click', '.rf-hover-toggle a', function (e) {
            e.preventDefault();
            var li = $(this).parent(), on = !li.hasClass('active');
            document.cookie = 'rf_hover=' + (on ? '' : 'off') + ';path=/;max-age=31536000';
            li.toggleClass('active', on);
            if (!on) { close(); }
        });
        // A click in the list (opening the ticket) or a scroll closes it
        $(document).on('mousedown', '.table-conversations', close);
        $(document).on('keydown', function (e) { if (e.key === 'Escape') { close(); } });
        // (not the card's own scroll: a long message scrolls inside it)
        window.addEventListener('scroll', function (e) {
            if (card && !(e.target && e.target.nodeType === 1 && card[0].contains(e.target))) { close(); }
        }, true);
    });
})();
