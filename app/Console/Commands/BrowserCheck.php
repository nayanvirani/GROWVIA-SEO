<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Developer check: open every app page in headless Chrome as the first user and report JavaScript
 * errors, blank pages and horizontal overflow. Uses a temporary session that is deleted afterwards.
 */
class BrowserCheck extends Command
{
    protected $signature = 'dev:browser-check {--url=* : Only check these paths} {--chrome=google-chrome}';

    protected $description = 'Open all pages in headless Chrome and report JS errors and layout overflow';

    public function handle(): int
    {
        $user = User::first();
        $website = $user?->activeWorkspace()?->websites()->first();
        if (! $user || ! $website) {
            $this->error('Needs a user with a website.');

            return self::FAILURE;
        }

        $session = app('session')->driver();
        $session->start();
        $session->put(Auth::guard('web')->getName(), $user->id);
        $session->save();
        $name = config('session.cookie');
        $cookie = Crypt::encrypt(CookieValuePrefix::create($name, Crypt::getKey()).$session->getId(), false);

        $urls = $this->option('url') ? array_map(fn ($p) => url($p), $this->option('url')) : $this->allPages($website);
        $port = 9400 + random_int(0, 99);
        $dir = storage_path('framework/browser-check');
        @mkdir($dir, 0775, true);
        $config = "{$dir}/config.json";
        file_put_contents($config, json_encode(['port' => $port, 'cookie' => ['name' => $name, 'value' => $cookie], 'urls' => $urls, 'widths' => [1366, 1920]]));

        $chrome = new Process([$this->option('chrome'), '--headless=new', '--disable-gpu', '--no-first-run', "--user-data-dir={$dir}/profile", "--remote-debugging-port={$port}", 'about:blank']);
        $chrome->start();
        try {
            sleep(3);
            $check = new Process(['node', '--experimental-websocket', base_path('scripts/browser-check.mjs'), $config], base_path(), null, null, 600);
            $check->run(fn ($type, $out) => $this->output->write(preg_replace('/^.*ExperimentalWarning.*$\n?/m', '', $out)));

            return $check->isSuccessful() ? self::SUCCESS : self::FAILURE;
        } finally {
            $chrome->stop(2);
            DB::table('sessions')->where('id', $session->getId())->delete();
            @unlink($config);
            Process::fromShellCommandline('rm -rf '.escapeshellarg("{$dir}/profile"))->run();
        }
    }

    private function allPages(Website $w): array
    {
        $page = $w->pages()->where('status_code', 200)->first();
        $draft = $w->contentDrafts()->first();

        return array_values(array_filter([
            route('websites.show', $w), route('recommendations.index', $w), route('audit.index', $w), route('audit.pages', $w),
            $page ? route('audit.page', [$w, $page]) : null, route('keywords.index', $w), route('content.index', $w),
            $draft ? route('content.drafts.edit', [$w, $draft]) : null, route('images.index', $w), route('backlinks.index', $w),
            route('listings.index', $w), route('automation.index', $w), route('websites.settings', $w),
            route('team.index'), route('providers.index'), route('notifications.index'), route('profile.edit'),
        ]));
    }
}
