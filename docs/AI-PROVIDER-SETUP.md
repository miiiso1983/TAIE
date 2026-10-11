# OpenAI setup

Run these commands in the current release directory on Cloudways with PHP 8.4:

```sh
/usr/bin/php8.4 artisan teami:ai:configure-openai
/usr/bin/php8.4 artisan teami:ai:check
```

The first command prompts for the API key with hidden input and no visible-input fallback. Never send the key through chat, a screenshot, command arguments, or source control. It sets `AI_API_ENDPOINT=https://api.openai.com/v1`, preserves existing chat and embedding model selections, and defaults empty selections to `gpt-4o-mini` and `text-embedding-3-small`. It refreshes the configuration cache and restarts queue workers gracefully. Application, audit and database secrets remain unchanged. The `.env` replacement is atomic and keeps group readability when present while removing public readability.

The check makes one chat request and one embedding request using synthetic text. Provider charges may apply; it neither sends company documents nor consumes tenants' 25-request trial allowance. Both capabilities must pass before considering the provider verified. A configured key alone does not establish working billing, model access or delivery. Errors never print the key or the provider response body.

Create an API key in your OpenAI project and ensure API billing and access to both models are enabled. Scope the key to the required chat completions and embeddings capabilities. Keep `AI_EMBEDDING_MODEL` unchanged for existing indexed documents; changing it requires reindexing. SMTP delivery remains a separate configuration.

Official references: [API authentication](https://developers.openai.com/api/reference/overview), [embeddings](https://developers.openai.com/api/reference/resources/embeddings/methods/create).
