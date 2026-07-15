<?php

namespace Tests\Unit\Support;

use App\Support\PayloadHasher;
use PHPUnit\Framework\TestCase;

class PayloadHasherTest extends TestCase
{
    public function test_the_fingerprint_is_deterministic_but_depends_on_a_secret_key(): void
    {
        $payload = [
            'recipient' => '6281234567890',
            'message' => ['text' => 'Kode OTP 123456'],
        ];
        $hasher = new PayloadHasher;

        $first = $hasher->hash($payload, 'secret-key-one');
        $same = $hasher->hash($payload, 'secret-key-one');
        $differentKey = $hasher->hash($payload, 'secret-key-two');
        $plainSha256 = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame($first, $same);
        self::assertNotSame($first, $differentKey);
        self::assertNotSame($plainSha256, $first);
        self::assertSame(64, strlen($first));
    }
}
