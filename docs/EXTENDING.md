# Extending Refresh — the FreeScout Interop contract

Refresh takes part in **FreeScout Interop** (protocol **1.0.0**), a small versioned contract through which sibling
FreeScout modules cooperate without reading each other's HTML or calling each other's classes. Another module declares
**intents and data** (a navigation entry, a ticket action, a swipe action, where a ticket was opened from); Refresh
decides how to render them in its interface, on the desktop and on phones.

Nothing here is required. Without another participating module, Refresh renders exactly as it does without the
contract: no element added, no extra request.

| | |
|---|---|
| Shared browser bridge | `Public/js/fs-interop.js` — unchanged copy of [altmenorg/fs-interop](https://github.com/altmenorg/fs-interop) **v1.0.0**, recorded in `Public/js/fs-interop.lock.json` (SHA-256). Never edit it: change the shared repository, release, then replace the file and the lock file together. |
| Refresh's participation | `Public/js/interop.js` (browser), `Services/Interop.php` (server) |
| Machine-readable declaration | `interop.json`, next to `module.json` |
| Payload schemas | in the shared repository (and RefreshGlobal's `docs/interop/schemas/`) |
| Manual check on a real FreeScout | `tests/interop-simulation.js` (paste into the browser console) |

Every module that takes part ships its own copy of the bridge and loads it first in FreeScout's `javascripts` filter.
The bridge is idempotent: whichever copy loads first creates `window.FreeScoutInterop`, the others keep its registries,
so the loading order of modules never matters.

## What Refresh provides

Refresh registers itself while its script loads (before DOM ready):

```javascript
FreeScoutInterop.modules.get('refresh');
// {id: 'refresh', version: '<module.json>', protocol: '1.0.0', capabilities: {
//   'navigation': '1.0', 'navigation-context': '1.0', 'ticket-toolbar': '1.0', 'swipe-actions': '1.0',
//   'conversation-ui': '1.0', 'dashboard-handoff': '1.0'}}
FreeScoutInterop.supports('refresh', 'ticket-toolbar', '>=1.0 <2.0'); // true
```

| Capability | What Refresh does |
|---|---|
| `navigation` | renders the entries of `FreeScoutInterop.navigation` (desktop left bar, phone tab bar, phone views drawer) |
| `navigation-context` | on a ticket page, uses the origin of `FreeScoutInterop.context` as first breadcrumb item, phone back arrow and landmark of the views panel |
| `ticket-toolbar` | renders the actions of `FreeScoutInterop.toolbar` in the desktop ticket toolbar and in the phone "Ticket actions" sheet |
| `swipe-actions` | offers the actions of `FreeScoutInterop.swipe` when a card of the phone ticket list is swiped |
| `conversation-ui` | renders the ticket page and announces it (`refresh:conversation-rendered`) |
| `dashboard-handoff` | asks `fsinterop.dashboard.owner` before rendering "My dashboard" (server side) |

Surfaces registered on the page (`FreeScoutInterop.surfaces.owner(surface, context)`): `navigation@*` on every page,
`ticket-list@mailbox` on a mailbox list, `ticket-toolbar@conversation` on a ticket, `dashboard@mailbox` on the dashboard
when Refresh rendered its block.

Only intents whose `owner` is not `refresh` are rendered. Every render is idempotent and runs again when a module
registers later (the bridge's `navigation:registered`, `toolbar:registered` and `context:changed` events), so a module
may register at script evaluation or on DOM ready.

### Navigation entries

```javascript
FreeScoutInterop.navigation.register({
    id: 'mymodule.page',              // unique, prefixed with your module
    owner: 'mymodule',
    label: 'My module',               // already translated
    href: '/my-module',
    icon: '/modules/mymodule/img/icon.svg', // SVG address (drawn in the bar's color), or a name (see Icons)
    placements: ['desktop-sidebar', 'mobile-drawer', 'mobile-bottom-bar'], // default: ['desktop-sidebar']
    position: { after: 'tickets' },   // or {before: …}; default: at the end
    active: false,                    // true on your own page
    replaces: { id: 'tickets', placements: ['desktop-sidebar', 'mobile-bottom-bar'] } // optional
});
```

| Placement | Where Refresh renders it |
|---|---|
| `desktop-sidebar` | a link of the left bar (`.rf-rail .rf-rail-links`), with a tooltip. A link already in the bar with the same address (for example added on the server through `refresh.rail_items`) is reused — marked and moved — not duplicated. |
| `mobile-bottom-bar` | a tab of the phone tab bar (`.rf-m-tabs`); `active: true` makes it the active tab |
| `mobile-drawer` | an entry of the phone views drawer (`.rf-views`, block `.rf-views-interop` under the view search), unless the drawer already links to that address (e.g. through `mailbox.after_sidebar_buttons`) |

Ids of Refresh's own entries, for `position` and `replaces`: left bar `dashboard`, `tickets`, `contacts`,
`saved-replies`, `tags`; phone tab bar `tickets`, `contacts`, `dashboard`, `notifications`, `profile`. Another
module's entry can also be named by its id. `replaces` **hides** Refresh's entry in the listed placements (all if
omitted); it stays in the page, because Refresh's scripts read its address.

The server-side filter `refresh.rail_items` (README, "For module developers") keeps working as before.

### Origin of a ticket (navigation context)

When your list opens a ticket, record where it came from (`conversation-origin` 1.0); the bridge keeps it for the
browser tab:

```javascript
FreeScoutInterop.context.setConversationOrigin({
    schema: '1.0',
    sourceModule: 'mymodule',
    conversationId: 1523,            // null: the next ticket opened
    mailboxId: null,
    origin: { id: 'mymodule.list', label: 'My list', href: '/my-module?page=2', count: 42 }
});
```

On that ticket's page Refresh uses it as the first item of the breadcrumb (`.rf-crumb`, link and label), the target of
the phone back arrow (`.rf-m-back`) and a landmark "label (count)" at the top of the views panel
(`.rf-views-origin`). An origin recorded for another ticket is ignored.

### Ticket toolbar actions

```javascript
FreeScoutInterop.toolbar.register({
    id: 'mymodule.archive',
    owner: 'mymodule',
    label: 'Archive the ticket',      // phone sheet entry and button tooltip
    shortLabel: 'Archive',            // optional: desktop button text
    icon: 'folder',
    contexts: ['conversation'],       // and/or 'conversation-deleted' (ticket in the trash); default: ['conversation']
    position: { after: 'close' },     // default
    order: 100,
    handler: function (context) { /* {conversationId, mailboxId, userId, mobile} */ }
});
```

* **Desktop**: a button of Refresh's ticket toolbar (`.rf-tb`, `button.rf-tb-interop[data-fsi-action]`). Ids for
  `position`: `star`, `reply`, `note`, `forward`, `close`, `more`, or another action's id; several actions after the
  same button keep their `order`. Without a position they go after "Close".
* **Phone**: an entry of the "Ticket actions" sheet (⋮ of the ticket bar). Ids for `position`: `close`, `due`, `merge`,
  `spam`, `delete`, `follow`, `forward`, `print` (when present on that ticket); after "Close" by default.
* The context is `conversation-deleted` for a ticket in the trash (decided on the server), `conversation` otherwise.

### Swipe actions (phone ticket list)

```javascript
FreeScoutInterop.swipe.register({
    id: 'mymodule.archive', owner: 'mymodule', label: 'Archive', icon: 'folder',
    direction: 'left',                // or 'right'
    contexts: ['ticket-list'],        // default
    handler: function (context) { /* {conversationId, mailboxId} */ }
});
```

Refresh stays the **only** handler of the touch events on its cards; do not add your own. A right swipe shows Refresh's
own panel (Delete, Take, Spam) followed by the right-swipe actions of other modules. A left swipe opens a panel on the
right of the card with the left-swipe actions; without any, a card cannot be pulled to the left (as before). Each action
is 75 px wide; a swipe past 110 px leaves the panel open.

### Icons

An icon given as an address (anything with `/`, `.` or `:`) is drawn as a CSS mask in the current text color, like the
module icons of the left bar: use a single-color SVG. A name is mapped to Refresh's icon set: `folder`, `folder-open`,
`trash`, `delete`, `inbox`, `inbox-stack`, `star`, `calendar`, `settings`, `search`, `contact`, `grid`, `ticket`; any
other name gets a neutral grid icon.

### Events

Emitted on `FreeScoutInterop.events`, payloads are plain data with `schema: '1.0'` and `source_module: 'refresh'`:

| Event | When | Payload |
|---|---|---|
| `refresh:ready` | the page is rendered, intents included | `version`, `context` (`dashboard`, `mailbox`, `conversation`, `other`) |
| `refresh:list-rendered` | a ticket list was rendered: page load, or a view switched in place (ajax) | `mailbox_id`, `view` |
| `refresh:conversation-rendered` | a ticket page was rendered | `conversation_id`, `mailbox_id`, `mobile` |

```javascript
FreeScoutInterop.events.on('refresh:list-rendered', function (e) { /* decorate the new rows */ });
```

## Server hooks

Public Eventy filters; Refresh never reads another module's settings or HTML, and others should not read Refresh's.

```php
// SLA targets (sla-targets 1.0), answered by Refresh from Manage > Settings > Refresh (an earlier answer is kept)
$targets = \Eventy::filter('fsinterop.sla.targets', null, ['schema' => '1.0', 'scope' => '…', 'user_id' => $userId]);
// ['schema' => '1.0', 'source_module' => 'refresh', 'first_response_hours' => 24, 'resolution_hours' => 72]

// Dashboard handoff (dashboard-owner 1.0): Refresh ASKS before rendering "My dashboard" on FreeScout's dashboard
// page. Answer with your module id to render the dashboard of that page yourself: Refresh then adds nothing.
\Eventy::addFilter('fsinterop.dashboard.owner', function ($owner, $context = []) {
    return $owner ?: ($iRenderIt ? 'mymodule' : $owner);
}, 20, 2);

// Every participating module adds its summary; Refresh's: id, name, version, protocol, capabilities
$modules = \Eventy::filter('fsinterop.modules', []);

// Who renders what on the page being built (navigation-context 1.0): Refresh claims navigation@*,
// ticket-list@mailbox, ticket-toolbar@conversation and, when it rendered its block, dashboard@mailbox
$context = \Eventy::filter('fsinterop.navigation.context', $base);
```

## Versions

A capability changed in a compatible way bumps its minor version (`1.1`); a breaking change of the contract bumps the
protocol major, and the other modules then fall back to their own behaviour instead of breaking. Update the bridge
copy only from a release of the shared repository, and check it:

```bash
sha256sum Public/js/fs-interop.js        # must equal "sha256" of Public/js/fs-interop.lock.json
FS_INTEROP_FILE=Public/js/fs-interop.js node <fs-interop>/tests/fs-interop.test.js
```
