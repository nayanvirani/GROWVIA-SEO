<?php

namespace App\Domain\Settings;

use App\Models\Integration;

/**
 * Per-workspace provider choices stored in the integrations table.
 * API keys live in the encrypted credentials column and never reach the browser.
 */
class ProviderSettings
{
    public const KINDS = ['ai', 'search'];

    /** @return array{preset: string, base_url: ?string, model: ?string, api_key: ?string, configured: bool} */
    public function get(string $workspaceId, string $kind): array
    {
        $row = $this->row($workspaceId, $kind);
        $meta = $row?->metadata_json ?? [];
        $preset = $meta['preset'] ?? ($kind === 'ai' ? config('seo.ai.provider', 'mock') : 'duckduckgo');
        $presets = config($kind === 'ai' ? 'seo.ai_presets' : 'seo.search_presets');
        if (! isset($presets[$preset])) {
            $preset = $kind === 'ai' ? 'mock' : 'duckduckgo';
        }
        $key = $row?->encrypted_credentials['api_key'] ?? null;

        return [
            'preset' => $preset,
            'base_url' => $meta['base_url'] ?? ($presets[$preset]['base_url'] ?? null),
            'model' => $meta['model'] ?? null,
            'api_key' => $key,
            'configured' => ! ($presets[$preset]['needs_key'] ?? false) || filled($key),
        ];
    }

    /** Public view for the settings page: never includes the key itself. */
    public function describe(string $workspaceId, string $kind): array
    {
        $s = $this->get($workspaceId, $kind);
        $key = $s['api_key'];
        unset($s['api_key']);

        return $s + ['has_key' => filled($key), 'key_hint' => $key ? '••••'.substr($key, -4) : null];
    }

    public function save(string $workspaceId, string $kind, array $data): void
    {
        $row = $this->row($workspaceId, $kind) ?? new Integration(['workspace_id' => $workspaceId, 'provider' => $kind]);
        $credentials = $row->encrypted_credentials ?? [];
        if (! empty($data['clear_key'])) {
            $credentials = [];
        } elseif (filled($data['api_key'] ?? null)) {
            $credentials['api_key'] = trim($data['api_key']);
        }
        $row->encrypted_credentials = $credentials ?: null;
        $row->metadata_json = array_filter([
            'preset' => $data['preset'],
            'base_url' => isset($data['base_url']) ? rtrim(trim($data['base_url']), '/') : null,
            'model' => isset($data['model']) ? trim($data['model']) : null,
        ], fn ($v) => $v !== null && $v !== '');
        $row->status = 'connected';
        $row->save();
    }

    private function row(string $workspaceId, string $kind): ?Integration
    {
        return Integration::where('workspace_id', $workspaceId)->whereNull('website_id')->where('provider', $kind)->first();
    }
}
