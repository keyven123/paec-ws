<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class BroadcastingAuthController extends Controller
{
    /**
     * Authorize a private/presence channel subscription for a customer.
     *
     * The auth:api middleware has already resolved the authenticated
     * App\Models\User onto the request, so Broadcast::auth() will run the
     * channel callbacks in routes/channels.php against that user and return a
     * signed Reverb/Pusher authentication payload.
     */
    public function customer(Request $request)
    {
        return Broadcast::auth($request);
    }

    /**
     * Authorize a private/presence channel subscription for an admin/merchant.
     * The auth:admin guard resolves an App\Models\AdminUser onto the request.
     */
    public function admin(Request $request)
    {
        $request->setUserResolver(fn () => auth('admin')->user());

        return Broadcast::auth($request);
    }
}
