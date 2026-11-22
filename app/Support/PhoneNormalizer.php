<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class PhoneNormalizer
{
    public function __construct(
        private string $countryCode = '62',
        private int $minDigits = 10,
        private int $maxDigits = 15,
    ) {}

    /**
     * Forgiving cleanup for any country: drops spaces, dashes, dots, brackets and a leading
     * "+" or "00", and turns a local "0..." number into the default country code. It never
     * throws; callers still validate the result as an international number.
     */
    public static function international(string $value, string $defaultCountryCode = '62'): string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/[^0-9+\s().\-]/', $value) === 1) {
            return $value;
        }

        $digits = (string) preg_replace('/\D+/', '', $value);

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        if (! str_starts_with($value, '+') && str_starts_with($digits, '0')) {
            return $defaultCountryCode.ltrim($digits, '0');
        }

        return $digits;
    }

    public function normalize(string $value): string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/[A-Za-z@,;]/', $value) === 1) {
            throw new InvalidArgumentException('Nomor WhatsApp tidak valid.');
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || $digits === '') {
            throw new InvalidArgumentException('Nomor WhatsApp tidak valid.');
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, $this->countryCode.'0')) {
            $digits = $this->countryCode.substr($digits, strlen($this->countryCode) + 1);
        } elseif (str_starts_with($digits, '0')) {
            $digits = $this->countryCode.ltrim($digits, '0');
        } elseif (! str_starts_with($digits, $this->countryCode) && str_starts_with($digits, '8')) {
            $digits = $this->countryCode.$digits;
        }

        if (! str_starts_with($digits, $this->countryCode)) {
            throw new InvalidArgumentException('Kode negara WhatsApp tidak didukung.');
        }

        $length = strlen($digits);

        if ($length < $this->minDigits || $length > $this->maxDigits) {
            throw new InvalidArgumentException('Panjang nomor WhatsApp tidak valid.');
        }

        return $digits;
    }
}
