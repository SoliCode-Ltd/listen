<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-4">
            <x-filament::button type="submit">
                Save .env Changes
            </x-filament::button>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                ⚠️ Changes are written to <code>.env</code> immediately. Application cache may need to be cleared.
            </p>
        </div>
    </form>
</x-filament-panels::page>
