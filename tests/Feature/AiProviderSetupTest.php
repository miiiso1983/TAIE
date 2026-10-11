<?php

namespace Tests\Feature;

use App\Core\AI\OpenAiEnvironmentConfigurator;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class AiProviderSetupTest extends TestCase
{
    public function test_configuration_refuses_non_interactive_secret_submission(): void
    {
        $this->artisan('teami:ai:configure-openai', ['--no-interaction' => true])
            ->expectsOutput('Run this command interactively on the server. Never pass the key as a command argument.')
            ->assertExitCode(1);
    }

    public function test_configuration_rejects_invalid_hidden_input_without_printing_it(): void
    {
        $this->artisan('teami:ai:configure-openai')
            ->expectsQuestion('OpenAI API key (input is hidden)', 'invalid-fixture-key')
            ->expectsOutput('Setup failed. Check the key format and environment file permissions. No key is displayed.')
            ->assertExitCode(1);
    }

    public function test_setup_preserves_existing_secrets_models_and_restricts_file_permissions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'teami-ai-test-');
        $key = 'sk-proj-'.str_repeat('x', 40);
        $source = "APP_KEY=fixture-unchanged\nDB_PASSWORD=\"fixture $ with spaces\"\nAUDIT_HMAC_KEY=fixture-hmac\nAI_API_KEY=old\nAI_API_KEY=duplicate\nAI_API_ENDPOINT=\nAI_CHAT_MODEL=custom-model\nAI_EMBEDDING_MODEL=text-embedding-3-small\n";
        file_put_contents($path, $source);
        chmod($path, 0644);
        try {
            app(OpenAiEnvironmentConfigurator::class)->configure($path, $key);
            $updated = file_get_contents($path);
            $values = Dotenv::parse($updated);
            $this->assertSame('fixture-unchanged', $values['APP_KEY']);
            $this->assertSame('fixture $ with spaces', $values['DB_PASSWORD']);
            $this->assertSame('fixture-hmac', $values['AUDIT_HMAC_KEY']);
            $this->assertSame('custom-model', $values['AI_CHAT_MODEL']);
            $this->assertSame('text-embedding-3-small', $values['AI_EMBEDDING_MODEL']);
            $this->assertSame('https://api.openai.com/v1', $values['AI_API_ENDPOINT']);
            $this->assertSame($key, $values['AI_API_KEY']);
            $this->assertSame(1, substr_count($updated, 'AI_API_KEY='));
            clearstatcache(true, $path);
            $this->assertSame(0640, fileperms($path) & 0777);
        } finally {
            unlink($path);
        }
    }

    public function test_invalid_secret_is_rejected_without_changing_environment(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'teami-ai-test-');
        file_put_contents($path, 'APP_KEY=unchanged');
        try {
            try {
                app(OpenAiEnvironmentConfigurator::class)->configure($path, "sk-invalid\nAPP_DEBUG=true");
                $this->fail('Invalid secret accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame('APP_KEY=unchanged', file_get_contents($path));
            }
        } finally {
            unlink($path);
        }
    }

    public function test_check_requires_configuration_and_makes_no_calls_without_it(): void
    {
        config(['teami_ai.api_key' => '', 'teami_ai.endpoint' => '']);
        Http::fake();
        $this->artisan('teami:ai:check')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_check_proves_chat_and_embeddings_and_uses_only_synthetic_text(): void
    {
        config(['teami_ai.api_key' => 'fixture-private-key', 'teami_ai.endpoint' => 'https://api.openai.com/v1']);
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]]),
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]),
        ]);
        $this->artisan('teami:ai:check')->expectsOutput('PASS Chat')->expectsOutput('PASS Embeddings')->assertExitCode(0);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions' && $request['messages'] === [['role' => 'user', 'content' => 'Reply with OK only.']]);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/embeddings' && $request['input'] === 'TAIE connection check.');
    }

    public function test_provider_errors_are_redacted_and_failure_is_not_reported_as_ready(): void
    {
        config(['teami_ai.api_key' => 'fixture-private-key', 'teami_ai.endpoint' => 'https://api.openai.com/v1']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response(['error' => ['message' => 'sensitive-provider-body']], 401),
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1]]]]),
        ]);
        $this->artisan('teami:ai:check')->doesntExpectOutput('sensitive-provider-body')->doesntExpectOutput('fixture-private-key')->expectsOutput('PASS Embeddings')->assertExitCode(1);
    }
}
