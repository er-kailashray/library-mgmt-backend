<?php

namespace App\Http\Controllers;

use App\Services\Reminders\ReminderService;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function __construct(
        private readonly ReminderService $reminders,
    ) {}

    public function overdue(Request $request)
    {
        $result = $this->reminders->remindAllOverdue($this->library($request), now());

        return response()->json([
            'status' => true,
            'message' => $result['failed'] > 0
                ? "Sent {$result['sent']} reminder(s), {$result['failed']} failed."
                : "Sent {$result['sent']} reminder(s).",
            'data' => $result,
        ]);
    }
}
