<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Overview\OverviewService;
use App\Domain\Website\WebsiteService;
use App\Http\Requests\StoreWebsiteRequest;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return $this->ok($request->user()->currentWorkspace->websites()->orderBy('name')->get());
    }

    public function store(StoreWebsiteRequest $request, WebsiteService $websites): JsonResponse
    {
        return $this->ok($websites->create($request->user()->currentWorkspace, $request->validated()), 201);
    }

    public function overview(Website $website, OverviewService $overview): JsonResponse
    {
        return $this->ok($overview->build($website));
    }
}
