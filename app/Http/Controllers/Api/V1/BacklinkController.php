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
        $data = $request->validate([
            'file' => ['required_without:competitor_id', 'file', 'mimes:csv,txt', 'max:2048'],
            'competitor_id' => ['required_without:file', Rule::exists('competitors', 'id')->where('website_id', $website->id)],
        ]);
        [$imported, $skipped] = isset($data['competitor_id'])
            ? $this->backlinks->importFromProvider($website, $website->competitors()->findOrFail($data['competitor_id']))
            : $this->backlinks->importCsv($website, $request->file('file'));

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
