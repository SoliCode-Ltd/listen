@php
    $columns = $this->getColumns();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $hasHeading = filled($heading);
    $hasDescription = filled($description);
@endphp

<x-filament-widgets::widget class="fi-wi-stats-overview grid gap-y-4">
    @if ($hasHeading || $hasDescription)
        <div class="fi-wi-stats-overview-header grid gap-y-1">
            @if ($hasHeading)
                <h3
                    class="fi-wi-stats-overview-header-heading col-span-full text-base font-semibold leading-6 text-gray-950 dark:text-white"
                >
                    {{ $heading }}
                </h3>
            @endif

            @if ($hasDescription)
                <p
                    class="fi-wi-stats-overview-header-description overflow-hidden break-words text-sm text-gray-500 dark:text-gray-400"
                >
                    {{ $description }}
                </p>
            @endif
        </div>
    @endif

    <div
        @if ($pollingInterval = $this->getPollingInterval())
            wire:poll.{{ $pollingInterval }}
        @endif
        @class([
            'fi-wi-stats-overview-stats-ctn grid gap-6',
            'md:grid-cols-1' => $columns === 1,
            'md:grid-cols-2' => $columns === 2,
            'md:grid-cols-3' => $columns === 3,
            'md:grid-cols-2 xl:grid-cols-4' => $columns === 4,
        ])
    >
        @foreach ($this->getCachedStats() as $stat)
            {{ $stat }}
        @endforeach
    </div>

    {{-- Who cancelled: opened from the "Cancelled, still in paid time" card. --}}
    <x-filament::modal :id="\App\Filament\Widgets\ExpectedIncome::CANCELLED_MODAL" width="4xl">
        <x-slot name="heading">Cancelled, still in paid time</x-slot>
        <x-slot name="description">Card subscriptions set to end. Each member keeps the plan until the date it ends, then moves to Free. Soonest first.</x-slot>

        @php($members = $this->cancelledMembers())
        @if ($members === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">Nobody right now. If the card says otherwise, press Refresh numbers.</p>
        @else
            <div class="-mx-2 overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-2 py-2 text-start font-medium">Member</th>
                            <th class="px-2 py-2 text-start font-medium">Plan</th>
                            <th class="px-2 py-2 text-start font-medium">Cancelled on</th>
                            <th class="px-2 py-2 text-start font-medium">Plan ends</th>
                            <th class="px-2 py-2 text-end font-medium">A month</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($members as $member)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="px-2 py-2">
                                    @if ($member['user_id'])
                                        <a href="{{ \App\Filament\Resources\UserResource::getUrl('view', ['record' => $member['user_id']]) }}" class="font-medium text-gray-950 hover:underline dark:text-white">{{ $member['name'] }}</a>
                                    @else
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $member['name'] }}</span>
                                    @endif
                                    <div class="text-gray-500 dark:text-gray-400">{{ $member['email'] }}</div>
                                </td>
                                <td class="px-2 py-2">{{ $member['plan'] }} <span class="text-gray-500 dark:text-gray-400">· {{ $member['cycle'] }}</span></td>
                                <td class="px-2 py-2 whitespace-nowrap">{{ $member['canceled_at']?->format('M j, Y') ?? '—' }}</td>
                                <td class="px-2 py-2 whitespace-nowrap">
                                    {{ $member['ends_at']?->format('M j, Y') ?? '—' }}
                                    @if ($member['ends_at'])
                                        <div class="text-gray-500 dark:text-gray-400">{{ $member['ends_at']->diffForHumans() }}</div>
                                    @endif
                                </td>
                                <td class="px-2 py-2 text-end whitespace-nowrap">{{ $member['monthly'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::modal>
</x-filament-widgets::widget>
