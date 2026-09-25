# Refresh: a new interface for FreeScout

**A new, Freshdesk-inspired interface for [FreeScout](https://freescout.net).** Refresh reworks the whole agent interface
of FreeScout: a left navigation bar, ticket views with SLA badges, a dashboard, a properties panel next to each ticket,
a Freshdesk-style reply editor, ajax actions everywhere, a phone version, an installable app and Web Push notifications.
It borrows Freshdesk's ergonomics, with a look of its own (slate and indigo, light borders, small radii), also applied
to FreeScout's own pages: settings, profiles, customers, users, mailboxes and modules.

It is a single module: no core file is modified, nothing depends on the Customization module, and switching it off
brings the stock FreeScout interface back.

![Ticket list](screenshots/list.png)

## Features

### Navigation
- **Left bar** with icons: Dashboard, Tickets, Contacts, Saved replies, Tags (when the Tags module is active), plus the
  entries of other modules (see [For module developers](#for-module-developers)). Your logo on top.
- **Views panel**: switching views, filters, sorting and pages happens in place (ajax), with browser history support.
- **Search** in the top bar with instant suggestions; Enter searches all tickets.

### Ticket list
- **Views**, in the same order as Freshdesk: tickets I created, my new and open tickets, tickets I'm watching,
  undelivered messages, all tickets, all unresolved tickets; then unassigned, new, overdue, due today, open, pending,
  starred; trash and spam. Live counters.
- **Saved views**: save the current filters as a shared view.
- **Filters panel**: agents, statuses, priorities, types, tags, customer, created / closed period, resolution and first
  response due dates. **Sort** by date created, last modified, last customer response, due date, priority or status.
- **Card layout** with SLA badges (*New*, *First response overdue*, *Overdue*, *Customer responded*, *Pending*),
  a status line ("Customer responded 2 hours ago • Resolution due in 5 hours"), and priority / agent / status
  drop-downs you can change right from the list.
- **Bulk actions** bar (assign, status, tags, merge, delete) and **CSV export** of the current view.
- FreeScout's own folder pages (Unassigned, Mine, Starred…) open the equivalent view.

![Filters panel](screenshots/filters.png)

### Ticket
- Freshdesk-like **conversation**: "Name replied • 3 days ago (Mon 21 Sep 2026 13:43)", round avatars, notes in yellow,
  quoted history folded behind a "•••" button.
- **Properties panel** on the right: type, status, priority, agent, tags, resolution due date (editable), SLA summary,
  customer details and the customer's recent tickets. **Update** saves in place; closing a ticket takes you to the
  next one.
- **Reply editor** restyled after Freshdesk: tabs Reply / Note / Forward, recipients as chips, formatting toolbar,
  canned responses, attachments, drafts with edit / discard icons, and a send button with "Send and set as Pending /
  Closed / Open".
- **Toolbar**: reply, note, forward, close / reopen (ajax), star, merge, print, delete.
- **Split ticket**: move a message to a new ticket, with a note left in the original one.
- **Inline tags** field with suggestions and "Create …" (Tags module).
- **Toasts** instead of the green banners, with close button, "View" and "Undo".

![Ticket](screenshots/ticket.png)

### Dashboard and contacts
- **Dashboard**: tiles (unresolved, overdue, due today, open, pending, unassigned), today's figures compared with
  yesterday (received, resolved, average first response time, resolved within SLA), unresolved tickets per agent,
  undelivered e-mails, tickets created per hour of the day.
- **Contacts** page: searchable list of customers with their phones, links to their tickets, CSV export.

![Dashboard](screenshots/dashboard.png)

### Phone version, installable app and notifications
- **Phone version** (under 768 px), modelled on the Freshdesk Android app: title bar, views drawer, sort and filter
  sheets, ticket cards, infinite scroll, long press to select, bottom tab bar, full-screen editor and properties.
- **Installable app (PWA)**: on a phone, "Install app" / "Add to Home Screen" opens FreeScout full screen, with its own
  name and icon. No store, no closed-source app.
- **Web Push notifications** on phones and desktops, even when FreeScout is closed. They use FreeScout's own
  notification settings (the *Mobile* column of Profile › Notifications). Pure PHP implementation (VAPID + aes128gcm),
  no external service and no Composer dependency.

<p>
  <img src="screenshots/mobile-list.png" alt="Phone version: ticket list" width="300">
  &nbsp;
  <img src="screenshots/mobile-ticket.png" alt="Phone version: ticket" width="300">
</p>

### Languages
English and French. Other languages can be added with a `Resources/lang/<locale>.json` file (English key =>
translation), plus `Resources/lang/<locale>/labels.php` for the few words FreeScout translates with another meaning,
and optionally `Resources/lang/overrides/<locale>.php` to reword FreeScout's own strings (the French file turns
"conversation" into "ticket", like Freshdesk).

## Requirements

- FreeScout 1.8 or newer. Web Push needs PHP 7.3+ with the `openssl` extension.
- **HTTPS** for the installable app and push notifications (browsers require it).
- Tested with the Tags module; the Freshdesk-style *Type* and *Priority* fields are stored in the conversation data and
  filled by the [Freshdesk Import](https://github.com/altmenorg/freescout-freshdesk-import) module.

## Installation

1. Download the latest release and unzip it into the `Modules` folder of FreeScout: you get `Modules/Refresh`
   (the folder **must** have this name).
2. In FreeScout, **Manage › Modules**: activate **Refresh**.
3. Optional: **Manage › Settings › Refresh** to set your SLA, logo and app name.

To go back to the stock interface, deactivate the module. Its settings are kept.

**Upgrading from Modern UI 1.x** (this module's former name): deactivate Modern UI, delete `Modules/ModernUi`, install
Refresh as above. Its settings, shared views and push notification keys are taken over automatically.

## Settings

**Manage › Settings › Refresh**

| Setting | |
|---|---|
| **First response** | Hours to the first agent reply (default 24). Used by badges, views and the dashboard. Calendar hours, paused while a ticket is *Pending*. |
| **Resolution** | Hours to resolve a ticket (default 72). The due date of a ticket can also be changed by hand. |
| **Logo** | Image shown at the top of the left bar. Empty: the header logo (Customization module or FreeScout's own). |
| **App name / Short name** | Name of the installable app, and the one shown under its icon. |
| **App icon** | Square PNG, 512 px recommended. Empty: FreeScout's icons. |
| **Contact e-mail** | Given to the push services as the sender of the notifications. Empty: the first administrator. |

**Push notifications, per agent:** install the app (or allow notifications in the desktop browser when asked), then
tick the *Mobile* column in **Profile › Notifications**.

![Settings](screenshots/settings.png)

The push keys are created on first use in `storage/app/refresh/`. Keep this folder when moving servers: new keys
would silently cut every existing subscription.

## For module developers

Other modules can add an entry with their own icon to the left bar:

```php
\Eventy::addFilter('refresh.rail_items', function ($items) {
    $items[] = [
        'url'    => route('mymodule.page'),
        'label'  => __('My module'),
        'icon'   => asset(\Module::getPublicPath('mymodule').'/icons/mymodule.svg'), // SVG, drawn in the bar's color
        'active' => \Route::is('mymodule.*'),
        'order'  => 500, // optional, default 100
    ];
    return $items;
});
```

Links a module adds to FreeScout's top menu also appear in the bar, with a generic icon, when they are not declared
this way. The [Cobrowse module](https://github.com/altmenorg/freescout-cobrowse) is an example.

## Credits

Icons: [Crayons](https://github.com/freshworks/crayons) (MIT), [Tabler Icons](https://tabler.io/icons) (MIT) and
[Lucide](https://lucide.dev) (ISC, partly derived from Feather, MIT). Full notices in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md). No fonts are bundled.

Refresh is not affiliated with or endorsed by Freshworks. "Freshdesk" is a trademark of Freshworks Inc., used here
only to describe the look the interface is inspired by.

## License

[GNU AGPL v3](LICENSE), like FreeScout.
