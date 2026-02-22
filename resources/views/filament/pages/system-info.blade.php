<x-filament-panels::page>
    <div class="space-y-6">
        @foreach ($systemInfo as $section => $items)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header flex items-center gap-3 px-6 py-4">
                    <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        {{ str_replace('_', ' ', ucfirst($section)) }}
                    </h3>
                </div>
                <div class="fi-section-content border-t border-gray-200 dark:border-white/10">
                    <dl class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($items as $label => $value)
                            <div class="flex justify-between px-6 py-3">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ $label }}
                                </dt>
                                <dd class="text-sm text-gray-900 dark:text-white">
                                    {{ $value }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
