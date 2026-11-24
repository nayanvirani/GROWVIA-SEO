<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->current_workspace_id !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['required', 'string', 'max:2048'],
            'website_type' => ['nullable', 'string', 'max:120'],
            'language' => ['required', 'string', 'max:10'],
            'target_country' => ['nullable', 'string', 'size:2'],
            'description' => ['nullable', 'string', 'max:2000'],
            'ownership_confirmed' => ['accepted'],
            'competitors' => ['nullable', 'array', 'max:10'],
            'competitors.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['ownership_confirmed.accepted' => 'Confirm that you own or are authorised to analyse this website.'];
    }
}
