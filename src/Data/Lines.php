<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

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
    public const KIND_COMMAND = 'command';

    public const KIND_COMMENT = 'comment';

    public const KIND_HEADING = 'heading';

    public const KIND_WARNING = 'warning';

    /** @var list<string> */
    private array $lines = [];

    /** @var list<self::KIND_*> One kind per rendered line, same order and count. */
    private array $kinds = [];

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
        return $this->appendLine($line, self::KIND_COMMAND);
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
     * A source builder's per-line kinds are carried over so composed
     * comments/headings keep their styling; a plain iterable is all commands.
     *
     * @param  iterable<string>|self  $lines
     */
    public function lines(iterable|self $lines): self
    {
        if ($lines instanceof self) {
            foreach ($lines->lines as $index => $line) {
                $this->appendLine($line, $lines->kinds[$index]);
            }

            return $this;
        }

        foreach ($lines as $line) {
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
     * Append "# {comment}" per argument ("#" alone for an empty string),
     * tagged as a comment so terminal rendering can de-emphasise it.
     */
    public function comment(string ...$comments): self
    {
        foreach ($comments as $comment) {
            $this->appendLine($comment === '' ? '#' : "# {$comment}", self::KIND_COMMENT);
        }

        return $this;
    }

    /**
     * Append the comment only when the condition holds.
     */
    public function commentIf(bool $condition, string $comment): self
    {
        return $condition ? $this->comment($comment) : $this;
    }

    /**
     * Append "# {heading}" per argument — rendered identically to a comment
     * in plain text, but tagged as a heading so terminal rendering can make
     * section titles stand out.
     */
    public function heading(string ...$headings): self
    {
        foreach ($headings as $heading) {
            $this->appendLine($heading === '' ? '#' : "# {$heading}", self::KIND_HEADING);
        }

        return $this;
    }

    /**
     * Append "#! {warning}" per argument — still a valid shell comment (the
     * leading '#'), but the '!' flags a caution and the line is tagged as a
     * warning so terminal rendering can colour it loudly.
     */
    public function warning(string ...$warnings): self
    {
        foreach ($warnings as $warning) {
            $this->appendLine($warning === '' ? '#!' : "#! {$warning}", self::KIND_WARNING);
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

    /**
     * The lines mapped through a per-kind styler — for terminal output that
     * colours comments and headings differently from commands. Blank lines
     * pass through untouched; the styler owns all formatting decisions.
     *
     * @param  Closure(self::KIND_*, string): string  $style  receives ($kind, $text)
     * @return list<string>
     */
    public function toStyledArray(Closure $style): array
    {
        $styled = [];

        foreach ($this->lines as $index => $line) {
            $styled[] = $line === '' ? '' : $style($this->kinds[$index], $line);
        }

        return $styled;
    }

    /**
     * Split embedded "\n" into physical lines so indentation stays correct
     * per line, then append each with the given kind.
     *
     * @param  self::KIND_*  $kind
     */
    private function appendLine(string $line, string $kind): self
    {
        foreach (explode("\n", $line) as $part) {
            $this->append($part, $kind);
        }

        return $this;
    }

    /**
     * @param  self::KIND_*  $kind
     */
    private function append(string $line, string $kind): void
    {
        if ($this->pendingBlank) {
            $this->pendingBlank = false;

            if ($this->lines !== [] && end($this->lines) !== '') {
                $this->lines[] = '';
                $this->kinds[] = self::KIND_COMMAND;
            }
        }

        $this->lines[] = $line === '' ? '' : str_repeat(' ', $this->indent).$line;
        $this->kinds[] = $kind;
    }
}
