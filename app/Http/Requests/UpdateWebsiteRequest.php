<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy checked in controller
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'website_type' => ['nullable', 'string', 'max:120'],
            'language' => ['sometimes', 'string', 'max:10'],
            'target_country' => ['nullable', 'string', 'size:2'],
            'crawl_enabled' => ['sometimes', 'boolean'],
            'autopilot' => ['sometimes', 'boolean'],
            'ownership_confirmed' => ['sometimes', 'boolean'],
            'crawl_max_pages' => ['sometimes', 'integer', 'min:1', 'max:'.config('seo.crawler.max_pages_limit')],
            'crawl_max_depth' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'crawl_frequency' => ['sometimes', 'in:weekly,monthly,manual'],
            'exclusion_paths' => ['nullable', 'string', 'max:5000'],
            'brand_voice' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'brand_facts' => ['nullable', 'string', 'max:10000'],
            'forbidden_claims' => ['nullable', 'string', 'max:2000'],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }
}
