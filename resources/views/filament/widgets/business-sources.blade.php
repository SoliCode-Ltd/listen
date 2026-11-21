{{-- Dashboard → Where members come from (App\Filament\Widgets\BusinessSources, App\Services\Acquisition\SourceReport). --}}
@php
    use App\Services\Acquisition\SourceReport;
    use Illuminate\Support\Carbon;

    $totals = $sources['totals'] ?? ['members' => 0, 'paid' => 0, 'within_day' => 0, 'money' => []];
    $channels = $sources['channels'] ?? [];
    $payments = $sources['payments'] ?? ['rows' => [], 'payments' => 0, 'money' => []];
    $recordedSince = ! empty($sources['recorded_since']) ? Carbon::parse($sources['recorded_since']) : null;
    $periodStart = $since ? Carbon::parse($since) : null;
    // One currency to a line, so the amounts never run out of their column.
    $money = fn (array $amounts): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(implode('<br>', array_map('e', SourceReport::moneyLines($amounts))));
    $th = 'px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400';
    $thNum = 'px-3 py-2 text-end text-xs font-medium text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 text-start text-gray-950 dark:text-white';
    $tdNum = 'px-3 py-2 text-end tabular-nums text-gray-950 dark:text-white';
    $tdMuted = 'px-3 py-2 text-end tabular-nums text-gray-500 dark:text-gray-400';
    $lists = [
        ['title' => 'Campaigns', 'note' => 'The campaign named in the link (utm_campaign), or the Google Ads campaign.', 'rows' => $sources['campaigns'] ?? [], 'empty' => 'No link named a campaign yet.'],
        ['title' => 'First page they opened', 'note' => 'The page they arrived on from outside the site.', 'rows' => $sources['landings'] ?? [], 'empty' => 'No page recorded yet.'],
        ['title' => 'Websites they came from', 'note' => 'The other site\'s name only: never its page.', 'rows' => $sources['sites'] ?? [], 'empty' => 'No other website recorded yet.'],
    ];
@endphp

<x-filament-widgets::widget>
    {{-- minmax(0, 1fr): a wide table scrolls inside its own box instead of widening the dashboard. --}}
    <div style="display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr);">
        <x-filament::section>
            <x-slot name="heading">Where members come from</x-slot>
            <x-slot name="description">
                Members who signed up in this period{{ $periodStart ? ' (from '.$periodStart->format('j M Y').')' : '' }}, by the last place they came from before signing up, and what they have paid since (refunds taken off).
                @if ($recordedSince)
                    Recorded for every member who joined from {{ $recordedSince->format('j M Y') }}; those who joined before show only what was kept then (a Google or ChatGPT ad click, an invite link).
                @else
                    Recording starts with the next member who signs up; those who joined before show only what was kept then (a Google or ChatGPT ad click, an invite link).
                @endif
                Open a source to see its sites, networks and links.
            </x-slot>

            @if ($channels === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">Nobody signed up in this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="{{ $th }}">Source</th>
                                <th class="{{ $thNum }}">New members</th>
                                <th class="{{ $thNum }}">Share</th>
                                <th class="{{ $thNum }}">Have paid</th>
                                <th class="{{ $thNum }}">Paid, of them</th>
                                <th class="{{ $thNum }}">Within a day</th>
                                <th class="{{ $thNum }}">Paid by them so far</th>
                            </tr>
                        </thead>
                        @foreach ($channels as $channel)
                            <tbody x-data="{ open: false }" class="border-b border-gray-100 dark:border-white/5">
                                <tr>
                                    <td class="{{ $td }}">
                                        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'"
                                            class="inline-flex items-center gap-1 whitespace-nowrap font-medium text-gray-950 hover:text-primary-600 focus-visible:underline dark:text-white dark:hover:text-primary-400">
                                            <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 shrink-0 transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                                            {{ $channel['label'] }}
                                        </button>
                                    </td>
                                    <td class="{{ $tdNum }}">{{ number_format($channel['members']) }}</td>
                                    <td class="{{ $tdMuted }}">{{ SourceReport::percent($channel['members'], $totals['members']) }}</td>
                                    <td class="{{ $tdNum }}">{{ number_format($channel['paid']) }}</td>
                                    <td class="{{ $tdMuted }}">{{ SourceReport::percent($channel['paid'], $channel['members']) }}</td>
                                    <td class="{{ $tdMuted }}">{{ number_format($channel['within_day']) }}</td>
                                    <td class="{{ $tdNum }} whitespace-nowrap">{{ $money($channel['money']) }}</td>
                                </tr>
                                @foreach ($channel['details'] as $detail)
                                    <tr x-show="open" style="display: none;" class="bg-gray-50 dark:bg-white/5">
                                        <td class="{{ $td }}" style="padding-inline-start: 2.25rem; min-width: 12rem;">{{ $detail['label'] }}</td>
                                        <td class="{{ $tdNum }}">{{ number_format($detail['members']) }}</td>
                                        <td class="{{ $tdMuted }}">{{ SourceReport::percent($detail['members'], $totals['members']) }}</td>
                                        <td class="{{ $tdNum }}">{{ number_format($detail['paid']) }}</td>
                                        <td class="{{ $tdMuted }}">{{ SourceReport::percent($detail['paid'], $detail['members']) }}</td>
                                        <td class="{{ $tdMuted }}">{{ number_format($detail['within_day']) }}</td>
                                        <td class="{{ $tdNum }} whitespace-nowrap">{{ $money($detail['money']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                        <tbody>
                            <tr>
                                <td class="{{ $td }} whitespace-nowrap font-medium">All new members</td>
                                <td class="{{ $tdNum }} font-medium">{{ number_format($totals['members']) }}</td>
                                <td class="{{ $tdMuted }}"></td>
                                <td class="{{ $tdNum }} font-medium">{{ number_format($totals['paid']) }}</td>
                                <td class="{{ $tdMuted }}">{{ SourceReport::percent($totals['paid'], $totals['members']) }}</td>
                                <td class="{{ $tdMuted }}">{{ number_format($totals['within_day']) }}</td>
                                <td class="{{ $tdNum }} whitespace-nowrap font-medium">{{ $money($totals['money']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Payments in this period, by how the payer found the site</x-slot>
            <x-slot name="description">Every payment made in the period, renewals and extra credits included, whenever the payer joined. Refunds taken off.</x-slot>

            @if (($payments['payments'] ?? 0) === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">No payment in this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="{{ $th }}">Source</th>
                                <th class="{{ $thNum }}">Payments</th>
                                <th class="{{ $thNum }}">Members who paid</th>
                                <th class="{{ $thNum }}">From this period's new members</th>
                                <th class="{{ $thNum }}">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payments['rows'] as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="{{ $td }} whitespace-nowrap">{{ $row['label'] }}</td>
                                    <td class="{{ $tdNum }}">{{ number_format($row['payments']) }}</td>
                                    <td class="{{ $tdNum }}">{{ number_format($row['payers']) }}</td>
                                    <td class="{{ $tdMuted }}">{{ number_format($row['from_new']) }}</td>
                                    <td class="{{ $tdNum }} whitespace-nowrap">{{ $money($row['money']) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td class="{{ $td }} whitespace-nowrap font-medium">All payments</td>
                                <td class="{{ $tdNum }} font-medium">{{ number_format($payments['payments']) }}</td>
                                <td class="{{ $tdNum }}"></td>
                                <td class="{{ $tdMuted }}"></td>
                                <td class="{{ $tdNum }} whitespace-nowrap font-medium">{{ $money($payments['money']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 26rem), 1fr));">
            @foreach ($lists as $list)
                <div style="min-width: 0;">
                    <x-filament::section compact>
                        <x-slot name="heading">{{ $list['title'] }}</x-slot>
                        <x-slot name="description">{{ $list['note'] }}</x-slot>
                        @if ($list['rows'] === [])
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $list['empty'] }}</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-200 dark:border-white/10">
                                            <th class="{{ $th }}"><span class="sr-only">{{ $list['title'] }}</span></th>
                                            <th class="{{ $thNum }}">New</th>
                                            <th class="{{ $thNum }}">Paid</th>
                                            <th class="{{ $thNum }}">Paid by them</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($list['rows'] as $row)
                                            <tr class="border-b border-gray-100 dark:border-white/5">
                                                <td class="{{ $td }}" style="max-width: 16rem;">
                                                    <span class="block truncate" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                                                    @isset($row['channel'])
                                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['channel'] }}</span>
                                                    @endisset
                                                </td>
                                                <td class="{{ $tdNum }}">{{ number_format($row['members']) }}</td>
                                                <td class="{{ $tdNum }}">{{ number_format($row['paid']) }}</td>
                                                <td class="{{ $tdNum }} whitespace-nowrap">{{ $money($row['money']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-filament::section>
                </div>
            @endforeach
        </div>

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">What each source means</x-slot>
            <dl class="text-sm" style="display: grid; gap: 0.75rem;">
                @foreach ($key as $label => $help)
                    <div>
                        <dt class="font-medium text-gray-950 dark:text-white">{{ $label }}</dt>
                        <dd class="text-gray-500 dark:text-gray-400">{{ $help }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="text-sm text-gray-500 dark:text-gray-400" style="margin-top: 1rem;">
                To tell your Facebook, Instagram or other ads apart from ordinary posts, add utm_medium=paid and utm_campaign=&lt;a name&gt; to the ads' links. Google and TikTok ads need nothing: their links carry their own marker.
            </p>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
