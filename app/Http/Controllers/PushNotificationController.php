<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePushTokenRequest;
use App\Models\PushToken;
use Illuminate\Http\Request;

/**
 * The owner's devices for push notifications and their on/off preference.
 * Like the other /me endpoints, these sit outside the library and subscription checks.
 */
class PushNotificationController extends Controller
{
    public function storeToken(StorePushTokenRequest $request)
    {
        // A device that changes hands (another owner signs in on it) moves to the new account.
        PushToken::updateOrCreate(
            ['token' => $request->validated('token')],
            ['user_id' => $request->user()->id, 'platform' => $request->validated('platform')],
        );

        return response()->json(['status' => true, 'message' => 'Device registered']);
    }

    public function removeToken(Request $request)
    {
        $request->validate(['token' => 'required|string|max:255']);

        $request->user()->pushTokens()->where('token', $request->token)->delete();

        return response()->json(['status' => true, 'message' => 'Device removed']);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);

        $user = $request->user();
        $user->update(['push_notifications_enabled' => $validated['enabled']]);

        return response()->json([
            'status' => true,
            'message' => $validated['enabled'] ? 'Notifications turned on' : 'Notifications turned off',
            'data' => ['push_notifications_enabled' => $user->push_notifications_enabled],
        ]);
    }
}
