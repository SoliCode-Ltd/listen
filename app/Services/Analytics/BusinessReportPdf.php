<?php

namespace App\Services\Analytics;

use App\Support\Countries;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

/**
 * The investor-ready PDF version of the business report.
 *
 * Built from the same snapshot as the dashboard and the Excel export, so the
 * numbers always match. Only aggregate figures are shown: no names, emails or
 * internal details such as blocked accounts or the server environment.
 */
class BusinessReportPdf
{
    public const BRAND = '#6D4CFF';

    /** Language codes the platform speaks, as readers expect to see them. */
    private const LANGUAGES = ['ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'de' => 'German', 'tr' => 'Turkish'];

    public function render(array $snapshot): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('chroot', [public_path(), resource_path()]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('reports.business-analytics', $this->viewData($snapshot))->render());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Footer on every page but the cover.
        $product = 'Listen Studio';
        $dompdf->getCanvas()->page_script(function (int $page, int $pages, $canvas, $fonts) use ($product) {
            if ($page === 1) {
                return;
            }
            $font = $fonts->getFont('DejaVu Sans');
            $y = $canvas->get_height() - 34;
            $grey = [0.42, 0.43, 0.5];
            $canvas->line(45, $y - 8, $canvas->get_width() - 45, $y - 8, [0.9, 0.9, 0.94], 0.6);
            $canvas->text(45, $y, $product.' · Business Analytics Report · Confidential', $font, 7, $grey);
            $label = "Page {$page} of {$pages}";
            $canvas->text($canvas->get_width() - 45 - $fonts->getTextWidth($label, $font, 7), $y, $label, $font, 7, $grey);
        });

        return (string) $dompdf->output();
    }

    /**
     * Everything the template needs, already worked out.
     */
    public function viewData(array $s): array
    {
        $generatedAt = Carbon::parse($s['meta']['generated_at'] ?? now());
        $since = Carbon::parse($s['meta']['since'] ?? $generatedAt->copy()->subMonths(11)->startOfMonth());
        $members = $s['members'] ?? [];
        $engagement = $s['engagement'] ?? [];
        $generations = $s['generations'] ?? [];
        $revenue = $s['revenue'] ?? [];
        $unit = $s['unit_economics'] ?? [];
        $apiTotals = $s['api_usage']['totals_30d'] ?? [];

        $monthly = $this->monthlyRows($s['monthly'] ?? [], $generatedAt);
        // Months before the first sign-up or audio say nothing; start the story there.
        while (count($monthly) > 1 && $monthly[0]['signups'] === 0 && $monthly[0]['generations'] === 0 && $monthly[0]['demo_generations'] === 0) {
            array_shift($monthly);
        }
        $complete = array_values(array_filter($monthly, fn (array $row) => ! $row['partial']));

        $mrr = (float) ($unit['mrr_usd'] ?? (($revenue['mrr_cents'] ?? 0) / 100));
        $newPrev = (int) ($members['new_prev_30d'] ?? 0);
        $new30 = (int) ($members['new_30d'] ?? 0);

        $languages = $generations['by_language_30d'] ?? [];
        $languageTotal = max(1, array_sum($languages));

        return [
            'r' => $this,
            'brand' => self::BRAND,
            'logo' => $this->logoDataUri(),
            'company' => 'SoliCode Ltd',
            'product' => 'Listen Studio',
            'contact' => (string) config('mail.from.address', ''),
            'website' => preg_replace('#^https?://#', '', rtrim((string) config('app.url', ''), '/')),
            'generatedAt' => $generatedAt,
            'periodLabel' => ($monthly[0]['label'] ?? $since->format('M Y')).' – '.$generatedAt->format('M Y'),
            'months' => (int) ($s['meta']['months'] ?? 12),

            'kpis' => [
                ['label' => 'Members', 'value' => $this->num($members['total'] ?? 0), 'note' => 'Registered accounts'],
                ['label' => 'New members', 'value' => $this->num($new30), 'note' => $newPrev > 0 ? $this->signedPct(($new30 - $newPrev) / $newPrev).' vs previous 30 days' : 'First full period'],
                ['label' => 'Active members', 'value' => $this->num($engagement['active_30d'] ?? 0), 'note' => $this->num($engagement['active_7d'] ?? 0).' in the last 7 days'],
                ['label' => 'Activation rate', 'value' => $this->pct($engagement['activation_rate'] ?? 0), 'note' => 'Members who created audio'],
                ['label' => 'Audio created', 'value' => $this->num($generations['audio_minutes_30d'] ?? 0).' min', 'note' => $this->num($generations['last_30d'] ?? 0).' generations'],
                ['label' => 'Monthly revenue (MRR)', 'value' => $this->money($mrr), 'note' => $this->money($mrr * 12).' annual run rate'],
                ['label' => 'Paying members', 'value' => $this->num($revenue['members_on_paid_plans'] ?? 0), 'note' => $this->pct($revenue['paid_conversion'] ?? 0, 2).' of members'],
                ['label' => 'Gross margin', 'value' => isset($unit['gross_margin_rate']) && $unit['gross_margin_rate'] !== null ? $this->pct($unit['gross_margin_rate']) : '—', 'note' => 'Revenue after voice API costs'],
            ],

            'highlights' => $this->highlights($s, $complete, $languages, $languageTotal),

            'monthly' => $monthly,
            'charts' => [
                'signups' => $this->chart($monthly, 'signups'),
                'active' => $this->chart($monthly, 'active_members'),
                'generations' => $this->chart($monthly, 'generations'),
                'minutes' => $this->chart($monthly, 'audio_minutes'),
            ],
            'monthlyTotals' => [
                'signups' => array_sum(array_column($monthly, 'signups')),
                'generations' => array_sum(array_column($monthly, 'generations')),
                'audio_minutes' => array_sum(array_column($monthly, 'audio_minutes')),
                'demo_generations' => array_sum(array_column($monthly, 'demo_generations')),
            ],

            'engagement' => $engagement,
            'distribution' => $this->share($engagement['generations_per_member'] ?? []),
            'cohorts' => array_slice(array_values(array_filter($s['cohorts'] ?? [], fn (array $row) => ($row['signups'] ?? 0) > 0)), -6),

            'generations' => $generations,
            'languages' => collect($languages)->map(fn ($n, $code) => [
                'label' => self::LANGUAGES[strtolower((string) $code)] ?? strtoupper((string) $code),
                'value' => (int) $n,
                'share' => $n / $languageTotal,
            ])->values()->all(),
            'tiers' => $this->share(collect($generations['by_voice_tier_30d'] ?? [])
                ->mapWithKeys(fn ($n, $tier) => [match ((string) $tier) {
                    'hd' => 'HD (premium)',
                    'standard' => 'Standard',
                    default => ucfirst((string) $tier),
                } => $n])->all()),
            'styles' => $this->share(collect($generations['by_style_30d'] ?? [])
                ->mapWithKeys(fn ($n, $style) => [ucwords(str_replace(['_', '-'], ' ', (string) $style)) => $n])
                ->take(6)->all()),
            'providers' => collect($s['providers'] ?? [])->map(fn (array $p) => $p + [
                'label' => $this->providerLabel((string) ($p['provider'] ?? '')),
            ])->all(),

            'audience' => $this->audience($s),

            'revenue' => $revenue,
            'mrr' => $mrr,
            'plans' => $s['plans'] ?? [],
            'trials' => $s['trials'] ?? [],
            'manual' => $s['manual_payments'] ?? [],
            'unit' => $unit,
            'api' => $apiTotals,
            'channels' => $s['growth_channels'] ?? [],
        ];
    }

    /**
     * Where members are and what they are like, ready for the Audience page.
     */
    private function audience(array $s): array
    {
        $a = $s['audience'] ?? [];
        $members = max(1, (int) ($a['members'] ?? ($s['members']['total'] ?? 0)));
        $known = (int) ($a['with_country'] ?? 0);
        $rows = collect($a['countries'] ?? []);
        $knownRows = $rows->whereNotNull('code')->values();
        $top = $knownRows->take(8)->all();
        $rest = $knownRows->slice(8);

        $other = $rest->isEmpty() ? null : [
            'country' => $rest->count().' more countries',
            'region' => '',
            'members' => $rest->sum('members'),
            'active_30d' => $rest->sum('active_30d'),
            'paying' => $rest->sum('paying'),
        ];
        $unknown = $rows->firstWhere('code', null);

        $regions = collect($a['regions'] ?? [])->reject(fn ($r) => $r['region'] === Countries::UNKNOWN);
        $topCountry = $knownRows->first();
        $emails = $a['email_types'] ?? [];
        $emailTotal = max(1, array_sum($emails));

        return [
            'has_countries' => $known > 0,
            'members' => $members,
            'known' => $known,
            'coverage' => $known / $members,
            'country_count' => (int) ($a['country_count'] ?? 0),
            'arab_share' => $known > 0 ? ($a['arab_world_members'] ?? 0) / $known : 0,
            'top_country' => $topCountry ? $topCountry['country'] : null,
            'top_country_share' => $topCountry && $known > 0 ? $topCountry['members'] / $known : 0,
            'work_email_share' => ($emails['Work email'] ?? 0) / $emailTotal,
            'countries' => array_map(fn (array $r) => $r + ['share' => $known > 0 ? $r['members'] / $known : 0], $top),
            'other' => $other ? $other + ['share' => $known > 0 ? $other['members'] / $known : 0] : null,
            'unknown' => $unknown ? (int) $unknown['members'] : 0,
            'regions' => $this->share($regions->mapWithKeys(fn ($r) => [$r['region'] => $r['members']])->all()),
            'region_rows' => $regions->values()->all(),
            'signup' => $this->share($s['members']['signup_method'] ?? []),
            'emails' => $this->share($emails),
            'dialects' => $this->share(collect($a['dialects'] ?? [])
                ->mapWithKeys(fn ($n, $label) => [$label === 'Modern Standard Arabic' ? 'Fusha (MSA)' : $label => $n])->all()),
        ];
    }

    /**
     * Plain-English findings, each one taken straight from the numbers.
     *
     * @param  array<int, array<string, mixed>>  $complete
     * @param  array<string, int>  $languages
     * @return array<int, string>
     */
    private function highlights(array $s, array $complete, array $languages, int $languageTotal): array
    {
        $out = [];
        $members = $s['members'] ?? [];
        $engagement = $s['engagement'] ?? [];
        $generations = $s['generations'] ?? [];

        $new30 = (int) ($members['new_30d'] ?? 0);
        $prev = (int) ($members['new_prev_30d'] ?? 0);
        if ($new30 > 0) {
            $out[] = $prev > 0
                ? sprintf('%s new members joined in the last 30 days, %s compared with the 30 days before.', $this->num($new30), $this->signedPct(($new30 - $prev) / $prev))
                : sprintf('%s new members joined in the last 30 days.', $this->num($new30));
        }

        if (count($complete) >= 2) {
            $last = $complete[count($complete) - 1];
            $first = $complete[max(0, count($complete) - 4)];
            if ($first['active_members'] > 0 && $last['active_members'] !== $first['active_members']) {
                $out[] = sprintf(
                    'Monthly active members went from %s in %s to %s in %s (%s).',
                    $this->num($first['active_members']), $first['label'],
                    $this->num($last['active_members']), $last['label'],
                    $this->signedPct(($last['active_members'] - $first['active_members']) / $first['active_members'])
                );
            }
        }

        $audience = $s['audience'] ?? [];
        $known = (int) ($audience['with_country'] ?? 0);
        if ($known >= 20) {
            $leaders = collect($audience['countries'] ?? [])->whereNotNull('code')->take(2)
                ->map(fn ($r) => sprintf('%s (%s)', $r['country'], $this->pct($r['members'] / $known, 0)))->implode(' and ');
            $out[] = sprintf(
                'Members come from %s countries; %s are in the Arab world, led by %s.',
                $this->num($audience['country_count'] ?? 0),
                $this->pct(($audience['arab_world_members'] ?? 0) / $known, 0),
                $leaders
            );
        }

        $arabic = (int) ($languages['ar'] ?? 0);
        if ($arabic > 0) {
            $out[] = sprintf('%s of audio generated in the last 30 days was Arabic, the core market Listen Studio is built for.', $this->pct($arabic / $languageTotal, 0));
        }

        if (($generations['last_30d'] ?? 0) > 0) {
            $out[] = sprintf(
                '%s generations produced %s minutes of audio in the last 30 days, with a %s success rate.',
                $this->num($generations['last_30d']),
                $this->num($generations['audio_minutes_30d'] ?? 0),
                $this->pct(1 - (float) ($generations['failure_rate_30d'] ?? 0), 1)
            );
        }

        if (($engagement['activated'] ?? 0) > 0) {
            $out[] = sprintf(
                '%s members (%s) have created audio; active members average %s generations a month.',
                $this->num($engagement['activated']),
                $this->pct($engagement['activation_rate'] ?? 0),
                $this->num($engagement['generations_per_active_member_30d'] ?? 0, 1)
            );
        }

        $demo = $s['growth_channels'] ?? [];
        if (($demo['demo_generations_total'] ?? 0) > 0) {
            $out[] = sprintf(
                'The free web demo has served %s generations to %s visitors.',
                $this->num($demo['demo_generations_total']),
                $this->num($demo['demo_visitors'] ?? 0)
            );
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function monthlyRows(array $rows, Carbon $generatedAt): array
    {
        $current = $generatedAt->format('Y-m');

        return array_map(function (array $row) use ($current) {
            $month = Carbon::createFromFormat('Y-m-d', ($row['month'] ?? '1970-01').'-01');

            return [
                'month' => $row['month'] ?? '',
                'label' => $month->format('M Y'),
                'short' => $month->format('M'),
                'partial' => ($row['month'] ?? '') === $current,
                'signups' => (int) ($row['signups'] ?? 0),
                'active_members' => (int) ($row['active_members'] ?? 0),
                'generations' => (int) ($row['generations'] ?? 0),
                'audio_minutes' => (float) ($row['audio_minutes'] ?? 0),
                'demo_generations' => (int) ($row['demo_generations'] ?? 0),
                'paid_starts' => (int) ($row['stripe_started'] ?? 0) + (int) ($row['manual_approved'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * Bars for one series, scaled to the tallest month.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{bars: array<int, array<string, mixed>>, max: float}
     */
    private function chart(array $rows, string $key): array
    {
        $max = max(1, ...array_map(fn (array $row) => (float) $row[$key], $rows ?: [['x' => 0, $key => 0]]));

        return [
            'max' => $max,
            'bars' => array_map(fn (array $row) => [
                'label' => $row['short'],
                'value' => $row[$key],
                'height' => (int) round(((float) $row[$key] / $max) * 100),
                'partial' => $row['partial'],
            ], $rows),
        ];
    }

    /**
     * @param  array<string, int|float>  $pairs
     * @return array<int, array{label: string, value: int|float, share: float}>
     */
    private function share(array $pairs): array
    {
        $total = max(1, array_sum($pairs));

        return collect($pairs)->map(fn ($n, $label) => [
            'label' => (string) $label,
            'value' => $n,
            'share' => $n / $total,
        ])->values()->all();
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'google_cloud' => 'Google Cloud Text-to-Speech',
            'gemini' => 'Google Gemini speech',
            'cache' => 'Served from cache (no API cost)',
            default => ucwords(str_replace('_', ' ', $provider)),
        };
    }

    private function logoDataUri(): ?string
    {
        $path = public_path('images/email-logo-en.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    // Formatting used by the template.

    public function num(int|float|null $value, int $decimals = 0): string
    {
        $value = (float) ($value ?? 0);

        return number_format($value, floor($value) === $value ? 0 : $decimals);
    }

    public function pct(int|float|null $ratio, int $decimals = 1): string
    {
        return number_format(((float) ($ratio ?? 0)) * 100, $decimals).'%';
    }

    public function signedPct(float $ratio): string
    {
        return ($ratio >= 0 ? '+' : '−').number_format(abs($ratio) * 100, 0).'%';
    }

    public function money(int|float|null $usd, int $decimals = 0): string
    {
        $usd = (float) ($usd ?? 0);
        $decimals = $decimals === 0 && abs($usd) < 100 && floor($usd) !== $usd ? 2 : $decimals;

        return ($usd < 0 ? '−' : '').'$'.number_format(abs($usd), $decimals);
    }
}
