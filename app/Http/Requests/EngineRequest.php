<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class EngineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route()->getActionMethod() === 'send' && ! $this->exists('idempotency_key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'startSession' => [
                'id' => ['required', 'string', 'regex:/\A[a-z][a-z0-9-]{1,46}\z/'],
                'mode' => ['required', 'string', Rule::in(['qr', 'pairing'])],
                'phone' => ['required_if:mode,pairing', 'nullable', 'string', 'max:32'],
            ],
            'send' => [
                'phone' => ['required', 'string', 'max:32'],
                'text' => ['required', 'string', 'max:10000'],
                'idempotency_key' => ['required', 'string', 'max:120'],
            ],
            'logout' => [
                'logout' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_bool($value)) {
                        $fail('Nilai logout harus berupa boolean JSON.');
                    }
                }],
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'id.*' => 'ID sesi WhatsApp tidak valid.',
            'mode.*' => 'Pilih mode QR atau pairing WhatsApp.',
            'phone.*' => 'Nomor WhatsApp tidak valid.',
            'text.*' => 'Isi pesan WhatsApp tidak valid.',
            'idempotency_key.*' => 'Kunci pengiriman WhatsApp tidak valid.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $field = $validator->errors()->keys()[0];
        $code = match ($field) {
            'id' => 'invalid_session',
            'idempotency_key' => 'invalid_idempotency_key',
            default => 'invalid_'.$field,
        };

        throw new HttpResponseException(response()->json([
            'ok' => false,
            'status' => 'failed',
            'message' => $validator->errors()->first(),
            'retryable' => false,
            'error_code' => $code,
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
