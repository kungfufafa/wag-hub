<?php

namespace Tests\Unit\Domain\Connections;

use App\Domain\Connections\ConnectionCapability;
use App\Services\Connections\ConnectionCapabilityCatalog;
use Tests\TestCase;

class ConnectionCapabilityCatalogTest extends TestCase
{
    public function test_applications_learn_capabilities_not_provider_names(): void
    {
        $catalog = new ConnectionCapabilityCatalog;

        $this->assertSame([
            ConnectionCapability::SendText->value,
            ConnectionCapability::NumberLookup->value,
            ConnectionCapability::DeliveryStatus->value,
        ], $catalog->forDriver('wag_hub'));

        $this->assertContains(ConnectionCapability::SendImage->value, $catalog->forDriver('waha'));
        $this->assertContains(ConnectionCapability::InboundMessages->value, $catalog->forDriver('fonnte'));
        $this->assertNotContains(ConnectionCapability::NumberLookup->value, $catalog->forDriver('waba'));
        $this->assertSame([], $catalog->forDriver('unknown-future'));
    }

    public function test_routed_connections_expose_the_union_of_provider_capabilities(): void
    {
        $catalog = new ConnectionCapabilityCatalog;

        $this->assertEqualsCanonicalizing([
            ConnectionCapability::SendText->value,
            ConnectionCapability::SendImage->value,
            ConnectionCapability::SendDocument->value,
            ConnectionCapability::SendVideo->value,
            ConnectionCapability::SendAudio->value,
            ConnectionCapability::NumberLookup->value,
            ConnectionCapability::InboundMessages->value,
            ConnectionCapability::DeliveryStatus->value,
        ], $catalog->union(['wag_hub', 'waha']));
    }

    public function test_capability_checks_reject_unsupported_message_types(): void
    {
        $catalog = new ConnectionCapabilityCatalog;

        $this->assertTrue($catalog->supports(['send_text'], 'text'));
        $this->assertFalse($catalog->supports(['send_text'], 'image'));
        $this->assertTrue($catalog->supports(['send_image'], 'image'));
        $this->assertSame(ConnectionCapability::SendDocument, $catalog->forMessageType('document'));
    }
}
