<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Keyword\KeywordResearchService;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KeywordController extends ApiController
{
    public function research(Request $request, Website $website, KeywordResearchService $research): JsonResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'seeds' => ['required', 'array', 'min:1', 'max:10'],
            'seeds.*' => ['required', 'string', 'max:120'],
            'locale' => ['required', 'string', 'size:2'],
            'language' => ['required', 'string', 'max:10'],
        ]);

        return $this->ok($research->research($website, $data['seeds'], strtolower($data['locale']), $data['language']), 201);
    }

    public function index(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate(['intent' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:120']]);

        return $this->ok($website->keywords()->with('clusters:id,name')
            ->when($data['intent'] ?? null, fn ($q, $i) => $q->where('intent', $i))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where('keyword', 'like', "%{$s}%"))
            ->orderByDesc('volume')->paginate(100));
    }
}
