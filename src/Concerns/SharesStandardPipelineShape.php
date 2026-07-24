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
        return array_values(array_filter([
            $plan->pint ? 'lint' : null,
            'build',
            'test',
            $plan->host->deploys() ? 'deploy' : null,
        ]));
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
        $details = $this->deployHookHeader($plan);

        foreach ($plan->branchEnvironments as $branch => $environment) {
            $details[] = "{$environment} (deploys on push to {$branch}):";

            foreach ($plan->host->hookValueGuidance($environment) as $line) {
                $details[] = "  {$line}";
            }
        }

        return $details;
    }
}
