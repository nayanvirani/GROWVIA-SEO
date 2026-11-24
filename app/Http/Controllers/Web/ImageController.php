<?php

namespace App\Http\Controllers\Web;

use App\Domain\Content\ContentService;
use App\Http\Controllers\Controller;
use App\Models\ImageAsset;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ImageController extends Controller
{
    public function index(Website $website): Response
    {
        return Inertia::render('images/Index', [
            'images' => $website->imageAssets()->with('draft:id,title')->latest()->limit(100)->get(),
            'drafts' => $website->contentDrafts()->latest()->limit(100)->get(['id', 'title']),
        ]);
    }

    public function store(Request $request, Website $website, ContentService $content): RedirectResponse
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'topic' => ['required_without:content_draft_id', 'nullable', 'string', 'max:200'],
            'content_draft_id' => ['nullable', Rule::exists('content_drafts', 'id')->where('website_id', $website->id)],
            'aspect_ratio' => ['required', Rule::in(['16:9', '1:1', '4:3', '3:2', '9:16'])],
            'placement' => ['nullable', 'string', 'max:100'],
            'audience' => ['nullable', 'string', 'max:200'],
        ]);
        $draft = isset($data['content_draft_id']) ? $website->contentDrafts()->find($data['content_draft_id']) : null;
        $content->imageBrief($website, $draft, $data);

        return back()->with('success', 'Image brief created. Review before using any generated image.');
    }

    public function update(Request $request, Website $website, ImageAsset $image): RedirectResponse
    {
        $this->authorize('update', $image);
        $image->update($request->validate([
            'alt_text' => ['nullable', 'string', 'max:250'],
            'prompt' => ['sometimes', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['brief', 'approved', 'rejected'])],
        ]));

        return back();
    }

    public function destroy(Website $website, ImageAsset $image): RedirectResponse
    {
        $this->authorize('delete', $image);
        $image->delete();

        return back();
    }
}
