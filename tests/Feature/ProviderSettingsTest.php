<?php

namespace Tests\Feature;

use App\Domain\AI\AiService;
use App\Domain\Settings\ProviderSettings;
use App\Models\Integration;
use App\Providers\AI\AiProviderFactory;
use App\Providers\AI\OpenAiCompatibleProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function saveGroq($user): void
    {
        $this->actingAs($user)->patch(route('providers.update', 'ai'), [
            'preset' => 'groq', 'api_key' => 'gsk_secret_1234', 'model' => 'llama-3.3-70b-versatile',
        ])->assertSessionHasNoErrors();
    }

    public function test_default_is_offline_demo_and_page_lists_free_providers()
    {
        [$user] = $this->userWithWebsite();
        $this->actingAs($user)->get(route('providers.index'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('providers/Index')
            ->where('ai.preset', 'mock')
            ->where('search.preset', 'duckduckgo')
            ->has('aiPresets.gemini')->has('aiPresets.groq')->has('aiPresets.openrouter')->has('aiPresets.ollama')
            ->has('searchPresets.tavily'));
    }

    public function test_key_is_encrypted_never_sent_to_browser_and_kept_when_blank()
    {
        [$user] = $this->userWithWebsite();
        $this->saveGroq($user);

        $raw = DB::table('integrations')->where('provider', 'ai')->value('encrypted_credentials');
        $this->assertStringNotContainsString('gsk_secret_1234', $raw);
        $this->actingAs($user)->get(route('providers.index'))->assertDontSee('gsk_secret_1234')
            ->assertInertia(fn ($p) => $p->where('ai.has_key', true)->where('ai.key_hint', '••••1234')->missing('ai.api_key'));

        // Saving again without a key keeps the stored one.
        $this->actingAs($user)->patch(route('providers.update', 'ai'), ['preset' => 'groq', 'model' => 'other-model']);
        $s = app(ProviderSettings::class)->get($user->current_workspace_id, 'ai');
        $this->assertSame('gsk_secret_1234', $s['api_key']);
        $this->assertSame('other-model', $s['model']);
    }

    public function test_model_is_required_and_custom_urls_must_be_public()
    {
        [$user] = $this->userWithWebsite();
        $this->actingAs($user)->patch(route('providers.update', 'ai'), ['preset' => 'gemini', 'api_key' => 'k'])->assertSessionHasErrors('model');
        $this->actingAs($user)->patch(route('providers.update', 'ai'), ['preset' => 'custom', 'base_url' => 'http://127.0.0.1:9000/v1', 'model' => 'x'])
            ->assertSessionHasErrors('base_url');
        $this->actingAs($user)->patch(route('providers.update', 'ai'), ['preset' => 'ollama', 'base_url' => 'http://127.0.0.1:11434/v1', 'model' => 'llama3.2'])
            ->assertSessionHasNoErrors();
    }

    public function test_load_models_uses_typed_key_and_filters_openrouter_free_models()
    {
        [$user] = $this->userWithWebsite();
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => [['id' => 'paid/model'], ['id' => 'meta/llama:free'], ['id' => 'google/gemma:free']]])]);

        $this->actingAs($user)->post(route('providers.models'), ['preset' => 'openrouter', 'api_key' => 'sk-or-typed'])
            ->assertSessionHas('flash_data.models', ['google/gemma:free', 'meta/llama:free']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-or-typed'));
        $this->assertSame(0, Integration::count(), 'loading models stores nothing');
    }

    public function test_ai_service_uses_the_workspace_provider_and_handles_free_tier_quirks()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->saveGroq($user);
        Http::fake(['api.groq.com/openai/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'response_format json_object not supported']], 400)
            ->push(['model' => 'llama-3.3-70b-versatile', 'choices' => [['message' => ['content' => "Sure!\n```json\n{\"explanation\":\"x\",\"next_step\":\"y\",\"confidence\":\"high\"}\n```"]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]])
            ->push(['error' => ['message' => 'Rate limit reached']], 429)
            ->push(['error' => ['message' => 'Rate limit reached']], 429),
        ]);

        $ai = app(AiService::class);
        $this->assertTrue($ai->isReal($website));
        $result = $ai->run($website, 'explain_issue', ['issue_summary' => 'x']);
        $this->assertSame('groq', $result['provider']);
        $this->assertSame('y', $result['data']['next_step']);
        Http::assertSent(fn ($r) => ! isset($r['response_format']) && $r['model'] === 'llama-3.3-70b-versatile');

        try {
            $ai->run($website, 'explain_issue', ['issue_summary' => 'x']);
            $this->fail('Expected rate limit error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('free limit', $e->getMessage());
        }
    }

    public function test_connection_test_button()
    {
        [$user] = $this->userWithWebsite();
        $this->saveGroq($user);
        Http::fake(['api.groq.com/*' => Http::sequence()
            ->push(['model' => 'm', 'choices' => [['message' => ['content' => '{"results":[{"keyword":"best shopify upsell app","intent":"commercial"}]}']]]])
            ->push(['error' => ['message' => 'bad key']], 401)]);
        $this->actingAs($user)->post(route('providers.test', 'ai'))->assertSessionHas('success');

        $this->actingAs($user)->post(route('providers.test', 'ai'))->assertSessionHas('error', fn ($m) => str_contains($m, 'rejected the API key'));
    }

    public function test_json_extraction()
    {
        $this->assertSame(['a' => 1], OpenAiCompatibleProvider::extractJson("```json\n{\"a\":1}\n```"));
        $this->assertSame(['a' => 1], OpenAiCompatibleProvider::extractJson('Here you go: {"a":1} hope it helps'));
        $this->assertSame([], OpenAiCompatibleProvider::extractJson('no json'));
    }

    public function test_recommends_a_current_general_model()
    {
        $gemini = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-3.8-flash', 'gemini-3.8-flash-tts', 'gemini-3-flash-preview',
            'gemini-2.5-flash-native-audio-preview-12-2025', 'gemini-flash-latest', 'gemini-omni-1.2-flash'];
        $this->assertSame('gemini-flash-latest', OpenAiCompatibleProvider::recommend($gemini, 'flash'));
        $this->assertSame('gemini-3.8-flash', OpenAiCompatibleProvider::recommend(array_diff($gemini, ['gemini-flash-latest']), 'flash'));
        $this->assertSame('llama-3.3-70b-versatile', OpenAiCompatibleProvider::recommend(['whisper-large-v3', 'llama-3.1-8b-instant', 'llama-3.3-70b-versatile', 'llama-guard-4'], 'llama'));
    }

    public function test_retired_model_error_is_explained_and_saved_on_the_brief()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->patch(route('providers.update', 'ai'), ['preset' => 'gemini', 'api_key' => 'AIza-test', 'model' => 'gemini-2.5-flash']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([['error' => ['code' => 404,
            'message' => 'This model models/gemini-2.5-flash is no longer available to new users.']]], 404)]);

        $brief = $website->contentBriefs()->create(['title' => 'Shopify bundles', 'status' => 'idea']);
        $this->actingAs($user)->post(route('content.briefs.generate', [$website, $brief]));

        $brief->refresh();
        $this->assertSame('generation_failed', $brief->status);
        $this->assertStringContainsString('no longer available to new users', $brief->last_error);
        $this->assertStringContainsString('Load models', $brief->last_error);
    }

    public function test_recommends_the_big_general_model_from_a_groq_list()
    {
        $groq = ['allam-2-7b', 'canopylabs/orpheus-v1-english', 'meta-llama/llama-prompt-guard-2-86m', 'openai/gpt-oss-120b',
            'openai/gpt-oss-20b', 'openai/gpt-oss-safeguard-20b', 'qwen/qwen3.8-27b', 'whisper-large-v3'];
        $this->assertSame('openai/gpt-oss-120b', OpenAiCompatibleProvider::recommend($groq, null));
    }

    public function test_backup_ai_takes_over_when_main_is_out_of_quota_but_not_on_bad_keys()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->patch(route('providers.update', 'ai'), ['preset' => 'groq', 'api_key' => 'gsk_main', 'model' => 'openai/gpt-oss-120b']);
        $this->actingAs($user)->patch(route('providers.update', 'ai_backup'), ['preset' => 'groq', 'model' => 'qwen/qwen3.8-27b'])->assertSessionHasNoErrors();

        Http::fake(['api.groq.com/*' => function ($request) {
            return $request['model'] === 'openai/gpt-oss-120b'
                ? Http::response(['error' => ['message' => 'Rate limit reached for model']], 429)
                : Http::response(['model' => 'qwen/qwen3.8-27b', 'choices' => [['message' => ['content' => '{"explanation":"x","next_step":"y","confidence":"high"}']]]]);
        }]);
        $result = app(AiService::class)->run($website, 'explain_issue', ['issue_summary' => 'x']);
        $this->assertSame('groq (backup)', $result['provider']);
        $this->assertSame('qwen/qwen3.8-27b', $result['model']);
        Http::assertSent(fn ($r) => $r['model'] === 'qwen/qwen3.8-27b' && $r->hasHeader('Authorization', 'Bearer gsk_main'), 'same provider reuses the main key');

        // A wrong key is a configuration problem: no silent failover.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);
        try {
            app(AiService::class)->run($website, 'explain_issue', ['issue_summary' => 'x']);
            $this->fail('expected error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rejected the API key', $e->getMessage());
            $this->assertStringNotContainsString('Backup AI', $e->getMessage());
        }

        $this->actingAs($user)->get(route('providers.index'))->assertInertia(fn ($p) => $p->where('backup.preset', 'groq')->where('backup.model', 'qwen/qwen3.8-27b')->has('backupPresets.none'));
        $this->actingAs($user)->patch(route('providers.update', 'ai_backup'), ['preset' => 'none'])->assertSessionHasNoErrors();
        $this->assertNull(app(AiProviderFactory::class)->backupFor($website->workspace_id));
    }
}
