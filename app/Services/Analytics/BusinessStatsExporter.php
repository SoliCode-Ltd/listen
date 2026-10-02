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
            ['Revenue', 'Members on paid plans', $revenue['members_on_paid_plans'] ?? 0],
            ['Revenue', 'Free-to-paid conversion', $this->pct($revenue['paid_conversion'] ?? 0)],
            ['Revenue', 'Paying Stripe subscriptions', $revenue['paying_stripe'] ?? 0],
            ['Revenue', 'Est. MRR (USD, list price)', round(($revenue['mrr_cents'] ?? 0) / 100, 2)],
            ['Revenue', 'Est. ARR (USD, list price)', round(($revenue['mrr_cents'] ?? 0) * 12 / 100, 2)],
            ['Revenue', 'ARPU (USD / paying sub / month)', round(($revenue['arpu_cents'] ?? 0) / 100, 2)],
            ['Revenue', 'Stripe subscriptions ended (30d)', $revenue['canceled_30d'] ?? 0],
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
            ['Generations', 'Est. TTS cost USD (30d)', $generations['est_cost_usd_30d'] ?? 0],
            ['Generations', 'Translated share (30d)', $this->pct($generations['translate_share_30d'] ?? 0)],
            ['Generations', 'Cloned voices', $generations['cloned_voices'] ?? 0],
            ['Generations', 'Blocked generations (30d)', $generations['blocked_generations_30d'] ?? 0],
            ['Growth', 'Demo generations total', $channels['demo_generations_total'] ?? 0],
            ['Growth', 'Demo generations (30d)', $channels['demo_generations_30d'] ?? 0],
            ['Growth', 'Demo visitors', $channels['demo_visitors'] ?? 0],
            ['Growth', 'Demo visitors who signed up', $channels['demo_visitors_signed_up'] ?? 0],
            ['Growth', 'Referral visits', $channels['referral_visits'] ?? 0],
            ['Growth', 'Contact messages (30d)', $channels['contact_messages_30d'] ?? 0],
        ];
        foreach ($manual['approved_by_currency'] ?? [] as $currency => $amount) {
            $summary[] = ['Manual payments', "Approved total ({$currency})", $amount];
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
            'Providers (30d)' => $this->table(array_map(fn ($r) => array_merge($r, [
                'failure_rate' => $this->pct($r['failure_rate']),
                'fallback_rate' => $this->pct($r['fallback_rate']),
            ]), $s['providers'] ?? [])),
        ];

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
