<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

/**
 * Where the DEPLOY_HOOK URL comes from. Only the app:pipeline guidance is
 * host-specific — the generated pipeline and scripts/ci/deploy-hook.sh work
 * with any HTTPS POST deploy hook regardless of this choice.
 */
enum DeployHookHost: string
{
    case FORTRABBIT = 'fortrabbit';
    case FORGE = 'forge';
    case WEBHOOK = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::FORTRABBIT => 'fortrabbit',
            self::FORGE => 'Laravel Forge',
            self::WEBHOOK => 'Another host (generic HTTPS deploy hook)',
        };
    }

    /**
     * Where to find the DEPLOY_HOOK value for one environment — rendered in
     * that secret's guidance section.
     *
     * @return list<string>
     */
    public function hookValueGuidance(string $environment): array
    {
        return match ($this) {
            self::FORTRABBIT => [
                "Value: the deploy hook URL from the fortrabbit dashboard — your app → {$environment} → Deploy hook.",
                'Example: https://api.fortrabbit.com/webhooks/environments/{app-env-id}/deploy/{secret}',
            ],
            self::FORGE => [
                "Value: the deployment trigger URL from Forge — your server → the {$environment} site → Deployments → Deployment trigger URL.",
            ],
            self::WEBHOOK => [
                "Value: your host's HTTPS deploy hook URL for {$environment} — any URL that starts a deploy when POSTed.",
            ],
        };
    }

    /**
     * Host-specific next-step notes.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return match ($this) {
            self::FORTRABBIT => [
                'The deploy script sends the `User-Agent: fortrabbit` header fortrabbit requires — without it its webhook answers 403.',
            ],
            self::FORGE, self::WEBHOOK => [],
        };
    }
}
