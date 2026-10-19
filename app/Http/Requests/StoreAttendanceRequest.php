<?php

namespace App\Http\Requests;

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
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->where('library_id', $library->id)],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['required', Rule::in(['present', 'absent'])],
        ];
    }
}
