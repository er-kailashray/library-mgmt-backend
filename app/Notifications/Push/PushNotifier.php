<?php

namespace App\Notifications\Push;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications to an owner's devices through the Expo push service.
 * Tokens Expo reports as no longer registered (app uninstalled, etc.) are deleted.
 */
class PushNotifier
{
    /** Expo accepts at most 100 messages per request. */
    private const CHUNK = 100;

    /**
     * @param  array<string, mixed>  $data  delivered to the app; `url` is the screen opened on tap
     * @return bool true when at least one device accepted the notification
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        // A freshly created model may not have the column loaded yet; the database default is "on".
        if ($user->push_notifications_enabled === false) {
            return false;
        }

        $tokens = $user->pushTokens()->pluck('token');
        if ($tokens->isEmpty()) {
            return false;
        }

        $delivered = false;

        foreach ($tokens->chunk(self::CHUNK) as $chunk) {
            $messages = $chunk->values()->map(fn (string $token) => [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'data' => (object) $data,
                'sound' => 'default',
                'priority' => 'high',
                'channelId' => 'default',
            ])->all();

            try {
                $response = Http::acceptJson()
                    ->when(config('services.expo.access_token'), fn ($request, $token) => $request->withToken($token))
                    ->timeout(10)
                    ->post(config('services.expo.push_url'), $messages)
                    ->throw();
            } catch (\Throwable $e) {
                Log::warning('Push notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

                continue;
            }

            // Tickets come back in the same order as the messages.
            foreach ($response->json('data', []) as $i => $ticket) {
                if (($ticket['status'] ?? null) === 'ok') {
                    $delivered = true;
                } elseif (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    PushToken::where('token', $messages[$i]['to'])->delete();
                } else {
                    Log::warning('Push ticket error', ['user_id' => $user->id, 'ticket' => $ticket]);
                }
            }
        }

        return $delivered;
    }
}
