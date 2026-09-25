/* Refresh — mobile version, clone of the Android Freshdesk app (recorded screen by screen on a physical phone).
 *
 * Only runs under 768px (phone; the FreeScout app is a WebView of the site): desktop is never touched.
 * Same principle as desktop: native elements and the module's own are MOVED or relayed (handlers kept),
 * no action is reimplemented. Styles: Public/css/mobile.css (all under @media (max-width: 767px)).
 * Provider functions reused (exposed on window): rfSwitchMode, rfMode, rfBindDd.
 *
 * Screens: list (title bar + views drawer, sort in a bottom sheet, full-screen filter, cards, infinite scroll,
 * long-press = selection + bulk action bar, + button, tab bar), ticket (bar with back #number edit more, SLA block +
 * priority / agent / status row, thread, reply bar, full-screen editor, full-screen properties, actions menu),
 * full-screen search, notifications, profile.
 */
(function ($) {
    // Refresh translations: dictionary of the user's language, put in <head> by the module (meta refresh-l10n).
    var rfT = window.rfT = window.rfT || function (s) {
        if (!window.rfL) {
            try { window.rfL = JSON.parse(document.querySelector('meta[name="refresh-l10n"]').getAttribute('content')); } catch (e) { window.rfL = {}; }
        }
        return window.rfL[s] || s;
    };
    // day / month abbreviations in the user's language (keys: English abbreviations)
    // 2:44 PM in English (like the Freshdesk app), 14:44 in the other languages
    function rfTime(h, mm) {
        return /^en/.test(document.documentElement.lang || 'en') ? ((h % 12) || 12) + ':' + mm + ' ' + (h < 12 ? 'AM' : 'PM') : h + ':' + mm;
    }
    function rfDay(i) { return rfT(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][i]); }
    function rfMonth(i) { return rfT(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][i]); }
    'use strict';
    if (!$ || !window.matchMedia || !window.matchMedia('(max-width: 767px)').matches) { return; }

    var ic = function (name, sm) { return '<i class="rf-i rf-i-' + name + (sm ? ' rf-i-sm' : '') + '"></i>'; };
    var txt = function (el) { return $.trim($(el).first().text()).replace(/\s+/g, ' '); };
    // App priorities: round dots ("Priority" sheet, ticket row, properties) in teal / blue / orange / red,
    // "High" label (desktop keeps "High" and the light green square on cards, same as the app)
    var PRIO_DOT = { 1: '#94a3b8', 2: '#38bdf8', 3: '#f59e0b', 4: '#e11d48' }; // same as Views::priorities()
    var prioLabel = function (t) { return t === rfT('High') ? rfT('High') : t; };
    // Avatars: palette matched from the app (mint, blue, peach, pink), color stable per name
    var avColor = function (name) {
        var h = 0;
        for (var i = 0; i < (name || '').length; i++) { h = (h * 31 + name.charCodeAt(i)) % 9973; }
        return ['#bbf7d0', '#c7d2fe', '#fde6b0', '#fecdd6'][h % 4];
    };

    $(function () {
        var body = $('body');
        if (!body.hasClass('rf-shell') || body.hasClass('rf-m')) { return; }
        body.addClass('rf-m');
        // FreeScout app (InAppBrowser): in_app cookie set by main.js; the tab bar there matches the app's own bar height
        if (/(^|;\s*)in_app=1/.test(document.cookie)) { body.addClass('rf-m-app'); }

        // Virtual keyboard: the layout shrinks (bars fixed at the bottom stay visible above the keyboard, like the app)
        var vp = $('meta[name="viewport"]');
        if (vp.length && (vp.attr('content') || '').indexOf('interactive-widget') === -1) {
            vp.attr('content', vp.attr('content') + ', interactive-widget=resizes-content');
        }

        // Keyboard open (body.rf-m-kb): the FreeScout app (Cordova InAppBrowser) does not resize the page and the WebView
        // underestimates the keyboard height (measured on a physical phone: 226px reported, ~340px actual).
        // A bar "above the keyboard" is therefore impossible to place: the send bar goes under the editor's header.
        if (window.visualViewport) {
            var vv = window.visualViewport;
            var kb = function () {
                var h = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
                document.documentElement.style.setProperty('--rf-kb', h + 'px');
                body.toggleClass('rf-m-kb', h > 80);
            };
            vv.addEventListener('resize', kb);
            vv.addEventListener('scroll', kb);
            kb();
        }

        var isList = $('.rf-list-layout').length > 0;
        var isConv = body.hasClass('rf-conv');

        // ------------------------------------------------------------ scrim + bottom sheets (Freshdesk menus)
        var scrim = $('<div class="rf-m-scrim"></div>').appendTo(body);
        var closeAll = function () {
            $('.rf-m-sheet').remove();
            body.removeClass('rf-m-overlay rf-m-drawer rf-m-pop-open');
        };
        scrim.on('click', closeAll);
        // items: [{label, icon, color, active, onClick, sep, cls}] ; opts: {title, head (small caps), pop, foot, keep}
        var sheet = function (items, opts) {
            opts = opts || {};
            closeAll();
            var s = $('<div class="rf-m-sheet" role="dialog"></div>').toggleClass('rf-m-pop', !!opts.pop).toggleClass('rf-m-sheet-actions', !!opts.head);
            // actions menu (small-caps header): no grip handle, like the app
            if (!opts.pop && !opts.head) { s.append('<div class="rf-m-grip"></div>'); }
            if (opts.title) { s.append($('<div class="rf-m-sheet-title"></div>').text(opts.title)); }
            if (opts.head) { s.append($('<div class="rf-m-sheet-head"></div>').text(opts.head)); }
            var list = $('<div class="rf-m-sheet-list"></div>');
            $.each(items, function (i, it) {
                if (it.sep) { list.append('<div class="rf-m-sheet-sep"></div>'); return; }
                var b = $('<button type="button" class="rf-m-opt"></button>').addClass(it.cls || '').toggleClass('active', !!it.active);
                if (it.icon) { b.append(ic(it.icon)); }
                if (it.color) { b.append($('<i class="rf-sq"></i>').css('background', it.color)); }
                b.append($('<span class="rf-m-opt-label"></span>').text(it.label));
                b.append('<span class="rf-m-check">' + ic('m-check') + '</span>');
                b.on('click', function () {
                    if (!opts.keep) { closeAll(); }
                    if (it.onClick) { it.onClick(b); }
                });
                list.append(b);
            });
            s.append(list);
            if (opts.foot) { s.append(opts.foot); }
            body.append(s).addClass('rf-m-overlay').toggleClass('rf-m-pop-open', !!opts.pop);
            setTimeout(function () { s.addClass('open'); }, 10);
            return s;
        };

        // ------------------------------------------------------------ shell data (built by the provider)
        var railHref = function (icon) { return $('.rf-rail .rf-rail-link').has('.rf-i-' + icon).first().attr('href') || ''; };
        var railActive = function (icon) { return $('.rf-rail .rf-rail-link').has('.rf-i-' + icon).first().hasClass('active'); };
        var ticketsUrl = railHref('fd-all-tickets');
        var newUrl = $('.rf-new-dd .dropdown-menu a').first().attr('href') || '';
        var userName = txt('.dropdown-toggle-account .nav-user');

        // ------------------------------------------------------------ top bar
        var top = $('<div class="rf-m-top"></div>');
        var bar = $('<header class="rf-m-bar"></header>');
        top.append(bar);
        body.prepend(top);
        var title = $('<div class="rf-m-title"></div>');

        // ------------------------------------------------------------ tab bar (Tickets, Contacts, Dashboard, Notifications, Profile)
        var tabs = $('<nav class="rf-m-tabs"></nav>');
        var tab = function (cls, href, iconHtml, label, active) {
            var t = $(href ? '<a class="rf-m-tab"></a>' : '<button type="button" class="rf-m-tab"></button>');
            if (href) { t.attr('href', href); }
            return t.addClass(cls).toggleClass('active', !!active).attr('aria-label', label).append(iconHtml);
        };
        tabs.append(tab('rf-m-tab-tickets', ticketsUrl, ic('m-ticket'), rfT('Tickets'), railActive('fd-all-tickets')));
        tabs.append(tab('rf-m-tab-contacts', railHref('contact'), ic('m-contact'), rfT('Contacts'), railActive('contact')));
        tabs.append(tab('rf-m-tab-dash', railHref('nav-dashboard'), ic('m-chart'), rfT('Dashboard'), railActive('nav-dashboard')));
        var notifTab = tab('rf-m-tab-notif', '', ic('m-bell'), 'Notifications', false)
            .toggleClass('has-unread', $('.web-notifications > .dropdown-toggle').hasClass('has-unread'));
        tabs.append(notifTab);
        tabs.append(tab('rf-m-tab-me', '', $('<span class="rf-m-me"></span>').text((userName.charAt(0) || '?').toUpperCase()), rfT('Profile'), false));
        body.append(tabs);

        // Notifications and Account: tab pages (the tab bar stays visible), like the app
        var tabPanel = function (cls, tabCls) {
            var p = $('<div class="rf-m-panel"></div>').addClass(cls).appendTo(body);
            tabs.on('click', '.' + tabCls, function () {
                $('.rf-m-panel').removeClass('open');
                p.addClass('open').scrollTop(0);
                body.addClass('rf-m-panel-open');
                tabs.find('.rf-m-tab').removeClass('active');
                $(this).addClass('active');
            });
            return p;
        };
        // Notifications: native list ("Mark all as read", "Load more": handlers kept)
        var notifPanel = tabPanel('rf-m-notif', 'rf-m-tab-notif');
        var nMenu = $('.web-notifications > .dropdown-menu').first();
        var nCount = txt(nMenu.find('.web-notifications-count'));
        var notifHead = $('<div class="rf-m-bar"><div class="rf-m-title"></div></div>');
        notifHead.find('.rf-m-title').text('Notifications' + (nCount ? ' (' + nCount + ')' : ''));
        notifPanel.append(notifHead);
        if (nMenu.length) {
            var markRead = nMenu.find('.web-notifications-mark-read').first();
            if (markRead.length) {
                notifHead.append(markRead.addClass('rf-m-ib').attr({ 'aria-label': rfT('Mark all as read'), title: rfT('Mark all as read') }).html(ic('m-markread')));
            }
            notifPanel.append(nMenu.find('.web-notifications-list').first());
        }

        // Account: user, useful FreeScout settings, admin, "Change URL" (app), log out
        var me = window.rfMe || { name: userName, email: '', host: window.location.host };
        var acct = tabPanel('rf-m-acct', 'rf-m-tab-me');
        acct.append('<div class="rf-m-bar"><div class="rf-m-title">' + rfT('Account') + '</div></div>');
        var who = $('<div class="rf-m-acct-who"></div>');
        who.append($('<span class="rf-m-acct-av"></span>').text((me.name.charAt(0) || '?').toUpperCase()));
        who.append($('<div></div>').append(
            $('<div class="rf-m-acct-name"></div>').text(me.name),
            $('<div class="rf-m-acct-mail"></div>').text(me.email),
            $('<div class="rf-m-acct-host"></div>').text(me.host)
        ));
        acct.append(who);
        var list = $('<div class="rf-m-acct-list"></div>');
        var row = function (icon, label, onClick, cls) {
            list.append($('<button type="button" class="rf-m-acct-row"></button>').addClass(cls || '')
                .append(icon ? ic(icon) : '', $('<span></span>').text(label), cls ? '' : '<span class="rf-m-acct-chev">' + ic('chevron-right', true) + '</span>')
                .on('click', onClick));
        };
        if (me.profile) { row('m-person', rfT('Your profile'), function () { window.location.href = me.profile; }); }
        if (me.notifications) { row('m-bell', rfT('Ticket notifications'), function () { window.location.href = me.notifications; }); }
        var admin = $('.rf-rail-menu > li > a').filter(function () { return !!$.trim($(this).text()) && $(this).attr('href') !== '#'; });
        if (admin.length) {
            list.append('<div class="rf-m-acct-sec">Administration</div>');
            admin.each(function () {
                var a = $(this);
                row('settings', txt(a), function () { a[0].click(); });
            });
        }
        acct.append(list);
        var foot = $('<div class="rf-m-acct-list rf-m-acct-foot"></div>');
        list = foot;
        var sw = $('a.in-app-switcher').not('.hidden').first();
        if (sw.length) { row('refresh', txt(sw) || rfT('Change helpdesk URL'), function () { sw[0].click(); }); }
        var logout = $('#logout-link');
        if (logout.length) { row('', rfT('Log out'), function () { logout[0].click(); }, 'rf-m-acct-logout'); }
        acct.append(foot);

        // ------------------------------------------------------------ full-screen search (field + provider suggestions)
        // App-style search: "back Search", TICKETS / CUSTOMERS tabs, illustrated empty state.
        // Tickets: provider suggestions; Customers: filtered Contacts page (?q=), read via ajax.
        var sbox = $('.rf-hsearch-box').first();
        if (sbox.length) {
            sbox.appendTo(body);
            sbox.find('.rf-hsearch-close').html(ic('m-back')).prependTo(sbox);
            var sq = sbox.find('input[name=q]').attr('placeholder', rfT('Search'));
            var stabs = $('<div class="rf-m-stabs"><button type="button" class="active" data-t="t">' + rfT('Tickets') + '</button><button type="button" data-t="c">' + rfT('Customers') + '</button></div>');
            var sempty = $('<div class="rf-m-sempty"><span class="rf-m-sempty-ill">' + ic('m-doc') + ic('search') + '</span><p>' + rfT('We hope you find what you are looking for') + '</p></div>');
            var clients = $('<div class="rf-m-sclients"></div>');
            // tabs right under the field, before the provider's suggestions (otherwise they end up under the results list)
            sq.after(stabs, sempty, clients);
            var contactsUrl = railHref('contact');
            var cXhr = null, cT = null;
            var searchClients = function () {
                var q = $.trim(sq.val());
                clients.empty();
                if (q.length < 2 || !contactsUrl) { return; }
                clearTimeout(cT);
                cT = setTimeout(function () {
                    if (cXhr) { cXhr.abort(); }
                    cXhr = $.ajax({ url: contactsUrl, data: { q: q }, dataType: 'html' }).done(function (html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        clients.empty();
                        $(doc).find('.rf-ctable td.rf-ct-name a').slice(0, 20).each(function () {
                            var nm = $.trim($(this).text());
                            clients.append($('<a class="rf-m-sclient"></a>').attr('href', this.getAttribute('href'))
                                .append($('<span class="rf-m-sclient-av"></span>').text((nm.charAt(0) || '?').toUpperCase()).css('background', avColor(nm)), $('<span></span>').text(nm)));
                        });
                        if (!clients.children().length) { clients.append('<div class="rf-m-snone">' + rfT('No customers') + '</div>'); }
                    });
                }, 250);
            };
            var syncS = function () {
                var q = $.trim(sq.val()), tabC = sbox.hasClass('rf-m-s-c');
                sbox.toggleClass('rf-m-s-empty', q.length < 2);
                if (tabC) { searchClients(); }
            };
            stabs.on('click', 'button', function () {
                stabs.find('button').removeClass('active');
                $(this).addClass('active');
                sbox.toggleClass('rf-m-s-c', $(this).attr('data-t') === 'c');
                syncS();
                sq.trigger('focus');
            });
            sq.on('input', syncS);
            sbox.addClass('rf-m-s-empty');
        }
        var openSearch = function () { $('.rf-head-btns .rf-hsearch').first().trigger('click'); };

        if ($('.table-conversations').length) {
            prepRows();
        }
        // Cards: tap = open the ticket (or check in selection mode); long-press = selection (list only).
        // The native long-press (taphold.js, 700ms) cancels on the slightest touchmove: a real finger always moves a
        // little, so it almost never fired. Replaced here: 500ms, 10px tolerance, vibration; the native one is neutralized
        // (taphold_cancelled) so the checkbox doesn't toggle a second time.
        var lastHold = 0;
        var hold = null;
        $(document).on('touchstart', 'tr.conv-row', function (e) {
            if (!isList) { return; }
            var tr = $(this), t = e.originalEvent.touches[0];
            clearTimeout(hold && hold.timer);
            hold = { x: t.clientX, y: t.clientY, timer: setTimeout(function () {
                tr.data('taphold_cancelled', true);
                lastHold = new Date().getTime();
                var cb = tr.find('.conv-checkbox');
                cb.prop('checked', !cb.prop('checked')).trigger('change');
                tr.toggleClass('selected', cb.prop('checked'));
                if (navigator.vibrate) { try { navigator.vibrate(15); } catch (er) {} }
            }, 500) };
        });
        $(document).on('touchmove', 'tr.conv-row', function (e) {
            var t = e.originalEvent.touches[0];
            if (hold && (Math.abs(t.clientX - hold.x) > 10 || Math.abs(t.clientY - hold.y) > 10)) { clearTimeout(hold.timer); hold = null; }
        });
        $(document).on('touchend touchcancel', 'tr.conv-row', function () {
            if (hold) { clearTimeout(hold.timer); hold = null; }
            $(this).data('taphold_cancelled', true);
        });
        $(document).on('click', 'tr.conv-row', function (e) {
            if ($(e.target).closest('.rf-dd, .rf-dd-menu').length) { return; }
            if (new Date().getTime() - lastHold < 800 || $(this).hasClass('rf-m-swiped')) { e.preventDefault(); return; } // swiped card: the tap closes it
            var tr = $(this);
            if (body.hasClass('rf-m-sel')) {
                e.preventDefault();
                var cb = tr.find('.conv-checkbox');
                cb.prop('checked', !cb.prop('checked')).trigger('change');
                tr.toggleClass('selected', cb.prop('checked'));
                return;
            }
            if ($(e.target).closest('a, input, label').length) { return; }
            var href = tr.find('td.conv-subject > a').attr('href');
            if (href) { window.location.href = href; }
        });
        // Card dot menus (priority / agent / status): app-style bottom sheet ("Priority", round dots, checkmark).
        // The provider builds its own menu (hidden via CSS) and keeps control of saving: a choice in the sheet
        // reopens that menu and clicks the matching entry.
        var ddReplay = false;
        // capture phase: the provider stops click propagation on the dot
        document.addEventListener('click', function (ev) {
            var hit = $(ev.target).closest('.rf-dd');
            if (ddReplay || !hit.length) { return; }
            var el = hit;
            setTimeout(function () {
                var menu = $('.rf-dd-menu').last();
                if (!menu.length) { return; }
                var field = el.attr('data-field');
                var items = [];
                menu.find('li > a').each(function () {
                    var a = $(this), v = a.attr('data-v');
                    var sq = a.find('.rf-sq');
                    items.push({ label: field === 'priority' ? prioLabel($.trim(a.text())) : $.trim(a.text()), color: field === 'priority' ? (PRIO_DOT[v] || '') : (sq.length ? sq.css('background-color') : ''), active: a.parent().hasClass('active'), onClick: function () {
                        ddReplay = true;
                        el.trigger('click');
                        ddReplay = false;
                        $('.rf-dd-menu').last().find('a').filter(function () { return String($(this).attr('data-v')) === String(v); }).first().trigger('click');
                    } });
                });
                menu.remove();
                sheet(items, { title: { priority: rfT('Priority'), agent: rfT('Agent'), status: rfT('Status') }[field] || '' });
            }, 0);
        }, true);

        // Selection bar: native "Assign" and "Status" menus -> app-style "Agent" / "Status" sheets
        var bindBulkSheets = function () {
            $('#conversations-bulk-actions [data-toggle="dropdown"]').not('.rf-m-bs').addClass('rf-m-bs').each(function () {
                var tg = $(this), menu = tg.siblings('.dropdown-menu').first();
                var ttl = menu.hasClass('conv-user') ? rfT('Agent') : (menu.hasClass('conv-status') ? rfT('Status') : '');
                if (!ttl) { return; }
                tg.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation(); // no Bootstrap menu
                    var items = [];
                    menu.find('li > a').each(function () {
                        var a = $(this);
                        if (!$.trim(a.text()) || a.closest('li').hasClass('divider') || a.closest('li').hasClass('hidden')) { return; }
                        items.push({ label: $.trim(a.text()), onClick: function () { a.trigger('click'); } });
                    });
                    sheet(items, { title: ttl });
                });
            });
        };
        bindBulkSheets();
        $(document).on('change', '.conv-checkbox', function () { setTimeout(bindBulkSheets, 0); });

        // Right swipe on a card (list): navy blue panel with Delete / Take / Spam, like the app.
        // Delete = native bulk delete on this single ticket (native confirmation); Take = assign the ticket to me;
        // Spam = "Spam" status. All actions go through the native ajax (conversations.ajax).
        var swipe = null, openRow = null;
        var closeSwipe = function () {
            if (!openRow) { return; }
            openRow.css('transform', '').removeClass('rf-m-swiped');
            openRow.data('rfSwipe') && openRow.data('rfSwipe').remove();
            openRow = null;
        };
        var swipePanel = function (tr) {
            var p = $('<div class="rf-m-swipe"></div>').css({ top: tr[0].offsetTop, height: tr.outerHeight() });
            var act = function (icon, label, fn) { p.append($('<button type="button" class="rf-m-swipe-act"></button>').append(ic(icon), $('<span></span>').text(label)).on('click', fn)); };
            var convId = tr.find('.conv-checkbox').val();
            var after = function (r) { if (window.loaderHide) { loaderHide(); } if (r && r.status === 'success') { window.location.reload(); } else if (window.showAjaxError) { showAjaxError(r); } };
            act('fd-trash', rfT('Delete'), function () {
                closeSwipe();
                $('.conv-checkbox:checked').prop('checked', false).trigger('change').closest('tr').removeClass('selected');
                tr.find('.conv-checkbox').prop('checked', true).trigger('change');
                setTimeout(function () { $('#conversations-bulk-actions .conv-delete').first().trigger('click'); }, 50);
            });
            act('m-ticket', rfT('Take'), function () {
                closeSwipe();
                fsAjax({ action: 'conversation_change_user', user_id: getGlobalAttr('auth_user_id'), conversation_id: convId }, laroute.route('conversations.ajax'), after, true);
            });
            act('fd-ban', rfT('Spam'), function () {
                closeSwipe();
                fsAjax({ action: 'conversation_change_status', status: 4, conversation_id: convId }, laroute.route('conversations.ajax'), after, true);
            });
            tr.before(p);
            tr.data('rfSwipe', p);
            return p;
        };
        $(document).on('touchstart', 'body.rf-m-list tr.conv-row', function (e) {
            if (body.hasClass('rf-m-sel')) { return; }
            var t = e.originalEvent.touches[0];
            if (openRow && openRow[0] !== this) { closeSwipe(); }
            swipe = { tr: $(this), x: t.clientX, y: t.clientY, dir: '', base: $(this).hasClass('rf-m-swiped') ? 225 : 0 };
        });
        $(document).on('touchmove', 'body.rf-m-list tr.conv-row', function (e) {
            if (!swipe) { return; }
            var t = e.originalEvent.touches[0], dx = t.clientX - swipe.x, dy = t.clientY - swipe.y;
            if (!swipe.dir && (Math.abs(dx) > 10 || Math.abs(dy) > 10)) { swipe.dir = Math.abs(dx) > Math.abs(dy) ? 'h' : 'v'; }
            if (swipe.dir !== 'h') { return; }
            if (!swipe.tr.data('rfSwipe') || !swipe.tr.data('rfSwipe').parent().length) { swipePanel(swipe.tr); }
            var x = Math.max(0, Math.min(swipe.base + dx, 300));
            swipe.tr.css({ transition: 'none', transform: 'translateX(' + x + 'px)' });
            swipe.x2 = x;
        });
        $(document).on('touchend touchcancel', 'body.rf-m-list tr.conv-row', function () {
            if (!swipe) { return; }
            swipe.tr.css('transition', '');
            if (swipe.dir === 'h') {
                lastHold = new Date().getTime(); // don't open the ticket on release
                if ((swipe.x2 || 0) > 110) {
                    swipe.tr.css('transform', 'translateX(225px)').addClass('rf-m-swiped');
                    openRow = swipe.tr;
                } else {
                    openRow = swipe.tr;
                    closeSwipe();
                }
            }
            swipe = null;
        });
        $(document).on('click', 'tr.conv-row.rf-m-swiped', function (e) { e.preventDefault(); e.stopImmediatePropagation(); closeSwipe(); });

        if (isList) {
            initList();
        } else if (isConv) {
            initConv();
        } else if ($('#form-create').length) {
            initNew();
        } else if ($('.profile-preview').length && /\/customers\/\d+/.test(window.location.pathname)) {
            initCustomer();
        } else {
            initOther();
        }

        // ============================================================ LISTE
        function initList() {
            body.addClass('rf-m-list');
            bar.append($('<button type="button" class="rf-m-ib rf-m-menu" aria-label="' + rfT('Views') + '">' + ic('m-menu') + '</button>').on('click', function () {
                closeAll();
                body.addClass('rf-m-drawer rf-m-overlay');
            }));
            bar.append(title);
            bar.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Search') + '">' + ic('search') + '</button>').on('click', openSearch));

            // Selection bar (long-press on a card), in place of the title bar
            var selbar = $('<header class="rf-m-bar rf-m-selbar"></header>');
            var selTitle = $('<div class="rf-m-title"></div>');
            selbar.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Cancel selection') + '">' + ic('m-back') + '</button>').on('click', function () {
                $('.conv-checkbox:checked').each(function () {
                    $(this).prop('checked', false).trigger('change').closest('tr').removeClass('selected');
                });
            }), selTitle);
            top.append(selbar);

            // Sort / Filter
            var sortbar = $('<div class="rf-m-sortbar"></div>');
            var sortBtn = $('<button type="button" class="rf-m-sortbtn">' + ic('m-sort') + '<span></span></button>');
            var filtBtn = $('<button type="button" class="rf-m-sortbtn rf-m-filtbtn">' + ic('m-filter') + '<span>' + rfT('Filter') + '</span></button>');
            sortbar.append(sortBtn, '<span class="rf-m-sortsep"></span>', filtBtn);
            top.append(sortbar);

            // Views drawer: the module's own panel (view search, sections); a click closes the drawer, the provider loads the view via ajax
            $(document).on('click', '.rf-views a.rf-v:not(.dropdown-toggle)', function () { closeAll(); });
            $('.rf-views-filter').attr('placeholder', rfT('Search views'));

            // + button (new ticket)
            // App-style + button: "New e-mail" / "New ticket" menu (FreeScout has no standalone contact creation)
            if (newUrl) {
                var fab = $('<button type="button" class="rf-m-fab" aria-label="' + rfT('New') + '">' + ic('m-plus') + '</button>');
                var dial = $('<div class="rf-m-dial"></div>');
                var dialItem = function (label, icon, href) {
                    return $('<a class="rf-m-dial-item"></a>').attr('href', href).append($('<span class="rf-m-dial-lbl"></span>').text(label), $('<span class="rf-m-dial-btn"></span>').append(ic(icon)));
                };
                dial.append(dialItem(rfT('New e-mail'), 'm-envelope', newUrl), dialItem(rfT('New ticket'), 'm-ticket', newUrl + (newUrl.indexOf('?') === -1 ? '?' : '&') + 'rf_phone=1'));
                fab.on('click', function () { body.toggleClass('rf-m-dial-open'); });
                dial.on('click', function (e) { if (e.target === this) { body.removeClass('rf-m-dial-open'); } });
                body.append(dial, fab);
            }

            // Sort: bottom "Sort by" sheet (criteria, then Ascending / Descending), Cancel / Apply
            sortBtn.on('click', function () {
                var dd = $('.rf-sort').not('.rf-layout-dd').first();
                var crit = [], ord = [], afterDiv = false;
                dd.find('.dropdown-menu > li').each(function () {
                    var li = $(this);
                    if (li.hasClass('divider')) { afterDiv = true; return; }
                    var a = li.children('a');
                    (afterDiv ? ord : crit).push({ label: txt(a), href: a.attr('href'), active: li.hasClass('active') });
                });
                var pick = { c: null, o: null };
                var items = [];
                $.each(crit, function (i, c) {
                    if (c.active) { pick.c = c; }
                    items.push({ label: c.label, active: c.active, cls: 'rf-m-g-c', onClick: function (b) { pick.c = c; b.addClass('active').siblings('.rf-m-g-c').removeClass('active'); syncApply(); } });
                });
                items.push({ sep: true });
                $.each(ord, function (i, o) {
                    if (o.active) { pick.o = o; }
                    items.push({ label: o.label, active: o.active, cls: 'rf-m-g-o', onClick: function (b) { pick.o = o; b.addClass('active').siblings('.rf-m-g-o').removeClass('active'); syncApply(); } });
                });
                var foot = $('<div class="rf-m-sheet-foot"><button type="button" class="rf-m-btn">' + rfT('Cancel') + '</button><button type="button" class="rf-m-btn rf-m-btn-primary" disabled>' + rfT('Apply') + '</button></div>');
                var init = { c: pick.c, o: pick.o };
                var syncApply = function () { foot.find('.rf-m-btn-primary').prop('disabled', pick.c === init.c && pick.o === init.o); };
                foot.on('click', '.rf-m-btn', function () {
                    var go = $(this).hasClass('rf-m-btn-primary');
                    closeAll();
                    if (!go || !pick.c) { return; }
                    var url = pick.c.href;
                    var m = pick.o ? /[?&]order=([^&]*)/.exec(pick.o.href) : null;
                    if (m) { url = url.replace(/([?&]order=)[^&]*/, '$1' + m[1]); }
                    window.location.href = url;
                });
                sheet(items, { title: rfT('Sort by'), foot: foot, keep: true });
            });

            // Filter: the module's Filters panel in full screen (X Filter … Apply)
            filtBtn.on('click', function () {
                closeAll();
                body.addClass('rf-m-filter');
                prepFilters();
            });
            $(document).on('click', '.rf-m-fclose', function () { body.removeClass('rf-m-filter'); });

            var syncSel = function () {
                var n = $('.conv-checkbox:checked').length;
                body.toggleClass('rf-m-sel', n > 0);
                selTitle.text(n + ' ' + rfT('selected'));
                // native bulk-action bar (moved by the provider): menus open upward
                $('#conversations-bulk-actions .btn-group').addClass('dropup');
            };
            $(document).on('change', '.conv-checkbox', function () { setTimeout(syncSel, 0); });

            // Infinite scroll, like the app; new rows: context menu blocked like conversationsTableInit (main.js),
            // long-press delegated (above), dot menus bound by the provider
            infinite('.table-conversations > tbody', '.table-conversations > tbody > tr.conv-row', function (added) {
                added.on('contextmenu', function (ev) { ev.preventDefault(); ev.stopPropagation(); });
                prepRows();
                if (window.rfBindDd) { window.rfBindDd(); }
            });

            // After an ajax view change (provider), the list and the header bar are replaced: rebuild the cards and labels
            var refresh = function () {
                var vt = txt('.rf-viewbar-title'), n = txt('.rf-viewbar .rf-pill');
                title.text(vt + (n ? ' (' + n + ')' : ''));
                sortBtn.find('span').text(txt($('.rf-sort').not('.rf-layout-dd').first().find('.rf-sort-current')));
                var fc = /\((\d+)\)/.exec(txt('.rf-toggle-filters'));
                filtBtn.find('span').text(rfT('Filter') + (fc ? ' (' + fc[1] + ')' : ''));
                prepRows();
                prepFilters();
                syncSel();
            };
            refresh();
            var rt = null;
            if (window.MutationObserver) {
                new MutationObserver(function () { clearTimeout(rt); rt = setTimeout(refresh, 80); })
                    .observe($('.content-2col')[0] || document.body, { childList: true, subtree: true });
            }
        }

        // App-style short durations: 41m, 19h, 1d, 2w, 3mo, 1y ("Created 41m ago", "Due in 23h")
        // App-style long date from a timestamp: "Mon 18 Sep 2023, 2:44 PM"
        function fdStamp(ts) {
            var d = new Date(ts * 1000), h = d.getHours(), m = d.getMinutes();
            return rfDay(d.getDay()) + ' ' + d.getDate() + ' '
                + rfMonth(d.getMonth()) + ' ' + d.getFullYear() + ', '
                + rfTime(h, (m < 10 ? '0' : '') + m);
        }

        // function declarations (hoisted): prepRows() is called before this point in the script
        function shortAgo(sec) {
            sec = Math.max(0, sec);
            var m = Math.floor(sec / 60), h = Math.floor(m / 60), d = Math.floor(h / 24);
            if (m < 60) { return Math.max(1, m) + 'm'; }
            if (h < 24) { return h + 'h'; }
            if (d < 7) { return d + 'd'; }
            if (d < 30) { return Math.floor(d / 7) + 'w'; }
            if (d < 365) { return Math.floor(d / 30) + 'mo'; }
            return Math.floor(d / 365) + 'y';
        }

        // List cards, matched from the app: name; pills [status][ticket #][due][Created …]; subject; preview;
        // priority / agent / status
        function prepRows() {
            var now = Math.floor(new Date().getTime() / 1000);
            var isCustPage = $('.profile-preview').length > 0 && /\/customers\/\d+/.test(window.location.pathname);
            $('.table-conversations tr.conv-row').not('.rf-m-done').each(function () {
                var tr = $(this).addClass('rf-m-done');
                var a = tr.find('td.conv-subject > a').first();
                var p1 = a.children('p').not('.conv-preview').first();
                var pv = a.children('p.conv-preview').first();
                var textOf = function (p) {
                    return $.trim(p.contents().filter(function () { return this.nodeType === 3; }).text()).replace(/\s+/g, ' ');
                };
                var pills = $('<span class="rf-m-pills"></span>');
                // status: only "New" (mint) and statuses with no due-date equivalent; overdue ones go through the red pill
                p1.find('.rf-badge').not('.rf-badge-first, .rf-badge-late').each(function () {
                    pills.append($('<span class="rf-m-pill"></span>').toggleClass('rf-m-pill-new', $(this).hasClass('rf-badge-new')).text(txt(this)));
                });
                var numEl = p1.find('.rf-num').first();
                var num = $.trim(numEl.text()).replace('#', '');
                if (num) { pills.append($('<span class="rf-m-pill"></span>').append(ic('m-ticket'), $('<span></span>').text(num))); }
                var line = pv.find('.rf-status-line').first();
                var sla = line.find('.rf-meta-sla').first();
                if (sla.length && !tr.hasClass('conv-closed')) {
                    var due = parseInt(sla.attr('data-due'), 10);
                    if (due && due < now) {
                        pills.append($('<span class="rf-m-pill rf-m-pill-late"></span>').append(ic('m-reply-due'), $('<span></span>').text(rfT('Overdue by') + ' ' + shortAgo(now - due))));
                    } else if (due) {
                        pills.append($('<span class="rf-m-pill"></span>').append(ic('m-reply-due'), $('<span></span>').text(rfT('Due in') + ' ' + shortAgo(due - now))));
                    }
                }
                var created = parseInt(numEl.attr('data-created'), 10);
                if (tr.hasClass('conv-closed')) {
                    var fm = line.find('.rf-meta-closed').first();
                    var closedAt = parseInt(fm.attr('data-closed'), 10);
                    pills.append($('<span class="rf-m-pill"></span>').text(closedAt ? rfT('Closed :time ago').replace(':time', shortAgo(now - closedAt)) : txt(fm)));
                } else if (created) {
                    pills.append($('<span class="rf-m-pill"></span>').text(rfT('Created :time ago').replace(':time', shortAgo(now - created))));
                }
                var chan = line.find('.rf-meta-who i').first().clone().attr('class', 'rf-i rf-i-m-mail');
                // pastel avatar + white initial, like the app (desktop: very pale pastel, dark initial)
                var av = tr.find('td.conv-customer .rf-av').first();
                var nm = txt(tr.find('td.conv-customer > a').first().contents().filter(function () { return this.nodeType === 3; }));
                av.addClass('rf-m-av').css('--rf-m-av', avColor(nm));
                if (isCustPage) {
                    // app-style contact-page ticket card: channel; subject + number; date • SLA status; separator; priority • agent • status (no borders)
                    var meta = line.find('.rf-meta').not('.rf-meta-who').map(function () { return txt(this); }).get().join(' • ');
                    var when = created ? fdStamp(created) : '';
                    tr.addClass('rf-m-cticket');
                    a.append($('<span class="rf-m-card rf-m-ccard"></span>').append(
                        $('<span class="rf-m-cchan"></span>').append(ic('m-mail')),
                        $('<span class="rf-m-csubj"></span>').append($('<span></span>').text(textOf(p1)), ' ', $('<span class="rf-m-cnum"></span>').text('#' + num)),
                        $('<span class="rf-m-cmeta"></span>').text((when ? when + ' • ' : '') + meta)
                    ));
                    tr.find('.rf-side-agent > .rf-i').first().attr('class', 'rf-i rf-i-m-person-plus');
                    tr.find('.rf-side-status > .rf-i').first().attr('class', 'rf-i rf-i-m-pulse');
                    var psq = tr.find('.rf-side-prio .rf-sq');
                    psq.css('background', PRIO_DOT[tr.find('.rf-side-prio').attr('data-value')] || psq.css('background-color'));
                    var pl2 = tr.find('.rf-side-prio .rf-dd-label');
                    pl2.text(prioLabel(txt(pl2)));
                    return;
                }
                var card = $('<span class="rf-m-card"></span>').append(
                    pills,
                    $('<span class="rf-m-subj"></span>').text(textOf(p1)),
                    $('<span class="rf-m-prevline"></span>').append(chan.length ? chan : ic('m-mail'), $('<span class="rf-m-prev"></span>').text(textOf(pv)))
                );
                a.append(card);
                // bottom pills: app icons (person +, activity), priority square
                var pl = tr.find('.rf-side-prio .rf-dd-label');
                pl.text(prioLabel(txt(pl)));
                tr.find('.rf-side-agent > .rf-i').first().attr('class', 'rf-i rf-i-m-person-plus');
                tr.find('.rf-side-status > .rf-i').first().attr('class', 'rf-i rf-i-m-pulse');
            });
        }

        // Full-screen filter: app-style header (X Filter … magnifier, reset), "Apply" disabled until something changes
        function prepFilters() {
            var f = $('.rf-filters').first();
            if (!f.length || f.find('.rf-m-fhead').length) { return; }
            var head = $('<div class="rf-m-bar rf-m-fhead"><button type="button" class="rf-m-ib rf-m-fclose" aria-label="' + rfT('Close') + '">' + ic('m-close') + '</button><div class="rf-m-title">' + rfT('Filter') + '</div></div>');
            head.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Search') + '">' + ic('search') + '</button>').on('click', function () {
                body.toggleClass('rf-m-fq');
                if (body.hasClass('rf-m-fq')) { f.find('.rf-f-q').trigger('focus'); }
            }));
            var reset = f.find('.rf-filters-reset').first();
            head.append($('<a class="rf-m-ib rf-m-freset" aria-label="' + rfT('Reset') + '">' + ic('m-reset') + '</a>').attr('href', reset.attr('href') || '#').toggleClass('off', !reset.length));
            f.prepend(head);
            if ($.trim(f.find('.rf-f-q').val() || '')) { body.addClass('rf-m-fq'); }
            var apply = f.find('.rf-filters-foot .rf-btn-primary').prop('disabled', true);
            var initial = f.serialize();
            f.on('change input', ':input', function () { apply.prop('disabled', f.serialize() === initial); });
        }

        // ============================================================ TICKET
        function initConv() {
            body.addClass('rf-m-ticket');
            var back = $('.rf-crumb a').first().attr('href') || ticketsUrl;
            var num = txt($('.rf-crumb > span').last());
            bar.append($('<a class="rf-m-ib rf-m-back" aria-label="' + rfT('Back') + '">' + ic('m-back') + '</a>').attr('href', back));
            bar.append(title.text(num ? '#' + num : ''));
            bar.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Edit ticket') + '">' + ic('m-pencil') + '</button>').on('click', function () { openEdit(); }));
            bar.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Ticket actions') + '">' + ic('m-more') + '</button>').on('click', actionsSheet));

            // Header: status, subject, SLA block with the priority | agent | status row (-> properties)
            var rp = $('.rf-rp').first();
            var subjBlock = $('#conv-subject .conv-subj-block').first();
            var statusName = txt(rp.find('.rf-rp-status-name'));
            if (statusName) { subjBlock.prepend($('<span class="rf-m-status"></span>').addClass('rf-m-status-' + (rp.find('.rf-rp-status-select').val() || '')).text(statusName)); }
            var sla = rp.find('.rf-rp-sla').first();
            var box = $('<div class="rf-m-slabox"></div>');
            if (sla.length) {
                var late = sla.hasClass('rf-rp-sla-late');
                var slaText = txt(sla.children('div').first().children('div').first());
                if (slaText) {
                    box.append($('<div class="rf-m-slarow"></div>').toggleClass('late', late)
                        .append('<span class="rf-m-slaic">' + ic(late ? 'fd-alert' : 'm-reply-due', true) + '</span>', $('<span></span>').text(slaText)));
                }
                var slaDate = txt(sla.find('.rf-rp-sla-date'));
                if (slaDate) {
                    box.append($('<div class="rf-m-slarow"></div>').append('<span class="rf-m-slaic">' + ic('m-timer', true) + '</span>', $('<span></span>').text(rfT('Due:') + ' ' + slaDate)));
                }
            }
            var prio = rp.find('.rf-rp-priority option:selected');
            var agent = txt(rp.find('.rf-rp-user option:selected'));
            var st = txt(rp.find('.rf-rp-status-select option:selected'));
            var prow = $('<button type="button" class="rf-m-proprow"></button>');
            prow.append($('<span class="rf-m-pp"></span>').append($('<i class="rf-m-dot"></i>').css('background', PRIO_DOT[prio.val()] || '#9aa6b8'), $('<span></span>').text(prioLabel(txt(prio)) || '--')));
            prow.append($('<span class="rf-m-pp rf-m-pp-agent"></span>').append(ic('m-person', true), $('<span></span>').text(agent && agent !== '--' ? agent : '--')));
            prow.append($('<span class="rf-m-pp"></span>').append(ic('m-pulse', true), $('<span></span>').text(st)));
            prow.append('<span class="rf-m-pp-chev">' + ic('m-chevron', true) + '</span>');
            prow.on('click', openProps);
            box.append(prow);
            $('#conv-subject').after(box);
            // channel icon: app-style filled envelope
            $('#conv-subject .rf-chan .rf-i').attr('class', 'rf-i rf-i-m-mail');

            // App-style message headers: "Name To recipient", date "Thu 24 Sep, 2:59 PM", CC recipients
            var info = window.rfReplyInfo || {};
            var fdDate = function (t) {
                var m = /([A-Za-z]+)\.? (\d{1,2}), (\d{4}) (\d{1,2}):(\d{2})/.exec(t || '');
                if (!m) { return ''; }
                var mi = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 }[m[1].slice(0, 3).toLowerCase()];
                if (mi === undefined) { return ''; }
                var d = new Date(parseInt(m[3], 10), mi, parseInt(m[2], 10));
                var h = parseInt(m[4], 10);
                return rfDay(d.getDay()) + ' ' + d.getDate() + ' '
                    + rfMonth(mi) + ', '
                    + rfTime(h, m[5]);
            };
            $('#conv-layout-main > .thread').not('.rf-m-th').each(function () {
                var t = $(this).addClass('rf-m-th');
                var person = t.find('.thread-person').first();
                var to = t.hasClass('thread-type-customer') ? info.from : (t.hasClass('thread-type-message') ? info.to : '');
                if (to) { person.after($('<span class="rf-m-to"></span>').text(rfT('To') + ' ' + to)); }
                var dt = t.find('.thread-date').first();
                var when = fdDate(dt.attr('data-original-title') || dt.attr('title'));
                if (when) { t.find('.thread-title').first().append($('<span class="rf-m-thdate"></span>').text(when)); }
                // avatar: app-style palette
                var av = t.find('.thread-photo .rf-av').first();
                av.css({ background: avColor(txt(person)), borderColor: avColor(txt(person)) });
            });

            // Full-screen properties: the module's own form (Priority, Agent, Status, Type, Tags), saved on every choice
            var cust = $('#conv-layout-customer');
            var props = rp.find('.rf-rp-props').first();
            if (props.length) {
                props.prepend($('<div class="rf-m-bar rf-m-phead"><button type="button" class="rf-m-ib" aria-label="' + rfT('Back') + '">' + ic('m-back') + '</button><div class="rf-m-title">' + rfT('Properties') + '</div></div>'));
                props.on('click', '.rf-m-phead .rf-m-ib', function () { body.removeClass('rf-m-props rf-m-edit'); });
                var prioSel = props.find('.rf-rp-priority');
                prioSel.find('option').each(function () { $(this).text(prioLabel($(this).text())); });
                var paintPrio = function () { props.find('.rf-rp-prio-sq').css('background', PRIO_DOT[prioSel.val()] || '#9aa6b8'); };
                paintPrio();
                prioSel.on('change', function () { setTimeout(paintPrio, 0); });
                // app-style labels and empty text: "Tags", "- -"
                props.find('.rf-rp-f-tags > label').text(rfT('Tags'));
                props.find('.rf-rp-tags').on('select2:open', function () {});
                setTimeout(function () { props.find('.rf-rp-tags .select2-search__field').attr('placeholder', '- -'); }, 0);
                props.on('change', '.rf-rp-type, .rf-rp-status-select, .rf-rp-priority, .rf-rp-user', function () {
                    if (body.hasClass('rf-m-edit')) { return; } // "Edit ticket": saved via the bottom button
                    setTimeout(function () {
                        var save = props.find('.rf-rp-save');
                        if (!save.prop('disabled')) { save.trigger('click'); }
                    }, 0);
                });
            }
            function openProps() {
                closeAll();
                body.removeClass('rf-m-edit').addClass('rf-m-props');
                props.find('.rf-m-phead .rf-m-title').text(rfT('Properties'));
                props.find('.rf-rp-status-select').closest('.rf-rp-f').find('> label').removeClass('rf-m-req').text(rfT('Status'));
                props.find('.rf-rp-priority').closest('.rf-rp-f').find('> label').removeClass('rf-m-req');
                cust.scrollTop(0);
            }
            // "Edit ticket" (pencil), like the app: Subject + properties as underlined fields, "Save changes"
            var subjNow = function () { return $.trim($('.conv-subjtext > span:first').text()); };
            var esubj = $('<div class="rf-rp-f rf-m-esubj"><label class="rf-m-req">' + rfT('Subject') + '</label><input type="text" class="rf-m-esubj-in"></div>');
            var esave = $('<button type="button" class="rf-m-esave">' + rfT('Save changes') + '</button>');
            props.find('.rf-rp-body').append(esubj); // at the end of the list: the Properties screen's nth-child rules stay correct (reordered via CSS order)
            props.append(esave);
            function openEdit() {
                closeAll();
                esubj.find('input').val(subjNow());
                body.addClass('rf-m-props rf-m-edit');
                props.find('.rf-m-phead .rf-m-title').text(rfT('Edit ticket'));
                // app-style screen labels (asterisks on required fields)
                props.find('.rf-rp-status-select').closest('.rf-rp-f').find('> label').addClass('rf-m-req').text(rfT('Status'));
                props.find('.rf-rp-priority').closest('.rf-rp-f').find('> label').addClass('rf-m-req');
                cust.scrollTop(0);
            }
            esave.on('click', function () {
                var val = $.trim(esubj.find('input').val());
                var saveProps = function () {
                    var save = props.find('.rf-rp-save');
                    if (!save.prop('disabled')) { save.trigger('click'); } else { window.location.reload(); }
                };
                esave.prop('disabled', true);
                if (val && val !== subjNow()) {
                    fsAjax({ action: 'update_subject', conversation_id: getGlobalAttr('conversation_id'), value: val }, laroute.route('conversations.ajax'), function (r) {
                        if (r && r.status === 'success') { saveProps(); } else { esave.prop('disabled', false); if (window.showAjaxError) { showAjaxError(r); } }
                    }, true);
                } else {
                    saveProps();
                }
            });

            // Ticket actions (more menu): relays to the module's toolbar and the native "More actions" menu
            function actionsSheet() {
                var items = [];
                var closeBtn = $('.rf-tb-close').first();
                if (closeBtn.length) {
                    var reopen = closeBtn.hasClass('rf-reopen');
                    items.push({ label: reopen ? rfT('Reopen ticket') : rfT('Close ticket'), icon: 'fd-check-circle', onClick: function () { closeBtn.trigger('click'); } });
                }
                var due = rp.find('.rf-rp-sla-edit');
                if (due.length) {
                    items.push({ label: rfT('Due'), icon: 'fd-calendar', onClick: function () {
                        openProps();
                        body.addClass('rf-m-props-due');
                        rp.find('.rf-rp-due-form').show();
                    } });
                }
                var menu = $('.rf-tb-more > .dropdown-menu').first();
                var pick = function (re) {
                    return menu.find('li > a').filter(function () { return !$(this).parent().hasClass('hidden-lg') && re.test($(this).text()); }).first();
                };
                var merge = pick(/fusionner/i);
                if (merge.length) { items.push({ label: rfT('Merge'), icon: 'fd-merge', onClick: function () { merge.trigger('click'); } }); }
                var spam = $('#conv-status .conv-status a[data-status="4"]').first();
                if (spam.length) { items.push({ label: rfT('Spam'), icon: 'fd-ban', onClick: function () { spam.trigger('click'); } }); }
                var del = $('.rf-tb-delete').first();
                if (del.length) { items.push({ label: rfT('Delete'), icon: 'fd-trash', onClick: function () { del.trigger('click'); } }); }
                var follow = menu.find('a.conv-follow').first();
                if (follow.length) {
                    var fl = $.trim(follow.clone().find('.hidden').remove().end().text()).replace(/\s+/g, ' ');
                    items.push({ label: fl || rfT('Follow'), icon: 'fd-watching', onClick: function () { follow.trigger('click'); } });
                }
                items.push({ label: rfT('Forward'), icon: 'forward', onClick: function () { openCompose('.conv-forward'); } });
                var print = pick(/imprimer/i);
                if (print.length) { items.push({ label: rfT('Print'), icon: 'print', onClick: function () { print[0].click(); } }); }
                sheet(items, { head: rfT('Ticket actions') });
            }

            // Reply bar (bottom of screen) -> full-screen editor
            var reply = $('.conv-action-wrapper').first();
            var rbar = $('<div class="rf-m-replybar"></div>');
            rbar.append($('<button type="button" class="rf-m-rb-input">' + ic('m-mail') + '<span>' + rfT('Your reply here') + '</span></button>').on('click', function () { openCompose('.conv-reply'); }));
            rbar.append($('<button type="button" class="rf-m-rb-ic" aria-label="' + rfT('Add note') + '">' + ic('m-doc') + '</button>').on('click', function () { openCompose('.conv-add-note'); }));
            rbar.append($('<button type="button" class="rf-m-rb-ic" aria-label="' + rfT('Forward') + '">' + ic('forward') + '</button>').on('click', function () { openCompose('.conv-forward'); }));
            body.append(rbar);

            var modes = { '.conv-reply': rfT('Reply'), '.conv-forward': rfT('Forward'), '.conv-add-note': rfT('Add note') };
            var edbar = $('<div class="rf-m-bar rf-m-edbar"></div>');
            var edMode = $('<button type="button" class="rf-m-edmode"><span></span>' + ic('m-chevron', true) + '</button>');
            edbar.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Back') + '">' + ic('m-back') + '</button>').on('click', function () {
                // like the app: leave the editor, the draft stays (saved by FreeScout) and reopens from the bar
                body.removeClass('rf-m-compose');
            }), edMode);
            reply.prepend(edbar);
            edMode.on('click', function () {
                var cur = window.rfMode ? window.rfMode() : '';
                var items = [];
                $.each(modes, function (sel, label) {
                    items.push({ label: label, active: sel === cur, onClick: function () { if (sel !== cur && window.rfSwitchMode) { window.rfSwitchMode(sel); } } });
                });
                sheet(items, { pop: true });
            });
            function openCompose(sel) {
                closeAll();
                body.addClass('rf-m-compose');
                var cur = window.rfMode ? window.rfMode() : '';
                if (cur !== sel && window.rfSwitchMode) { window.rfSwitchMode(sel); }
                syncCompose();
            }
            // native editor state: closed (sent, discarded) -> full screen left; opened another way (shortcut, a message's tool) -> full screen
            var wasOpen = false;
            // App-style fields: Cc and Bcc lines always shown, "To" recipient as a chip
            var skinHead = function () {
                var head = $('.rf-ed-head').first();
                if (!head.length) { return; }
                head.find('#cc, #bcc').closest('.form-group').removeClass('hidden');
                var to = head.find('.rf-ed-to .rf-ed-val').first();
                if (to.length && !to.find('.rf-m-chip').length && $.trim(to.text())) {
                    to.html($('<span class="rf-m-chip"></span>').text($.trim(to.text())));
                }
            };
            var syncCompose = function () {
                var cur = window.rfMode ? window.rfMode() : '';
                if (cur) { setTimeout(skinHead, 0); }
                edMode.find('span').text(modes[cur] || rfT('Reply'));
                if (!cur) { body.removeClass('rf-m-compose'); } else if (!wasOpen) { body.addClass('rf-m-compose'); }
                wasOpen = !!cur;
                rbar.find('.rf-m-rb-input span').text(cur ? rfT('Resume draft') : rfT('Your reply here'));
            };
            var blk = $('.conv-reply-block').get(0);
            if (blk && window.MutationObserver) {
                new MutationObserver(syncCompose).observe(blk, { attributes: true, attributeFilter: ['class'] });
            }
            syncCompose();

            // "Aa": formatting toolbar collapsed by default on mobile (the desktop choice, remembered, is not reused)
            $(document).on('click', '.rf-ed-tools > .rf-ed-ic:first-child', function () {
                $(this).closest('.note-editor').toggleClass('rf-m-fmt');
            });
        }

        // ============================================================ NEW E-MAIL / NEW TICKET (app-style forms)
        // Stacked fields (uppercase label, underlined field), CC / BCC always shown, DESCRIPTION + signature + paperclip,
        // Status and Agent as fields, full-width button at the bottom. The native form stays the same (send, draft, attachments).
        function initNew() {
            body.addClass('rf-m-new rf-m-notabs');
            bar.append($('<button type="button" class="rf-m-ib rf-m-back" aria-label="' + rfT('Back') + '">' + ic('m-back') + '</button>').on('click', function () {
                if (window.history.length > 1) { window.history.back(); } else { window.location.href = ticketsUrl; }
            }));
            bar.append(title);
            var f = $('#form-create');
            var phone = function () { return $('#phone-conv-switch').hasClass('active'); };
            var send = f.find('.btn-group-send').first();
            var sync = function () {
                body.toggleClass('rf-m-phone', phone()); // no "From" line for a phone ticket
                title.text(phone() ? rfT('New ticket') : rfT('New e-mail'));
                send.find('.btn-send-text').text(rfT('Send'));
                send.find('.btn-create-conv').text(rfT('Create ticket'));
            };
            $(document).on('click', '.conv-switch-button', function () { setTimeout(sync, 0); });
            f.find('#field-to > .control-label, #subject').closest('.form-group').find('> .control-label').addClass('rf-m-req');
            // app-style "FROM" line (read-only: the mailbox has no sending alias)
            if (window.rfMe && window.rfMe.mailbox && !f.find('.conv-from-alias').length) {
                f.find('#field-to').before($('<div class="form-group rf-m-nfrom"><label class="control-label rf-m-req">' + rfT('From') + '</label><div class="col-sm-9"><div class="rf-m-nfromval"></div></div></div>').find('.rf-m-nfromval').text(window.rfMe.mailbox).end());
            }
            f.find('#bcc').closest('.form-group').find('> .control-label').text('Bcc'); // app-style label
            // CC / BCC: lines shown by default (the native link also initializes their selectors)
            setTimeout(function () { if ($('#toggle-cc').length && !f.find('#cc').closest('.form-group').is(':visible')) { $('#toggle-cc').trigger('click'); } }, 0);
            var bodyGroup = f.find('.conv-reply-body').first();
            bodyGroup.before('<div class="rf-m-nlabel rf-m-req">Description</div>');
            // Status / Agent (native footer selectors) presented as fields, paperclip under the description, send button at the bottom.
            // The editor's footer is only built once fully loaded (initReplyForm): re-run on "load", without duplicating.
            var skinNew = function () {
                var bar2 = f.find('.note-statusbar').first();
                send = bar2.find('.btn-group-send').first().length ? bar2.find('.btn-group-send').first() : send;
                var after = bodyGroup;
                bar2.find('select').each(function () {
                    var sel = $(this), name = sel.attr('name');
                    var lbl = name === 'status' ? rfT('Status') : (name === 'user_id' ? rfT('Agent') : '');
                    if (!lbl) { return; }
                    var w = $('<div class="rf-m-nf"></div>').append($('<label></label>').text(lbl), sel);
                    after.after(w);
                    after = w;
                });
                var att = f.find('.note-btn-attachment').first();
                if (att.length && !att.closest('.rf-m-nattach').length) { bodyGroup.find('.note-editor').first().after($('<div class="rf-m-nattach"></div>').append(att.html(ic('fd-attach')))); }
                if (send.length && !send.closest('.rf-m-nsend').length) { f.append($('<div class="rf-m-nsend"></div>').append(send)); }
                sync();
            };
            // retried until the native footer exists ("load" may have already fired by the time this script runs)
            var tries = 0;
            var waitBar = function () {
                skinNew();
                if (!f.find('.rf-m-nf').length && tries++ < 20) { setTimeout(waitBar, 250); }
            };
            waitBar();
        }

        // ============================================================ CUSTOMER PAGE (clone of the app's contact page)
        // Grey header: large avatar, name, round buttons (new ticket, call); Profile / Tickets tabs; pencil = edit.
        function initCustomer() {
            body.addClass('rf-m-cust');
            var pv = $('.profile-preview').first();
            var name = txt(pv.find('.customer-name'));
            var editUrl = $('.nav-tabs-main a').filter(function () { return /\/edit$/.test(this.getAttribute('href') || ''); }).attr('href');
            var isEdit = /\/edit$/.test(window.location.pathname);
            bar.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Back') + '">' + ic('m-back') + '</button>').on('click', function () {
                if (window.history.length > 1) { window.history.back(); } else { window.location.href = railHref('contact'); }
            }));
            bar.append(title.text(isEdit ? rfT('Edit contact') : name));
            if (isEdit) { body.addClass('rf-m-notabs'); return; }
            if (editUrl) { bar.append($('<a class="rf-m-ib" aria-label="' + rfT('Edit') + '">' + ic('m-pencil') + '</a>').attr('href', editUrl)); }
            top.addClass('rf-m-top-grey');

                        var hero = $('<div class="rf-m-hero"></div>');
            hero.append($('<span class="rf-m-hero-av"></span>').text((name.charAt(0) || '?').toUpperCase()).css('background', avColor(name)));
            hero.append($('<div class="rf-m-hero-name"></div>').text(name));
            var btns = $('<div class="rf-m-hero-btns"></div>');
            var email = $.trim(pv.find('.customer-email').first().text());
            var phone = $.trim((pv.find('.customer-phone').first().contents().filter(function () { return this.nodeType === 3; }).first().text() || pv.find('.customer-phone').first().text()).split('(')[0]);
            if (newUrl) { btns.append($('<a class="rf-m-hero-btn" aria-label="' + rfT('New ticket') + '">' + ic('m-ticket-plus') + '</a>').attr('href', newUrl + (email ? '?to=' + encodeURIComponent(email) : ''))); }
            if (phone) { btns.append($('<a class="rf-m-hero-btn" aria-label="' + rfT('Call') + '">' + ic('phone') + '</a>').attr('href', 'tel:' + phone.replace(/[^\d+]/g, ''))); }
            hero.append(btns);

            var ctabs = $('<div class="rf-m-ctabs"><button type="button" data-t="profile">' + rfT('Profile') + '</button><button type="button" data-t="tickets">' + rfT('Tickets') + '</button></div>');
            var prof = $('<div class="rf-m-cprofile"></div>');
            var row = function (label, value) { if (value) { prof.append($('<div class="rf-m-kv"></div>').append($('<label></label>').text(label), $('<div></div>').text(value))); } };
            pv.find('.customer-email').each(function () { row('E-mail', $.trim($(this).text())); });
            pv.find('.customer-phone').each(function () {
                var t = $.trim($(this).text()).replace(/\s+/g, ' ');
                var m = /^(.*?)\s*\((.*)\)$/.exec(t);
                row(rfT('Phone number') + (m ? ' ' + m[2].toLowerCase() : ''), m ? m[1] : t); // app-style label
            });
            pv.find('.customer-extra').children().each(function () { row('', $.trim($(this).text()).replace(/\s+/g, ' ')); });
            if (!prof.children().length) { prof.append('<div class="rf-m-kv"><div>' + rfT('No information') + '</div></div>'); }
            $('.content-2col').first().prepend(hero, ctabs, prof);
            body.addClass('rf-m-notabs'); // stacked screen in the app: no tab bar
            var tbl = $('.content-2col .table-conversations').first();
            if (tbl.length && !$('.content-2col a[rel="next"]').length) { tbl.after($('<div class="rf-m-cend"></div>').text(rfT('That\u2019s all, folks!'))); }
            var setTab = function (t) {
                body.toggleClass('rf-m-ctab-tickets', t === 'tickets');
                ctabs.find('button').removeClass('active').filter('[data-t="' + t + '"]').addClass('active');
                try { window.sessionStorage.setItem('rf_m_ctab', t); } catch (e) {}
            };
            ctabs.on('click', 'button', function () { setTab($(this).attr('data-t')); });
            // like the app: opens on Profile (Tickets only when coming back from a page of the customer's ticket list)
            setTab(/[?&]page=/.test(window.location.search) ? 'tickets' : 'profile');
            // name in the top bar only once the header has scrolled past, like the app
            title.addClass('rf-m-title-scroll');
            $(window).on('scroll', function () { title.toggleClass('on', window.pageYOffset > 150); });
        }

        // ============================================================ OTHER PAGES (dashboard, contacts, settings…)
        function initOther() {
            var tabPage = railActive('nav-dashboard') || $('.rf-ctable').length > 0;
            if (!tabPage) {
                bar.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Back') + '">' + ic('m-back') + '</button>').on('click', function () {
                    if (window.history.length > 1) { window.history.back(); } else { window.location.href = ticketsUrl; }
                }));
            }
            var t = txt('.rf-head-title') || txt($('.rf-crumb > span').last()) || document.title.split(' - ')[0];
            bar.append(title.text(t));
            if ($('.rf-ctable').length) {
                initContacts();
            } else if (tabPage) {
                bar.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Search') + '">' + ic('search') + '</button>').on('click', openSearch));
            }
            // paged sections (settings, mailbox settings…): the (hidden) sidebar menu opens from the title
            var secLinks = $('.sidebar-2col .sidebar-menu a[href]').filter(function () { return $.trim($(this).text()) && this.getAttribute('href') !== '#'; });
            if (!tabPage && secLinks.length > 1) {
                title.addClass('rf-m-title-menu').append(ic('m-chevron', true)).on('click', function () {
                    var items = [];
                    secLinks.each(function () {
                        var a = $(this);
                        items.push({ label: txt(a), active: a.closest('li').hasClass('active'), onClick: function () { window.location.href = a.attr('href'); } });
                    });
                    sheet(items, { title: t });
                });
            }
            body.toggleClass('rf-m-notabs', !tabPage);
            // native pages (profile, notifications, settings…): app-style form styling (mobile.css, .rf-m-page)
            body.toggleClass('rf-m-page', !tabPage);
        }

        // Contacts (clone of the app): avatar + name cards, magnifier = contacts search field, infinite scroll
        function initContacts() {
            body.addClass('rf-m-contacts');
            title.text(rfT('All contacts'));
            var search = $('.rf-contacts-search').first();
            if (search.find('input[name=q]').val()) { body.addClass('rf-m-csearch'); }
            bar.append($('<button type="button" class="rf-m-ib" aria-label="' + rfT('Search') + '">' + ic('search') + '</button>').on('click', function () {
                body.toggleClass('rf-m-csearch');
                if (body.hasClass('rf-m-csearch')) { search.find('input[name=q]').trigger('focus'); }
            }));
            var paint = function () {
                $('.rf-ctable td.rf-ct-name').not('.rf-m-done').each(function () {
                    var td = $(this).addClass('rf-m-done');
                    var nm = txt(td.find('a'));
                    td.find('.rf-av').addClass('rf-m-av').css('--rf-m-av', avColor(nm));
                });
            };
            paint();
            // the whole card opens the contact
            $(document).on('click', '.rf-ctable > tbody > tr', function (e) {
                if ($(e.target).closest('a').length) { return; }
                var a = $(this).find('td.rf-ct-name a').attr('href');
                if (a) { window.location.href = a; }
            });
            infinite('.rf-ctable > tbody', '.rf-ctable > tbody > tr', paint);
        }

        // Generic infinite scroll: the next page (module's .rf-pager) is appended to the end of the list
        function infinite(tbodySel, rowSel, after) {
            var loading = false;
            $(window).on('scroll', function () {
                if (loading || window.innerHeight + window.pageYOffset < document.documentElement.scrollHeight - 700) { return; }
                var next = $('.rf-pager a.rf-pager-btn').last();
                var url = next.attr('href');
                if (next.hasClass('disabled') || !url || url === '#') { return; }
                loading = true;
                body.addClass('rf-m-loading');
                $.ajax({ url: url, dataType: 'html' }).done(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var tbody = $(tbodySel).first();
                    var added = $();
                    $(doc).find(rowSel).each(function () {
                        var row = $(document.adoptNode(this));
                        tbody.append(row);
                        added = added.add(row);
                    });
                    var np = $(doc).find('.rf-pager').first();
                    if (np.length) { $('.rf-pager').first().replaceWith(document.adoptNode(np[0])); }
                    if (after) { after(added); }
                }).always(function () {
                    loading = false;
                    body.removeClass('rf-m-loading');
                });
            });
        }
    });
})(window.jQuery);
