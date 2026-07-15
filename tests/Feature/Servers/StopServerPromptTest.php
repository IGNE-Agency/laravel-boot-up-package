<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Servers\CommandRewrites;
use Igne\LaravelBootUp\Servers\Server;
use Igne\LaravelBootUp\Servers\ServersConfig;
use Igne\LaravelBootUp\Servers\StopServerPrompt;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

function stopPromptServer(): Server
{
    return new class implements Server
    {
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
