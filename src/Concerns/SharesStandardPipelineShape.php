<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Igne\LaravelBootUp\Data\CiJob;
use Igne\LaravelBootUp\Data\PipelinePlan;

/**
 * The pieces every generated pipeline shares regardless of CI provider:
 * the standard check jobs, the anchor set derived from them, and the
 * per-environment deploy-hook guidance.
 */
trait SharesStandardPipelineShape
{
    /**
     * The status checks every provider renders — lint when Pint is present,
     * then build and test. The single definition both generators render
     * from, and the list anchors() derives from, so the anchor set cannot
     * drift from the jobs that actually appear in the file.
     *
     * @return list<CiJob>
     */
    protected function standardJobs(PipelinePlan $plan): array
    {
        return collect([
            $plan->pint ? new CiJob('lint', 'Lint', 'Check the code style', timeoutMinutes: 10, usesNode: false) : null,
            new CiJob('build', 'Build', 'Build the frontend and framework caches', timeoutMinutes: 15, usesNode: true),
            new CiJob('test', 'Test', 'Run the test suite', timeoutMinutes: 20, usesNode: true),
        ])->filter()->values()->all();
    }

    /**
     * The standard job keys, plus deploy when the host deploys.
     *
     * @return list<string>
     */
    public function anchors(PipelinePlan $plan): array
    {
        return [
            ...collect($this->standardJobs($plan))->map(fn (CiJob $job): string => $job->key),
            ...($plan->host->deploys() ? ['deploy'] : []),
        ];
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
