<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

use Closure;
use Igne\LaravelBootUp\Data\Lines;
use Laravel\Prompts\Concerns\Colors;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * The single terminal seam: every message and prompt the package emits goes
 * through here, so styling stays consistent and an active progress bar can
 * be suspended before foreign output and redrawn after it.
 *
 * Semantics: success() is a green completed state, info() a plain activity
 * line, note() a dim skip/neutral/hint line. Block methods render as one
 * Prompts note each (one write chunk), except list(), which writes one line
 * per item so artisan tests can match individual bullets.
 */
final class Terminal
{
    use Colors;

    private ?TrackedProgress $activeProgress = null;

    public function intro(string $message): void
    {
        $this->suspend(fn () => intro($message));
    }

    public function outro(string $message): void
    {
        $this->suspend(fn () => outro($message));
    }

    /**
     * A completed state: "X created.", "X started.", "X verified.".
     */
    public function success(string $message): void
    {
        $this->suspend(fn () => info($message));
    }

    /**
     * An activity line: "Installing...", "Running...".
     */
    public function info(string $message): void
    {
        $this->suspend(fn () => note($message));
    }

    /**
     * A skip, neutral, or hint line — dimmed so it fades behind the work.
     *
     * @param  string|list<string>  $message
     */
    public function note(string|array $message): void
    {
        $lines = \is_array($message) ? $message : explode(PHP_EOL, $message);

        $this->suspend(fn () => note(implode(PHP_EOL, array_map($this->dim(...), $lines))));
    }

    public function warning(string $message): void
    {
        $this->suspend(fn () => warning($message));
    }

    public function error(string $message): void
    {
        $this->suspend(fn () => error($message));
    }

    /**
     * One extra blank line. Prompts already pads a blank-line margin between
     * elements, so this is only for deliberate breathing room.
     */
    public function blank(): void
    {
        $this->suspend(fn () => note(''));
    }

    public function heading(string $title): void
    {
        $this->suspend(fn () => note($this->bold($this->cyan($title))));
    }

    /**
     * Wrap text in an orange 256-colour foreground — for loud warnings that
     * need to stand out from ordinary (dim) output. The Prompts Colors trait
     * has no orange, so this is the one raw escape the seam owns.
     */
    public function orange(string $text): string
    {
        return "\e[38;5;208m{$text}\e[39m";
    }

    /**
     * A visible divider before a logical group of output: bold-cyan title,
     * optional dim description, optional indented body lines.
     *
     * @param  list<string>  $lines
     */
    public function section(string $title, array $lines = [], ?string $description = null): void
    {
        $this->block($title, $lines, description: $description);
    }

    /**
     * A bullet per item, written line-by-line.
     *
     * @param  list<string>  $items
     */
    public function list(array $items): void
    {
        foreach ($items as $item) {
            $this->suspend(fn () => note("• {$item}"));
        }
    }

    /**
     * A grouped result block: title, bulleted items, optional footer line.
     *
     * @param  list<string>  $items
     */
    public function summary(string $title, array $items, ?string $footer = null): void
    {
        $this->block($title, array_map(fn (string $item): string => "• {$item}", $items), footer: $footer);
    }

    /**
     * A numbered section: bold-cyan title, then items rendered 1., 2., 3.….
     *
     * @param  list<string>  $items
     */
    public function orderedList(string $title, array $items): void
    {
        $numbered = [];

        foreach (array_values($items) as $index => $item) {
            $number = $index + 1;
            $numbered[] = "{$number}. {$item}";
        }

        $this->block($title, $numbered);
    }

    /**
     * The one titled-block shape behind section(), summary() and
     * orderedList(): bold-cyan title, optional dim description, indented
     * body, optional footer — rendered as ONE Prompts note so it stays a
     * single write chunk.
     *
     * @param  list<string>  $body
     */
    private function block(string $title, array $body, ?string $description = null, ?string $footer = null): void
    {
        $block = Lines::make()->line($this->bold($this->cyan($title)));

        if ($description !== null) {
            $block->line($this->dim($description));
        }

        $block->indent(2, fn (Lines $lines) => $lines->lines($body));

        if ($footer !== null) {
            $block->lineWithBreak($footer);
        }

        $this->suspend(fn () => note(implode(PHP_EOL, $block->toArray())));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function table(array $headers, array $rows): void
    {
        $this->suspend(fn () => table($headers, $rows));
    }

    public function confirm(
        string $label,
        bool $default = true,
        string $yes = 'Yes',
        string $no = 'No',
        bool|string $required = false,
        mixed $validate = null,
        string $hint = '',
    ): bool {
        return $this->suspend(fn () => confirm($label, $default, $yes, $no, $required, $validate, $hint));
    }

    /**
     * @param  array<int|string, string>  $options
     */
    public function select(
        string $label,
        array $options,
        int|string|null $default = null,
        int $scroll = 5,
        mixed $validate = null,
        string $hint = '',
        bool|string $required = true,
    ): int|string {
        return $this->suspend(fn () => select($label, $options, $default, $scroll, $validate, $hint, $required));
    }

    public function text(
        string $label,
        string $placeholder = '',
        string $default = '',
        bool|string $required = false,
        mixed $validate = null,
        string $hint = '',
    ): string {
        return $this->suspend(fn () => text($label, $placeholder, $default, $required, $validate, $hint));
    }

    public function password(
        string $label,
        string $placeholder = '',
        bool|string $required = false,
        mixed $validate = null,
        string $hint = '',
    ): string {
        return $this->suspend(fn () => password($label, $placeholder, $required, $validate, $hint));
    }

    /**
     * Create and register the progress bar this terminal keeps out of the
     * way of other output. The caller drives start/advance/finish/fail.
     */
    public function progress(string $label, iterable|int $steps, string $hint = ''): TrackedProgress
    {
        return $this->activeProgress = new TrackedProgress(
            $label,
            $steps,
            $hint,
            onDetach: function (TrackedProgress $progress): void {
                if ($this->activeProgress === $progress) {
                    $this->activeProgress = null;
                }
            },
        );
    }

    /**
     * Run a callback with the active progress bar out of the way: erase its
     * frame, run the callback, then redraw it underneath. Used both for the
     * package's own output and to wrap foreign streamed sub-process output
     * (ProcessRunner::run) that would otherwise corrupt the bar's frame.
     */
    public function suspend(Closure $callback): mixed
    {
        $progress = $this->activeProgress;

        if ($progress === null || ! $progress->isRendered()) {
            return $callback();
        }

        $progress->clear();

        try {
            return $callback();
        } finally {
            $progress->resume();
        }
    }
}
