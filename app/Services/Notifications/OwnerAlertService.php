<?php

namespace App\Services\Notifications;

use App\Models\Batch;
use App\Models\Library;
use App\Models\OwnerNotification;
use App\Notifications\Push\PushNotifier;
use App\Services\Dues\MemberDue;
use App\Services\Dues\MemberDueService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled alerts for library owners: the morning dues summary, renewals due today, and a
 * heads-up shortly before each batch starts. Every alert is sent at most once per library/batch
 * per day, so re-running a job doesn't notify twice. The morning ones also go to the owner's
 * Alerts inbox; the batch heads-up only matters in the moment, so it is push only.
 */
class OwnerAlertService
{
    /** Minutes before a batch's start time that its alert goes out. */
    public const BATCH_LEAD_MINUTES = 5;

    public function __construct(
        private readonly MemberDueService $dues,
        private readonly PushNotifier $push,
        private readonly OwnerNotifier $notifier,
    ) {}

    /** @return int how many pushes were delivered */
    public function sendMorningDigests(CarbonInterface $now): int
    {
        $sent = 0;

        Library::with('user')->has('user')->chunkById(100, function ($libraries) use ($now, &$sent) {
            foreach ($libraries as $library) {
                $sent += $this->sendRenewalsDueToday($library, $now) + $this->sendDuesSummary($library, $now);
            }
        });

        return $sent;
    }

    /** @return int how many notifications were sent */
    public function sendBatchStartAlerts(CarbonInterface $now): int
    {
        $startsAt = $now->copy()->addMinutes(self::BATCH_LEAD_MINUTES);
        $minute = $startsAt->format('H:i');
        $sent = 0;

        $batches = Batch::with('library.user')
            ->whereTime('start_time', '>=', "{$minute}:00")
            ->whereTime('start_time', '<=', "{$minute}:59")
            ->withCount(['allocations as members_count' => fn ($query) => $query->where('status', 'active')])
            ->get();

        foreach ($batches as $batch) {
            $owner = $batch->library?->user;
            if (! $owner || ! $this->once("batch-start:{$batch->id}:{$startsAt->toDateString()}")) {
                continue;
            }

            $members = $batch->members_count === 1 ? '1 member' : "{$batch->members_count} members";

            $sent += (int) $this->push->sendToUser(
                $owner,
                "{$batch->name} starts in ".self::BATCH_LEAD_MINUTES.' minutes',
                "{$startsAt->format('g:i A')} · {$members} enrolled. Tap to mark attendance.",
                ['url' => '/attendance'],
            );
        }

        return $sent;
    }

    private function sendRenewalsDueToday(Library $library, CarbonInterface $now): int
    {
        // A renewal on today's date is the first day it is due.
        $today = $this->overdue($library, $now)->filter(fn (MemberDue $due) => $due->daysOverdue === 0);
        if ($today->isEmpty() || ! $this->once("renewals-today:{$library->id}:{$now->toDateString()}")) {
            return 0;
        }

        $count = $today->count();
        $names = $today->pluck('memberName')->take(2)->implode(', ');
        $more = $count > 2 ? ' and '.($count - 2).' more' : '';

        return (int) $this->notifier->notify(
            $library->user,
            $library,
            OwnerNotification::TYPE_RENEWALS_DUE,
            $count === 1 ? '1 renewal due today' : "{$count} renewals due today",
            "{$names}{$more} · ₹".number_format($today->sum('amount')).' to collect',
            '/payments',
            "renewals-today:{$library->id}:{$now->toDateString()}",
        );
    }

    private function sendDuesSummary(Library $library, CarbonInterface $now): int
    {
        // Renewals due today were just announced separately, so this covers the older dues.
        $overdue = $this->overdue($library, $now)->filter(fn (MemberDue $due) => $due->daysOverdue > 0);
        $windowDays = (int) config('library.dues.upcoming_renewal_window_days');
        $upcoming = $this->dues->upcomingRenewals($library->id, $now, $windowDays);

        if (($overdue->isEmpty() && $upcoming->isEmpty()) || ! $this->once("dues-summary:{$library->id}:{$now->toDateString()}")) {
            return 0;
        }

        $parts = [];
        if ($overdue->isNotEmpty()) {
            $parts[] = ($overdue->count() === 1 ? '1 member' : "{$overdue->count()} members").' overdue';
            $parts[] = '₹'.number_format($overdue->sum('amount')).' pending';
        }
        if ($upcoming->isNotEmpty()) {
            $parts[] = ($upcoming->count() === 1 ? '1 renewal' : "{$upcoming->count()} renewals")." in the next {$windowDays} days";
        }

        return (int) $this->notifier->notify(
            $library->user,
            $library,
            OwnerNotification::TYPE_DUES_SUMMARY,
            "Today at {$library->library_name}",
            implode(' · ', $parts),
            $overdue->isNotEmpty() ? '/payments' : '/renewals',
            "dues-summary:{$library->id}:{$now->toDateString()}",
        );
    }

    /** @return Collection<int, MemberDue> */
    private function overdue(Library $library, CarbonInterface $now): Collection
    {
        return $this->dues->overdueMembers($library->id, $now);
    }

    /** True the first time a key is seen (kept for two days); false after that. */
    private function once(string $key): bool
    {
        return Cache::add("push-alert:{$key}", true, now()->addDays(2));
    }
}
