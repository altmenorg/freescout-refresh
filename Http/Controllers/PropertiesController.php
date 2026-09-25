<?php

namespace Modules\Refresh\Http\Controllers;

use App\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Refresh\Services\Views;

/**
 * Saves Freshdesk properties that FreeScout lacks (type, priority), stored in conversations.meta
 * (fd_type, fd_priority) like the Freshdesk import. Status, agent and tags go through FreeScout's native actions.
 */
class PropertiesController extends Controller
{
    public function save(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('update', $conversation);

        // Panel's "Update" (via ajax): the native agent / status actions called just before drop a flash message
        // ("Status updated") that would show up on the next page load; the panel already shows its own toast.
        if ($request->input('clear_flash')) {
            \Session::forget('flash_success_floating');
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : (json_decode((string)$conversation->meta, true) ?: []);
        // Optional fields: the ticket panel sends type + priority, the list menus only priority.
        if ($request->has('type')) {
            $type = trim((string)$request->input('type', ''));
            if ($type === '') {
                unset($meta['fd_type']);
            } else {
                $meta['fd_type'] = mb_substr($type, 0, 100);
            }
        }
        if ($request->has('priority')) {
            $priority = (int)$request->input('priority', 1);
            $meta['fd_priority'] = isset(Views::priorities()[$priority]) ? $priority : 1;
        }
        // Resolution due date manually edited (pencil on the SLA block); empty = back to automatic calculation (72h)
        if ($request->has('due')) {
            $due = trim((string)$request->input('due', ''));
            if ($due === '') {
                unset($meta['rf_due']);
            } else {
                try {
                    $meta['rf_due'] = \Carbon\Carbon::parse($due, config('app.timezone'))->setTimezone('UTC')->toIso8601String();
                } catch (\Exception $e) {
                    return response()->json(['status' => 'error', 'msg' => __('Invalid date')], 422);
                }
            }
        }
        $conversation->meta = $meta;
        $conversation->save();
        \Cache::forget('refresh_types');

        return response()->json(['status' => 'success', 'type' => $meta['fd_type'] ?? '', 'priority' => $meta['fd_priority'] ?? 1]);
    }
}
