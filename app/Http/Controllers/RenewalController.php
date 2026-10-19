<?php

namespace App\Http\Controllers;

use App\Services\Dues\MemberDueService;
use App\Services\Dues\UpcomingRenewal;
use App\Services\Reminders\ReminderService;
use Illuminate\Http\Request;

class RenewalController extends Controller
{
    public function __construct(
        private readonly MemberDueService $dues,
        private readonly ReminderService $reminders,
    ) {}

    public function index(Request $request)
    {
        $library = $this->library($request);
        $windowDays = (int) config('library.dues.upcoming_renewal_window_days');

        $renewals = $this->dues->upcomingRenewals($library->id, now(), $windowDays)
            ->map(fn (UpcomingRenewal $renewal) => [
                'member_id' => $renewal->memberId,
                'name' => $renewal->memberName,
                'phone' => $renewal->memberPhone,
                'amount' => $renewal->amount,
                'renewal_date' => $renewal->renewalDate->toDateString(),
                'days_until_renewal' => $renewal->daysUntilRenewal,
            ])
            ->sortBy('days_until_renewal')
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'window_days' => $windowDays,
                'renewals' => $renewals,
            ],
        ]);
    }

    public function remindAll(Request $request)
    {
        $result = $this->reminders->remindAllUpcomingRenewals($this->library($request), now());

        return response()->json([
            'status' => true,
            'message' => $result['failed'] > 0
                ? "Sent {$result['sent']} reminder(s), {$result['failed']} failed."
                : "Sent {$result['sent']} reminder(s).",
            'data' => $result,
        ]);
    }
}
