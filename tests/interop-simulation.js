/*
 * FreeScout Interop — simulation of another module, to check Refresh's side of the contract on a real FreeScout.
 *
 * Paste into the browser console of a Refresh page (desktop or phone width), once the page has loaded. It registers,
 * under the owner "interop-simulation", what RefreshGlobal's Public/js/interop-adapter.js registers:
 *   - a navigation entry after "tickets" (desktop rail, phone tab bar, phone views drawer);
 *   - a ticket toolbar action after "close" (contexts conversation and conversation-deleted);
 *   - a left swipe action for the ticket list cards (phone);
 *   - on a ticket page, the origin of this ticket (breadcrumb, phone back arrow, landmark of the views panel).
 * Refresh renders them at once (bridge events); the origin is kept for the tab (sessionStorage): reload the ticket page
 * to see it applied from the start. Clicking an action logs its context. Nothing is sent to the server.
 * Run FSInteropSimulation.report() to list what Refresh rendered; FSInteropSimulation.reset() forgets the origin
 * (registries cannot be emptied: reload the page).
 * Optional: FSInteropSimulation.replaceTickets = true before pasting, to test "replaces" (Refresh's Tickets hidden).
 */
(function () {
    'use strict';
    var FI = window.FreeScoutInterop;
    if (!FI) {
        console.error('FreeScout Interop bridge not loaded (Public/js/fs-interop.js)');
        return;
    }
    var OWNER = 'interop-simulation';
    var sim = window.FSInteropSimulation = window.FSInteropSimulation || {};
    var events = [];
    ['refresh:ready', 'refresh:list-rendered', 'refresh:conversation-rendered', 'navigation:registered', 'toolbar:registered', 'swipe:registered', 'context:changed']
        .forEach(function (name) { FI.events.on(name, function (p) { events.push([name, p]); }); });
    // an SVG icon given by address, as modules do (here: Refresh's own folder icon)
    var svg = (window.FSInteropSimulation.base || '') + '/modules/refresh/icons/folder.svg'; // base: FreeScout's subfolder, if any

    console.log('Refresh registered:', FI.modules.get('refresh'));
    console.log('supports navigation / navigation-context / ticket-toolbar / swipe-actions / dashboard-handoff:',
        ['navigation', 'navigation-context', 'ticket-toolbar', 'swipe-actions', 'dashboard-handoff'].map(function (c) { return FI.supports('refresh', c, '>=1.0 <2.0'); }));

    FI.modules.register({ id: OWNER, version: '0.0.1', protocol: '1.0.0', capabilities: {} });

    FI.navigation.register({
        id: OWNER + '.page',
        owner: OWNER,
        label: 'Simulated module',
        href: window.location.origin + '/?interop-simulation',
        icon: svg,
        placements: ['desktop-sidebar', 'mobile-drawer', 'mobile-bottom-bar'],
        position: { after: 'tickets' },
        active: false,
        order: 100,
        replaces: sim.replaceTickets ? { id: 'tickets', placements: ['desktop-sidebar', 'mobile-bottom-bar'] } : undefined
    });

    FI.toolbar.register({
        id: OWNER + '.action',
        owner: OWNER,
        label: 'Simulated action',
        shortLabel: 'Simulate',
        icon: 'folder',
        contexts: ['conversation', 'conversation-deleted'],
        position: { after: 'close' },
        order: 100,
        handler: function (context) { console.log('toolbar action run with', context); alert('Toolbar action: ' + JSON.stringify(context)); }
    });

    FI.swipe.register({
        id: OWNER + '.swipe',
        owner: OWNER,
        direction: 'left',
        label: 'Simulate',
        icon: 'folder',
        contexts: ['ticket-list'],
        order: 100,
        handler: function (context) { console.log('swipe action run with', context); alert('Swipe action: ' + JSON.stringify(context)); }
    });

    var m = /\/conversation\/(\d+)/.exec(window.location.pathname);
    if (m) {
        FI.context.setConversationOrigin({
            schema: '1.0',
            sourceModule: OWNER,
            conversationId: parseInt(m[1], 10),
            mailboxId: null,
            origin: { id: OWNER + '.list', label: 'Simulated list', href: window.location.origin + '/?interop-simulation-list#back=' + m[1], count: 42 }
        });
    }

    sim.report = function () {
        var q = function (sel) { return document.querySelectorAll(sel).length; };
        var crumb = document.querySelector('.rf-crumb a');
        var back = document.querySelector('.rf-m-back');
        var out = {
            'rail entry (.rf-rail [data-fsi-nav])': q('.rf-rail [data-fsi-nav="' + OWNER + '.page"]'),
            'Refresh Tickets hidden (.rf-interop-replaced)': q('.rf-interop-replaced'),
            'phone tab (.rf-m-tabs [data-fsi-nav])': q('.rf-m-tabs [data-fsi-nav="' + OWNER + '.page"]'),
            'phone drawer entry (.rf-views-interop a)': q('.rf-views-interop a'),
            'toolbar button (.rf-tb-interop)': q('.rf-tb-interop'),
            'toolbar button follows Close': !!(document.querySelector('.rf-tb-close + .rf-tb-interop')),
            'breadcrumb first link': crumb ? crumb.textContent + ' -> ' + crumb.getAttribute('href') : null,
            'phone back arrow': back ? back.getAttribute('href') : null,
            'landmark (.rf-views-origin)': q('.rf-views-origin'),
            'swipe actions offered (left)': window.rfInterop ? window.rfInterop.swipeActions('left').length : 'rfInterop missing',
            'phone sheet entries': window.rfInterop ? window.rfInterop.sheetItems().length : 'rfInterop missing',
            'events seen': events.map(function (e) { return e[0]; })
        };
        console.table(out);
        return out;
    };
    sim.reset = function () { FI.context.clearConversationOrigin(); };
    setTimeout(sim.report, 50);
})();
