<?php

namespace App\Core\AI;

use App\Core\Contracts\EmbeddingProviderInterface;
use App\Core\Contracts\LLMProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class OpenAICompatibleProvider implements EmbeddingProviderInterface, LLMProviderInterface
{
    private function post(string $path, array $payload): array
    {
        $endpoint = rtrim((string) config('teami_ai.endpoint'), '/');
        $key = (string) config('teami_ai.api_key');
        if ($endpoint === '' || $key === '') {
            throw new ServiceUnavailableHttpException(null, 'AI provider is not configured.');
        }

        try {
            $response = Http::acceptJson()->asJson()->withToken($key)
                ->timeout((int) config('teami_ai.request_timeout', 45))
                ->post($endpoint.'/'.$path, $payload);
        } catch (ConnectionException) {
            throw new ServiceUnavailableHttpException(30, 'AI provider is temporarily unavailable.');
        }
        if (! $response->successful()) {
            throw new HttpException(502, 'AI provider rejected the request.');
        }

        return $response->json() ?? throw new HttpException(502, 'AI provider returned invalid JSON.');
    }

    public function embed(string $text): array
    {
        $result = $this->post('embeddings', ['model' => $this->model(), 'input' => $text]);
        $values = $result['data'][0]['embedding'] ?? null;
        if (! is_array($values) || $values === []) {
            throw new HttpException(502, 'AI provider returned no embedding.');
        }

        return array_map('floatval', $values);
    }

    public function model(): string
    {
        return (string) config('teami_ai.embedding_model');
    }

    public function complete(array $messages): array
    {
        $result = $this->post('chat/completions', ['model' => config('teami_ai.chat_model'), 'messages' => $messages, 'temperature' => 0.1]);
        $content = $result['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new HttpException(502, 'AI provider returned no answer.');
        }

        return ['content' => trim($content), 'usage' => array_map('intval', $result['usage'] ?? [])];
    }
}
