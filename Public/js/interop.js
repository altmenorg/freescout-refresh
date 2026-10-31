/* Refresh — its side of the FreeScout Interop contract (protocol 1.0.0), browser side. Contract: docs/EXTENDING.md.
 *
 * Loaded on every page right after the shared bridge (Public/js/fs-interop.js, window.FreeScoutInterop).
 *  1. While it loads: registers Refresh (id, version, protocol, capabilities of <meta name="refresh-interop">, written
 *     by Services\Interop) and the surfaces it renders on this page.
 *  2. Renders the INTENTS other modules declare in the bridge's registries, in Refresh's own interface:
 *     - navigation entries: desktop rail (.rf-rail-links), phone tab bar (.rf-m-tabs), phone views drawer (.rf-views);
 *     - origin of a ticket (navigation context): first breadcrumb item (.rf-crumb), phone back arrow (.rf-m-back),
 *       "← label (count)" landmark at the top of the views panel;
 *     - ticket toolbar actions: buttons of the desktop toolbar (.rf-tb, after Close by default); the phone "Ticket
 *       actions" sheet and the swipe actions of the phone cards are read from here by Public/js/mobile.js
 *       (window.rfInterop), the only handler of the touch events.
 *  3. Emits refresh:ready, refresh:list-rendered and refresh:conversation-rendered.
 * Nothing is rendered when no other module registers anything: the page stays exactly as without the contract. Every
 * render is idempotent and runs again when a module registers later (the bridge's "<registry>:registered" events).
 */
(function (root, $) {
    'use strict';
    var FI = root.FreeScoutInterop;
    if (!FI || !$) { return; }
    var cfg = null;
    try { cfg = JSON.parse(document.querySelector('meta[name="refresh-interop"]').getAttribute('content')); } catch (e) { cfg = null; }
    if (!cfg || !cfg.module) { return; }
    var ID = cfg.module.id;
    var page = cfg.page || {};

    // ------------------------------------------------------------ 1. registration (script evaluation: other modules decide on DOM ready)
    FI.modules.register({ id: ID, version: cfg.module.version, protocol: cfg.protocol, capabilities: cfg.capabilities || {} });
    $.each(page.surfaces || [], function (i, key) {
        var p = String(key).split('@');
        FI.surfaces.register({ module: ID, surface: p[0], context: p[1] || '*', version: '1.0' });
    });

    // ------------------------------------------------------------ helpers
    var isPhone = function () { return $('body').hasClass('rf-m'); };
    // only the intents of OTHER modules are rendered (Refresh draws its own interface itself)
    var others = function (filter) { return function (it) { return it.owner !== ID && (!filter || filter(it)); }; };
    var hasContext = function (it, ctx, byDefault) { return $.isArray(it.contexts) ? $.inArray(ctx, it.contexts) >= 0 : byDefault; };
    var hrefKey = function (url) {
        var a = document.createElement('a');
        a.href = String(url || '');
        return (a.protocol + '//' + a.host + a.pathname).replace(/\/$/, '') + a.search;
    };
    // Icon of an intent: an SVG address is drawn like the rail's module icons (CSS mask, --rf-i, set through the CSSOM:
    // allowed by FreeScout's CSP); a name is taken from Refresh's icon set when it has one, else a neutral grid.
    var ICONS = { folder: 'folder', 'folder-open': 'folder', trash: 'fd-trash', 'delete': 'fd-trash', inbox: 'inbox', 'inbox-stack': 'inbox',
        star: 'fd-star-filled', calendar: 'calendar', settings: 'settings', search: 'search', contact: 'contact', grid: 'grid', ticket: 'fd-all-tickets' };
    var icon = function (name, sm) {
        var i = $('<i class="rf-i"></i>').toggleClass('rf-i-sm', !!sm);
        name = String(name || '');
        if (/[\/.:]/.test(name)) {
            i[0].style.setProperty('--rf-i', 'url("' + name.replace(/"/g, '%22') + '")');
        } else {
            i.addClass('rf-i-' + (ICONS[name] || 'grid'));
        }
        return i;
    };

    // ------------------------------------------------------------ 2a. navigation
    // Refresh's own entries, by the id other modules use in position.after / before and replaces.id
    var RAIL = { dashboard: 'nav-dashboard', tickets: 'fd-all-tickets', contacts: 'contact', 'saved-replies': 'canned-responses', tags: 'items' };
    var TABS = { tickets: 'rf-m-tab-tickets', contacts: 'rf-m-tab-contacts', dashboard: 'rf-m-tab-dash', notifications: 'rf-m-tab-notif', profile: 'rf-m-tab-me' };
    var placementsOf = function (entry) { return $.isArray(entry.placements) && entry.placements.length ? entry.placements : ['desktop-sidebar']; };
    // Places an element relative to a sibling (Refresh's entry or another module's), else leaves it where it is.
    var place = function (el, entry, find) {
        var pos = entry.position || {};
        var after = pos.after ? find(pos.after) : $();
        var before = !after.length && pos.before ? find(pos.before) : $();
        if (after.length) { el.insertAfter(after.last()); } else if (before.length) { el.insertBefore(before.first()); }
    };
    var hideReplaced = function (entry, placement, own) {
        var r = entry.replaces;
        if (r && r.id && (!$.isArray(r.placements) || $.inArray(placement, r.placements) >= 0)) {
            // hidden, not removed: Refresh's scripts still read its address (mobile.js: tab bar, "Tickets" links)
            own(r.id).not('.rf-interop-replaced').addClass('rf-interop-replaced').hide();
        }
    };

    var renderNavigation = function () {
        var entries = FI.navigation.list(others());
        // previous pass undone (an entry may have been registered again with other data)
        $('.rf-interop-nav').remove();
        $('.rf-interop-replaced').removeClass('rf-interop-replaced').show();
        $('.rf-interop-was-active').removeClass('rf-interop-was-active').addClass('active');
        if (!entries.length) { return; }

        var rail = $('.rf-rail .rf-rail-links').first();
        var tabs = $('.rf-m-tabs').first();
        var drawer = isPhone() ? $('.rf-views').first() : $();
        var railOwn = function (id) {
            if (RAIL[id]) { return rail.children('.rf-rail-link').has('.rf-i-' + RAIL[id]).first(); }
            return rail.children('[data-fsi-nav="' + id + '"]');
        };
        var tabOwn = function (id) { return TABS[id] ? tabs.children('.' + TABS[id]) : tabs.children('[data-fsi-nav="' + id + '"]'); };

        $.each(entries, function (i, entry) {
            var placements = placementsOf(entry);
            var key = hrefKey(entry.href);
            // desktop rail: an entry already in the bar (e.g. added on the server through refresh.rail_items) is reused
            if (rail.length && $.inArray('desktop-sidebar', placements) >= 0) {
                // (never one of Refresh's own entries: the dashboard's address is the site root, a prefix of many)
                var link = rail.children('.rf-rail-link').filter(function () {
                    var self = $(this);
                    return hrefKey(this.getAttribute('href')) === key && !$.grep($.map(RAIL, function (cls) { return cls; }), function (cls) { return self.has('.rf-i-' + cls).length > 0; }).length;
                }).first();
                if (!link.length) {
                    link = $('<a class="rf-rail-link rf-interop-nav" data-toggle="tooltip" data-placement="right"></a>')
                        .attr({ href: entry.href, title: entry.label }).append(icon(entry.icon));
                    rail.append(link);
                    if ($.fn.tooltip) { link.tooltip(); }
                }
                link.attr('data-fsi-nav', entry.id);
                if (entry.active) { link.addClass('active'); }
                place(link, entry, railOwn);
                hideReplaced(entry, 'desktop-sidebar', railOwn);
            }
            // phone tab bar (Public/js/mobile.js)
            if (tabs.length && $.inArray('mobile-bottom-bar', placements) >= 0
                && !tabs.children('a.rf-m-tab').filter(function () { return hrefKey(this.getAttribute('href')) === key; }).length) {
                var tab = $('<a class="rf-m-tab rf-interop-nav"></a>').attr({ href: entry.href, title: entry.label, 'aria-label': entry.label, 'data-fsi-nav': entry.id })
                    .append(icon(entry.icon));
                tabs.append(tab);
                place(tab, entry, tabOwn);
                if (entry.active) {
                    tabs.children('.rf-m-tab.active').not(tab).removeClass('active').addClass('rf-interop-was-active');
                    tab.addClass('active');
                }
                hideReplaced(entry, 'mobile-bottom-bar', tabOwn);
            }
            // phone views drawer (the views panel): after the views search, unless the panel already links there
            if (drawer.length && $.inArray('mobile-drawer', placements) >= 0
                && !drawer.find('a[href]').filter(function () { return hrefKey(this.getAttribute('href')) === key; }).length) {
                var list = drawer.children('.rf-views-interop');
                if (!list.length) {
                    list = $('<div class="rf-views-list rf-views-interop rf-interop-nav"></div>');
                    var search = drawer.children('.rf-views-search').first();
                    if (search.length) { list.insertAfter(search); } else { drawer.prepend(list); }
                }
                list.append($('<a class="rf-v"></a>').toggleClass('active', !!entry.active).attr({ href: entry.href, title: entry.label, 'data-fsi-nav': entry.id })
                    .append(icon(entry.icon), $('<span class="rf-v-label"></span>').text(entry.label)));
            }
        });
    };

    // ------------------------------------------------------------ 2b. navigation context: where the ticket was opened from
    var currentOrigin = function () {
        if (!page.conversationId) { return null; }
        var o = FI.context.getConversationOrigin(page.conversationId);
        return o && o.origin && o.origin.href && o.sourceModule !== ID ? o : null;
    };
    var renderOrigin = function () {
        var o = currentOrigin();
        if (!o) { return; }
        var label = String(o.origin.label || '');
        var crumb = $('.rf-crumb').first();
        var first = crumb.children('a').first();
        if (crumb.length && first.length) {
            first.attr('href', o.origin.href);
            if (label) { first.text(label); }
        } else if (crumb.length && label) {
            crumb.prepend($('<a></a>').attr('href', o.origin.href).text(label), '<i class="rf-i rf-i-chevron-right rf-i-sm"></i>');
        }
        $('.rf-m-back').attr('href', o.origin.href);
        // landmark at the top of the views panel (desktop panel and phone drawer)
        var views = $('.rf-views').first();
        if (views.length && label && !views.children('.rf-views-origin').length) {
            var text = label + (o.origin.count !== null && o.origin.count !== undefined && o.origin.count !== '' ? ' (' + o.origin.count + ')' : '');
            var mark = $('<a class="rf-v rf-views-origin"></a>').attr({ href: o.origin.href, title: label })
                .append('<i class="rf-i rf-i-arrow-left"></i>', $('<span class="rf-v-label"></span>').text(text));
            var search = views.children('.rf-views-search').first();
            if (search.length) { mark.insertBefore(search); } else { views.prepend(mark); }
        }
    };

    // ------------------------------------------------------------ 2c. ticket toolbar
    var toolbarContext = function (mobile) {
        return { conversationId: page.conversationId, mailboxId: page.mailboxId, userId: page.userId, mobile: !!mobile };
    };
    var toolbarActions = function () {
        if (!page.conversationId) { return []; }
        var ctx = page.deleted ? 'conversation-deleted' : 'conversation';
        // without "contexts", an action is for a ticket that is not in the trash
        return FI.toolbar.list(others(function (a) { return hasContext(a, ctx, ctx === 'conversation'); }));
    };
    // Refresh's toolbar buttons, by the id used in position.after / before
    var TB = { star: '.rf-tb-star', reply: '.rf-tb-act[data-native=".conv-reply"]', note: '.rf-tb-act[data-native=".conv-add-note"]',
        forward: '.rf-tb-act[data-native=".conv-forward"]', close: '.rf-tb-close', more: '.rf-tb-more' };
    var renderToolbar = function () {
        var tb = $('.rf-tb').first();
        tb.children('.rf-tb-interop').remove();
        var actions = toolbarActions();
        if (!tb.length || !actions.length) { return; }
        var lastAfter = {};
        $.each(actions, function (i, a) {
            var btn = $('<button type="button" class="rf-btn rf-tb-interop"></button>').attr({ title: a.label, 'data-fsi-action': a.id })
                .append(a.icon ? icon(a.icon, true) : '', document.createTextNode(a.shortLabel || a.label));
            var pos = a.position || {};
            var anchorId = pos.before ? null : (pos.after || 'close');
            var anchor = anchorId ? (lastAfter[anchorId] || tb.children(TB[anchorId] || '[data-fsi-action="' + anchorId + '"]').last()) : $();
            var before = pos.before ? tb.children(TB[pos.before] || '[data-fsi-action="' + pos.before + '"]').first() : $();
            if (anchor && anchor.length) {
                btn.insertAfter(anchor);
                lastAfter[anchorId] = btn;
            } else if (before.length) {
                btn.insertBefore(before);
            } else {
                // after the last button, before ⋮ and the pager
                var stop = tb.children('.rf-tb-more, .rf-tb-right').first();
                if (stop.length) { btn.insertBefore(stop); } else { tb.append(btn); }
            }
        });
    };
    $(document).on('click', '.rf-tb-interop', function () {
        FI.toolbar.run(this.getAttribute('data-fsi-action'), toolbarContext(false));
    });

    // ------------------------------------------------------------ events
    var emitList = function () {
        if ($('.rf-list-layout').length) {
            FI.events.emit('refresh:list-rendered', { schema: '1.0', source_module: ID, mailbox_id: page.mailboxId, view: $('body').attr('data-rf-view') || null });
        }
    };

    // ------------------------------------------------------------ for Public/js/mobile.js and the provider's script
    root.rfInterop = {
        /**
         * Entries of the phone "Ticket actions" sheet: {key, label, iconEl, after, onClick}; "after" is the key of one
         * of Refresh's entries (close, due, merge, spam, delete, follow, forward, print), "close" by default.
         */
        sheetItems: function () {
            return $.map(toolbarActions(), function (a) {
                var pos = a.position || {};
                return { key: a.id, label: a.label, iconEl: icon(a.icon), after: pos.after || (pos.before ? null : 'close'), before: pos.before || null,
                    onClick: function () { FI.toolbar.run(a.id, toolbarContext(true)); } };
            });
        },
        /** Swipe actions of a direction ("left" or "right") for the ticket list cards: {label, iconEl, run(conversationId)}. */
        swipeActions: function (direction) {
            return $.map(FI.swipe.list(others(function (a) { return a.direction === direction && hasContext(a, 'ticket-list', true); })), function (a) {
                return { label: a.label, iconEl: icon(a.icon), run: function (conversationId) {
                    FI.swipe.run(a.id, { conversationId: parseInt(conversationId, 10) || null, mailboxId: page.mailboxId });
                } };
            });
        },
        listRendered: emitList
    };

    // ------------------------------------------------------------ rendering, on DOM ready and when a module registers later
    var renderAll = function () {
        renderNavigation();
        renderOrigin();
        renderToolbar();
    };
    var timer = null;
    var schedule = function () {
        clearTimeout(timer);
        timer = setTimeout(renderAll, 0);
    };
    $(function () {
        // after the other "ready" handlers (Public/js/mobile.js builds the phone bars on this same event)
        setTimeout(function () {
            renderAll();
            FI.events.on('navigation:registered', schedule);
            FI.events.on('toolbar:registered', schedule);
            FI.events.on('context:changed', schedule);
            FI.events.emit('refresh:ready', { schema: '1.0', source_module: ID, version: cfg.module.version, context: page.context || 'other' });
            emitList();
            if (page.conversationId && $('body').hasClass('rf-conv')) {
                FI.events.emit('refresh:conversation-rendered', { schema: '1.0', source_module: ID, conversation_id: page.conversationId, mailbox_id: page.mailboxId, mobile: isPhone() });
            }
        }, 0);
    });
})(window, window.jQuery);
