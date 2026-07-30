<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Igne\LaravelBootUp\Data\PipelinePlan;

/**
 * The pieces every generated pipeline shares regardless of CI provider:
 * the standard anchor set and the per-environment deploy-hook guidance.
 */
trait SharesStandardPipelineShape
{
    /**
     * lint when Pint is present, build, test, and deploy when the host
     * deploys.
     *
     * @return list<string>
     */
    public function anchors(PipelinePlan $plan): array
    {
        return collect([
            $plan->pint ? 'lint' : null,
            'build',
            'test',
            $plan->host->deploys() ? 'deploy' : null,
        ])->filter()->values()->all();
    }

    /**
     * The provider-specific opening of the hook guidance: where the
     * DEPLOY_HOOK secret lives in this provider's settings.
     *
     * @return list<string>
     */
    abstract protected function deployHookHeader(PipelinePlan $plan): array;

    /**
     * @return list<string>
     */
    protected function deployHookDetails(PipelinePlan $plan): array
    {
        $guidance = collect($plan->branchEnvironments)
            ->flatMap(fn (string $environment, string $branch): array => [
                "{$environment} (deploys on push to {$branch}):",
                ...collect($plan->host->hookValueGuidance($environment))
                    ->map(fn (string $line): string => "  {$line}"),
            ]);

        return [...$this->deployHookHeader($plan), ...$guidance];
    }
}
