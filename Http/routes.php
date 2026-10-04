<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\Refresh\Http\Controllers'], function () {
    Route::get('/mailbox/{mailbox_id}/tickets/{view}/export', ['uses' => 'TicketsController@export', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.tickets.export');
    Route::get('/mailbox/{mailbox_id}/tickets/{view?}', ['uses' => 'TicketsController@index', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.tickets');
    // "Tickets" rail entry (Freshdesk: /a/tickets): last viewed view, remembered in a cookie by TicketsController@index
    Route::get('/tickets', ['uses' => 'TicketsController@last', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.tickets.last');
    Route::get('/contacts', ['uses' => 'ContactsController@index', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.contacts');
    Route::get('/contacts/export', ['uses' => 'ContactsController@export', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.contacts.export');
    Route::get('/refresh/search', ['uses' => 'TicketsController@quickSearch', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.search');
    Route::post('/refresh/views', ['uses' => 'SavedViewsController@store', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.views.store');
    Route::post('/refresh/views/{view_id}/delete', ['uses' => 'SavedViewsController@destroy', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.views.destroy');
    // "Split ticket" (Freshdesk): the message moves into a new ticket
    Route::post('/refresh/thread/{thread_id}/split', ['uses' => 'SplitController@split', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.split');
    // New ticket on behalf of a contact (no e-mail sent) and the contact panel of the New ticket / Send an e-mail pages
    Route::post('/refresh/new-ticket', ['uses' => 'NewTicketController@store', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.new_ticket');
    Route::get('/refresh/contact-panel', ['uses' => 'NewTicketController@contactPanel', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.contact_panel');
    Route::post('/refresh/conversation/{id}/properties', ['uses' => 'PropertiesController@save', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.properties');
});
