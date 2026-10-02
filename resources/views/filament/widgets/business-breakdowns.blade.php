<x-filament-widgets::widget>
    <div style="display: grid; gap: 1.5rem;">
        @if (! empty($statErrors))
            <x-filament::section>
                <x-slot name="heading">Some statistics could not be calculated</x-slot>
                <ul class="text-sm text-danger-600 dark:text-danger-400">
                    @foreach ($statErrors as $section => $message)
                        <li><strong>{{ $section }}:</strong> {{ $message }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Signup cohorts: activation and retention</x-slot>
            <x-slot name="description">
                For members who joined in each month: how many ever generated audio, and how many were still generating 1 and 2 months later.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-start text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-2 text-start font-medium">Joined</th>
                            <th class="px-3 py-2 text-end font-medium">Signups</th>
                            <th class="px-3 py-2 text-end font-medium">Ever generated</th>
                            <th class="px-3 py-2 text-end font-medium">Activation</th>
                            <th class="px-3 py-2 text-end font-medium">Active month +1</th>
                            <th class="px-3 py-2 text-end font-medium">Active month +2</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cohorts as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="px-3 py-2">{{ $monthLabel($row['month']) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['signups']) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['ever_generated']) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $percent($row['activation_rate']) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">
                                    {{ $row['active_month_1'] === null ? '—' : number_format($row['active_month_1']).' ('.$percent($row['retention_month_1']).')' }}
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums">
                                    {{ $row['active_month_2'] === null ? '—' : number_format($row['active_month_2']).' ('.$percent($row['retention_month_2']).')' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-gray-500">No signups in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 46rem), 1fr));">
            <x-filament::section>
                <x-slot name="heading">Plans</x-slot>
                <x-slot name="description">Members currently on each plan, paying Stripe subscriptions and estimated MRR at list price.</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2 text-start font-medium">Plan</th>
                                <th class="px-3 py-2 text-end font-medium">Price / mo</th>
                                <th class="px-3 py-2 text-end font-medium">Members</th>
                                <th class="px-3 py-2 text-end font-medium">Stripe monthly</th>
                                <th class="px-3 py-2 text-end font-medium">Stripe yearly</th>
                                <th class="px-3 py-2 text-end font-medium">MRR</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plans as $plan)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="px-3 py-2">
                                        {{ $plan['plan'] }}
                                        @unless ($plan['active'])
                                            <span class="text-xs text-gray-400">(inactive)</span>
                                        @endunless
                                    </td>
                                    <td class="px-3 py-2 text-end tabular-nums">${{ number_format($plan['price_monthly_usd'], 2) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ number_format($plan['members']) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ number_format($plan['stripe_monthly']) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ number_format($plan['stripe_yearly']) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">${{ number_format($plan['mrr_usd'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Voice providers (last 30 days)</x-slot>
                <x-slot name="description">Speed, reliability and recorded cost of each text-to-speech provider.</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2 text-start font-medium">Provider</th>
                                <th class="px-3 py-2 text-end font-medium">Generations</th>
                                <th class="px-3 py-2 text-end font-medium">Avg time</th>
                                <th class="px-3 py-2 text-end font-medium">Failures</th>
                                <th class="px-3 py-2 text-end font-medium">Fallbacks</th>
                                <th class="px-3 py-2 text-end font-medium">Minutes</th>
                                <th class="px-3 py-2 text-end font-medium">Est. cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($providers as $p)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="px-3 py-2">{{ $p['provider'] }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ number_format($p['generations']) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ $p['avg_latency_s'] === null ? '—' : $p['avg_latency_s'].' s' }}</td>
                                    <td @class([
                                        'px-3 py-2 text-end tabular-nums',
                                        'font-semibold text-danger-600 dark:text-danger-400' => $p['failure_rate'] > 0.05,
                                    ])>{{ $percent($p['failure_rate']) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ $percent($p['fallback_rate']) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ number_format($p['audio_minutes'], 1) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">${{ number_format($p['est_cost_usd'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">No generations in the last 30 days.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr));">
            @foreach ($breakdowns as $title => $items)
                @php($sum = max(1, array_sum($items)))
                <x-filament::section compact>
                    <x-slot name="heading">{{ $title }}</x-slot>
                    <ul class="text-sm" style="display: grid; gap: 0.5rem;">
                        @foreach ($items as $label => $count)
                            <li>
                                <div style="display: flex; justify-content: space-between; gap: 0.75rem;">
                                    <span class="truncate">{{ $label }}</span>
                                    <span class="tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ number_format($count) }} · {{ $percent($count / $sum) }}
                                    </span>
                                </div>
                                <div style="margin-top: 0.25rem; height: 0.375rem; border-radius: 9999px; background: rgba(var(--gray-400), 0.2);">
                                    <div style="height: 0.375rem; border-radius: 9999px; background: rgb(var(--primary-500)); width: {{ round($count / $sum * 100, 1) }}%;"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endforeach
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Admin accounts and admin test trials are excluded. MRR uses Stripe list prices (coupons and taxes ignored).
            Numbers are cached for 10 minutes; use “Refresh numbers” for the latest.
            Generated {{ \Illuminate\Support\Carbon::parse($meta['generated_at'] ?? now())->diffForHumans() }}.
        </p>
    </div>
</x-filament-widgets::widget>
