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
        $primary = $this->make($s['preset'], $s['base_url'], $s['api_key'], $s['model']);

        $b = $this->settings->get($workspaceId, 'ai_backup');
        if ($s['preset'] === 'mock' || $b['preset'] === 'none' || blank($b['model'])) {
            return $primary;
        }
        // Same provider as the main AI (e.g. a second Groq model): reuse the main key.
        $key = $b['api_key'] ?? ($b['preset'] === $s['preset'] ? $s['api_key'] : null);

        return new FailoverAiProvider($primary, $this->make($b['preset'], $b['base_url'], $key, $b['model']));
    }

    /** The backup alone (for the settings page Test button). */
    public function backupFor(string $workspaceId): ?AiContentProvider
    {
        $s = $this->settings->get($workspaceId, 'ai');
        $b = $this->settings->get($workspaceId, 'ai_backup');
        if ($b['preset'] === 'none' || blank($b['model'])) {
            return null;
        }

        return $this->make($b['preset'], $b['base_url'], $b['api_key'] ?? ($b['preset'] === $s['preset'] ? $s['api_key'] : null), $b['model']);
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
