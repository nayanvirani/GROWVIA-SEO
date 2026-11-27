<?php

namespace App\Domain\Website;

use App\Models\Website;
use App\Models\Workspace;
use App\Support\ActivityLogger;
use App\Support\Security\HostGuard;
use App\Support\Url;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsiteService
{
    public const VERIFICATION_META = 'growvia-site-verification';

    public function create(Workspace $workspace, array $data): Website
    {
        $baseUrl = $this->validatedBaseUrl($data['base_url']);
        $host = Url::host($baseUrl);

        if ($workspace->websites()->where('normalized_host', $host)->exists()) {
            throw ValidationException::withMessages(['base_url' => 'This website already exists in the workspace.']);
        }

        $website = $workspace->websites()->create([
            'name' => $data['name'],
            'base_url' => $baseUrl,
            'normalized_host' => $host,
            'website_type' => $data['website_type'] ?? null,
            'language' => $data['language'] ?? 'en',
            'target_country' => isset($data['target_country']) ? strtolower($data['target_country']) : null,
            'ownership_confirmed' => (bool) ($data['ownership_confirmed'] ?? false),
            'settings_json' => array_filter([
                'description' => $data['description'] ?? null,
                'crawl_max_pages' => $data['crawl_max_pages'] ?? null,
                'crawl_max_depth' => $data['crawl_max_depth'] ?? null,
            ], fn ($v) => $v !== null),
        ]);

        foreach (array_filter($data['competitors'] ?? []) as $domain) {
            $website->competitors()->firstOrCreate(['domain' => Url::host($domain)]);
        }

        ActivityLogger::log($website, 'website.created', $website);

        return $website->refresh();
    }

    public function update(Website $website, array $data): Website
    {
        $settings = $website->settings();
        foreach (['autopilot', 'crawl_max_pages', 'crawl_max_depth', 'crawl_frequency', 'brand_voice', 'description', 'brand_facts', 'forbidden_claims', 'contact_email'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = $data[$key];
            }
        }
        if (array_key_exists('exclusion_paths', $data)) {
            $settings['exclusion_paths'] = collect(preg_split('/\r?\n/', (string) $data['exclusion_paths']))
                ->map(fn ($p) => trim($p))->filter()->values()->all();
        }

        $website->fill(collect($data)->only(['name', 'website_type', 'language', 'target_country', 'crawl_enabled', 'ownership_confirmed'])->all());
        $website->settings_json = $settings;
        $website->save();

        ActivityLogger::log($website, 'website.updated', $website, ['fields' => array_keys($data)]);

        return $website;
    }

    /** Issue a meta-tag token. Only the hash is stored; the plain token is shown once. */
    public function startVerification(Website $website): string
    {
        $token = Str::random(32);
        $website->verifications()->create(['method' => 'meta_tag', 'token_hash' => hash('sha256', $token)]);

        return $token;
    }

    public function verify(Website $website, string $token): bool
    {
        $verification = $website->verifications()
            ->where('token_hash', hash('sha256', $token))->whereNull('verified_at')->latest()->first();
        if (! $verification || ! HostGuard::isAllowedUrl($website->base_url)) {
            return false;
        }

        $response = Http::timeout(15)->withOptions(['allow_redirects' => false])
            ->withUserAgent(config('seo.crawler.user_agent'))->get($website->base_url);
        $pattern = '/<meta[^>]+name=["\']'.self::VERIFICATION_META.'["\'][^>]+content=["\']'.preg_quote($token, '/').'["\']/i';

        if ($response->successful() && preg_match($pattern, $response->body())) {
            $verification->update(['verified_at' => now()]);
            $website->update(['ownership_confirmed' => true, 'status' => 'verified']);
            ActivityLogger::log($website, 'website.verified', $website);

            return true;
        }

        return false;
    }

    private function validatedBaseUrl(string $url): string
    {
        try {
            $normalized = Url::normalize($url);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['base_url' => 'Enter a valid website URL.']);
        }
        if (! HostGuard::isAllowedUrl($normalized, resolve: ! app()->runningUnitTests())) {
            throw ValidationException::withMessages(['base_url' => 'Only public http(s) websites can be added.']);
        }
        $parts = parse_url($normalized);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/';
    }
}
