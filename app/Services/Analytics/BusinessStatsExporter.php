<?php

namespace App\Services\Analytics;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes a BusinessStatsService snapshot to a multi-sheet Excel workbook.
 */
class BusinessStatsExporter
{
    public function toXlsx(array $snapshot, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();
        $first = true;

        foreach ($this->sheets($snapshot) as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName(mb_substr($name, 0, 31));
            $first = false;

            foreach ($rows as $i => $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => $this->cell($v), $row), $i === 0 ? $bold : null));
            }
        }

        $writer->close();
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    public function sheets(array $s): array
    {
        $members = $s['members'] ?? [];
        $engagement = $s['engagement'] ?? [];
        $generations = $s['generations'] ?? [];
        $revenue = $s['revenue'] ?? [];
        $trials = $s['trials'] ?? [];
        $manual = $s['manual_payments'] ?? [];
        $channels = $s['growth_channels'] ?? [];

        $summary = [
            ['Section', 'Metric', 'Value'],
            ['Report', 'Generated at', $s['meta']['generated_at'] ?? ''],
            ['Report', 'Environment', $s['meta']['app_env'] ?? ''],
            ['Report', 'Period start', $s['meta']['since'] ?? ''],
            ['Members', 'Total members (excl. admins)', $members['total'] ?? 0],
            ['Members', 'New today', $members['new_today'] ?? 0],
            ['Members', 'New last 7 days', $members['new_7d'] ?? 0],
            ['Members', 'New last 30 days', $members['new_30d'] ?? 0],
            ['Members', 'New previous 30 days', $members['new_prev_30d'] ?? 0],
            ['Members', 'Email verified', $members['email_verified'] ?? 0],
            ['Members', 'Blocked', $members['blocked'] ?? 0],
            ['Engagement', 'Ever generated audio', $engagement['activated'] ?? 0],
            ['Engagement', 'Activation rate', $this->pct($engagement['activation_rate'] ?? 0)],
            ['Engagement', 'Active today', $engagement['active_today'] ?? 0],
            ['Engagement', 'Active last 7 days', $engagement['active_7d'] ?? 0],
            ['Engagement', 'Active last 30 days', $engagement['active_30d'] ?? 0],
            ['Engagement', 'Generations per active member (30d)', $engagement['generations_per_active_member_30d'] ?? 0],
            ['Engagement', 'Share of generations by top 10% members', $this->pct($engagement['top_10pct_share'] ?? 0)],
            ['Revenue', 'Paying members (card + local payment)', $revenue['members_on_paid_plans'] ?? 0],
            ['Revenue', 'Paying by card', $revenue['plan_holders']['card'] ?? 0],
            ['Revenue', 'Paying by local payment', $revenue['plan_holders']['manual'] ?? 0],
            ['Revenue', 'On a paid plan in a free trial (not paying)', $revenue['plan_holders']['trial'] ?? 0],
            ['Revenue', 'On a paid plan given free (not paying)', $revenue['plan_holders']['granted'] ?? 0],
            ['Revenue', 'Free-to-paid conversion', $this->pct($revenue['paid_conversion'] ?? 0)],
            ['Revenue', 'Paid card subscriptions', $revenue['paying_stripe'] ?? 0],
            ['Revenue', 'MRR source', ($revenue['mrr_source'] ?? '') === 'stripe' ? 'Stripe, after discounts' : 'List-price estimate (Stripe not read)'],
            ['Revenue', 'MRR (USD)', round(($revenue['mrr_cents'] ?? 0) / 100, 2)],
            ['Revenue', 'ARR (USD)', round(($revenue['mrr_cents'] ?? 0) * 12 / 100, 2)],
            ['Revenue', 'ARPU (USD / paid card subscription / month)', round(($revenue['arpu_cents'] ?? 0) / 100, 2)],
            ['Revenue', 'Paid card subscriptions ended (30d)', $revenue['canceled_30d'] ?? 0],
            ['Trials', 'Open trials', $trials['open'] ?? 0],
            ['Trials', 'Paid trials', $trials['paid'] ?? 0],
            ['Trials', 'Trial conversion (paid / finished)', $this->pct($trials['conversion'] ?? 0)],
            ['Trials', 'Trial revenue (USD)', round(($trials['revenue_cents'] ?? 0) / 100, 2)],
            ['Manual payments', 'Pending review', $manual['pending'] ?? 0],
            ['Generations', 'Total', $generations['total'] ?? 0],
            ['Generations', 'Last 30 days', $generations['last_30d'] ?? 0],
            ['Generations', 'Failure rate (30d)', $this->pct($generations['failure_rate_30d'] ?? 0)],
            ['Generations', 'Audio minutes (30d)', $generations['audio_minutes_30d'] ?? 0],
            ['Generations', 'Billable characters (30d)', $generations['billable_chars_30d'] ?? 0],
            ['Generations', 'Cost logged for member generations USD (30d)', $generations['est_cost_usd_30d'] ?? 0],
            ['Generations', 'Translated share (30d)', $this->pct($generations['translate_share_30d'] ?? 0)],
            ['Generations', 'Cloned voices', $generations['cloned_voices'] ?? 0],
            ['Generations', 'Blocked generations (30d)', $generations['blocked_generations_30d'] ?? 0],
            ['Growth', 'Demo generations total', $channels['demo_generations_total'] ?? 0],
            ['Growth', 'Demo generations (30d)', $channels['demo_generations_30d'] ?? 0],
            ['Growth', 'Demo visitors', $channels['demo_visitors'] ?? 0],
            ['Growth', 'Members who used the demo', $channels['demo_members'] ?? 0],
            ['Growth', 'Of them, members who have paid', $channels['demo_members_paid'] ?? 0],
            ['Growth', 'Referral visits', $channels['referral_visits'] ?? 0],
            ['Growth', 'Contact messages (30d)', $channels['contact_messages_30d'] ?? 0],
        ];
        foreach ($manual['approved_by_currency'] ?? [] as $currency => $amount) {
            $summary[] = ['Manual payments', "Approved total ({$currency})", $amount];
        }
        if ($recurring = $revenue['recurring'] ?? null) {
            $usd = fn (array $cents) => round(($cents['usd'] ?? 0) / 100, 2);
            $kinds = $recurring['kinds'];
            array_push($summary,
                ['Expected income', 'Renewing card subscriptions', $recurring['expected']['count']],
                ['Expected income', 'Expected monthly income before fees (USD)', $usd($recurring['expected']['gross_cents'])],
                ['Expected income', 'Card fee rate (last 90 days)', $recurring['fees']['rate'] === null ? '' : $this->pct($recurring['fees']['rate'])],
                ['Expected income', 'Expected monthly income after fees (USD)', $usd($recurring['expected']['net_cents'])],
                ['Expected income', 'Due in the next 30 days after fees (USD)', $usd($recurring['due_30d']['net_cents'])],
                ['Expected income', 'Cancelled, still in paid time', $kinds['cancelling']['count']],
                ['Expected income', 'Cancelled: monthly value that stops (USD)', $usd($kinds['cancelling']['monthly_cents'])],
                ['Expected income', 'In trial', $kinds['trialing']['count']],
                ['Expected income', 'Trials: monthly value if they pay (USD)', $usd($kinds['trialing']['monthly_cents'])],
                ['Expected income', 'Overdue renewals', $kinds['past_due']['count']],
                ['Expected income', 'Overdue: monthly value unpaid (USD)', $usd($kinds['past_due']['monthly_cents'])],
                ['Expected income', 'Read from Stripe at', $recurring['generated_at']],
            );
        }

        $sheets = [
            'Summary' => $summary,
            'Monthly' => $this->table($s['monthly'] ?? []),
            'Cohorts' => $this->table(array_map(fn ($r) => array_merge($r, [
                'activation_rate' => $this->pct($r['activation_rate']),
                'retention_month_1' => $r['retention_month_1'] === null ? null : $this->pct($r['retention_month_1']),
                'retention_month_2' => $r['retention_month_2'] === null ? null : $this->pct($r['retention_month_2']),
            ]), $s['cohorts'] ?? [])),
            'Plans' => $this->table($s['plans'] ?? []),
        ];

        $api = $s['api_usage'] ?? [];
        $unit = $s['unit_economics'] ?? [];
        $apiRows = [['Metric', 'Last 30 days', 'Selected period', 'All time']];
        $apiMetrics = [
            'requests' => 'Generations (all sources)',
            'api_requests' => 'Provider API calls',
            'cache_hits' => 'Served from cache',
            'failed' => 'Failed generations',
            'translated' => 'Translated requests',
            'chars' => 'Characters processed',
            'api_chars' => 'Characters sent to providers',
            'member_chars' => 'Characters — members',
            'demo_chars' => 'Characters — demo',
            'admin_chars' => 'Characters — admins',
            'audio_minutes' => 'Audio minutes',
            'cost' => 'API cost (USD)',
            'recorded_cost' => 'Cost logged by the app (USD)',
            'member_cost' => 'Cost — members (USD)',
            'demo_cost' => 'Cost — demo (USD)',
            'admin_cost' => 'Cost — admins (USD)',
            'cost_per_1k_chars' => 'Cost per 1,000 characters (USD)',
            'cost_per_audio_minute' => 'Cost per audio minute (USD)',
        ];
        foreach ($apiMetrics as $key => $label) {
            $apiRows[] = [
                $label,
                $api['totals_30d'][$key] ?? 0,
                $api['totals_period'][$key] ?? 0,
                $api['totals_all_time'][$key] ?? 0,
            ];
        }
        $apiRows[] = [];
        $apiRows[] = ['Unit economics (last 30 days)', 'Value'];
        $apiRows[] = ['MRR (USD, list price)', $unit['mrr_usd'] ?? 0];
        $apiRows[] = ['API cost (USD)', $unit['api_cost_30d'] ?? 0];
        $apiRows[] = ['Gross margin (USD)', $unit['gross_margin_usd'] ?? 0];
        $apiRows[] = ['Gross margin', isset($unit['gross_margin_rate']) ? $this->pct($unit['gross_margin_rate']) : '—'];
        $apiRows[] = ['API cost per active member (USD)', $unit['api_cost_per_active_member'] ?? 0];
        $apiRows[] = ['Revenue per paying member (USD / month)', $unit['revenue_per_paying_member'] ?? 0];
        $apiRows[] = ['Demo share of API cost', $this->pct($unit['demo_cost_share'] ?? 0)];

        $modelRows = fn (array $rows) => $this->table(array_map(fn ($r) => array_merge($r, [
            'failure_rate' => $this->pct($r['failure_rate']),
        ]), $rows));

        $sheets['API usage'] = $apiRows;
        $sheets['API by model (30d)'] = $modelRows($api['by_model_30d'] ?? []);
        $sheets['API by model (period)'] = $modelRows($api['by_model_period'] ?? []);
        $sheets['API monthly'] = $this->table($api['monthly'] ?? []);
        $sheets['Plan quota usage'] = $this->table($api['quota_chars_by_month'] ?? []);

        $audience = $s['audience'] ?? [];
        $sheets['Countries'] = $this->table(array_map(fn (array $r) => [
            'country' => $r['country'],
            'code' => $r['code'],
            'region' => $r['region'],
            'members' => $r['members'],
            'active_30d' => $r['active_30d'],
            'paying' => $r['paying'],
        ], $audience['countries'] ?? []));
        $sheets['Regions'] = $this->table($audience['regions'] ?? []);

        $breakdowns = [
            ['Members', 'Signup method', $members['signup_method'] ?? []],
            ['Engagement', 'Generations per member', $engagement['generations_per_member'] ?? []],
            ['Generations', 'Status (all time)', $generations['by_status'] ?? []],
            ['Generations', 'Voice tier (30d)', $generations['by_voice_tier_30d'] ?? []],
            ['Generations', 'Target language (30d)', $generations['by_language_30d'] ?? []],
            ['Generations', 'Performance style (30d)', $generations['by_style_30d'] ?? []],
            ['Revenue', 'Members by plan', $revenue['members_by_plan'] ?? []],
            ['Revenue', 'Paying Stripe subs by plan', $revenue['paying_by_plan'] ?? []],
            ['Revenue', 'Stripe subscription status', $revenue['stripe_by_status'] ?? []],
            ['Trials', 'Trial status', $trials['by_status'] ?? []],
            ['Trials', 'Trial plan', $trials['by_plan'] ?? []],
            ['Manual payments', 'Status', $manual['by_status'] ?? []],
            ['Manual payments', 'Method', $manual['by_method'] ?? []],
            ['Growth', 'Referral conversions', $channels['referral_conversions'] ?? []],
            ['API', 'Characters by plan (30d)', $api['chars_by_plan_30d'] ?? []],
            ['Audience', 'Email type', $audience['email_types'] ?? []],
            ['Audience', 'Arabic dialect requested (period)', $audience['dialects'] ?? []],
        ];
        $rows = [['Section', 'Breakdown', 'Item', 'Count']];
        foreach ($breakdowns as [$section, $label, $pairs]) {
            foreach ($pairs as $item => $count) {
                $rows[] = [$section, $label, (string) $item, $count];
            }
        }
        $sheets['Breakdowns'] = $rows;

        return $sheets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function table(array $rows): array
    {
        if ($rows === []) {
            return [['No data']];
        }

        $headers = array_keys($rows[0]);

        return array_merge(
            [array_map(fn ($h) => ucfirst(str_replace('_', ' ', $h)), $headers)],
            array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? null, $headers), $rows)
        );
    }

    private function pct(float $ratio): string
    {
        return round($ratio * 100, 1).'%';
    }

    private function cell(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            default => json_encode($value),
        };
    }
}
