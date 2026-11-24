<?php

namespace App\Providers\Analytics;

use App\Models\Integration;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Search Console searchAnalytics.query adapter using a stored, encrypted OAuth refresh token. */
class GoogleSearchConsoleProvider implements SearchPerformanceProvider
{
    public function __construct(private readonly Integration $integration, private readonly array $config) {}

    public function name(): string
    {
        return 'search_console';
    }

    public function queryPageMetrics(Website $website, string $property, string $start, string $end): array
    {
        $token = $this->accessToken();
        $rows = [];
        $startRow = 0;
        do {
            $response = Http::withToken($token)->timeout(60)->post(
                'https://searchconsole.googleapis.com/webmasters/v3/sites/'.rawurlencode($property).'/searchAnalytics/query',
                ['startDate' => $start, 'endDate' => $end, 'dimensions' => ['date', 'page', 'query'], 'rowLimit' => 5000, 'startRow' => $startRow]
            );
            if ($response->failed()) {
                throw new RuntimeException('Search Console query failed with HTTP '.$response->status());
            }
            $batch = $response->json('rows', []);
            foreach ($batch as $r) {
                $rows[] = [
                    'date' => $r['keys'][0], 'page_url' => $r['keys'][1], 'query' => $r['keys'][2],
                    'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'],
                    'ctr' => (float) $r['ctr'], 'position' => (float) $r['position'],
                ];
            }
            $startRow += 5000;
        } while (count($batch) === 5000 && $startRow < 50000);

        return $rows;
    }

    private function accessToken(): string
    {
        $refresh = $this->integration->encrypted_credentials['refresh_token'] ?? null;
        if (! $refresh) {
            throw new RuntimeException('Search Console is not connected.');
        }
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        if ($response->failed()) {
            throw new RuntimeException('Could not refresh the Search Console token.');
        }

        return (string) $response->json('access_token');
    }
}
