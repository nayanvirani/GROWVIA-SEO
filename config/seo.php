<?php

return [
    'crawler' => [
        'base_url' => env('CRAWLER_BASE_URL', 'http://127.0.0.1:8100'),
        'token' => env('CRAWLER_API_TOKEN'),
        'user_agent' => env('CRAWLER_USER_AGENT', 'GrowviaSEOBot/1.0'),
        'max_pages_limit' => (int) env('CRAWLER_MAX_PAGES_LIMIT', 1000),
        'poll_seconds' => (int) env('CRAWLER_POLL_SECONDS', 5),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'mock'),
        'api_key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        'model_small' => env('AI_MODEL_SMALL', 'gpt-4o-mini'),
        'model_large' => env('AI_MODEL_LARGE', 'gpt-4o'),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 3000),
        'prompt_version' => 'v1',
    ],

    'seo_data' => [
        'provider' => env('SEO_DATA_PROVIDER', 'mock'),
        'api_key' => env('SEO_DATA_API_KEY'),
        'cache_days' => 30,
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim(env('APP_URL', ''), '/').'/integrations/search-console/callback'),
    ],

    'audit' => [
        'title_min' => 15,
        'title_max' => 65,
        'description_min' => 50,
        'description_max' => 170,
        'thin_content_words' => 150,
    ],
];
