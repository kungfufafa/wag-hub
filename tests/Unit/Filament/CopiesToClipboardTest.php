<?php

namespace Tests\Unit\Filament;

use App\Filament\Support\CopiesToClipboard;
use PHPUnit\Framework\TestCase;

class CopiesToClipboardTest extends TestCase
{
    public function test_handler_embeds_the_plaintext_and_safe_fallbacks(): void
    {
        $script = CopiesToClipboard::alpine("OTP 123\nBaris dua");

        $this->assertStringContainsString('copyWithEvent', $script);
        $this->assertStringContainsString('clipboard?.writeText', $script);
        $this->assertStringContainsString('OTP 123', $script);
        $this->assertStringContainsString('Baris dua', $script);
        $this->assertStringNotContainsString('</script>', $script);
    }
}
