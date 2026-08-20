<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ShutdownConfig;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Contracts\WarnsBeforeStop;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Servers\StopServerPrompt;
use Igne\LaravelBootUp\Tests\Feature\Boot\Fixtures\RecordingServer;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

function stopPromptServer(?string $impact = null): Server
{
    if ($impact === null) {
        return new RecordingServer;
    }

    return new class($impact) implements Server, WarnsBeforeStop
    {
        public function __construct(private readonly string $impact) {}

        public function stopImpact(): string
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

        public function isRunning(): bool
        {
            return true;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

test('returns the configured default without prompting when the prompt is disabled', function (): void {
    Prompt::fake();

    $keep = new StopServerPrompt(new ShutdownConfig(promptStopServer: false, stopServerByDefault: false));
    $stop = new StopServerPrompt(new ShutdownConfig(promptStopServer: false, stopServerByDefault: true));

    expect($keep->shouldStop(stopPromptServer()))->toBeFalse()
        ->and($stop->shouldStop(stopPromptServer()))->toBeTrue();
});

test('confirms with the server label and honours the answer', function (): void {
    Prompt::fake(['y', Key::ENTER]);

    $prompt = new StopServerPrompt(new ShutdownConfig(promptStopServer: true, stopServerByDefault: false));

    expect($prompt->shouldStop(stopPromptServer()))->toBeTrue();
    Prompt::assertStrippedOutputContains('Stop Double Server? Other projects may be using it.');
});

test('enter accepts the configured default answer', function (): void {
    Prompt::fake([Key::ENTER]);

    $prompt = new StopServerPrompt(new ShutdownConfig(promptStopServer: true, stopServerByDefault: false));

    expect($prompt->shouldStop(stopPromptServer()))->toBeFalse();
});

test('a server with stop impact is never stopped silently, even when configured to', function (): void {
    Prompt::fake();

    $prompt = new StopServerPrompt(new ShutdownConfig(promptStopServer: false, stopServerByDefault: true));

    expect($prompt->shouldStop(stopPromptServer('stopping halts every site.')))->toBeFalse();
    Prompt::assertStrippedOutputContains('Leaving Double Server running');
    Prompt::assertStrippedOutputContains('stopping halts every site.');
});

test('the stop impact is shown as a warning before the confirm and forces a no default', function (): void {
    Prompt::fake([Key::ENTER]);

    $prompt = new StopServerPrompt(new ShutdownConfig(promptStopServer: true, stopServerByDefault: true));

    expect($prompt->shouldStop(stopPromptServer('stopping halts every site.')))->toBeFalse();
    Prompt::assertStrippedOutputContains('stopping halts every site.');
});

test('an explicit yes still stops a server with stop impact', function (): void {
    Prompt::fake(['y', Key::ENTER]);

    $prompt = new StopServerPrompt(new ShutdownConfig(promptStopServer: true, stopServerByDefault: false));

    expect($prompt->shouldStop(stopPromptServer('stopping halts every site.')))->toBeTrue();
});

test('cleanup confirms with the server label and honours the answer', function (): void {
    Prompt::fake(['n', Key::ENTER]);

    $prompt = new StopServerPrompt(new ShutdownConfig(promptStopServer: true, stopServerByDefault: false));

    expect($prompt->shouldCleanUp(stopPromptServer()))->toBeFalse();
    Prompt::assertStrippedOutputContains("Clean up Double Server's leftover resources?");
});

test('cleanup defaults to yes at the prompt', function (): void {
    Prompt::fake([Key::ENTER]);

    $prompt = new StopServerPrompt(new ShutdownConfig(promptStopServer: true, stopServerByDefault: false));

    expect($prompt->shouldCleanUp(stopPromptServer()))->toBeTrue();
});

test('unattended cleanup follows the stop-by-default setting', function (): void {
    Prompt::fake();

    $keep = new StopServerPrompt(new ShutdownConfig(promptStopServer: false, stopServerByDefault: false));
    $clean = new StopServerPrompt(new ShutdownConfig(promptStopServer: false, stopServerByDefault: true));

    expect($keep->shouldCleanUp(stopPromptServer()))->toBeFalse()
        ->and($clean->shouldCleanUp(stopPromptServer()))->toBeTrue();
});
