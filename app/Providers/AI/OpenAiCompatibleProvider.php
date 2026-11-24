<?php

namespace App\Providers\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Any chat-completions compatible endpoint (OpenAI or a compatible gateway). */
class OpenAiCompatibleProvider implements AiContentProvider
{
    private const LONG_FORM_TASKS = ['blog_draft', 'landing_page', 'content_brief'];

    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'openai';
    }

    public function generate(string $task, array $context, array $schema): array
    {
        if (empty($this->config['api_key'])) {
            throw new RuntimeException('AI_API_KEY is not configured.');
        }
        $model = in_array($task, self::LONG_FORM_TASKS, true) ? $this->config['model_large'] : $this->config['model_small'];

        $response = Http::withToken($this->config['api_key'])
            ->timeout(120)
            ->retry(2, 1000, throw: false)
            ->post(rtrim($this->config['base_url'], '/').'/chat/completions', [
                'model' => $model,
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => $this->config['max_output_tokens'],
                'messages' => [
                    ['role' => 'system', 'content' => $context['_system']],
                    ['role' => 'user', 'content' => json_encode([
                        'task' => $task,
                        'output_schema' => $schema,
                        'context' => collect($context)->except('_system')->all(),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('AI provider request failed with HTTP '.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        $data = json_decode((string) $content, true);

        return [
            'data' => is_array($data) ? $data : [],
            'model' => $response->json('model', $model),
            'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ];
    }
}
