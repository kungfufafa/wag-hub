<?php

namespace Tests\Unit\Support;

use App\Support\UniqueIdentifier;
use PHPUnit\Framework\TestCase;

class UniqueIdentifierTest extends TestCase
{
    public function test_slug_from_name_uses_ascii_kebab_case(): void
    {
        $this->assertSame('web-shelf', UniqueIdentifier::slugFromName('Web Shelf'));
        $this->assertSame('item', UniqueIdentifier::slugFromName('   '));
    }

    public function test_unique_slug_appends_a_suffix_when_the_base_exists(): void
    {
        $existing = ['web-shelf' => true, 'web-shelf-2' => true];

        $this->assertSame(
            'web-shelf-3',
            UniqueIdentifier::uniqueSlug(
                'Web Shelf',
                fn (string $slug): bool => isset($existing[$slug]),
            ),
        );
    }

    public function test_release_keeps_the_value_within_the_column_limit(): void
    {
        $released = UniqueIdentifier::release(str_repeat('a', 80), 99, 80);

        $this->assertSame(80, strlen($released));
        $this->assertStringEndsWith('--d99', $released);
    }
}
