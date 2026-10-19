<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Closure;
use Igne\LaravelBootUp\Data\Lines;

/**
 * What a generated script prints when it fails, laid out the same way in
 * every script: a headline, paragraphs indented under it, and the commands
 * that fix it indented one step further. Rendered as shell `echo` lines, so
 * the message can sit inside a script's `if` and carry shell of its own.
 */
final class FailureMessage
{
    private const string PARAGRAPH_INDENT = '    ';

    private const string COMMAND_INDENT = '      ';

    private function __construct(private readonly Lines $lines) {}

    public static function make(): self
    {
        return new self(Lines::make());
    }

    /**
     * "==> {headline}", set off from whatever the script printed before.
     */
    public function headline(string $headline): self
    {
        return $this->blankLine()->echo("==> {$headline}");
    }

    /**
     * A paragraph: an empty line, then the text indented under the headline.
     */
    public function paragraph(string ...$text): self
    {
        return $this->block(self::PARAGRAPH_INDENT, $text);
    }

    /**
     * Commands ready to paste, indented one step further than a paragraph.
     */
    public function commands(string ...$commands): self
    {
        return $this->block(self::COMMAND_INDENT, $commands);
    }

    public function blankLine(): self
    {
        $this->lines->line('echo');

        return $this;
    }

    /**
     * A line of shell that prints part of the message itself, such as a
     * function that lists files.
     */
    public function shell(string $line): self
    {
        $this->lines->line($line);

        return $this;
    }

    /**
     * The part of the message the callback adds prints only when the shell
     * condition holds.
     *
     * @param  Closure(self): mixed  $body
     */
    public function when(string $condition, Closure $body): self
    {
        $this->lines->line("if {$condition}; then")
            ->indent(2, fn () => $body($this))
            ->line('fi');

        return $this;
    }

    public function appendTo(Lines $script): void
    {
        $script->lines($this->lines);
    }

    /**
     * Append the message sent to stderr: git clients show a hook's stderr
     * when it blocks a commit.
     */
    public function appendToStderr(Lines $script): void
    {
        $script->line('{')
            ->indent(2, fn (Lines $script) => $script->lines($this->lines))
            ->line('} >&2');
    }

    /**
     * @param  list<string>  $text
     */
    private function block(string $indent, array $text): self
    {
        $this->blankLine();

        foreach ($text as $line) {
            $this->echo($indent.$line);
        }

        return $this;
    }

    private function echo(string $text): self
    {
        $this->lines->echo($text);

        return $this;
    }
}
