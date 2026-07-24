<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ServersConfig;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Servers\StopServerPrompt;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\DefaultServerCapabilities;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

function stopPromptServer(?string $impact = null): Server
{
    return new class($impact) implements Server
    {
        use DefaultServerCapabilities;

        public function __construct(private readonly ?string $impact = null) {}

        public function stopImpact(): ?string
        {
            return $this->impact;
        }

        public function key(): string
        {
            return 'double';
        }

        public function label(): string
        {
            return 'Double Server';
        }

        public function requiredTools(): array
        {
            return [];
        }

        public function commandRewrites(): CommandRewrites
        {
            return CommandRewrites::none();
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(ServeContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

test('returns the configured default without prompting when the prompt is disabled', function (): void {
    Prompt::fake();

    $keep = new StopServerPrompt(new ServersConfig(promptStopServer: false, stopServerByDefault: false));
    $stop = new StopServerPrompt(new ServersConfig(promptStopServer: false, stopServerByDefault: true));

    expect($keep->shouldStop(stopPromptServer()))->toBeFalse()
        ->and($stop->shouldStop(stopPromptServer()))->toBeTrue();
});

test('confirms with the server label and honours the answer', function (): void {
    Prompt::fake(['y', Key::ENTER]);

    $prompt = new StopServerPrompt(new ServersConfig(promptStopServer: true, stopServerByDefault: false));

    expect($prompt->shouldStop(stopPromptServer()))->toBeTrue();
    Prompt::assertStrippedOutputContains('Stop Double Server? Other projects may be using it.');
});

test('enter accepts the configured default answer', function (): void {
    Prompt::fake([Key::ENTER]);

    $prompt = new StopServerPrompt(new ServersConfig(promptStopServer: true, stopServerByDefault: false));

    expect($prompt->shouldStop(stopPromptServer()))->toBeFalse();
});

test('a server with stop impact is never stopped silently, even when configured to', function (): void {
    Prompt::fake();

    $prompt = new StopServerPrompt(new ServersConfig(promptStopServer: false, stopServerByDefault: true));

    expect($prompt->shouldStop(stopPromptServer('stopping halts every site.')))->toBeFalse();
    Prompt::assertStrippedOutputContains('Leaving Double Server running');
    Prompt::assertStrippedOutputContains('stopping halts every site.');
});

test('the stop impact is shown as a warning before the confirm and forces a no default', function (): void {
    Prompt::fake([Key::ENTER]);

    $prompt = new StopServerPrompt(new ServersConfig(promptStopServer: true, stopServerByDefault: true));

    expect($prompt->shouldStop(stopPromptServer('stopping halts every site.')))->toBeFalse();
    Prompt::assertStrippedOutputContains('stopping halts every site.');
});

test('an explicit yes still stops a server with stop impact', function (): void {
    Prompt::fake(['y', Key::ENTER]);

    $prompt = new StopServerPrompt(new ServersConfig(promptStopServer: true, stopServerByDefault: false));

    expect($prompt->shouldStop(stopPromptServer('stopping halts every site.')))->toBeTrue();
});
