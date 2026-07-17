<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Support;

use Closure;

/**
 * Fluent line-by-line builder for generated text documents — env files,
 * pipeline YAML and shell scripts.
 *
 * blank() is a separator, not content: it collapses at the start, against
 * another blank and at the end, so conditional sections never leak stray
 * empty lines. Indentation applies per physical line and never to blank
 * lines, so indented blocks contain no trailing whitespace.
 */
final class Lines
{
    /** @var list<string> */
    private array $lines = [];

    private int $indent = 0;

    private bool $pendingBlank = false;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Append one line at the current indent. Embedded "\n" splits into
     * multiple physical lines so indentation stays correct per line.
     */
    public function line(string $line): self
    {
        foreach (explode("\n", $line) as $part) {
            $this->append($part);
        }

        return $this;
    }

    /**
     * Append the line only when the condition holds.
     */
    public function lineIf(bool $condition, string $line): self
    {
        return $condition ? $this->line($line) : $this;
    }

    /**
     * Append many lines (or another builder's lines) at the current indent.
     *
     * @param  iterable<string>|self  $lines
     */
    public function lines(iterable|self $lines): self
    {
        foreach ($lines instanceof self ? $lines->toArray() : $lines as $line) {
            $this->line($line);
        }

        return $this;
    }

    /**
     * blank() + line(): the line starts a new block, separated from whatever
     * came before.
     */
    public function lineWithBreak(string $line): self
    {
        return $this->blank()->line($line);
    }

    /**
     * Append the line-with-break only when the condition holds.
     */
    public function lineWithBreakIf(bool $condition, string $line): self
    {
        return $condition ? $this->lineWithBreak($line) : $this;
    }

    /**
     * blank() + lines(): the block is separated from whatever came before.
     * An empty block still leaves the separator pending for what comes next.
     *
     * @param  iterable<string>|self  $lines
     */
    public function linesWithBreak(iterable|self $lines): self
    {
        return $this->blank()->lines($lines);
    }

    /**
     * Separate what came before from what comes next with one empty line.
     * Collapses: never first, never doubled, never trailing.
     */
    public function blank(): self
    {
        $this->pendingBlank = true;

        return $this;
    }

    /**
     * Append "# {comment}" per argument ("#" alone for an empty string).
     */
    public function comment(string ...$comments): self
    {
        foreach ($comments as $comment) {
            $this->line($comment === '' ? '#' : "# {$comment}");
        }

        return $this;
    }

    /**
     * @param  Closure(self): void  $then
     * @param  Closure(self): void|null  $otherwise
     */
    public function when(bool $condition, Closure $then, ?Closure $otherwise = null): self
    {
        if ($condition) {
            $then($this);
        } elseif ($otherwise !== null) {
            $otherwise($this);
        }

        return $this;
    }

    /**
     * @param  iterable<mixed, mixed>  $items
     * @param  Closure(self, mixed, mixed): void  $callback  receives ($lines, $value, $key)
     */
    public function each(iterable $items, Closure $callback): self
    {
        foreach ($items as $key => $value) {
            $callback($this, $value, $key);
        }

        return $this;
    }

    /**
     * Everything appended inside the callback is indented $spaces further.
     * Nesting accumulates; the previous level is restored afterwards.
     *
     * @param  Closure(self): void  $callback
     */
    public function indent(int $spaces, Closure $callback): self
    {
        $this->indent += $spaces;

        try {
            $callback($this);
        } finally {
            $this->indent -= $spaces;
        }

        return $this;
    }

    /**
     * The lines so far, indentation applied and any pending separator
     * dropped — for `...` spreads and mixing with list<string> helpers.
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        return $this->lines;
    }

    /**
     * The document: lines joined by "\n" with a single trailing newline,
     * or an empty string when nothing was appended.
     */
    public function render(): string
    {
        return $this->lines === [] ? '' : implode("\n", $this->lines)."\n";
    }

    private function append(string $line): void
    {
        if ($this->pendingBlank) {
            $this->pendingBlank = false;

            if ($this->lines !== [] && end($this->lines) !== '') {
                $this->lines[] = '';
            }
        }

        $this->lines[] = $line === '' ? '' : str_repeat(' ', $this->indent).$line;
    }
}
