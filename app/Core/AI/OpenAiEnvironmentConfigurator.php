<?php

namespace App\Core\AI;

use Dotenv\Dotenv;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

final class OpenAiEnvironmentConfigurator
{
    public function configure(string $path, string $key): void
    {
        if (! preg_match('/\Ask-[A-Za-z0-9_-]{16,480}\z/', $key)) {
            throw new InvalidArgumentException('Enter a valid OpenAI API key.');
        }
        if (! is_file($path) || ! is_readable($path) || ! is_writable($path) || ! is_writable(dirname($path))) {
            throw new RuntimeException('The environment file must exist and be writable.');
        }

        $original = file_get_contents($path);
        $values = Dotenv::parse($original);
        $settings = [
            'AI_API_ENDPOINT' => 'https://api.openai.com/v1',
            'AI_API_KEY' => $key,
            'AI_CHAT_MODEL' => trim($values['AI_CHAT_MODEL'] ?? '') ?: 'gpt-4o-mini',
            'AI_EMBEDDING_MODEL' => trim($values['AI_EMBEDDING_MODEL'] ?? '') ?: 'text-embedding-3-small',
            'AI_REQUEST_TIMEOUT' => trim($values['AI_REQUEST_TIMEOUT'] ?? '') ?: '45',
        ];
        $updated = $original;
        foreach ($settings as $name => $value) {
            // Preserve custom model names while producing valid quoted dotenv values.
            $line = $name.'="'.str_replace(['\\', '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '', ''], $value).'"';
            $pattern = '/^[\t ]*(?:export[\t ]+)?'.preg_quote($name, '/').'[\t ]*=.*(?:\r?\n|$)/m';
            $updated = preg_replace($pattern, '', $updated);
            $updated = rtrim($updated, "\r\n").PHP_EOL.$line.PHP_EOL;
        }
        if (file_get_contents($path) !== $original) {
            throw new RuntimeException('Environment changed during setup; please try again.');
        }

        // Atomic replacement keeps unrelated secrets intact and never makes the file public-readable.
        (new Filesystem)->replace($path, $updated, (fileperms($path) & 0640) | 0600);
    }
}
