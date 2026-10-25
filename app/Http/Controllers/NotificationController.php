<?php

namespace App\Http\Controllers;

use App\Models\OwnerNotification;
use Illuminate\Http\Request;

/**
 * The owner's Alerts inbox. Like the other /me endpoints, these sit outside the library and
 * subscription checks: alerts belong to the person, and an expired plan's alerts must stay readable.
 */
class NotificationController extends Controller
{
    private const PAGE_SIZE = 30;

    /** Newest first; pass `before` (an id) to load the page after it. */
    public function index(Request $request)
    {
        $validated = $request->validate(['before' => 'nullable|integer|min:1']);
        $user = $request->user();

        $items = $user->ownerNotifications()
            ->when($validated['before'] ?? null, fn ($query, $before) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'items' => $items->take(self::PAGE_SIZE)->map(fn (OwnerNotification $notification) => $notification->present())->values(),
                'has_more' => $items->count() > self::PAGE_SIZE,
                'unread_count' => $this->unread($request),
            ],
        ]);
    }

    /** For the badge on the Alerts tab. */
    public function unreadCount(Request $request)
    {
        return response()->json([
            'status' => true,
            'data' => ['unread_count' => $this->unread($request)],
        ]);
    }

    /** Marks the given alerts read, or all of them when no ids are sent. */
    public function markRead(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'nullable|array|max:100',
            'ids.*' => 'integer',
        ]);

        $request->user()->ownerNotifications()
            ->whereNull('read_at')
            ->when($validated['ids'] ?? null, fn ($query, $ids) => $query->whereIn('id', $ids))
            ->update(['read_at' => now()]);

        return response()->json([
            'status' => true,
            'data' => ['unread_count' => $this->unread($request)],
        ]);
    }

    private function unread(Request $request): int
    {
        return $request->user()->ownerNotifications()->whereNull('read_at')->count();
    }
}
