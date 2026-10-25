<?php

namespace App\Services\Subscriptions;

use App\Models\Subscription;
use App\Notifications\DTO\MessageDTO;
use App\Notifications\Push\PushNotifier;
use App\Notifications\Services\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily SMS + push nudges to library owners about their trial/plan: 7, 3 and 1 day before it ends,
 * when it ends (grace starts) and when the library goes read-only. Each is sent at most once per
 * trial/plan period; it counts as sent once either channel delivers, and if both fail it isn't
 * recorded, so the next run retries it.
 */
class SubscriptionReminderService
{
    /** Days-left thresholds, most urgent first. */
    private const ENDING_SOON = [1 => 'ends_in_1', 3 => 'ends_in_3', 7 => 'ends_in_7'];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly NotificationService $notifications,
        private readonly PushNotifier $push,
    ) {}

    /** @return int how many reminders were sent */
    public function sendDue(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $sent = 0;

        Subscription::with(['library.user', 'plan'])->chunkById(100, function ($batch) use ($now, &$sent) {
            foreach ($batch as $subscription) {
                $kind = $this->dueKind($subscription, $now);
                if ($kind && ! $this->alreadySent($subscription, $kind) && $this->send($subscription, $kind)) {
                    $sent++;
                }
            }
        });

        return $sent;
    }

    /** The one reminder that applies right now, if any. */
    private function dueKind(Subscription $subscription, CarbonInterface $now): ?string
    {
        return match ($this->subscriptions->stateOf($subscription, $now)) {
            SubscriptionState::Grace => 'ended',
            SubscriptionState::Expired => 'read_only',
            default => $this->endingSoonKind((int) $now->copy()->startOfDay()->diffInDays($subscription->ends_at->copy()->startOfDay(), false)),
        };
    }

    /** After a few missed runs, only the most relevant threshold is sent (not a late "7 days" too). */
    private function endingSoonKind(int $daysLeft): ?string
    {
        foreach (self::ENDING_SOON as $threshold => $kind) {
            if ($daysLeft <= $threshold) {
                return $kind;
            }
        }

        return null;
    }

    private function alreadySent(Subscription $subscription, string $kind): bool
    {
        return DB::table('subscription_reminders')
            ->where('subscription_id', $subscription->id)
            ->where('kind', $kind)
            ->where('period_ends_at', $subscription->ends_at)
            ->exists();
    }

    private function send(Subscription $subscription, string $kind): bool
    {
        $owner = $subscription->library->user;
        $smsSent = true;

        try {
            $this->notifications->send('sms', new MessageDTO(
                to: str_starts_with($owner->phone, '+') ? $owner->phone : '+91'.$owner->phone,
                message: 'Librum: '.$this->message($subscription, $kind).' Open Librum > Settings > Plan & Billing.',
            ));
        } catch (\Throwable $e) {
            Log::warning('Subscription reminder failed', ['subscription_id' => $subscription->id, 'kind' => $kind, 'error' => $e->getMessage()]);
            $smsSent = false;
        }

        $pushSent = $this->push->sendToUser($owner, $this->title($subscription, $kind), ucfirst($this->message($subscription, $kind)), ['url' => '/subscription']);

        if (! $smsSent && ! $pushSent) {
            return false;
        }

        DB::table('subscription_reminders')->insert([
            'subscription_id' => $subscription->id,
            'kind' => $kind,
            'period_ends_at' => $subscription->ends_at,
            'sent_at' => now(),
        ]);

        return true;
    }

    /** Shared by both channels; the SMS adds the "Librum:" prefix and where to go in the app. */
    private function message(Subscription $subscription, string $kind): string
    {
        $library = $subscription->library->library_name;
        $what = $this->what($subscription);
        $ends = $subscription->ends_at->format('j M Y');
        $graceDays = (int) config('subscription.grace_days');

        return match ($kind) {
            'ends_in_7', 'ends_in_3' => "your {$what} for {$library} ends on {$ends}. Choose a plan to keep full access.",
            'ends_in_1' => "your {$what} for {$library} ends tomorrow ({$ends}). Subscribe today to avoid interruption.",
            'ended' => "your {$what} for {$library} has ended. You have {$graceDays} days of full access left before the app turns read-only.",
            'read_only' => "{$library} is now read-only - your data is safe and viewable, but you can't add or change anything until you subscribe.",
        };
    }

    private function title(Subscription $subscription, string $kind): string
    {
        $what = ucfirst($this->what($subscription));

        return match ($kind) {
            'ends_in_7', 'ends_in_3' => "{$what} ends on {$subscription->ends_at->format('j M')}",
            'ends_in_1' => "{$what} ends tomorrow",
            'ended' => "{$what} has ended",
            'read_only' => 'Your library is now read-only',
        };
    }

    private function what(Subscription $subscription): string
    {
        return $subscription->plan ? "{$subscription->plan->name} plan" : 'free trial';
    }
}
