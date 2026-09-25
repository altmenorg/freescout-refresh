<?php

namespace Modules\Refresh\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Refresh\Services\Views;

/**
 * Shared saved views ("Shared" section of the views menu, like Freshdesk):
 * a combination of base view + filters + sort, visible to the whole team. Storage: `refresh_saved_views` option.
 */
class SavedViewsController extends Controller
{
    public function store(Request $request)
    {
        $label = trim((string)$request->input('label', ''));
        $view = (string)$request->input('view', 'all');
        if ($label === '' || !isset(Views::definitions()[$view])) {
            return response()->json(['status' => 'error', 'msg' => __('Invalid name or view')], 422);
        }
        parse_str((string)$request->input('query', ''), $query);
        unset($query['page'], $query['sv']);

        $list = Views::savedViews();
        $id = substr(md5(uniqid('', true)), 0, 8);
        $list[] = [
            'id'      => $id,
            'label'   => mb_substr($label, 0, 60),
            'view'    => $view,
            'query'   => $query,
            'user_id' => auth()->id(),
            'created' => date('c'),
        ];
        \App\Option::set('refresh_saved_views', json_encode(array_values($list)));
        Views::forgetCounts();

        return response()->json(['status' => 'success', 'url' => Views::savedViewUrl((int)$request->input('mailbox_id', 1), end($list))]);
    }

    public function destroy(Request $request, $view_id)
    {
        $user = auth()->user();
        $list = Views::savedViews();
        $kept = [];
        $found = false;
        foreach ($list as $sv) {
            if ($sv["id"] === $view_id && ($user->isAdmin() || (int)$sv['user_id'] === (int)$user->id)) {
                $found = true;
                continue;
            }
            $kept[] = $sv;
        }
        if (!$found) {
            return response()->json(['status' => 'error', 'msg' => __('View not found or not allowed')], 403);
        }
        \App\Option::set('refresh_saved_views', json_encode(array_values($kept)));
        Views::forgetCounts();

        return response()->json(['status' => 'success']);
    }
}
