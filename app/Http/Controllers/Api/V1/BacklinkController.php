<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Backlink\BacklinkService;
use App\Models\BacklinkProspect;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BacklinkController extends ApiController
{
    public function __construct(private readonly BacklinkService $backlinks) {}

    public function import(Request $request, Website $website): JsonResponse
    {
        $this->authorize('update', $website);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        [$imported, $skipped] = $this->backlinks->importCsv($website, $request->file('file'));

        return $this->ok(compact('imported', 'skipped'), 201);
    }

    public function prospects(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(BacklinkProspect::STATUSES)]]);

        return $this->ok($website->backlinkProspects()
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('score')->paginate(50));
    }

    public function outreach(BacklinkProspect $prospect): JsonResponse
    {
        $this->authorize('update', $prospect);

        return $this->ok($this->backlinks->outreachDraft($prospect), 201);
    }
}
