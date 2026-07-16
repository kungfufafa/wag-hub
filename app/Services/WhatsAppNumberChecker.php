<?php

namespace App\Services;

use App\Infrastructure\WhatsApp\ProviderDriverManager;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use Illuminate\Database\Eloquent\Builder;

final readonly class WhatsAppNumberChecker
{
    public function __construct(private ProviderDriverManager $drivers) {}

    /**
     * @return array{status: string, registered: ?bool, checks: list<array<string, mixed>>}|null
     */
    public function check(ClientApplication $application, string $recipient): ?array
    {
        $accounts = ProviderAccount::query()
            ->where('is_active', true)
            ->whereHas('routingSteps', function (Builder $steps) use ($application): void {
                $steps
                    ->where('is_active', true)
                    ->whereHas('routingPolicy', function (Builder $policies) use ($application): void {
                        $policies
                            ->where('client_application_id', $application->getKey())
                            ->where('is_active', true);
                    });
            })
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        $checks = [];

        foreach ($accounts as $account) {
            $result = $this->drivers->checkNumber($account, $recipient);
            $check = [
                'provider' => (string) $account->slug,
                'driver' => (string) $account->driver,
                'status' => $result->status,
                'registered' => $result->registered,
            ];

            if ($result->reasonCode !== null) {
                $check['reason_code'] = $result->reasonCode;
            }

            $checks[] = $check;
        }

        $statuses = array_column($checks, 'status');
        $hasRegistered = in_array('registered', $statuses, true);
        $hasNotRegistered = in_array('not_registered', $statuses, true);

        [$status, $registered] = match (true) {
            $hasRegistered && $hasNotRegistered => ['conflict', null],
            $hasRegistered => ['registered', true],
            $hasNotRegistered => ['not_registered', false],
            count(array_unique($statuses)) === 1 && $statuses[0] === 'unsupported' => ['unsupported', null],
            default => ['unknown', null],
        };

        return compact('status', 'registered', 'checks');
    }
}
