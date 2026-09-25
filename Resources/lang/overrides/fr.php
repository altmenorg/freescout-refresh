<?php

/*
 * French vocabulary overrides of FreeScout's own strings (conversation -> ticket, etc.), Freshdesk wording.
 * One file per locale; keys are FreeScout's JSON translation keys prefixed with "*.".
 */

return [
    '*.Active'                         => 'Ouvert',
    '*.active'                         => 'ouverts',
    '*.Mine'                           => 'Mes tickets',
    '*.Assigned'                       => 'Assignés',
    '*.Conversation'                   => 'Ticket',
    '*.Conversations'                  => 'Tickets',
    '*.:count conversations'           => ':count tickets',
    '*.New Conversation'               => 'Nouveau ticket',
    '*.View conversation'              => 'Voir le ticket',
    '*.There are no conversations here' => 'Aucun ticket ici',
    '*.Active Conversations'           => 'Tickets ouverts',
    '*.Waiting Since'                  => 'Depuis',
    '*.Assigned To'                    => 'Agent',
    '*.Mailbox'                        => 'Tous les tickets',
    '*.Mailbox Settings'               => 'Configuration',
    // toasts after sending (ConversationsController::ajax send_reply): FreeScout's fr.json leaves "View" and "Undo" in English
    '*.:%tag_start%Email Sent:%tag_end% :%undo_start%Undo:%a_end%'
        => ':%tag_start%E-mail envoyé:%tag_end% :%undo_start%Annuler:%a_end%',
    '*.:%tag_start%Email Sent:%tag_end% :%view_start%View:%a_end% or :%undo_start%Undo:%a_end%'
        => ':%tag_start%E-mail envoyé:%tag_end% :%view_start%Voir:%a_end% ou :%undo_start%Annuler:%a_end%',
    '*.:%tag_start%Conversation created:%tag_end% :%view_start%View:%a_end% or :%undo_start%Undo:%a_end%'
        => ':%tag_start%Ticket créé:%tag_end% :%view_start%Voir:%a_end% ou :%undo_start%Annuler:%a_end%',
    '*.:%tag_start%Note added:%tag_end% :%view_start%View:%a_end%'
        => ':%tag_start%Note ajoutée:%tag_end% :%view_start%Voir:%a_end%',
];
