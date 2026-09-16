<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class FlowBuilderPageTest extends TestCase
{
    use DatabaseMigrations;

    public function test_flow_builder_page_renders(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/panel/flow-builder')->assertOk();
    }
}
