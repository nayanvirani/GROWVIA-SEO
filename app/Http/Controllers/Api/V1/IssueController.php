<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRules;
use App\Models\SeoIssue;
use App\Models\Website;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IssueController extends ApiController
{
    public function index(Request $request, Website $website): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(SeoIssue::STATUSES)],
            'severity' => ['nullable', Rule::in(['high', 'medium', 'low', 'info'])],
            'rule_key' => ['nullable', Rule::in(array_keys(AuditRules::RULES))],
        ]);
        $issues = $website->issues()->with('page:id,canonical_url')
            ->where('status', $f['status'] ?? 'open')
            ->when($f['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($f['rule_key'] ?? null, fn ($q, $r) => $q->where('rule_key', $r))
            ->paginate(50);
        $issues->getCollection()->transform(fn ($i) => $i->setAttribute('explanation', AuditRules::get($i->rule_key)));

        return $this->ok($issues);
    }

    public function update(Request $request, SeoIssue $issue): JsonResponse
    {
        $this->authorize('update', $issue);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'dismissed'])]]);
        $issue->update($data);
        ActivityLogger::log($issue->website, 'issue.'.$data['status'], $issue);

        return $this->ok($issue);
    }
}
