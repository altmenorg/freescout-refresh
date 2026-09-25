<?php

namespace Modules\Refresh\Http\Controllers;

use App\Subscription;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Refresh\Services\Settings;
use Modules\Refresh\Services\WebPush;

/**
 * Installable app (PWA): manifest, service worker, and Web Push subscriptions of the agents' devices.
 * Notifications are sent by RefreshServiceProvider (hook subscription.process_events, "Mobile" channel).
 */
class PushController extends Controller
{
    /** Manifest served by a route (FreeScout's own site.webmanifest has no name, so the site is not installable). */
    public function manifest()
    {
        return response()->json([
            'id'               => '/',
            'name'             => Settings::appName(),
            'short_name'       => Settings::appShortName(),
            'lang'             => app()->getLocale(),
            'start_url'        => '/',
            'scope'            => '/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#ffffff',
            'theme_color'      => '#ffffff', // white status bar: Android draws dark, readable icons on it
            'icons'            => Settings::appIcons(),
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Service worker, served from the module rather than copied into FreeScout's public folder. The URL has no ".js"
     * extension on purpose: web servers often route such paths to static files only.
     */
    public function serviceWorker()
    {
        return response(file_get_contents(__DIR__.'/../../Resources/js/service-worker.js'), 200, [
            'Content-Type'           => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',  // allows the scope "/" although the script is under /refresh/
            'Cache-Control'          => 'no-cache',
        ]);
    }

    public function key()
    {
        return response()->json(['key' => WebPush::publicKey()]);
    }

    public function subscribe(Request $request)
    {
        $user = auth()->user();
        $sub = $request->input('subscription');
        if (!is_array($sub) || !WebPush::subscribe($user->id, $sub, $request->userAgent())) {
            return response()->json(['status' => 'error', 'msg' => __('Invalid subscription')]);
        }
        // First device of this agent: the "Mobile" column of the profile was never used (it is only enabled by this
        // module), so the agent's "Email" choices are copied; they can be changed in Profile › Notifications.
        if (!Subscription::where('user_id', $user->id)->where('medium', Subscription::MEDIUM_MOBILE)->exists()) {
            $events = Subscription::where('user_id', $user->id)->where('medium', Subscription::MEDIUM_EMAIL)->pluck('event');
            foreach ($events as $event) {
                Subscription::create(['user_id' => $user->id, 'medium' => Subscription::MEDIUM_MOBILE, 'event' => $event]);
            }
        }
        return response()->json(['status' => 'success']);
    }

    public function unsubscribe(Request $request)
    {
        if ($request->input('endpoint')) {
            WebPush::unsubscribe((string)$request->input('endpoint'));
        }
        return response()->json(['status' => 'success']);
    }

    public function test()
    {
        $n = WebPush::sendToUser(auth()->user()->id, [
            'title' => Settings::appName(),
            'body'  => __('Test notification: everything works.'),
            'url'   => '/',
            'tag'   => 'test',
            'icon'  => Settings::notificationIcon(),
        ]);
        return response()->json([
            'status' => $n ? 'success' : 'error',
            'sent'   => $n,
            'msg'    => $n ? __(':count device(s) notified', ['count' => $n]) : __('No subscribed device accepted the notification'),
        ]);
    }
}
