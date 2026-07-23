<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Support;

use Closure;
use Laravel\Prompts\Progress;

/**
 * A progress bar the Terminal can erase and redraw, so messages and prompts
 * emitted while it is on screen do not corrupt its frame diffing.
 *
 * Coupled to laravel/prompts v0.3 internals: Prompt::render() erases the
 * previous frame with cursor movement, so clear() empties the frame and
 * resume() forces the next render() to write a fresh one. Re-verify against
 * Prompt::render() when bumping the prompts dependency.
 *
 * @extends Progress<iterable<mixed>|int>
 */
final class TrackedProgress extends Progress
{
    /**
     * @param  Closure(self): void  $onDetach
     */
    public function __construct(
        string $label,
        iterable|int $steps,
        string $hint,
        private readonly Closure $onDetach,
    ) {
        parent::__construct($label, $steps, $hint);
    }

    /**
     * Whether a live frame is on screen that foreign output would corrupt.
     * Error/submit/cancel frames are settled — nothing redraws over them.
     */
    public function isRendered(): bool
    {
        return $this->prevFrame !== '' && \in_array($this->state, ['initial', 'active'], true);
    }

    /**
     * Erase the current frame so foreign output can be written cleanly.
     */
    public function clear(): void
    {
        if ($this->prevFrame === '') {
            return;
        }

        $height = $this->frameHeight();

        $this->moveCursorToColumn(1);
        $this->moveCursorUp(min(self::terminal()->lines(), $height) - 1);
        $this->eraseDown();

        $this->prevFrame = '';
    }

    /**
     * The frame's height in VISUAL rows, not logical lines: a label or hint
     * wider than the terminal wraps onto extra rows, so counting newlines
     * would move the cursor up too few rows and leave stale bar fragments
     * behind streamed output.
     */
    private function frameHeight(): int
    {
        $columns = max(1, self::terminal()->cols());
        $rows = 0;

        foreach (explode(PHP_EOL, $this->prevFrame) as $line) {
            $width = mb_strwidth((string) preg_replace('/\e\[[0-9;]*m/', '', $line));
            $rows += max(1, (int) ceil($width / $columns));
        }

        return $rows;
    }

    /**
     * Redraw the frame below whatever was written while it was cleared.
     */
    public function resume(): void
    {
        // The margin must be computed against the output just written, and
        // the 'initial' state makes render() full-write instead of diffing
        // against a frame that is no longer on screen.
        $this->capturePreviousNewLines();
        $this->state = 'initial';
        $this->render();
    }

    public function finish(): void
    {
        parent::finish();

        ($this->onDetach)($this);
    }

    /**
     * Settle the bar in its error style; Progress has no fail() upstream.
     */
    public function fail(): void
    {
        $this->settle('error');
    }

    /**
     * Settle the bar in its cancelled style, for interrupt handlers.
     */
    public function interrupt(): void
    {
        $this->settle('cancel');
    }

    private function settle(string $state): void
    {
        $this->state = $state;
        $this->render();
        $this->restoreCursor();
        $this->resetSignals();

        ($this->onDetach)($this);
    }

    /**
     * Themes register renderers by exact prompt class; render as the
     * Progress this is.
     */
    protected function getRenderer(): callable
    {
        return new (self::$themes[self::$theme][Progress::class] ?? self::$themes['default'][Progress::class])($this);
    }
}
