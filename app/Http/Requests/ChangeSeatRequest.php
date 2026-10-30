<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeSeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $library = $this->attributes->get('library');

        return [
            // The batch whose seat is changing; the member keeps the batch, only the seat moves.
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->where('library_id', $library->id)->whereNull('deleted_at')],
            'seat_id' => ['required', 'integer', Rule::exists('seats', 'id')->where('library_id', $library->id)->whereNull('deleted_at')],
        ];
    }
}
