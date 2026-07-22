<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

/**
 * Where the DEPLOY_HOOK URL comes from. The generated pipeline and
 * scripts/ci/deploy-hook.sh work with any HTTPS POST deploy hook, so for the
 * real hosts only the generate:pipeline guidance differs. NONE goes further: the
 * deploy jobs, DEPLOY_HOOK secrets and deploy-hook.sh are omitted entirely —
 * the pipeline runs checks only.
 */
enum DeployHookHost: string
{
    case FORTRABBIT = 'fortrabbit';
    case FORGE = 'forge';
    case WEBHOOK = 'webhook';
    case NONE = 'none';

    /**
     * Whether the pipeline gets deploy jobs at all — everything deploy-related
     * (jobs, secrets, deploy-hook.sh, guidance) keys off this.
     */
    public function deploys(): bool
    {
        return $this !== self::NONE;
    }

    public function label(): string
    {
        return match ($this) {
            self::FORTRABBIT => 'fortrabbit',
            self::FORGE => 'Laravel Forge',
            self::WEBHOOK => 'Another host (generic HTTPS deploy hook)',
            self::NONE => 'None — skip the deploy step',
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
            self::NONE => [],
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
            self::FORGE, self::WEBHOOK, self::NONE => [],
        };
    }
}
