<?php

namespace App\Domain\Backlink;

use App\Domain\AI\AiService;
use App\Domain\Content\ContentService;
use App\Models\BacklinkProspect;
use App\Models\OutreachDraft;
use App\Models\Website;
use App\Support\ActivityLogger;
use App\Support\Security\HostGuard;
use App\Support\Url;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BacklinkService
{
    public const CSV_COLUMNS = ['source_url', 'target_url', 'anchor_text', 'page_title', 'rel', 'domain_rating', 'organic_traffic', 'notes'];

    public function __construct(
        private readonly ProspectScorer $scorer,
        private readonly AiService $ai,
    ) {}

    /** Import from a CSV upload. Returns [imported, skipped]. */
    public function importCsv(Website $website, UploadedFile $file): array
    {
        $h = fopen($file->getRealPath(), 'r');
        $header = array_map(fn ($c) => Str::snake(trim((string) $c)), fgetcsv($h, escape: '') ?: []);
        if (! in_array('source_url', $header, true)) {
            throw ValidationException::withMessages(['file' => 'CSV must include a source_url column.']);
        }
        $imported = $skipped = 0;
        $rowNo = 0;
        while (($row = fgetcsv($h, escape: '')) !== false && $rowNo++ < 5000) {
            $data = array_intersect_key(array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null)), array_flip(self::CSV_COLUMNS));
            $this->upsertProspect($website, $data, 'csv') ? $imported++ : $skipped++;
        }
        fclose($h);
        ActivityLogger::log($website, 'backlinks.csv_imported', null, compact('imported', 'skipped'));

        return [$imported, $skipped];
    }

    public function upsertProspect(Website $website, array $data, string $source, string $type = 'resource_page', array $evidence = []): ?BacklinkProspect
    {
        $url = trim((string) ($data['source_url'] ?? ''));
        try {
            $url = Url::normalize($url);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (! HostGuard::isAllowedUrl($url, resolve: false)) {
            return null; // private hosts, odd schemes
        }

        $scored = $this->scorer->score($data);
        $prospect = BacklinkProspect::firstOrNew(['website_id' => $website->id, 'source_url_hash' => Url::hash($url)]);
        $prospect->fill([
            'source_url' => $url,
            'source_domain' => Url::host($url),
            'target_url' => $data['target_url'] ?? $website->base_url,
            'page_title' => isset($data['page_title']) ? Str::limit($data['page_title'], 250, '') : null,
            'anchor_text' => isset($data['anchor_text']) ? Str::limit($data['anchor_text'], 250, '') : null,
            'prospect_type' => $type,
            'score' => $scored['score'],
            'score_breakdown_json' => $scored['breakdown'],
            'risk_flags_json' => $scored['risk_flags'],
            'evidence_json' => array_filter($evidence + [
                'rel' => $data['rel'] ?? null, 'notes' => $data['notes'] ?? null,
                'discovery_source' => $source, 'observed' => now()->toDateString(),
            ]),
            'discovery_source' => $source,
        ]);
        $prospect->workspace_id = $website->workspace_id;
        if (! $prospect->exists) {
            $prospect->status = $scored['hard_reject'] ? 'rejected' : 'discovered';
        }
        $prospect->save();

        return $prospect;
    }

    public function outreachDraft(BacklinkProspect $prospect): OutreachDraft
    {
        if ($prospect->status === 'rejected') {
            throw ValidationException::withMessages(['prospect' => 'Rejected prospects cannot be contacted.']);
        }
        $result = $this->ai->run($prospect->website, 'outreach_email', [
            'prospect_title' => $prospect->page_title,
            'source_domain' => $prospect->source_domain,
            'source_url' => $prospect->source_url,
            'target_url' => $prospect->target_url,
            'rules' => 'Personal, honest, no promises of payment or followed links, easy to decline.',
        ]);

        $draft = $prospect->outreachDrafts()->create([
            'website_id' => $prospect->website_id,
            'subject' => Str::limit($result['data']['subject'], 250, ''),
            'body' => $result['data']['body'],
            'status' => 'draft',
        ]);
        ActivityLogger::log($prospect->website, 'outreach.drafted', $draft);

        return $draft;
    }

    public function exportCsv(Website $website, ContentService $content): string
    {
        $rows = [['source_url', 'source_domain', 'page_title', 'target_url', 'score', 'status', 'risk_flags', 'discovery_source']];
        foreach ($website->backlinkProspects()->orderByDesc('score')->cursor() as $p) {
            $rows[] = [$p->source_url, $p->source_domain, $p->page_title, $p->target_url, $p->score, $p->status,
                implode('|', $p->risk_flags_json ?? []), $p->discovery_source];
        }

        return $content->csv($rows);
    }
}
