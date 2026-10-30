<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlockSeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $library = $this->attributes->get('library');

        return [
            // Leave out (or send empty) to block the seat in every batch.
            'batch_ids' => ['nullable', 'array'],
            'batch_ids.*' => ['integer', Rule::exists('batches', 'id')->where('library_id', $library->id)->whereNull('deleted_at')],
            // Defaults to today; a block can be set up ahead, up to a year.
            'starts_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString()],
            // Last blocked day; leave out for "until I unblock it".
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.($this->input('starts_on') ?: 'today')],
            'reason' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'starts_on.after_or_equal' => "A block can't start in the past.",
            'ends_on.after_or_equal' => "The last blocked day can't be before the first.",
        ];
    }
}
