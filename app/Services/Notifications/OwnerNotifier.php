<?php

namespace App\Services\Notifications;

use App\Models\Library;
use App\Models\OwnerNotification;
use App\Models\User;
use App\Notifications\Push\PushNotifier;

/**
 * Alerts for library owners: saved to their in-app inbox (always, even with push off or no
 * device registered), then pushed to their devices.
 */
class OwnerNotifier
{
    public function __construct(private readonly PushNotifier $push) {}

    /**
     * @param  string|null  $url  screen the app opens on tap, e.g. "/payments"
     * @param  string|null  $dedupeKey  an alert with a key already in the owner's inbox isn't saved again (the push still goes out)
     * @return bool true when at least one device accepted the push
     */
    public function notify(User $user, ?Library $library, string $type, string $title, string $body, ?string $url = null, ?string $dedupeKey = null): bool
    {
        $attributes = [
            'library_id' => $library?->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ];

        if ($dedupeKey === null) {
            $user->ownerNotifications()->create($attributes);
        } else {
            $user->ownerNotifications()->firstOrCreate(['dedupe_key' => $dedupeKey], $attributes);
        }

        return $this->push->sendToUser($user, $title, $body, $url ? ['url' => $url] : []);
    }
}
