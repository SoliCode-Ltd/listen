{{-- Admin → Where members come from (App\Filament\Pages\MemberSources, App\Services\Acquisition\SourceReport). --}}
@php
    use App\Filament\Resources\UserResource;
    use App\Services\Acquisition\SourceReport;
    use App\Support\Countries;

    $report = $this->report();
    // One currency to a line, so the amounts never run out of their column.
    $money = fn (array $amounts): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(implode('<br>', array_map('e', SourceReport::moneyLines($amounts))));
    $totals = $report['totals'];
    $members = max(1, (int) $totals['members']);
    $lastDay = $report['to']->copy()->subSecond();
    $th = 'px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400';
    $thNum = 'px-3 py-2 text-end text-xs font-medium text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 text-start text-gray-950 dark:text-white';
    $tdNum = 'px-3 py-2 text-end tabular-nums text-gray-950 dark:text-white';
    $tdMuted = 'px-3 py-2 text-end tabular-nums text-gray-500 dark:text-gray-400';
    $lists = [
        ['title' => 'Campaigns', 'note' => 'The campaign named in the link (utm_campaign), or the Google Ads campaign.', 'rows' => $report['campaigns'], 'country' => false, 'empty' => 'No link named a campaign.'],
        ['title' => 'First page they opened', 'note' => 'The page they arrived on from outside the site.', 'rows' => $report['landings'], 'country' => false, 'empty' => 'No page was recorded.'],
        ['title' => 'Websites they came from', 'note' => 'The other site\'s name only: never its page.', 'rows' => $report['sites'], 'country' => false, 'empty' => 'No other site was recorded.'],
        ['title' => 'Countries', 'note' => 'Where they signed up from.', 'rows' => $report['countries'], 'country' => true, 'empty' => 'No country was recorded.'],
        ['title' => 'Signed up in', 'note' => 'The website, or the Android or iPhone app.', 'rows' => $report['signed_up'], 'country' => false, 'empty' => '–'],
    ];
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr));">
        <x-filament::section compact>
            <p class="text-sm text-gray-500 dark:text-gray-400">New members</p>
            <p class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($totals['members']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $report['from']->format('j M Y') }} to {{ $lastDay->format('j M Y') }}</p>
        </x-filament::section>
        <x-filament::section compact>
            <p class="text-sm text-gray-500 dark:text-gray-400">Have paid</p>
            <p class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($totals['paid']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ SourceReport::percent($totals['paid'], $totals['members']) }} of the new members</p>
        </x-filament::section>
        <x-filament::section compact>
            <p class="text-sm text-gray-500 dark:text-gray-400">Paid within a day of signing up</p>
            <p class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($totals['within_day']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ SourceReport::percent($totals['within_day'], $totals['paid']) }} of those who paid</p>
        </x-filament::section>
        <x-filament::section compact>
            <p class="text-sm text-gray-500 dark:text-gray-400">Paid by them so far</p>
            <p class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($totals['money']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">Every payment, renewals included, refunds taken off</p>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Where they came from</x-slot>
        <x-slot name="description">
            {{ $report['count'] === SourceReport::COUNT_FIRST ? 'Each member counted by their first visit.' : 'Each member counted by the last place they came from before signing up.' }}
            Open a group to see its sites, networks and links.
        </x-slot>

        @if ($totals['members'] === 0)
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
                    @foreach ($report['channels'] as $channel)
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
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Payments in this period, by how the payer found the site</x-slot>
        <x-slot name="description">Every payment made between these dates, renewals and extra credits included, whenever the payer joined. Refunds taken off.</x-slot>

        @if ($report['payments']['payments'] === 0)
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
                        @foreach ($report['payments']['rows'] as $row)
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
                            <td class="{{ $tdNum }} font-medium">{{ number_format($report['payments']['payments']) }}</td>
                            <td class="{{ $tdNum }}"></td>
                            <td class="{{ $tdMuted }}"></td>
                            <td class="{{ $tdNum }} whitespace-nowrap font-medium">{{ $money($report['payments']['money']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 24rem), 1fr));">
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
                                                <span class="block truncate" title="{{ $row['label'] }}">{{ $list['country'] && $row['label'] !== '?' ? Countries::label($row['label']) : ($row['label'] === '?' ? 'Not known' : $row['label']) }}</span>
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

    <x-filament::section>
        <x-slot name="heading">Newest members in this period</x-slot>
        <x-slot name="description">The latest {{ count($report['latest']) }}, one by one.</x-slot>

        @if ($report['latest'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">Nobody signed up in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="{{ $th }}">Joined</th>
                            <th class="{{ $th }}">Member</th>
                            <th class="{{ $th }}">Came from</th>
                            <th class="{{ $th }}">Campaign</th>
                            <th class="{{ $th }}">First page</th>
                            <th class="{{ $th }}">Signed up in</th>
                            <th class="{{ $th }}">Country</th>
                            <th class="{{ $thNum }}">Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['latest'] as $member)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="{{ $td }} whitespace-nowrap">{{ $member['joined']->format('j M, H:i') }}</td>
                                <td class="{{ $td }}" style="min-width: 10rem; max-width: 14rem;">
                                    <a href="{{ UserResource::getUrl('view', ['record' => $member['id']]) }}" class="block truncate font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $member['name'] !== '' ? $member['name'] : '#'.$member['id'] }}</a>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $member['email'] }}</span>
                                </td>
                                <td class="{{ $td }}" style="min-width: 10rem;">
                                    <span class="block">{{ $member['channel'] }}</span>
                                    @if ($member['detail'] && $member['detail'] !== $member['channel'])
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $member['detail'] }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }}" style="max-width: 12rem;"><span class="block truncate" title="{{ $member['campaign'] }}">{{ $member['campaign'] ?? '–' }}</span></td>
                                <td class="{{ $td }}" style="max-width: 14rem;"><span class="block truncate" title="{{ $member['landing'] }}">{{ $member['landing'] ?? '–' }}</span></td>
                                <td class="{{ $td }} whitespace-nowrap">{{ $member['signed_up_in'] ?? '–' }}</td>
                                <td class="{{ $td }} whitespace-nowrap">{{ $member['country'] ? Countries::label($member['country']) : '–' }}</td>
                                <td class="{{ $tdNum }} whitespace-nowrap">{{ $money($member['paid']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">What each group means</x-slot>
        <dl class="text-sm" style="display: grid; gap: 0.75rem;">
            @foreach ($this->channelKey() as $label => $help)
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
</x-filament-panels::page>
