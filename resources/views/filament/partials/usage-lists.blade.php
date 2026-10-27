{{-- Ranked lists of what was made (App\Services\Analytics\UsageInsights::breakdowns): each share is of every generation made. --}}
@php($total = max(1, (int) $breakdowns['total']))
@if (empty($breakdowns['lists']))
    <p class="text-sm text-gray-500 dark:text-gray-400">Nothing was made in this selection.</p>
@else
    <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 17rem), 1fr));">
        @foreach ($breakdowns['lists'] as $title => $list)
            <div style="min-width: 0;">
            <x-filament::section compact>
                <x-slot name="heading">{{ $title }}</x-slot>
                <ul class="text-sm" style="display: grid; gap: 0.5rem; grid-template-columns: minmax(0, 1fr);">
                    @foreach ($list['items'] as $label => $count)
                        <li>
                            <div style="display: flex; justify-content: space-between; gap: 0.75rem; min-width: 0;">
                                <span class="truncate" style="min-width: 0;" title="{{ $label }}">{{ $label }}</span>
                                <span class="tabular-nums text-gray-500 dark:text-gray-400" style="white-space: nowrap;">
                                    {{ number_format($count) }} · {{ number_format($count / $total * 100, $count / $total < 0.1 ? 1 : 0) }}%
                                </span>
                            </div>
                            <div style="margin-top: 0.25rem; height: 0.375rem; border-radius: 9999px; background: rgba(var(--gray-400), 0.2);">
                                <div style="height: 0.375rem; border-radius: 9999px; background: rgb(var(--primary-500)); width: {{ min(100, round($count / $total * 100, 1)) }}%;"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($list['note'])
                    <p class="text-xs text-gray-500 dark:text-gray-400" style="margin-top: 0.75rem;">{{ $list['note'] }}</p>
                @endif
            </x-filament::section>
            </div>
        @endforeach
    </div>
@endif
