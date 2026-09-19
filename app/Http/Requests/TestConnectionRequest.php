<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:30'],
            'text' => ['nullable', 'string', 'max:500'],
        ];
    }
}
