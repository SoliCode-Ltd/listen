<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Support\SeoHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Tells the IndexNow search engines (Bing, Yandex, Naver, Seznam and others
 * that share it) that every public page exists or changed, so they crawl it
 * soon instead of waiting. Run after a deploy that adds or changes pages.
 * Google does not use IndexNow: it reads the sitemap from Search Console.
 */
class SubmitIndexNowCommand extends Command
{
    protected $signature = 'seo:indexnow {--dry-run : List the URLs without sending them}';

    protected $description = 'Send every public page in the sitemap to IndexNow (Bing, Yandex, Naver, Seznam).';

    public function handle(): int
    {
        $urls = array_values(array_unique(array_map(
            fn (array $entry): string => (string) $entry['url'],
            SeoHelper::publicSitemapEntries(),
        )));
        $host = (string) parse_url((string) ($urls[0] ?? config('app.url')), PHP_URL_HOST);

        if ($this->option('dry-run')) {
            $this->line(implode("\n", $urls));
            $this->info(count($urls).' URLs on '.$host.' (not sent).');

            return self::SUCCESS;
        }

        $key = trim((string) SiteSetting::get('indexnow_key', ''));
        if ($key === '') {
            $key = Str::lower(Str::random(32));
            SiteSetting::set('indexnow_key', $key, 'seo');
            $this->info('Made a new IndexNow key.');
        }

        $sent = 0;
        foreach (array_chunk($urls, 9000) as $batch) {
            $response = Http::timeout(30)->acceptJson()->post('https://api.indexnow.org/indexnow', [
                'host' => $host,
                'key' => $key,
                'keyLocation' => route('indexnow.key'),
                'urlList' => $batch,
            ]);
            if (! in_array($response->status(), [200, 202], true)) {
                $this->error('IndexNow answered HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300));

                return self::FAILURE;
            }
            $sent += count($batch);
        }

        $this->info("Sent {$sent} URLs on {$host} to IndexNow.");

        return self::SUCCESS;
    }
}
