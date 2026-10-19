<?php

namespace App\Services\Reminders;

use App\Models\Library;
use App\Models\Member;
use App\Notifications\DTO\MessageDTO;
use App\Notifications\Services\NotificationService;
use App\Services\Dues\MemberDue;
use App\Services\Dues\MemberDueService;
use App\Services\Dues\UpcomingRenewal;
use Carbon\CarbonInterface;

class ReminderService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly MemberDueService $dues,
    ) {}

    /**
     * Sends one SMS reminder to the given member - overdue (with the amount owed)
     * if they're overdue, a renewal nudge if their allocation is about to lapse,
     * otherwise a generic reminder. Throws if the SMS provider fails (e.g. bad
     * Twilio credentials) - the caller decides how to report that.
     */
    public function remindMember(Library $library, Member $member, CarbonInterface $today): void
    {
        $due = $this->dues->overdueMembers($library->id, $today)->get($member->id);

        if ($due) {
            $message = $this->buildOverdueMessage($library, $member->name, $due->amount);
        } else {
            $upcoming = $this->dues->upcomingRenewals($library->id, $today, $this->renewalWindowDays())->get($member->id);
            $message = $upcoming
                ? $this->buildRenewalMessage($library, $member->name, $upcoming->amount, $upcoming->renewalDate)
                : "Dear {$member->name}, this is a reminder from {$library->library_name} to renew your membership soon.";
        }

        $this->notifications->send('sms', new MessageDTO(to: $this->formatPhone($member->phone), message: $message));
    }

    /**
     * Reminds every currently overdue member in the library. Each send is
     * independent - one failing (e.g. an invalid number) doesn't stop the rest.
     *
     * @return array{sent: int, failed: int, failed_members: array<int, string>}
     */
    public function remindAllOverdue(Library $library, CarbonInterface $today): array
    {
        $overdue = $this->dues->overdueMembers($library->id, $today);

        return $this->sendBulk($overdue, fn ($due, Member $member) => $this->buildOverdueMessage($library, $member->name, $due->amount));
    }

    /**
     * Reminds every member whose allocation renews within the configured window
     * but hasn't lapsed yet - a nudge before they become overdue, not after.
     *
     * @return array{sent: int, failed: int, failed_members: array<int, string>}
     */
    public function remindAllUpcomingRenewals(Library $library, CarbonInterface $today): array
    {
        $upcoming = $this->dues->upcomingRenewals($library->id, $today, $this->renewalWindowDays());

        return $this->sendBulk($upcoming, fn (UpcomingRenewal $renewal, Member $member) => $this->buildRenewalMessage($library, $member->name, $renewal->amount, $renewal->renewalDate));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MemberDue|UpcomingRenewal>  $entries
     * @param  callable(MemberDue|UpcomingRenewal, Member): string  $buildMessage
     * @return array{sent: int, failed: int, failed_members: array<int, string>}
     */
    private function sendBulk($entries, callable $buildMessage): array
    {
        $sent = 0;
        $failedMembers = [];

        foreach ($entries as $entry) {
            $member = Member::find($entry->memberId);
            if (! $member) {
                continue;
            }

            try {
                $this->notifications->send('sms', new MessageDTO(
                    to: $this->formatPhone($member->phone),
                    message: $buildMessage($entry, $member),
                ));
                $sent++;
            } catch (\Throwable) {
                $failedMembers[] = $member->name;
            }
        }

        return ['sent' => $sent, 'failed' => count($failedMembers), 'failed_members' => $failedMembers];
    }

    private function buildOverdueMessage(Library $library, string $memberName, float $dueAmount): string
    {
        $amount = number_format($dueAmount, 0);

        return "Dear {$memberName}, your library fee of Rs.{$amount} is overdue. Please pay at the earliest. - {$library->library_name}";
    }

    private function buildRenewalMessage(Library $library, string $memberName, float $amount, CarbonInterface $renewalDate): string
    {
        $amountText = number_format($amount, 0);
        $dateText = $renewalDate->format('d M');

        return "Dear {$memberName}, your library membership renews on {$dateText} (Rs.{$amountText}). Renew in time to keep your seat. - {$library->library_name}";
    }

    private function renewalWindowDays(): int
    {
        return (int) config('library.dues.upcoming_renewal_window_days');
    }

    private function formatPhone(string $phone): string
    {
        return str_starts_with($phone, '+') ? $phone : '+91'.$phone;
    }
}
