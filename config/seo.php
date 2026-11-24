<?php

return [
    'crawler' => [
        'base_url' => env('CRAWLER_BASE_URL', 'http://127.0.0.1:8100'),
        'token' => env('CRAWLER_API_TOKEN'),
        'user_agent' => env('CRAWLER_USER_AGENT', 'GrowviaSEOBot/1.0'),
        'max_pages_limit' => (int) env('CRAWLER_MAX_PAGES_LIMIT', 1000),
        'poll_seconds' => (int) env('CRAWLER_POLL_SECONDS', 5),
    ],

    // Fallback when a workspace has not chosen a provider in Settings → AI & data providers.
    'ai' => [
        'provider' => env('AI_PROVIDER', 'mock'),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 4000),
        'prompt_version' => 'v1',
    ],

    /*
    | Free AI providers. All speak the OpenAI-compatible chat API, so one adapter serves them all.
    | Model names change often: the settings page loads the live list from each provider's /models.
    */
    'ai_presets' => [
        'mock' => [
            'label' => 'Offline demo (no AI)',
            'base_url' => null,
            'needs_key' => false,
            'signup_url' => null,
            'note' => 'Template text only. Good for trying the app; switch to a real provider for useful drafts.',
        ],
        'gemini' => [
            'label' => 'Google Gemini (free tier)',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'needs_key' => true,
            'signup_url' => 'https://aistudio.google.com/apikey',
            'model_hint' => 'flash',
            'note' => 'Generous free tier, best quality for long articles. Google may use free-tier prompts to improve its products.',
        ],
        'groq' => [
            'label' => 'Groq (free tier)',
            'base_url' => 'https://api.groq.com/openai/v1',
            'needs_key' => true,
            'signup_url' => 'https://console.groq.com/keys',
            'model_hint' => 'llama',
            'note' => 'Very fast, no credit card. Daily request limits per model.',
        ],
        'openrouter' => [
            'label' => 'OpenRouter (free models)',
            'base_url' => 'https://openrouter.ai/api/v1',
            'needs_key' => true,
            'signup_url' => 'https://openrouter.ai/keys',
            'model_hint' => ':free',
            'free_filter' => ':free',
            'note' => 'Pick a model ending in ":free". About 50 free requests per day.',
        ],
        'cerebras' => [
            'label' => 'Cerebras (free tier)',
            'base_url' => 'https://api.cerebras.ai/v1',
            'needs_key' => true,
            'signup_url' => 'https://cloud.cerebras.ai',
            'model_hint' => 'llama',
            'note' => 'Fast open models with a free daily allowance.',
        ],
        'mistral' => [
            'label' => 'Mistral (free Experiment plan)',
            'base_url' => 'https://api.mistral.ai/v1',
            'needs_key' => true,
            'signup_url' => 'https://console.mistral.ai/api-keys',
            'model_hint' => 'small',
            'note' => 'Free experiment plan with rate limits.',
        ],
        'ollama' => [
            'label' => 'Ollama (runs on this computer)',
            'base_url' => 'http://127.0.0.1:11434/v1',
            'needs_key' => false,
            'signup_url' => 'https://ollama.com/download',
            'model_hint' => 'llama3.2',
            'note' => 'Completely free and private, but slow on a laptop without a strong GPU.',
        ],
        'custom' => [
            'label' => 'Other OpenAI-compatible API',
            'base_url' => '',
            'needs_key' => true,
            'signup_url' => null,
            'note' => 'Any service with an OpenAI-compatible /chat/completions endpoint.',
        ],
    ],

    /* Web search used to discover backlink opportunities automatically. */
    'search_presets' => [
        'duckduckgo' => [
            'label' => 'DuckDuckGo (no key needed)',
            'needs_key' => false,
            'needs_url' => false,
            'signup_url' => null,
            'note' => 'Works out of the box. Runs slowly (a few searches per minute) to stay polite; may occasionally be blocked.',
        ],
        'tavily' => [
            'label' => 'Tavily (1,000 free searches/month)',
            'needs_key' => true,
            'needs_url' => false,
            'signup_url' => 'https://app.tavily.com',
            'note' => 'More reliable. Free key, no credit card.',
        ],
        'searxng' => [
            'label' => 'SearXNG (self-hosted)',
            'needs_key' => false,
            'needs_url' => true,
            'signup_url' => 'https://docs.searxng.org',
            'note' => 'Your own SearXNG instance with the JSON format enabled.',
        ],
        'none' => [
            'label' => 'Off',
            'needs_key' => false,
            'needs_url' => false,
            'signup_url' => null,
            'note' => 'Backlink discovery is disabled; you can still import a CSV.',
        ],
    ],

    'seo_data' => [
        // autocomplete = free Google + DuckDuckGo suggestions; mock = fixtures for tests
        'provider' => env('SEO_DATA_PROVIDER', 'autocomplete'),
        'cache_days' => 30,
    ],

    'autopilot' => [
        'max_seeds' => 8,
        'max_search_queries' => 12,
        'max_prospect_checks' => 15,
        'auto_briefs' => 3,
        'auto_drafts' => 1,
        'auto_outreach' => 5,
    ],

    'audit' => [
        'title_min' => 15,
        'title_max' => 65,
        'description_min' => 50,
        'description_max' => 170,
        'thin_content_words' => 150,
    ],
];
