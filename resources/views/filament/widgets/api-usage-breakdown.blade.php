<x-filament-widgets::widget>
    <div style="display: grid; gap: 1.5rem;">
        @foreach ([['Last 30 days', $byModel30], ["Last {$months} months", $byModelPeriod]] as [$title, $rows])
            <x-filament::section>
                <x-slot name="heading">API usage by provider and model · {{ $title }}</x-slot>
                <x-slot name="description">
                    Calls, characters, audio produced, speed, reliability and cost for every voice model, including the public demo.
                    “Logged” means the app recorded the cost; “estimated” uses the rates in config/analytics.php.
                    @if ($geminiFreeTier)
                        Gemini is set to the free tier, so it costs $0.
                    @endif
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2 text-start font-medium">Provider · model</th>
                                <th class="px-3 py-2 text-end font-medium">Calls</th>
                                <th class="px-3 py-2 text-end font-medium">Members / demo</th>
                                <th class="px-3 py-2 text-end font-medium">Characters</th>
                                <th class="px-3 py-2 text-end font-medium">Audio min</th>
                                <th class="px-3 py-2 text-end font-medium">Avg time</th>
                                <th class="px-3 py-2 text-end font-medium">Failures</th>
                                <th class="px-3 py-2 text-end font-medium">Cost</th>
                                <th class="px-3 py-2 text-end font-medium">Per 1k chars</th>
                                <th class="px-3 py-2 text-start font-medium">Cost source</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="px-3 py-2 font-medium">{{ $row['model'] }}</td>
                                    <td class="px-3 py-2 text-end">{{ number_format($row['requests']) }}</td>
                                    <td class="px-3 py-2 text-end text-gray-500 dark:text-gray-400">{{ number_format($row['member_requests']) }} / {{ number_format($row['demo_requests']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ number_format($row['chars']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ number_format($row['audio_minutes'], 1) }}</td>
                                    <td class="px-3 py-2 text-end">{{ $row['avg_latency_s'] === null ? '—' : $row['avg_latency_s'].' s' }}</td>
                                    <td @class([
                                        'px-3 py-2 text-end',
                                        'font-semibold text-danger-600 dark:text-danger-400' => $row['failure_rate'] > 0.05,
                                    ])>{{ $percent($row['failure_rate']) }}</td>
                                    <td class="px-3 py-2 text-end font-medium">{{ $usd($row['cost_usd']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ $usd($row['cost_per_1k_chars'], 4) }}</td>
                                    <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ str_replace('recorded', 'logged', $row['cost_basis']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="px-3 py-4 text-center text-gray-500">No text-to-speech usage in this period.</td></tr>
                            @endforelse
                        </tbody>
                        @if (! empty($rows))
                            <tfoot>
                                <tr class="font-semibold">
                                    <td class="px-3 py-2">Total</td>
                                    <td class="px-3 py-2 text-end">{{ number_format(array_sum(array_column($rows, 'requests'))) }}</td>
                                    <td class="px-3 py-2 text-end text-gray-500 dark:text-gray-400">{{ number_format(array_sum(array_column($rows, 'member_requests'))) }} / {{ number_format(array_sum(array_column($rows, 'demo_requests'))) }}</td>
                                    <td class="px-3 py-2 text-end">{{ number_format(array_sum(array_column($rows, 'chars'))) }}</td>
                                    <td class="px-3 py-2 text-end">{{ number_format(array_sum(array_column($rows, 'audio_minutes')), 1) }}</td>
                                    <td></td>
                                    <td></td>
                                    <td class="px-3 py-2 text-end">{{ $usd(array_sum(array_column($rows, 'cost_usd'))) }}</td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </x-filament::section>
        @endforeach

        <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 28rem), 1fr));">
            <x-filament::section>
                <x-slot name="heading">Monthly API spend</x-slot>
                <x-slot name="description">Cost split by who triggered it, with characters and audio produced.</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2 text-start font-medium">Month</th>
                                <th class="px-3 py-2 text-end font-medium">Characters</th>
                                <th class="px-3 py-2 text-end font-medium">Audio min</th>
                                <th class="px-3 py-2 text-end font-medium">Members</th>
                                <th class="px-3 py-2 text-end font-medium">Demo</th>
                                <th class="px-3 py-2 text-end font-medium">Admins</th>
                                <th class="px-3 py-2 text-end font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_reverse($monthly) as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="px-3 py-2">{{ $monthLabel($row['month']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ $compact($row['chars']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ number_format($row['audio_minutes'], 1) }}</td>
                                    <td class="px-3 py-2 text-end">{{ $usd($row['cost_members']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ $usd($row['cost_demo']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ $usd($row['cost_admin']) }}</td>
                                    <td class="px-3 py-2 text-end font-medium">{{ $usd($row['cost_total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <div style="display: grid; gap: 1.5rem; align-content: start;">
                <x-filament::section>
                    <x-slot name="heading">Characters by plan (last 30 days)</x-slot>
                    <x-slot name="description">What members on each plan generated (admins excluded).</x-slot>
                    @php($planSum = max(1, array_sum($charsByPlan)))
                    <ul class="text-sm" style="display: grid; gap: 0.5rem;">
                        @forelse ($charsByPlan as $plan => $chars)
                            <li>
                                <div style="display: flex; justify-content: space-between; gap: 0.75rem;">
                                    <span>{{ $plan }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ number_format($chars) }} · {{ $percent($chars / $planSum) }}</span>
                                </div>
                                <div style="margin-top: 0.25rem; height: 0.375rem; border-radius: 9999px; background: rgba(var(--gray-400), 0.2);">
                                    <div style="height: 0.375rem; border-radius: 9999px; background: rgb(var(--primary-500)); width: {{ round($chars / $planSum * 100, 1) }}%;"></div>
                                </div>
                            </li>
                        @empty
                            <li class="text-gray-500">No member usage in the last 30 days.</li>
                        @endforelse
                    </ul>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">Characters counted against plan quotas</x-slot>
                    <x-slot name="description">From the monthly usage counters that enforce plan limits.</x-slot>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2 text-start font-medium">Month</th>
                                <th class="px-3 py-2 text-end font-medium">Characters</th>
                                <th class="px-3 py-2 text-end font-medium">Requests</th>
                                <th class="px-3 py-2 text-end font-medium">Members using</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse (array_reverse($quotaByMonth) as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="px-3 py-2">{{ $monthLabel($row['month']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ number_format($row['chars']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ number_format($row['requests']) }}</td>
                                    <td class="px-3 py-2 text-end">{{ number_format($row['members_with_usage']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500">No usage counters in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-filament::section>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
