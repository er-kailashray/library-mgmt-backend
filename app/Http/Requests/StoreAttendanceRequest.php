<?php

namespace App\Http\Requests;

use App\Services\Attendance\AttendanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $library = $this->attributes->get('library');

        return [
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->where('library_id', $library->id)],
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->where('library_id', $library->id)->whereNull('deleted_at')],
            // Only the last few days can be changed (AttendanceService::EDITABLE_DAYS).
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.AttendanceService::earliestEditableDate()->toDateString()],
            // Clearing a mark needs no status.
            'status' => [$this->is('api/attendance/clear') ? 'nullable' : 'required', Rule::in(['present', 'absent'])],
            // When they arrived, "HH:MM". Defaults to now for today; required for an earlier day.
            'check_in_at' => ['nullable', 'date_format:H:i'],
        ];
    }
}
