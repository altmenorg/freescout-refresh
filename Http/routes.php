<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\ModernUi\Http\Controllers'], function () {
    Route::get('/mailbox/{mailbox_id}/tickets/{view}/export', ['uses' => 'TicketsController@export', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.tickets.export');
    Route::get('/mailbox/{mailbox_id}/tickets/{view?}', ['uses' => 'TicketsController@index', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.tickets');
    // "Tickets" rail entry (Freshdesk: /a/tickets): last viewed view, remembered in a cookie by TicketsController@index
    Route::get('/tickets', ['uses' => 'TicketsController@last', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.tickets.last');
    Route::get('/contacts', ['uses' => 'ContactsController@index', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.contacts');
    Route::get('/contacts/export', ['uses' => 'ContactsController@export', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.contacts.export');
    Route::get('/modernui/search', ['uses' => 'TicketsController@quickSearch', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.search');
    Route::post('/modernui/views', ['uses' => 'SavedViewsController@store', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.views.store');
    Route::post('/modernui/views/{view_id}/delete', ['uses' => 'SavedViewsController@destroy', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.views.destroy');
    // Installable PWA + Web Push: public manifest (read before login), the rest reserved for agents
    Route::get('/modernui/pwa-manifest', 'PushController@manifest')->name('modernui.pwa.manifest');
    Route::get('/modernui/service-worker', 'PushController@serviceWorker')->name('modernui.pwa.sw');
    Route::get('/modernui/push/key', ['uses' => 'PushController@key', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.push.key');
    Route::post('/modernui/push/subscribe', ['uses' => 'PushController@subscribe', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.push.subscribe');
    Route::post('/modernui/push/unsubscribe', ['uses' => 'PushController@unsubscribe', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.push.unsubscribe');
    Route::post('/modernui/push/test', ['uses' => 'PushController@test', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.push.test');
    // "Split ticket" (Freshdesk): the message moves into a new ticket
    Route::post('/modernui/thread/{thread_id}/split', ['uses' => 'SplitController@split', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.split');
    Route::post('/modernui/conversation/{id}/properties', ['uses' => 'PropertiesController@save', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('modernui.properties');
});
