<?php

namespace Modules\Refresh\Http\Controllers;

use App\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Refresh\Services\Forward;

/**
 * Forward within the ticket (Services\Forward, Public/js/forward.js): FreeScout's forward form posts here instead
 * of the native send_reply when the setting is on.
 */
class ForwardController extends Controller
{
    public function send(Request $request, $id)
    {
        $conversation = Conversation::find((int)$id);
        $user = auth()->user();
        if (!Forward::enabled() || !$conversation || !$user->can('view', $conversation)) {
            return response()->json(['status' => 'error', 'msg' => __('Not enough permissions')]);
        }

        return response()->json(Forward::send($request, $conversation, $user));
    }
}
