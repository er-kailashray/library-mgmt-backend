<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $library = $this->attributes->get('library');

        return [
            // Member must belong to the owner's library
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->where('library_id', $library->id)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_mode' => ['nullable', Rule::in(array_keys(config('library.payment_modes')))],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
