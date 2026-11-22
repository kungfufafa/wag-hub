<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrustCurrentHostForLogViewer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Opcodes\LogViewer\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_viewer_api_keeps_the_session_when_opened_on_a_host_other_than_app_url(): void
    {
        config(['app.url' => 'http://127.0.0.1:8765', 'log-viewer.api_stateful_domains' => null]);

        $request = Request::create('http://wag-hub.test/log-viewer/api/folders');
        $request->headers->set('Referer', 'http://wag-hub.test/log-viewer');

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));

        (new TrustCurrentHostForLogViewer)->handle($request, fn () => response('ok'));

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));

        $other = Request::create('http://wag-hub.test/log-viewer/api/folders');
        $other->headers->set('Referer', 'http://evil.example/page');

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($other));
    }

    public function test_non_admin_is_still_rejected(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);

        $this->actingAs($user)
            ->withHeader('Referer', 'http://wag-hub.test/log-viewer')
            ->getJson('http://wag-hub.test/log-viewer/api/folders')
            ->assertForbidden();
    }
}
