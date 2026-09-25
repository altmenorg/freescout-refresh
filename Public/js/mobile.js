/* ModernUi — mobile version, clone of the Android Freshdesk app (recorded screen by screen on a physical phone).
 *
 * Only runs under 768px (phone; the FreeScout app is a WebView of the site): desktop is never touched.
 * Same principle as desktop: native elements and the module's own are MOVED or relayed (handlers kept),
 * no action is reimplemented. Styles: Public/css/mobile.css (all under @media (max-width: 767px)).
 * Provider functions reused (exposed on window): muSwitchMode, muMode, muBindDd.
 *
 * Screens: list (title bar + views drawer, sort in a bottom sheet, full-screen filter, cards, infinite scroll,
 * long-press = selection + bulk action bar, + button, tab bar), ticket (bar with back #number edit more, SLA block +
 * priority / agent / status row, thread, reply bar, full-screen editor, full-screen properties, actions menu),
 * full-screen search, notifications, profile.
 */
(function ($) {
    // Modern UI translations: dictionary of the user's language, put in <head> by the module (meta modernui-l10n).
    var muT = window.muT = window.muT || function (s) {
        if (!window.muL) {
            try { window.muL = JSON.parse(document.querySelector('meta[name="modernui-l10n"]').getAttribute('content')); } catch (e) { window.muL = {}; }
        }
        return window.muL[s] || s;
    };
    // day / month abbreviations in the user's language (keys: English abbreviations)
    // 2:44 PM in English (like the Freshdesk app), 14:44 in the other languages
    function muTime(h, mm) {
        return /^en/.test(document.documentElement.lang || 'en') ? ((h % 12) || 12) + ':' + mm + ' ' + (h < 12 ? 'AM' : 'PM') : h + ':' + mm;
    }
    function muDay(i) { return muT(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][i]); }
    function muMonth(i) { return muT(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][i]); }
    'use strict';
    if (!$ || !window.matchMedia || !window.matchMedia('(max-width: 767px)').matches) { return; }

    var ic = function (name, sm) { return '<i class="mu-i mu-i-' + name + (sm ? ' mu-i-sm' : '') + '"></i>'; };
    var txt = function (el) { return $.trim($(el).first().text()).replace(/\s+/g, ' '); };
    // App priorities: round dots ("Priority" sheet, ticket row, properties) in teal / blue / orange / red,
    // "High" label (desktop keeps "High" and the light green square on cards, same as the app)
    var PRIO_DOT = { 1: '#94a3b8', 2: '#38bdf8', 3: '#f59e0b', 4: '#e11d48' }; // same as Views::priorities()
    var prioLabel = function (t) { return t === muT('High') ? muT('High') : t; };
    // Avatars: palette matched from the app (mint, blue, peach, pink), color stable per name
    var avColor = function (name) {
        var h = 0;
        for (var i = 0; i < (name || '').length; i++) { h = (h * 31 + name.charCodeAt(i)) % 9973; }
        return ['#bbf7d0', '#c7d2fe', '#fde6b0', '#fecdd6'][h % 4];
    };

    $(function () {
        var body = $('body');
        if (!body.hasClass('mu-shell') || body.hasClass('mu-m')) { return; }
        body.addClass('mu-m');
        // FreeScout app (InAppBrowser): in_app cookie set by main.js; the tab bar there matches the app's own bar height
        if (/(^|;\s*)in_app=1/.test(document.cookie)) { body.addClass('mu-m-app'); }

        // Virtual keyboard: the layout shrinks (bars fixed at the bottom stay visible above the keyboard, like the app)
        var vp = $('meta[name="viewport"]');
        if (vp.length && (vp.attr('content') || '').indexOf('interactive-widget') === -1) {
            vp.attr('content', vp.attr('content') + ', interactive-widget=resizes-content');
        }

        // Keyboard open (body.mu-m-kb): the FreeScout app (Cordova InAppBrowser) does not resize the page and the WebView
        // underestimates the keyboard height (measured on a physical phone: 226px reported, ~340px actual).
        // A bar "above the keyboard" is therefore impossible to place: the send bar goes under the editor's header.
        if (window.visualViewport) {
            var vv = window.visualViewport;
            var kb = function () {
                var h = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
                document.documentElement.style.setProperty('--mu-kb', h + 'px');
                body.toggleClass('mu-m-kb', h > 80);
            };
            vv.addEventListener('resize', kb);
            vv.addEventListener('scroll', kb);
            kb();
        }

        var isList = $('.mu-list-layout').length > 0;
        var isConv = body.hasClass('mu-conv');

        // ------------------------------------------------------------ scrim + bottom sheets (Freshdesk menus)
        var scrim = $('<div class="mu-m-scrim"></div>').appendTo(body);
        var closeAll = function () {
            $('.mu-m-sheet').remove();
            body.removeClass('mu-m-overlay mu-m-drawer mu-m-pop-open');
        };
        scrim.on('click', closeAll);
        // items: [{label, icon, color, active, onClick, sep, cls}] ; opts: {title, head (small caps), pop, foot, keep}
        var sheet = function (items, opts) {
            opts = opts || {};
            closeAll();
            var s = $('<div class="mu-m-sheet" role="dialog"></div>').toggleClass('mu-m-pop', !!opts.pop).toggleClass('mu-m-sheet-actions', !!opts.head);
            // actions menu (small-caps header): no grip handle, like the app
            if (!opts.pop && !opts.head) { s.append('<div class="mu-m-grip"></div>'); }
            if (opts.title) { s.append($('<div class="mu-m-sheet-title"></div>').text(opts.title)); }
            if (opts.head) { s.append($('<div class="mu-m-sheet-head"></div>').text(opts.head)); }
            var list = $('<div class="mu-m-sheet-list"></div>');
            $.each(items, function (i, it) {
                if (it.sep) { list.append('<div class="mu-m-sheet-sep"></div>'); return; }
                var b = $('<button type="button" class="mu-m-opt"></button>').addClass(it.cls || '').toggleClass('active', !!it.active);
                if (it.icon) { b.append(ic(it.icon)); }
                if (it.color) { b.append($('<i class="mu-sq"></i>').css('background', it.color)); }
                b.append($('<span class="mu-m-opt-label"></span>').text(it.label));
                b.append('<span class="mu-m-check">' + ic('m-check') + '</span>');
                b.on('click', function () {
                    if (!opts.keep) { closeAll(); }
                    if (it.onClick) { it.onClick(b); }
                });
                list.append(b);
            });
            s.append(list);
            if (opts.foot) { s.append(opts.foot); }
            body.append(s).addClass('mu-m-overlay').toggleClass('mu-m-pop-open', !!opts.pop);
            setTimeout(function () { s.addClass('open'); }, 10);
            return s;
        };

        // ------------------------------------------------------------ shell data (built by the provider)
        var railHref = function (icon) { return $('.mu-rail .mu-rail-link').has('.mu-i-' + icon).first().attr('href') || ''; };
        var railActive = function (icon) { return $('.mu-rail .mu-rail-link').has('.mu-i-' + icon).first().hasClass('active'); };
        var ticketsUrl = railHref('fd-all-tickets');
        var newUrl = $('.mu-new-dd .dropdown-menu a').first().attr('href') || '';
        var userName = txt('.dropdown-toggle-account .nav-user');

        // ------------------------------------------------------------ top bar
        var top = $('<div class="mu-m-top"></div>');
        var bar = $('<header class="mu-m-bar"></header>');
        top.append(bar);
        body.prepend(top);
        var title = $('<div class="mu-m-title"></div>');

        // ------------------------------------------------------------ tab bar (Tickets, Contacts, Dashboard, Notifications, Profile)
        var tabs = $('<nav class="mu-m-tabs"></nav>');
        var tab = function (cls, href, iconHtml, label, active) {
            var t = $(href ? '<a class="mu-m-tab"></a>' : '<button type="button" class="mu-m-tab"></button>');
            if (href) { t.attr('href', href); }
            return t.addClass(cls).toggleClass('active', !!active).attr('aria-label', label).append(iconHtml);
        };
        tabs.append(tab('mu-m-tab-tickets', ticketsUrl, ic('m-ticket'), muT('Tickets'), railActive('fd-all-tickets')));
        tabs.append(tab('mu-m-tab-contacts', railHref('contact'), ic('m-contact'), muT('Contacts'), railActive('contact')));
        tabs.append(tab('mu-m-tab-dash', railHref('nav-dashboard'), ic('m-chart'), muT('Dashboard'), railActive('nav-dashboard')));
        var notifTab = tab('mu-m-tab-notif', '', ic('m-bell'), 'Notifications', false)
            .toggleClass('has-unread', $('.web-notifications > .dropdown-toggle').hasClass('has-unread'));
        tabs.append(notifTab);
        tabs.append(tab('mu-m-tab-me', '', $('<span class="mu-m-me"></span>').text((userName.charAt(0) || '?').toUpperCase()), muT('Profile'), false));
        body.append(tabs);

        // Notifications and Account: tab pages (the tab bar stays visible), like the app
        var tabPanel = function (cls, tabCls) {
            var p = $('<div class="mu-m-panel"></div>').addClass(cls).appendTo(body);
            tabs.on('click', '.' + tabCls, function () {
                $('.mu-m-panel').removeClass('open');
                p.addClass('open').scrollTop(0);
                body.addClass('mu-m-panel-open');
                tabs.find('.mu-m-tab').removeClass('active');
                $(this).addClass('active');
            });
            return p;
        };
        // Notifications: native list ("Mark all as read", "Load more": handlers kept)
        var notifPanel = tabPanel('mu-m-notif', 'mu-m-tab-notif');
        var nMenu = $('.web-notifications > .dropdown-menu').first();
        var nCount = txt(nMenu.find('.web-notifications-count'));
        var notifHead = $('<div class="mu-m-bar"><div class="mu-m-title"></div></div>');
        notifHead.find('.mu-m-title').text('Notifications' + (nCount ? ' (' + nCount + ')' : ''));
        notifPanel.append(notifHead);
        if (nMenu.length) {
            var markRead = nMenu.find('.web-notifications-mark-read').first();
            if (markRead.length) {
                notifHead.append(markRead.addClass('mu-m-ib').attr({ 'aria-label': muT('Mark all as read'), title: muT('Mark all as read') }).html(ic('m-markread')));
            }
            notifPanel.append(nMenu.find('.web-notifications-list').first());
        }

        // Account: user, useful FreeScout settings, admin, "Change URL" (app), log out
        var me = window.muMe || { name: userName, email: '', host: window.location.host };
        var acct = tabPanel('mu-m-acct', 'mu-m-tab-me');
        acct.append('<div class="mu-m-bar"><div class="mu-m-title">' + muT('Account') + '</div></div>');
        var who = $('<div class="mu-m-acct-who"></div>');
        who.append($('<span class="mu-m-acct-av"></span>').text((me.name.charAt(0) || '?').toUpperCase()));
        who.append($('<div></div>').append(
            $('<div class="mu-m-acct-name"></div>').text(me.name),
            $('<div class="mu-m-acct-mail"></div>').text(me.email),
            $('<div class="mu-m-acct-host"></div>').text(me.host)
        ));
        acct.append(who);
        var list = $('<div class="mu-m-acct-list"></div>');
        var row = function (icon, label, onClick, cls) {
            list.append($('<button type="button" class="mu-m-acct-row"></button>').addClass(cls || '')
                .append(icon ? ic(icon) : '', $('<span></span>').text(label), cls ? '' : '<span class="mu-m-acct-chev">' + ic('chevron-right', true) + '</span>')
                .on('click', onClick));
        };
        if (me.profile) { row('m-person', muT('Your profile'), function () { window.location.href = me.profile; }); }
        if (me.notifications) { row('m-bell', muT('Ticket notifications'), function () { window.location.href = me.notifications; }); }
        var admin = $('.mu-rail-menu > li > a').filter(function () { return !!$.trim($(this).text()) && $(this).attr('href') !== '#'; });
        if (admin.length) {
            list.append('<div class="mu-m-acct-sec">Administration</div>');
            admin.each(function () {
                var a = $(this);
                row('settings', txt(a), function () { a[0].click(); });
            });
        }
        acct.append(list);
        var foot = $('<div class="mu-m-acct-list mu-m-acct-foot"></div>');
        list = foot;
        var sw = $('a.in-app-switcher').not('.hidden').first();
        if (sw.length) { row('refresh', txt(sw) || muT('Change helpdesk URL'), function () { sw[0].click(); }); }
        var logout = $('#logout-link');
        if (logout.length) { row('', muT('Log out'), function () { logout[0].click(); }, 'mu-m-acct-logout'); }
        acct.append(foot);

        // ------------------------------------------------------------ full-screen search (field + provider suggestions)
        // App-style search: "back Search", TICKETS / CUSTOMERS tabs, illustrated empty state.
        // Tickets: provider suggestions; Customers: filtered Contacts page (?q=), read via ajax.
        var sbox = $('.mu-hsearch-box').first();
        if (sbox.length) {
            sbox.appendTo(body);
            sbox.find('.mu-hsearch-close').html(ic('m-back')).prependTo(sbox);
            var sq = sbox.find('input[name=q]').attr('placeholder', muT('Search'));
            var stabs = $('<div class="mu-m-stabs"><button type="button" class="active" data-t="t">' + muT('Tickets') + '</button><button type="button" data-t="c">' + muT('Customers') + '</button></div>');
            var sempty = $('<div class="mu-m-sempty"><span class="mu-m-sempty-ill">' + ic('m-doc') + ic('search') + '</span><p>' + muT('We hope you find what you are looking for') + '</p></div>');
            var clients = $('<div class="mu-m-sclients"></div>');
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
                        $(doc).find('.mu-ctable td.mu-ct-name a').slice(0, 20).each(function () {
                            var nm = $.trim($(this).text());
                            clients.append($('<a class="mu-m-sclient"></a>').attr('href', this.getAttribute('href'))
                                .append($('<span class="mu-m-sclient-av"></span>').text((nm.charAt(0) || '?').toUpperCase()).css('background', avColor(nm)), $('<span></span>').text(nm)));
                        });
                        if (!clients.children().length) { clients.append('<div class="mu-m-snone">' + muT('No customers') + '</div>'); }
                    });
                }, 250);
            };
            var syncS = function () {
                var q = $.trim(sq.val()), tabC = sbox.hasClass('mu-m-s-c');
                sbox.toggleClass('mu-m-s-empty', q.length < 2);
                if (tabC) { searchClients(); }
            };
            stabs.on('click', 'button', function () {
                stabs.find('button').removeClass('active');
                $(this).addClass('active');
                sbox.toggleClass('mu-m-s-c', $(this).attr('data-t') === 'c');
                syncS();
                sq.trigger('focus');
            });
            sq.on('input', syncS);
            sbox.addClass('mu-m-s-empty');
        }
        var openSearch = function () { $('.mu-head-btns .mu-hsearch').first().trigger('click'); };

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
            if ($(e.target).closest('.mu-dd, .mu-dd-menu').length) { return; }
            if (new Date().getTime() - lastHold < 800 || $(this).hasClass('mu-m-swiped')) { e.preventDefault(); return; } // swiped card: the tap closes it
            var tr = $(this);
            if (body.hasClass('mu-m-sel')) {
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
            var hit = $(ev.target).closest('.mu-dd');
            if (ddReplay || !hit.length) { return; }
            var el = hit;
            setTimeout(function () {
                var menu = $('.mu-dd-menu').last();
                if (!menu.length) { return; }
                var field = el.attr('data-field');
                var items = [];
                menu.find('li > a').each(function () {
                    var a = $(this), v = a.attr('data-v');
                    var sq = a.find('.mu-sq');
                    items.push({ label: field === 'priority' ? prioLabel($.trim(a.text())) : $.trim(a.text()), color: field === 'priority' ? (PRIO_DOT[v] || '') : (sq.length ? sq.css('background-color') : ''), active: a.parent().hasClass('active'), onClick: function () {
                        ddReplay = true;
                        el.trigger('click');
                        ddReplay = false;
                        $('.mu-dd-menu').last().find('a').filter(function () { return String($(this).attr('data-v')) === String(v); }).first().trigger('click');
                    } });
                });
                menu.remove();
                sheet(items, { title: { priority: muT('Priority'), agent: muT('Agent'), status: muT('Status') }[field] || '' });
            }, 0);
        }, true);

        // Selection bar: native "Assign" and "Status" menus -> app-style "Agent" / "Status" sheets
        var bindBulkSheets = function () {
            $('#conversations-bulk-actions [data-toggle="dropdown"]').not('.mu-m-bs').addClass('mu-m-bs').each(function () {
                var tg = $(this), menu = tg.siblings('.dropdown-menu').first();
                var ttl = menu.hasClass('conv-user') ? muT('Agent') : (menu.hasClass('conv-status') ? muT('Status') : '');
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
            openRow.css('transform', '').removeClass('mu-m-swiped');
            openRow.data('muSwipe') && openRow.data('muSwipe').remove();
            openRow = null;
        };
        var swipePanel = function (tr) {
            var p = $('<div class="mu-m-swipe"></div>').css({ top: tr[0].offsetTop, height: tr.outerHeight() });
            var act = function (icon, label, fn) { p.append($('<button type="button" class="mu-m-swipe-act"></button>').append(ic(icon), $('<span></span>').text(label)).on('click', fn)); };
            var convId = tr.find('.conv-checkbox').val();
            var after = function (r) { if (window.loaderHide) { loaderHide(); } if (r && r.status === 'success') { window.location.reload(); } else if (window.showAjaxError) { showAjaxError(r); } };
            act('fd-trash', muT('Delete'), function () {
                closeSwipe();
                $('.conv-checkbox:checked').prop('checked', false).trigger('change').closest('tr').removeClass('selected');
                tr.find('.conv-checkbox').prop('checked', true).trigger('change');
                setTimeout(function () { $('#conversations-bulk-actions .conv-delete').first().trigger('click'); }, 50);
            });
            act('m-ticket', muT('Take'), function () {
                closeSwipe();
                fsAjax({ action: 'conversation_change_user', user_id: getGlobalAttr('auth_user_id'), conversation_id: convId }, laroute.route('conversations.ajax'), after, true);
            });
            act('fd-ban', muT('Spam'), function () {
                closeSwipe();
                fsAjax({ action: 'conversation_change_status', status: 4, conversation_id: convId }, laroute.route('conversations.ajax'), after, true);
            });
            tr.before(p);
            tr.data('muSwipe', p);
            return p;
        };
        $(document).on('touchstart', 'body.mu-m-list tr.conv-row', function (e) {
            if (body.hasClass('mu-m-sel')) { return; }
            var t = e.originalEvent.touches[0];
            if (openRow && openRow[0] !== this) { closeSwipe(); }
            swipe = { tr: $(this), x: t.clientX, y: t.clientY, dir: '', base: $(this).hasClass('mu-m-swiped') ? 225 : 0 };
        });
        $(document).on('touchmove', 'body.mu-m-list tr.conv-row', function (e) {
            if (!swipe) { return; }
            var t = e.originalEvent.touches[0], dx = t.clientX - swipe.x, dy = t.clientY - swipe.y;
            if (!swipe.dir && (Math.abs(dx) > 10 || Math.abs(dy) > 10)) { swipe.dir = Math.abs(dx) > Math.abs(dy) ? 'h' : 'v'; }
            if (swipe.dir !== 'h') { return; }
            if (!swipe.tr.data('muSwipe') || !swipe.tr.data('muSwipe').parent().length) { swipePanel(swipe.tr); }
            var x = Math.max(0, Math.min(swipe.base + dx, 300));
            swipe.tr.css({ transition: 'none', transform: 'translateX(' + x + 'px)' });
            swipe.x2 = x;
        });
        $(document).on('touchend touchcancel', 'body.mu-m-list tr.conv-row', function () {
            if (!swipe) { return; }
            swipe.tr.css('transition', '');
            if (swipe.dir === 'h') {
                lastHold = new Date().getTime(); // don't open the ticket on release
                if ((swipe.x2 || 0) > 110) {
                    swipe.tr.css('transform', 'translateX(225px)').addClass('mu-m-swiped');
                    openRow = swipe.tr;
                } else {
                    openRow = swipe.tr;
                    closeSwipe();
                }
            }
            swipe = null;
        });
        $(document).on('click', 'tr.conv-row.mu-m-swiped', function (e) { e.preventDefault(); e.stopImmediatePropagation(); closeSwipe(); });

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
            body.addClass('mu-m-list');
            bar.append($('<button type="button" class="mu-m-ib mu-m-menu" aria-label="' + muT('Views') + '">' + ic('m-menu') + '</button>').on('click', function () {
                closeAll();
                body.addClass('mu-m-drawer mu-m-overlay');
            }));
            bar.append(title);
            bar.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Search') + '">' + ic('search') + '</button>').on('click', openSearch));

            // Selection bar (long-press on a card), in place of the title bar
            var selbar = $('<header class="mu-m-bar mu-m-selbar"></header>');
            var selTitle = $('<div class="mu-m-title"></div>');
            selbar.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Cancel selection') + '">' + ic('m-back') + '</button>').on('click', function () {
                $('.conv-checkbox:checked').each(function () {
                    $(this).prop('checked', false).trigger('change').closest('tr').removeClass('selected');
                });
            }), selTitle);
            top.append(selbar);

            // Sort / Filter
            var sortbar = $('<div class="mu-m-sortbar"></div>');
            var sortBtn = $('<button type="button" class="mu-m-sortbtn">' + ic('m-sort') + '<span></span></button>');
            var filtBtn = $('<button type="button" class="mu-m-sortbtn mu-m-filtbtn">' + ic('m-filter') + '<span>' + muT('Filter') + '</span></button>');
            sortbar.append(sortBtn, '<span class="mu-m-sortsep"></span>', filtBtn);
            top.append(sortbar);

            // Views drawer: the module's own panel (view search, sections); a click closes the drawer, the provider loads the view via ajax
            $(document).on('click', '.mu-views a.mu-v:not(.dropdown-toggle)', function () { closeAll(); });
            $('.mu-views-filter').attr('placeholder', muT('Search views'));

            // + button (new ticket)
            // App-style + button: "New e-mail" / "New ticket" menu (FreeScout has no standalone contact creation)
            if (newUrl) {
                var fab = $('<button type="button" class="mu-m-fab" aria-label="' + muT('New') + '">' + ic('m-plus') + '</button>');
                var dial = $('<div class="mu-m-dial"></div>');
                var dialItem = function (label, icon, href) {
                    return $('<a class="mu-m-dial-item"></a>').attr('href', href).append($('<span class="mu-m-dial-lbl"></span>').text(label), $('<span class="mu-m-dial-btn"></span>').append(ic(icon)));
                };
                dial.append(dialItem(muT('New e-mail'), 'm-envelope', newUrl), dialItem(muT('New ticket'), 'm-ticket', newUrl + (newUrl.indexOf('?') === -1 ? '?' : '&') + 'mu_phone=1'));
                fab.on('click', function () { body.toggleClass('mu-m-dial-open'); });
                dial.on('click', function (e) { if (e.target === this) { body.removeClass('mu-m-dial-open'); } });
                body.append(dial, fab);
            }

            // Sort: bottom "Sort by" sheet (criteria, then Ascending / Descending), Cancel / Apply
            sortBtn.on('click', function () {
                var dd = $('.mu-sort').not('.mu-layout-dd').first();
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
                    items.push({ label: c.label, active: c.active, cls: 'mu-m-g-c', onClick: function (b) { pick.c = c; b.addClass('active').siblings('.mu-m-g-c').removeClass('active'); syncApply(); } });
                });
                items.push({ sep: true });
                $.each(ord, function (i, o) {
                    if (o.active) { pick.o = o; }
                    items.push({ label: o.label, active: o.active, cls: 'mu-m-g-o', onClick: function (b) { pick.o = o; b.addClass('active').siblings('.mu-m-g-o').removeClass('active'); syncApply(); } });
                });
                var foot = $('<div class="mu-m-sheet-foot"><button type="button" class="mu-m-btn">' + muT('Cancel') + '</button><button type="button" class="mu-m-btn mu-m-btn-primary" disabled>' + muT('Apply') + '</button></div>');
                var init = { c: pick.c, o: pick.o };
                var syncApply = function () { foot.find('.mu-m-btn-primary').prop('disabled', pick.c === init.c && pick.o === init.o); };
                foot.on('click', '.mu-m-btn', function () {
                    var go = $(this).hasClass('mu-m-btn-primary');
                    closeAll();
                    if (!go || !pick.c) { return; }
                    var url = pick.c.href;
                    var m = pick.o ? /[?&]order=([^&]*)/.exec(pick.o.href) : null;
                    if (m) { url = url.replace(/([?&]order=)[^&]*/, '$1' + m[1]); }
                    window.location.href = url;
                });
                sheet(items, { title: muT('Sort by'), foot: foot, keep: true });
            });

            // Filter: the module's Filters panel in full screen (X Filter … Apply)
            filtBtn.on('click', function () {
                closeAll();
                body.addClass('mu-m-filter');
                prepFilters();
            });
            $(document).on('click', '.mu-m-fclose', function () { body.removeClass('mu-m-filter'); });

            var syncSel = function () {
                var n = $('.conv-checkbox:checked').length;
                body.toggleClass('mu-m-sel', n > 0);
                selTitle.text(n + ' ' + muT('selected'));
                // native bulk-action bar (moved by the provider): menus open upward
                $('#conversations-bulk-actions .btn-group').addClass('dropup');
            };
            $(document).on('change', '.conv-checkbox', function () { setTimeout(syncSel, 0); });

            // Infinite scroll, like the app; new rows: context menu blocked like conversationsTableInit (main.js),
            // long-press delegated (above), dot menus bound by the provider
            infinite('.table-conversations > tbody', '.table-conversations > tbody > tr.conv-row', function (added) {
                added.on('contextmenu', function (ev) { ev.preventDefault(); ev.stopPropagation(); });
                prepRows();
                if (window.muBindDd) { window.muBindDd(); }
            });

            // After an ajax view change (provider), the list and the header bar are replaced: rebuild the cards and labels
            var refresh = function () {
                var vt = txt('.mu-viewbar-title'), n = txt('.mu-viewbar .mu-pill');
                title.text(vt + (n ? ' (' + n + ')' : ''));
                sortBtn.find('span').text(txt($('.mu-sort').not('.mu-layout-dd').first().find('.mu-sort-current')));
                var fc = /\((\d+)\)/.exec(txt('.mu-toggle-filters'));
                filtBtn.find('span').text(muT('Filter') + (fc ? ' (' + fc[1] + ')' : ''));
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
            return muDay(d.getDay()) + ' ' + d.getDate() + ' '
                + muMonth(d.getMonth()) + ' ' + d.getFullYear() + ', '
                + muTime(h, (m < 10 ? '0' : '') + m);
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
            $('.table-conversations tr.conv-row').not('.mu-m-done').each(function () {
                var tr = $(this).addClass('mu-m-done');
                var a = tr.find('td.conv-subject > a').first();
                var p1 = a.children('p').not('.conv-preview').first();
                var pv = a.children('p.conv-preview').first();
                var textOf = function (p) {
                    return $.trim(p.contents().filter(function () { return this.nodeType === 3; }).text()).replace(/\s+/g, ' ');
                };
                var pills = $('<span class="mu-m-pills"></span>');
                // status: only "New" (mint) and statuses with no due-date equivalent; overdue ones go through the red pill
                p1.find('.mu-badge').not('.mu-badge-first, .mu-badge-late').each(function () {
                    pills.append($('<span class="mu-m-pill"></span>').toggleClass('mu-m-pill-new', $(this).hasClass('mu-badge-new')).text(txt(this)));
                });
                var numEl = p1.find('.mu-num').first();
                var num = $.trim(numEl.text()).replace('#', '');
                if (num) { pills.append($('<span class="mu-m-pill"></span>').append(ic('m-ticket'), $('<span></span>').text(num))); }
                var line = pv.find('.mu-status-line').first();
                var sla = line.find('.mu-meta-sla').first();
                if (sla.length && !tr.hasClass('conv-closed')) {
                    var due = parseInt(sla.attr('data-due'), 10);
                    if (due && due < now) {
                        pills.append($('<span class="mu-m-pill mu-m-pill-late"></span>').append(ic('m-reply-due'), $('<span></span>').text(muT('Overdue by') + ' ' + shortAgo(now - due))));
                    } else if (due) {
                        pills.append($('<span class="mu-m-pill"></span>').append(ic('m-reply-due'), $('<span></span>').text(muT('Due in') + ' ' + shortAgo(due - now))));
                    }
                }
                var created = parseInt(numEl.attr('data-created'), 10);
                if (tr.hasClass('conv-closed')) {
                    var fm = line.find('.mu-meta-closed').first();
                    var closedAt = parseInt(fm.attr('data-closed'), 10);
                    pills.append($('<span class="mu-m-pill"></span>').text(closedAt ? muT('Closed :time ago').replace(':time', shortAgo(now - closedAt)) : txt(fm)));
                } else if (created) {
                    pills.append($('<span class="mu-m-pill"></span>').text(muT('Created :time ago').replace(':time', shortAgo(now - created))));
                }
                var chan = line.find('.mu-meta-who i').first().clone().attr('class', 'mu-i mu-i-m-mail');
                // pastel avatar + white initial, like the app (desktop: very pale pastel, dark initial)
                var av = tr.find('td.conv-customer .mu-av').first();
                var nm = txt(tr.find('td.conv-customer > a').first().contents().filter(function () { return this.nodeType === 3; }));
                av.addClass('mu-m-av').css('--mu-m-av', avColor(nm));
                if (isCustPage) {
                    // app-style contact-page ticket card: channel; subject + number; date • SLA status; separator; priority • agent • status (no borders)
                    var meta = line.find('.mu-meta').not('.mu-meta-who').map(function () { return txt(this); }).get().join(' • ');
                    var when = created ? fdStamp(created) : '';
                    tr.addClass('mu-m-cticket');
                    a.append($('<span class="mu-m-card mu-m-ccard"></span>').append(
                        $('<span class="mu-m-cchan"></span>').append(ic('m-mail')),
                        $('<span class="mu-m-csubj"></span>').append($('<span></span>').text(textOf(p1)), ' ', $('<span class="mu-m-cnum"></span>').text('#' + num)),
                        $('<span class="mu-m-cmeta"></span>').text((when ? when + ' • ' : '') + meta)
                    ));
                    tr.find('.mu-side-agent > .mu-i').first().attr('class', 'mu-i mu-i-m-person-plus');
                    tr.find('.mu-side-status > .mu-i').first().attr('class', 'mu-i mu-i-m-pulse');
                    var psq = tr.find('.mu-side-prio .mu-sq');
                    psq.css('background', PRIO_DOT[tr.find('.mu-side-prio').attr('data-value')] || psq.css('background-color'));
                    var pl2 = tr.find('.mu-side-prio .mu-dd-label');
                    pl2.text(prioLabel(txt(pl2)));
                    return;
                }
                var card = $('<span class="mu-m-card"></span>').append(
                    pills,
                    $('<span class="mu-m-subj"></span>').text(textOf(p1)),
                    $('<span class="mu-m-prevline"></span>').append(chan.length ? chan : ic('m-mail'), $('<span class="mu-m-prev"></span>').text(textOf(pv)))
                );
                a.append(card);
                // bottom pills: app icons (person +, activity), priority square
                var pl = tr.find('.mu-side-prio .mu-dd-label');
                pl.text(prioLabel(txt(pl)));
                tr.find('.mu-side-agent > .mu-i').first().attr('class', 'mu-i mu-i-m-person-plus');
                tr.find('.mu-side-status > .mu-i').first().attr('class', 'mu-i mu-i-m-pulse');
            });
        }

        // Full-screen filter: app-style header (X Filter … magnifier, reset), "Apply" disabled until something changes
        function prepFilters() {
            var f = $('.mu-filters').first();
            if (!f.length || f.find('.mu-m-fhead').length) { return; }
            var head = $('<div class="mu-m-bar mu-m-fhead"><button type="button" class="mu-m-ib mu-m-fclose" aria-label="' + muT('Close') + '">' + ic('m-close') + '</button><div class="mu-m-title">' + muT('Filter') + '</div></div>');
            head.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Search') + '">' + ic('search') + '</button>').on('click', function () {
                body.toggleClass('mu-m-fq');
                if (body.hasClass('mu-m-fq')) { f.find('.mu-f-q').trigger('focus'); }
            }));
            var reset = f.find('.mu-filters-reset').first();
            head.append($('<a class="mu-m-ib mu-m-freset" aria-label="' + muT('Reset') + '">' + ic('m-reset') + '</a>').attr('href', reset.attr('href') || '#').toggleClass('off', !reset.length));
            f.prepend(head);
            if ($.trim(f.find('.mu-f-q').val() || '')) { body.addClass('mu-m-fq'); }
            var apply = f.find('.mu-filters-foot .mu-btn-primary').prop('disabled', true);
            var initial = f.serialize();
            f.on('change input', ':input', function () { apply.prop('disabled', f.serialize() === initial); });
        }

        // ============================================================ TICKET
        function initConv() {
            body.addClass('mu-m-ticket');
            var back = $('.mu-crumb a').first().attr('href') || ticketsUrl;
            var num = txt($('.mu-crumb > span').last());
            bar.append($('<a class="mu-m-ib mu-m-back" aria-label="' + muT('Back') + '">' + ic('m-back') + '</a>').attr('href', back));
            bar.append(title.text(num ? '#' + num : ''));
            bar.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Edit ticket') + '">' + ic('m-pencil') + '</button>').on('click', function () { openEdit(); }));
            bar.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Ticket actions') + '">' + ic('m-more') + '</button>').on('click', actionsSheet));

            // Header: status, subject, SLA block with the priority | agent | status row (-> properties)
            var rp = $('.mu-rp').first();
            var subjBlock = $('#conv-subject .conv-subj-block').first();
            var statusName = txt(rp.find('.mu-rp-status-name'));
            if (statusName) { subjBlock.prepend($('<span class="mu-m-status"></span>').addClass('mu-m-status-' + (rp.find('.mu-rp-status-select').val() || '')).text(statusName)); }
            var sla = rp.find('.mu-rp-sla').first();
            var box = $('<div class="mu-m-slabox"></div>');
            if (sla.length) {
                var late = sla.hasClass('mu-rp-sla-late');
                var slaText = txt(sla.children('div').first().children('div').first());
                if (slaText) {
                    box.append($('<div class="mu-m-slarow"></div>').toggleClass('late', late)
                        .append('<span class="mu-m-slaic">' + ic(late ? 'fd-alert' : 'm-reply-due', true) + '</span>', $('<span></span>').text(slaText)));
                }
                var slaDate = txt(sla.find('.mu-rp-sla-date'));
                if (slaDate) {
                    box.append($('<div class="mu-m-slarow"></div>').append('<span class="mu-m-slaic">' + ic('m-timer', true) + '</span>', $('<span></span>').text(muT('Due:') + ' ' + slaDate)));
                }
            }
            var prio = rp.find('.mu-rp-priority option:selected');
            var agent = txt(rp.find('.mu-rp-user option:selected'));
            var st = txt(rp.find('.mu-rp-status-select option:selected'));
            var prow = $('<button type="button" class="mu-m-proprow"></button>');
            prow.append($('<span class="mu-m-pp"></span>').append($('<i class="mu-m-dot"></i>').css('background', PRIO_DOT[prio.val()] || '#9aa6b8'), $('<span></span>').text(prioLabel(txt(prio)) || '--')));
            prow.append($('<span class="mu-m-pp mu-m-pp-agent"></span>').append(ic('m-person', true), $('<span></span>').text(agent && agent !== '--' ? agent : '--')));
            prow.append($('<span class="mu-m-pp"></span>').append(ic('m-pulse', true), $('<span></span>').text(st)));
            prow.append('<span class="mu-m-pp-chev">' + ic('m-chevron', true) + '</span>');
            prow.on('click', openProps);
            box.append(prow);
            $('#conv-subject').after(box);
            // channel icon: app-style filled envelope
            $('#conv-subject .mu-chan .mu-i').attr('class', 'mu-i mu-i-m-mail');

            // App-style message headers: "Name To recipient", date "Thu 24 Sep, 2:59 PM", CC recipients
            var info = window.muReplyInfo || {};
            var fdDate = function (t) {
                var m = /([A-Za-z]+)\.? (\d{1,2}), (\d{4}) (\d{1,2}):(\d{2})/.exec(t || '');
                if (!m) { return ''; }
                var mi = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 }[m[1].slice(0, 3).toLowerCase()];
                if (mi === undefined) { return ''; }
                var d = new Date(parseInt(m[3], 10), mi, parseInt(m[2], 10));
                var h = parseInt(m[4], 10);
                return muDay(d.getDay()) + ' ' + d.getDate() + ' '
                    + muMonth(mi) + ', '
                    + muTime(h, m[5]);
            };
            $('#conv-layout-main > .thread').not('.mu-m-th').each(function () {
                var t = $(this).addClass('mu-m-th');
                var person = t.find('.thread-person').first();
                var to = t.hasClass('thread-type-customer') ? info.from : (t.hasClass('thread-type-message') ? info.to : '');
                if (to) { person.after($('<span class="mu-m-to"></span>').text(muT('To') + ' ' + to)); }
                var dt = t.find('.thread-date').first();
                var when = fdDate(dt.attr('data-original-title') || dt.attr('title'));
                if (when) { t.find('.thread-title').first().append($('<span class="mu-m-thdate"></span>').text(when)); }
                // avatar: app-style palette
                var av = t.find('.thread-photo .mu-av').first();
                av.css({ background: avColor(txt(person)), borderColor: avColor(txt(person)) });
            });

            // Full-screen properties: the module's own form (Priority, Agent, Status, Type, Tags), saved on every choice
            var cust = $('#conv-layout-customer');
            var props = rp.find('.mu-rp-props').first();
            if (props.length) {
                props.prepend($('<div class="mu-m-bar mu-m-phead"><button type="button" class="mu-m-ib" aria-label="' + muT('Back') + '">' + ic('m-back') + '</button><div class="mu-m-title">' + muT('Properties') + '</div></div>'));
                props.on('click', '.mu-m-phead .mu-m-ib', function () { body.removeClass('mu-m-props mu-m-edit'); });
                var prioSel = props.find('.mu-rp-priority');
                prioSel.find('option').each(function () { $(this).text(prioLabel($(this).text())); });
                var paintPrio = function () { props.find('.mu-rp-prio-sq').css('background', PRIO_DOT[prioSel.val()] || '#9aa6b8'); };
                paintPrio();
                prioSel.on('change', function () { setTimeout(paintPrio, 0); });
                // app-style labels and empty text: "Tags", "- -"
                props.find('.mu-rp-f-tags > label').text(muT('Tags'));
                props.find('.mu-rp-tags').on('select2:open', function () {});
                setTimeout(function () { props.find('.mu-rp-tags .select2-search__field').attr('placeholder', '- -'); }, 0);
                props.on('change', '.mu-rp-type, .mu-rp-status-select, .mu-rp-priority, .mu-rp-user', function () {
                    if (body.hasClass('mu-m-edit')) { return; } // "Edit ticket": saved via the bottom button
                    setTimeout(function () {
                        var save = props.find('.mu-rp-save');
                        if (!save.prop('disabled')) { save.trigger('click'); }
                    }, 0);
                });
            }
            function openProps() {
                closeAll();
                body.removeClass('mu-m-edit').addClass('mu-m-props');
                props.find('.mu-m-phead .mu-m-title').text(muT('Properties'));
                props.find('.mu-rp-status-select').closest('.mu-rp-f').find('> label').removeClass('mu-m-req').text(muT('Status'));
                props.find('.mu-rp-priority').closest('.mu-rp-f').find('> label').removeClass('mu-m-req');
                cust.scrollTop(0);
            }
            // "Edit ticket" (pencil), like the app: Subject + properties as underlined fields, "Save changes"
            var subjNow = function () { return $.trim($('.conv-subjtext > span:first').text()); };
            var esubj = $('<div class="mu-rp-f mu-m-esubj"><label class="mu-m-req">' + muT('Subject') + '</label><input type="text" class="mu-m-esubj-in"></div>');
            var esave = $('<button type="button" class="mu-m-esave">' + muT('Save changes') + '</button>');
            props.find('.mu-rp-body').append(esubj); // at the end of the list: the Properties screen's nth-child rules stay correct (reordered via CSS order)
            props.append(esave);
            function openEdit() {
                closeAll();
                esubj.find('input').val(subjNow());
                body.addClass('mu-m-props mu-m-edit');
                props.find('.mu-m-phead .mu-m-title').text(muT('Edit ticket'));
                // app-style screen labels (asterisks on required fields)
                props.find('.mu-rp-status-select').closest('.mu-rp-f').find('> label').addClass('mu-m-req').text(muT('Status'));
                props.find('.mu-rp-priority').closest('.mu-rp-f').find('> label').addClass('mu-m-req');
                cust.scrollTop(0);
            }
            esave.on('click', function () {
                var val = $.trim(esubj.find('input').val());
                var saveProps = function () {
                    var save = props.find('.mu-rp-save');
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
                var closeBtn = $('.mu-tb-close').first();
                if (closeBtn.length) {
                    var reopen = closeBtn.hasClass('mu-reopen');
                    items.push({ label: reopen ? muT('Reopen ticket') : muT('Close ticket'), icon: 'fd-check-circle', onClick: function () { closeBtn.trigger('click'); } });
                }
                var due = rp.find('.mu-rp-sla-edit');
                if (due.length) {
                    items.push({ label: muT('Due'), icon: 'fd-calendar', onClick: function () {
                        openProps();
                        body.addClass('mu-m-props-due');
                        rp.find('.mu-rp-due-form').show();
                    } });
                }
                var menu = $('.mu-tb-more > .dropdown-menu').first();
                var pick = function (re) {
                    return menu.find('li > a').filter(function () { return !$(this).parent().hasClass('hidden-lg') && re.test($(this).text()); }).first();
                };
                var merge = pick(/fusionner/i);
                if (merge.length) { items.push({ label: muT('Merge'), icon: 'fd-merge', onClick: function () { merge.trigger('click'); } }); }
                var spam = $('#conv-status .conv-status a[data-status="4"]').first();
                if (spam.length) { items.push({ label: muT('Spam'), icon: 'fd-ban', onClick: function () { spam.trigger('click'); } }); }
                var del = $('.mu-tb-delete').first();
                if (del.length) { items.push({ label: muT('Delete'), icon: 'fd-trash', onClick: function () { del.trigger('click'); } }); }
                var follow = menu.find('a.conv-follow').first();
                if (follow.length) {
                    var fl = $.trim(follow.clone().find('.hidden').remove().end().text()).replace(/\s+/g, ' ');
                    items.push({ label: fl || muT('Follow'), icon: 'fd-watching', onClick: function () { follow.trigger('click'); } });
                }
                items.push({ label: muT('Forward'), icon: 'forward', onClick: function () { openCompose('.conv-forward'); } });
                var print = pick(/imprimer/i);
                if (print.length) { items.push({ label: muT('Print'), icon: 'print', onClick: function () { print[0].click(); } }); }
                sheet(items, { head: muT('Ticket actions') });
            }

            // Reply bar (bottom of screen) -> full-screen editor
            var reply = $('.conv-action-wrapper').first();
            var rbar = $('<div class="mu-m-replybar"></div>');
            rbar.append($('<button type="button" class="mu-m-rb-input">' + ic('m-mail') + '<span>' + muT('Your reply here') + '</span></button>').on('click', function () { openCompose('.conv-reply'); }));
            rbar.append($('<button type="button" class="mu-m-rb-ic" aria-label="' + muT('Add note') + '">' + ic('m-doc') + '</button>').on('click', function () { openCompose('.conv-add-note'); }));
            rbar.append($('<button type="button" class="mu-m-rb-ic" aria-label="' + muT('Forward') + '">' + ic('forward') + '</button>').on('click', function () { openCompose('.conv-forward'); }));
            body.append(rbar);

            var modes = { '.conv-reply': muT('Reply'), '.conv-forward': muT('Forward'), '.conv-add-note': muT('Add note') };
            var edbar = $('<div class="mu-m-bar mu-m-edbar"></div>');
            var edMode = $('<button type="button" class="mu-m-edmode"><span></span>' + ic('m-chevron', true) + '</button>');
            edbar.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Back') + '">' + ic('m-back') + '</button>').on('click', function () {
                // like the app: leave the editor, the draft stays (saved by FreeScout) and reopens from the bar
                body.removeClass('mu-m-compose');
            }), edMode);
            reply.prepend(edbar);
            edMode.on('click', function () {
                var cur = window.muMode ? window.muMode() : '';
                var items = [];
                $.each(modes, function (sel, label) {
                    items.push({ label: label, active: sel === cur, onClick: function () { if (sel !== cur && window.muSwitchMode) { window.muSwitchMode(sel); } } });
                });
                sheet(items, { pop: true });
            });
            function openCompose(sel) {
                closeAll();
                body.addClass('mu-m-compose');
                var cur = window.muMode ? window.muMode() : '';
                if (cur !== sel && window.muSwitchMode) { window.muSwitchMode(sel); }
                syncCompose();
            }
            // native editor state: closed (sent, discarded) -> full screen left; opened another way (shortcut, a message's tool) -> full screen
            var wasOpen = false;
            // App-style fields: Cc and Bcc lines always shown, "To" recipient as a chip
            var skinHead = function () {
                var head = $('.mu-ed-head').first();
                if (!head.length) { return; }
                head.find('#cc, #bcc').closest('.form-group').removeClass('hidden');
                var to = head.find('.mu-ed-to .mu-ed-val').first();
                if (to.length && !to.find('.mu-m-chip').length && $.trim(to.text())) {
                    to.html($('<span class="mu-m-chip"></span>').text($.trim(to.text())));
                }
            };
            var syncCompose = function () {
                var cur = window.muMode ? window.muMode() : '';
                if (cur) { setTimeout(skinHead, 0); }
                edMode.find('span').text(modes[cur] || muT('Reply'));
                if (!cur) { body.removeClass('mu-m-compose'); } else if (!wasOpen) { body.addClass('mu-m-compose'); }
                wasOpen = !!cur;
                rbar.find('.mu-m-rb-input span').text(cur ? muT('Resume draft') : muT('Your reply here'));
            };
            var blk = $('.conv-reply-block').get(0);
            if (blk && window.MutationObserver) {
                new MutationObserver(syncCompose).observe(blk, { attributes: true, attributeFilter: ['class'] });
            }
            syncCompose();

            // "Aa": formatting toolbar collapsed by default on mobile (the desktop choice, remembered, is not reused)
            $(document).on('click', '.mu-ed-tools > .mu-ed-ic:first-child', function () {
                $(this).closest('.note-editor').toggleClass('mu-m-fmt');
            });
        }

        // ============================================================ NEW E-MAIL / NEW TICKET (app-style forms)
        // Stacked fields (uppercase label, underlined field), CC / BCC always shown, DESCRIPTION + signature + paperclip,
        // Status and Agent as fields, full-width button at the bottom. The native form stays the same (send, draft, attachments).
        function initNew() {
            body.addClass('mu-m-new mu-m-notabs');
            bar.append($('<button type="button" class="mu-m-ib mu-m-back" aria-label="' + muT('Back') + '">' + ic('m-back') + '</button>').on('click', function () {
                if (window.history.length > 1) { window.history.back(); } else { window.location.href = ticketsUrl; }
            }));
            bar.append(title);
            var f = $('#form-create');
            var phone = function () { return $('#phone-conv-switch').hasClass('active'); };
            var send = f.find('.btn-group-send').first();
            var sync = function () {
                body.toggleClass('mu-m-phone', phone()); // no "From" line for a phone ticket
                title.text(phone() ? muT('New ticket') : muT('New e-mail'));
                send.find('.btn-send-text').text(muT('Send'));
                send.find('.btn-create-conv').text(muT('Create ticket'));
            };
            $(document).on('click', '.conv-switch-button', function () { setTimeout(sync, 0); });
            f.find('#field-to > .control-label, #subject').closest('.form-group').find('> .control-label').addClass('mu-m-req');
            // app-style "FROM" line (read-only: the mailbox has no sending alias)
            if (window.muMe && window.muMe.mailbox && !f.find('.conv-from-alias').length) {
                f.find('#field-to').before($('<div class="form-group mu-m-nfrom"><label class="control-label mu-m-req">' + muT('From') + '</label><div class="col-sm-9"><div class="mu-m-nfromval"></div></div></div>').find('.mu-m-nfromval').text(window.muMe.mailbox).end());
            }
            f.find('#bcc').closest('.form-group').find('> .control-label').text('Bcc'); // app-style label
            // CC / BCC: lines shown by default (the native link also initializes their selectors)
            setTimeout(function () { if ($('#toggle-cc').length && !f.find('#cc').closest('.form-group').is(':visible')) { $('#toggle-cc').trigger('click'); } }, 0);
            var bodyGroup = f.find('.conv-reply-body').first();
            bodyGroup.before('<div class="mu-m-nlabel mu-m-req">Description</div>');
            // Status / Agent (native footer selectors) presented as fields, paperclip under the description, send button at the bottom.
            // The editor's footer is only built once fully loaded (initReplyForm): re-run on "load", without duplicating.
            var skinNew = function () {
                var bar2 = f.find('.note-statusbar').first();
                send = bar2.find('.btn-group-send').first().length ? bar2.find('.btn-group-send').first() : send;
                var after = bodyGroup;
                bar2.find('select').each(function () {
                    var sel = $(this), name = sel.attr('name');
                    var lbl = name === 'status' ? muT('Status') : (name === 'user_id' ? muT('Agent') : '');
                    if (!lbl) { return; }
                    var w = $('<div class="mu-m-nf"></div>').append($('<label></label>').text(lbl), sel);
                    after.after(w);
                    after = w;
                });
                var att = f.find('.note-btn-attachment').first();
                if (att.length && !att.closest('.mu-m-nattach').length) { bodyGroup.find('.note-editor').first().after($('<div class="mu-m-nattach"></div>').append(att.html(ic('fd-attach')))); }
                if (send.length && !send.closest('.mu-m-nsend').length) { f.append($('<div class="mu-m-nsend"></div>').append(send)); }
                sync();
            };
            // retried until the native footer exists ("load" may have already fired by the time this script runs)
            var tries = 0;
            var waitBar = function () {
                skinNew();
                if (!f.find('.mu-m-nf').length && tries++ < 20) { setTimeout(waitBar, 250); }
            };
            waitBar();
        }

        // ============================================================ CUSTOMER PAGE (clone of the app's contact page)
        // Grey header: large avatar, name, round buttons (new ticket, call); Profile / Tickets tabs; pencil = edit.
        function initCustomer() {
            body.addClass('mu-m-cust');
            var pv = $('.profile-preview').first();
            var name = txt(pv.find('.customer-name'));
            var editUrl = $('.nav-tabs-main a').filter(function () { return /\/edit$/.test(this.getAttribute('href') || ''); }).attr('href');
            var isEdit = /\/edit$/.test(window.location.pathname);
            bar.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Back') + '">' + ic('m-back') + '</button>').on('click', function () {
                if (window.history.length > 1) { window.history.back(); } else { window.location.href = railHref('contact'); }
            }));
            bar.append(title.text(isEdit ? muT('Edit contact') : name));
            if (isEdit) { body.addClass('mu-m-notabs'); return; }
            if (editUrl) { bar.append($('<a class="mu-m-ib" aria-label="' + muT('Edit') + '">' + ic('m-pencil') + '</a>').attr('href', editUrl)); }
            top.addClass('mu-m-top-grey');

                        var hero = $('<div class="mu-m-hero"></div>');
            hero.append($('<span class="mu-m-hero-av"></span>').text((name.charAt(0) || '?').toUpperCase()).css('background', avColor(name)));
            hero.append($('<div class="mu-m-hero-name"></div>').text(name));
            var btns = $('<div class="mu-m-hero-btns"></div>');
            var email = $.trim(pv.find('.customer-email').first().text());
            var phone = $.trim((pv.find('.customer-phone').first().contents().filter(function () { return this.nodeType === 3; }).first().text() || pv.find('.customer-phone').first().text()).split('(')[0]);
            if (newUrl) { btns.append($('<a class="mu-m-hero-btn" aria-label="' + muT('New ticket') + '">' + ic('m-ticket-plus') + '</a>').attr('href', newUrl + (email ? '?to=' + encodeURIComponent(email) : ''))); }
            if (phone) { btns.append($('<a class="mu-m-hero-btn" aria-label="' + muT('Call') + '">' + ic('phone') + '</a>').attr('href', 'tel:' + phone.replace(/[^\d+]/g, ''))); }
            hero.append(btns);

            var ctabs = $('<div class="mu-m-ctabs"><button type="button" data-t="profile">' + muT('Profile') + '</button><button type="button" data-t="tickets">' + muT('Tickets') + '</button></div>');
            var prof = $('<div class="mu-m-cprofile"></div>');
            var row = function (label, value) { if (value) { prof.append($('<div class="mu-m-kv"></div>').append($('<label></label>').text(label), $('<div></div>').text(value))); } };
            pv.find('.customer-email').each(function () { row('E-mail', $.trim($(this).text())); });
            pv.find('.customer-phone').each(function () {
                var t = $.trim($(this).text()).replace(/\s+/g, ' ');
                var m = /^(.*?)\s*\((.*)\)$/.exec(t);
                row(muT('Phone number') + (m ? ' ' + m[2].toLowerCase() : ''), m ? m[1] : t); // app-style label
            });
            pv.find('.customer-extra').children().each(function () { row('', $.trim($(this).text()).replace(/\s+/g, ' ')); });
            if (!prof.children().length) { prof.append('<div class="mu-m-kv"><div>' + muT('No information') + '</div></div>'); }
            $('.content-2col').first().prepend(hero, ctabs, prof);
            body.addClass('mu-m-notabs'); // stacked screen in the app: no tab bar
            var tbl = $('.content-2col .table-conversations').first();
            if (tbl.length && !$('.content-2col a[rel="next"]').length) { tbl.after($('<div class="mu-m-cend"></div>').text(muT('That\u2019s all, folks!'))); }
            var setTab = function (t) {
                body.toggleClass('mu-m-ctab-tickets', t === 'tickets');
                ctabs.find('button').removeClass('active').filter('[data-t="' + t + '"]').addClass('active');
                try { window.sessionStorage.setItem('mu_m_ctab', t); } catch (e) {}
            };
            ctabs.on('click', 'button', function () { setTab($(this).attr('data-t')); });
            // like the app: opens on Profile (Tickets only when coming back from a page of the customer's ticket list)
            setTab(/[?&]page=/.test(window.location.search) ? 'tickets' : 'profile');
            // name in the top bar only once the header has scrolled past, like the app
            title.addClass('mu-m-title-scroll');
            $(window).on('scroll', function () { title.toggleClass('on', window.pageYOffset > 150); });
        }

        // ============================================================ OTHER PAGES (dashboard, contacts, settings…)
        function initOther() {
            var tabPage = railActive('nav-dashboard') || $('.mu-ctable').length > 0;
            if (!tabPage) {
                bar.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Back') + '">' + ic('m-back') + '</button>').on('click', function () {
                    if (window.history.length > 1) { window.history.back(); } else { window.location.href = ticketsUrl; }
                }));
            }
            var t = txt('.mu-head-title') || txt($('.mu-crumb > span').last()) || document.title.split(' - ')[0];
            bar.append(title.text(t));
            if ($('.mu-ctable').length) {
                initContacts();
            } else if (tabPage) {
                bar.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Search') + '">' + ic('search') + '</button>').on('click', openSearch));
            }
            // paged sections (settings, mailbox settings…): the (hidden) sidebar menu opens from the title
            var secLinks = $('.sidebar-2col .sidebar-menu a[href]').filter(function () { return $.trim($(this).text()) && this.getAttribute('href') !== '#'; });
            if (!tabPage && secLinks.length > 1) {
                title.addClass('mu-m-title-menu').append(ic('m-chevron', true)).on('click', function () {
                    var items = [];
                    secLinks.each(function () {
                        var a = $(this);
                        items.push({ label: txt(a), active: a.closest('li').hasClass('active'), onClick: function () { window.location.href = a.attr('href'); } });
                    });
                    sheet(items, { title: t });
                });
            }
            body.toggleClass('mu-m-notabs', !tabPage);
            // native pages (profile, notifications, settings…): app-style form styling (mobile.css, .mu-m-page)
            body.toggleClass('mu-m-page', !tabPage);
        }

        // Contacts (clone of the app): avatar + name cards, magnifier = contacts search field, infinite scroll
        function initContacts() {
            body.addClass('mu-m-contacts');
            title.text(muT('All contacts'));
            var search = $('.mu-contacts-search').first();
            if (search.find('input[name=q]').val()) { body.addClass('mu-m-csearch'); }
            bar.append($('<button type="button" class="mu-m-ib" aria-label="' + muT('Search') + '">' + ic('search') + '</button>').on('click', function () {
                body.toggleClass('mu-m-csearch');
                if (body.hasClass('mu-m-csearch')) { search.find('input[name=q]').trigger('focus'); }
            }));
            var paint = function () {
                $('.mu-ctable td.mu-ct-name').not('.mu-m-done').each(function () {
                    var td = $(this).addClass('mu-m-done');
                    var nm = txt(td.find('a'));
                    td.find('.mu-av').addClass('mu-m-av').css('--mu-m-av', avColor(nm));
                });
            };
            paint();
            // the whole card opens the contact
            $(document).on('click', '.mu-ctable > tbody > tr', function (e) {
                if ($(e.target).closest('a').length) { return; }
                var a = $(this).find('td.mu-ct-name a').attr('href');
                if (a) { window.location.href = a; }
            });
            infinite('.mu-ctable > tbody', '.mu-ctable > tbody > tr', paint);
        }

        // Generic infinite scroll: the next page (module's .mu-pager) is appended to the end of the list
        function infinite(tbodySel, rowSel, after) {
            var loading = false;
            $(window).on('scroll', function () {
                if (loading || window.innerHeight + window.pageYOffset < document.documentElement.scrollHeight - 700) { return; }
                var next = $('.mu-pager a.mu-pager-btn').last();
                var url = next.attr('href');
                if (next.hasClass('disabled') || !url || url === '#') { return; }
                loading = true;
                body.addClass('mu-m-loading');
                $.ajax({ url: url, dataType: 'html' }).done(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var tbody = $(tbodySel).first();
                    var added = $();
                    $(doc).find(rowSel).each(function () {
                        var row = $(document.adoptNode(this));
                        tbody.append(row);
                        added = added.add(row);
                    });
                    var np = $(doc).find('.mu-pager').first();
                    if (np.length) { $('.mu-pager').first().replaceWith(document.adoptNode(np[0])); }
                    if (after) { after(added); }
                }).always(function () {
                    loading = false;
                    body.removeClass('mu-m-loading');
                });
            });
        }
    });
})(window.jQuery);
