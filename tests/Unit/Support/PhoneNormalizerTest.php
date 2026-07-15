<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNormalizerTest extends TestCase
{
    #[DataProvider('equivalentIndonesianPhoneFormats')]
    public function test_it_normalizes_supported_indonesian_phone_formats(
        string $input,
        string $expected,
    ): void {
        $normalizer = new PhoneNormalizer;

        self::assertSame($expected, $normalizer->normalize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentIndonesianPhoneFormats(): iterable
    {
        yield 'local number with leading zero' => ['081234567890', '6281234567890'];
        yield 'local number without leading zero' => ['81234567890', '6281234567890'];
        yield 'international plus prefix' => ['+6281234567890', '6281234567890'];
        yield 'international dial-out prefix' => ['006281234567890', '6281234567890'];
        yield 'spaces and punctuation' => ['+62 812-3456-7890', '6281234567890'];
        yield 'parentheses in local format' => ['(0812) 3456 7890', '6281234567890'];
        yield 'country code followed by a trunk zero' => ['62081234567890', '6281234567890'];
    }

    #[DataProvider('invalidPhoneInputs')]
    public function test_it_rejects_invalid_or_non_individual_targets(string $input): void
    {
        $normalizer = new PhoneNormalizer;

        $this->expectException(InvalidArgumentException::class);

        $normalizer->normalize($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPhoneInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'letters only' => ['bukan nomor telepon'];
        yield 'letters mixed into digits' => ['0812ABC4567890'];
        yield 'shorter than configured minimum' => ['0812345'];
        yield 'longer than configured maximum' => ['6281234567890123'];
        yield 'comma-separated targets' => ['081234567890, 081298765432'];
        yield 'semicolon-separated targets' => ['081234567890;081298765432'];
        yield 'WAHA individual raw JID' => ['6281234567890@c.us'];
        yield 'WhatsApp group raw JID' => ['120363012345678901@g.us'];
        yield 'non-Indonesian country code' => ['+14155552671'];
    }

    public function test_configured_digit_bounds_are_inclusive(): void
    {
        $normalizer = new PhoneNormalizer(minDigits: 10, maxDigits: 15);

        self::assertSame('6281234567', $normalizer->normalize('6281234567'));
        self::assertSame('628123456789012', $normalizer->normalize('628123456789012'));
    }
}
