<?php

namespace App\Console\Commands;

use App\Core\Contracts\EmbeddingProviderInterface;
use App\Core\Contracts\LLMProviderInterface;
use Illuminate\Console\Command;
use Throwable;

final class CheckAiProvider extends Command
{
    protected $signature = 'teami:ai:check';

    protected $description = 'Test chat and embeddings with synthetic text; never prints keys or provider responses.';

    public function handle(LLMProviderInterface $chat, EmbeddingProviderInterface $embeddings): int
    {
        if (! filled(config('teami_ai.api_key')) || ! str_starts_with((string) config('teami_ai.endpoint'), 'https://')) {
            $this->error('FAIL AI configuration missing. Run teami:ai:configure-openai on the server.');

            return self::FAILURE;
        }
        $this->line('Sending two small provider requests. Provider charges may apply; tenant quotas are unchanged.');
        $passed = true;
        foreach (['Chat' => fn () => $chat->complete([['role' => 'user', 'content' => 'Reply with OK only.']]), 'Embeddings' => fn () => $embeddings->embed('TAIE connection check.')] as $name => $probe) {
            try {
                $result = $probe();
                $valid = $name === 'Chat'
                    ? filled($result['content'] ?? null)
                    : $result !== [] && collect($result)->every(fn ($value) => is_numeric($value) && is_finite((float) $value));
                if (! $valid) {
                    throw new \RuntimeException;
                }
                $this->info('PASS '.$name);
            } catch (Throwable) {
                $this->error('FAIL '.$name.'. Check API key, provider billing, model access and network connectivity.');
                $passed = false;
            }
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
