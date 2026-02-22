<x-filament-panels::page>
    <div class="space-y-6">
        @foreach ($keys as $service => $items)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header flex items-center gap-3 px-6 py-4">
                    <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        {{ $service }}
                    </h3>
                </div>
                <div class="fi-section-content border-t border-gray-200 dark:border-white/10">
                    <dl class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($items as $label => $value)
                            <div class="flex justify-between px-6 py-3">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ $label }}
                                </dt>
                                <dd class="text-sm font-mono {{ str_contains($value, '✅') ? 'text-green-600 dark:text-green-400' : (str_contains($value, '❌') ? 'text-red-500' : (str_contains($value, '⚠️') ? 'text-yellow-500' : 'text-gray-900 dark:text-white')) }}">
                                    {{ $value }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        @endforeach

        <div class="rounded-xl bg-yellow-50 p-4 dark:bg-yellow-900/20 ring-1 ring-yellow-200 dark:ring-yellow-700/50">
            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                <strong>⚠️ Security Note:</strong> API keys are masked for security. To change any keys, update the <code class="px-1 py-0.5 rounded bg-yellow-100 dark:bg-yellow-900/50">.env</code> file directly or use the Environment Editor.
            </p>
        </div>
    </div>
</x-filament-panels::page>
