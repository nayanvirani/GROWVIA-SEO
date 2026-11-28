<?php

namespace App\Providers\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One adapter for every OpenAI-compatible chat API (Gemini, Groq, OpenRouter, Cerebras,
 * Mistral, Ollama, ...). Free tiers vary, so it degrades gracefully: falls back when JSON
 * mode is unsupported, extracts JSON from fenced replies and explains rate limits plainly.
 */
class OpenAiCompatibleProvider implements AiContentProvider
{
    private const LONG_TASKS = ['blog_draft', 'landing_page', 'content_brief', 'listing_pack'];

    /** @param array{name: string, base_url: string, api_key: ?string, model: ?string, max_output_tokens?: int} $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return $this->config['name'];
    }

    public function generate(string $task, array $context, array $schema): array
    {
        if (blank($this->config['model'])) {
            throw new RuntimeException('Choose a model in Settings → AI & data providers.');
        }
        $payload = [
            'model' => $this->config['model'],
            'temperature' => 0.4,
            'max_tokens' => $this->config['max_output_tokens'] ?? 4000,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $context['_system']."\nRespond with a single JSON object only, no markdown."],
                ['role' => 'user', 'content' => json_encode([
                    'task' => $task,
                    'required_json_keys' => $schema['required'] ?? [],
                    'context' => collect($context)->except('_system')->all(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ],
        ];

        // Long articles need time; short tasks (outreach, intent, relevance) should fail fast.
        $timeout = in_array($task, self::LONG_TASKS, true) ? 180 : 120;
        $response = $this->post('/chat/completions', $payload, $timeout);
        if ($response->status() === 400 && str_contains(strtolower($response->body()), 'response_format')) {
            unset($payload['response_format']); // provider/model without JSON mode
            $response = $this->post('/chat/completions', $payload, $timeout);
        }
        $this->throwIfFailed($response);

        return [
            'data' => self::extractJson((string) $response->json('choices.0.message.content')),
            'model' => (string) $response->json('model', $this->config['model']),
            'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ];
    }

    /** @return list<string> model ids offered by the provider */
    public function listModels(): array
    {
        $response = $this->http()->timeout(20)->get($this->url('/models'));
        $this->throwIfFailed($response);

        return collect($response->json('data', $response->json('models', [])))
            ->map(fn ($m) => is_array($m) ? ($m['id'] ?? $m['name'] ?? null) : $m)
            ->filter()->map(fn ($id) => preg_replace('#^models/#', '', $id))
            ->unique()->sort()->values()->all();
    }

    /**
     * Pick a sensible general-purpose text model: prefer a "-latest" alias (kept current by the
     * provider), then the newest version; skip audio/image/embedding/preview/lite variants.
     */
    public static function recommend(array $models, ?string $hint): ?string
    {
        // Not general text models: speech, images, safety filters, embeddings, niche-language or preview builds.
        $skip = '/(tts|image|audio|live|embed|vision|whisper|orpheus|guard|safeguard|moderation|preview|exp\b|lite|nano|omni|robotics|computer|search|transcribe|allam|-\d{4}$|\d{2}-\d{4})/i';
        $general = array_values(array_filter($models, fn ($m) => ! preg_match($skip, $m)));
        $ok = array_values(array_filter($general, fn ($m) => ! $hint || str_contains($m, $hint))) ?: $general;
        if (! $ok) {
            return $models[0] ?? null;
        }
        $latest = array_values(array_filter($ok, fn ($m) => str_ends_with($m, '-latest')));
        if ($latest) {
            return $latest[0];
        }
        // Prefer the biggest model ("120b" over "27b"), then the newest version.
        usort($ok, fn ($a, $b) => [self::size($b), self::version($b)] <=> [self::size($a), self::version($a)]);

        return $ok[0];
    }

    private static function size(string $model): int
    {
        return preg_match('/(\d+)b\b/i', $model, $m) ? (int) $m[1] : 0;
    }

    private static function version(string $model): string
    {
        return preg_match('/(\d+(?:\.\d+)*)/', $model, $m) ? $m[1] : '0';
    }

    public static function extractJson(string $content): array
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $content, $m)) {
            $content = trim($m[1]);
        }
        $data = json_decode($content, true);
        if (! is_array($data) && ($start = strpos($content, '{')) !== false && ($end = strrpos($content, '}')) > $start) {
            $data = json_decode(substr($content, $start, $end - $start + 1), true);
        }

        return is_array($data) ? $data : [];
    }

    /** Free tiers are often briefly overloaded (502/503/529): wait and retry before giving up. */
    private function post(string $path, array $payload, int $timeout = 180): Response
    {
        return $this->http()->timeout($timeout)
            ->retry(3, fn (int $attempt) => $attempt * 4000, fn ($e) => $e instanceof RequestException
                && in_array($e->response->status(), [500, 502, 503, 504, 529], true), throw: false)
            ->post($this->url($path), $payload);
    }

    private function http()
    {
        $http = Http::acceptJson()->connectTimeout(10)->withHeaders(['X-Title' => config('app.name')]);

        return filled($this->config['api_key']) ? $http->withToken($this->config['api_key']) : $http;
    }

    private function url(string $path): string
    {
        if (blank($this->config['base_url'])) {
            throw new RuntimeException('The AI provider URL is not set.');
        }

        return rtrim($this->config['base_url'], '/').$path;
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $json = $response->json();
        if (is_array($json) && array_is_list($json)) {
            $json = $json[0] ?? []; // Gemini wraps errors in a list
        }
        $detail = data_get($json, 'error.message') ?? data_get($json, 'error') ?? data_get($json, 'message') ?? '';
        $detail = is_string($detail) ? mb_substr($detail, 0, 200) : '';

        throw new RuntimeException(match (true) {
            $response->status() === 429 => "The free limit of {$this->name()} was reached. Try again later or pick another provider. {$detail}",
            in_array($response->status(), [500, 502, 503, 504, 529], true) => "{$this->name()} is busy right now (tried 3 times). Try again in a few minutes. {$detail}",
            in_array($response->status(), [401, 403], true) => "{$this->name()} rejected the API key. Check it in Settings → AI & data providers.",
            $response->status() === 404 => "{$this->name()} cannot use model \"{$this->config['model']}\". Click Load models and pick the recommended one. {$detail}",
            default => "{$this->name()} returned HTTP {$response->status()}. {$detail}",
        });
    }
}
