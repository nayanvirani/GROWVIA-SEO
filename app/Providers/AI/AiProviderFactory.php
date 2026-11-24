<?php

namespace App\Providers\AI;

use App\Domain\Settings\ProviderSettings;

class AiProviderFactory
{
    public function __construct(private readonly ProviderSettings $settings) {}

    public function forWorkspace(?string $workspaceId): AiContentProvider
    {
        if (! $workspaceId) {
            return new MockAiProvider;
        }
        $s = $this->settings->get($workspaceId, 'ai');

        return $this->make($s['preset'], $s['base_url'], $s['api_key'], $s['model']);
    }

    public function make(string $preset, ?string $baseUrl, ?string $apiKey, ?string $model): AiContentProvider
    {
        if ($preset === 'mock') {
            return new MockAiProvider;
        }

        return new OpenAiCompatibleProvider([
            'name' => $preset,
            'base_url' => $baseUrl ?: config("seo.ai_presets.{$preset}.base_url"),
            'api_key' => $apiKey,
            'model' => $model,
            'max_output_tokens' => config('seo.ai.max_output_tokens'),
        ]);
    }

    public function isReal(?string $workspaceId): bool
    {
        if (! $workspaceId) {
            return false;
        }
        $s = $this->settings->get($workspaceId, 'ai');

        return $s['preset'] !== 'mock' && $s['configured'] && filled($s['model']);
    }
}
