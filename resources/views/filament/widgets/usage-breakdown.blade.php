<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">What members made in {{ $monthLabel }}</x-slot>
        <x-slot name="description">
            {{ number_format($breakdowns['total']) }} generations made (failed ones left out), for the plan picked in the filters.
            Each share is of all of them, so a list that is not always chosen adds up to less than 100%. Pack characters are included here.
        </x-slot>

        @include('filament.partials.usage-lists', ['breakdowns' => $breakdowns])
    </x-filament::section>
</x-filament-widgets::widget>
