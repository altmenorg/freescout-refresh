<?php

/*
 * Toast after sending a reply: FreeScout says "Email Sent · View or Undo", but the e-mail is queued for
 * Conversation::UNDO_TIMOUT seconds and the toast only shows while it can still be undone. Refresh says "Sending in
 * N s · View or Undo" instead (RefreshServiceProvider::overrideSendToast()), in the languages Refresh supports (README,
 * "Languages"); other locales keep FreeScout's own text. A new language adds its line here with its other files.
 * [sending (%d = seconds), view, or, undo]
 */
return [
    'en' => ['Sending in %d s', 'View', 'or', 'Undo'],
    'fr' => ['Envoi dans %d s', 'Voir', 'ou', 'Annuler'],
    'de' => ['Wird in %d s gesendet', 'Ansehen', 'oder', 'Rückgängig'],
];
