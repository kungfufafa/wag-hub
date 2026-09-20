<?php

namespace Tests\Feature\Api\Engine;

use App\Models\ClientApplication;
use App\Models\EngineSession;
use App\Services\Engine\SessionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class EngineV2MysqlConcurrencyTest extends TestCase
{
    use BuildsGatewayFixtures, DatabaseMigrations;

    public function test_competing_processes_can_claim_only_the_last_available_slot(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Run against an isolated MySQL database to verify row-lock concurrency.');
        }
        $client = $this->createClientApplication(config('engine_v2.scopes'));
        $application = ClientApplication::findOrFail($client['id']);
        app(SessionService::class)->integration($application)->update(['configured_limit' => 1, 'maximum_allowed' => 1]);
        $program = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $owner = App\Models\ClientApplication::findOrFail((int) $argv[1]);
        try {
            app(App\Services\Engine\SessionService::class)->create($owner, $argv[2], []);
            echo 'created';
        } catch (App\Exceptions\WhatsAppEngineException $error) {
            echo $error->errorCode;
        }
        PHP;
        $workers = [
            new Process([PHP_BINARY, '-r', $program, (string) $client['id'], 'race-one'], base_path()),
            new Process([PHP_BINARY, '-r', $program, (string) $client['id'], 'race-two'], base_path()),
        ];
        foreach ($workers as $worker) {
            $worker->start();
        }
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $results[] = trim($worker->getOutput());
        }
        sort($results);
        $this->assertSame(['capacity_exceeded', 'created'], $results);
        $this->assertSame(1, EngineSession::query()->where('client_application_id', $client['id'])->count());
    }
}
