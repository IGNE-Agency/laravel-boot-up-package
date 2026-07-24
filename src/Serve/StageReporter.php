<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\StepDescriptor;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Services\TrackedProgress;
use Illuminate\Contracts\Container\Container;

/**
 * Narrates a serve run: a section divider whenever the pipeline enters a new
 * stage, and a progress bar that advances once per completed step with the
 * current step as its hint.
 */
final class StageReporter
{
    private ?ServeStage $currentStage = null;

    private ?TrackedProgress $progress = null;

    /** @var array<int, true> */
    private array $advanced = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Start the progress bar and wrap the plan into pipeline-ready pipes.
     *
     * @return list<ReportedStep>
     */
    public function begin(StepSequence $plan): array
    {
        if ($plan->count() > 0) {
            $this->progress = terminal()->progress('Boot progress', $plan->count(), $plan->steps[0]->label);
            $this->progress->start();
        }

        return array_map(
            fn (StepDescriptor $planned): ReportedStep => new ReportedStep($this->container, $this, $planned),
            $plan->steps,
        );
    }

    public function starting(StepDescriptor $step): void
    {
        if ($step->stage !== $this->currentStage) {
            $this->currentStage = $step->stage;
            terminal()->section($step->stage->value);
        }

        if ($this->progress !== null) {
            $this->progress->hint($step->label);
            $this->progress->render();
        }
    }

    public function completed(StepDescriptor $step): void
    {
        if (isset($this->advanced[$step->index])) {
            return;
        }

        $this->advanced[$step->index] = true;
        $this->progress?->advance();
    }

    public function finish(): void
    {
        $this->progress?->finish();
        $this->progress = null;
    }

    public function fail(): void
    {
        $this->progress?->fail();
        $this->progress = null;
    }

    public function interrupt(): void
    {
        $this->progress?->interrupt();
        $this->progress = null;
    }
}
