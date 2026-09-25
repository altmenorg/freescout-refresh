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
    // Installable PWA + Web Push: public manifest (read before login), the rest reserved for agents
    Route::get('/refresh/pwa-manifest', 'PushController@manifest')->name('refresh.pwa.manifest');
    Route::get('/refresh/service-worker', 'PushController@serviceWorker')->name('refresh.pwa.sw');
    Route::get('/refresh/push/key', ['uses' => 'PushController@key', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.push.key');
    Route::post('/refresh/push/subscribe', ['uses' => 'PushController@subscribe', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.push.subscribe');
    Route::post('/refresh/push/unsubscribe', ['uses' => 'PushController@unsubscribe', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.push.unsubscribe');
    Route::post('/refresh/push/test', ['uses' => 'PushController@test', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.push.test');
    // "Split ticket" (Freshdesk): the message moves into a new ticket
    Route::post('/refresh/thread/{thread_id}/split', ['uses' => 'SplitController@split', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.split');
    Route::post('/refresh/conversation/{id}/properties', ['uses' => 'PropertiesController@save', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('refresh.properties');
});
