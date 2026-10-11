<?php

namespace App\Console\Commands;

use App\Core\AI\OpenAiEnvironmentConfigurator;
use Illuminate\Console\Command;
use Throwable;

final class ConfigureOpenAi extends Command
{
    protected $signature = 'teami:ai:configure-openai';

    protected $description = 'Configure OpenAI using a hidden key prompt; preserve application and database secrets.';

    public function handle(OpenAiEnvironmentConfigurator $configurator): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively on the server. Never pass the key as a command argument.');

            return self::FAILURE;
        }
        try {
            $key = trim((string) $this->secret('OpenAI API key (input is hidden)', false));
            $configurator->configure(app()->environmentFilePath(), $key);
        } catch (Throwable) {
            $this->error('Setup failed. Check the key format and environment file permissions. No key is displayed.');

            return self::FAILURE;
        } finally {
            unset($key);
        }
        try {
            $cached = $this->callSilent('config:cache');
            $restarted = $cached === self::SUCCESS ? $this->callSilent('queue:restart') : self::FAILURE;
        } catch (Throwable) {
            $cached = $restarted = self::FAILURE;
        }
        if ($cached !== self::SUCCESS || $restarted !== self::SUCCESS) {
            $this->error('Settings saved. Run config:cache and queue:restart before using AI.');

            return self::FAILURE;
        }
        $this->info('OpenAI settings saved securely. Existing model selections were preserved.');
        $this->line('Verify both capabilities: php artisan teami:ai:check');

        return self::SUCCESS;
    }
}
