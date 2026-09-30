<x-filament-panels::page>
    @if(app()->environment('production'))
    <div class="p-4 mb-4 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
        <div class="flex items-center gap-2">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <p class="text-sm font-medium text-amber-800 dark:text-amber-300">
                You are editing environment variables in <strong>production</strong>. Changes take effect immediately. Be careful!
            </p>
        </div>
    </div>
    @endif

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-4">
            <x-filament::button type="submit">
                Save .env Changes
            </x-filament::button>

            <p class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 shrink-0 text-amber-500" />
                Changes are written to <code>.env</code> immediately. Application cache may need to be cleared.
            </p>
        </div>
    </form>
</x-filament-panels::page>

