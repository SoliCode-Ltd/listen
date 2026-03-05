<x-filament-panels::page>
    {{-- Quick API Keys Status --}}
    <div class="mb-6" wire:poll.10s>
        @php
            $stats = $this->getUsageStats();
        @endphp
        
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                    <x-heroicon-o-key class="w-5 h-5" />
                    Gemini API Keys Status
                </h2>
                <a href="{{ route('filament.admin.pages.gemini-keys') }}" class="text-primary-600 dark:text-primary-400 text-sm hover:underline">
                    Manage Keys →
                </a>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $stats['total_keys'] }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Total Keys</div>
                </div>
                <div class="text-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="text-3xl font-bold {{ $stats['available_keys'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $stats['available_keys'] }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Available Now</div>
                </div>
                <div class="text-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $stats['total_rpm_remaining'] }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">RPM Remaining</div>
                </div>
                <div class="text-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="text-3xl font-bold text-info-600 dark:text-info-400">{{ $stats['total_rpd_remaining'] }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">RPD Remaining</div>
                </div>
            </div>

            @if($stats['total_keys'] === 0)
                <div class="mt-4 p-4 bg-danger-50 dark:bg-danger-900/20 rounded-lg border border-danger-200 dark:border-danger-800">
                    <p class="text-sm text-danger-800 dark:text-danger-300">
                        ⚠️ No API keys configured. <a href="{{ route('filament.admin.pages.gemini-keys') }}" class="underline">Add your first key</a> to enable Gemini TTS.
                    </p>
                </div>
            @elseif($stats['available_keys'] === 0)
                <div class="mt-4 p-4 bg-warning-50 dark:bg-warning-900/20 rounded-lg border border-warning-200 dark:border-warning-800">
                    <p class="text-sm text-warning-800 dark:text-warning-300">
                        ⚠️ All keys exhausted. <a href="{{ route('filament.admin.pages.gemini-keys') }}" class="underline">Add more keys</a> or wait for rate limits to reset.
                    </p>
                </div>
            @endif

            <div class="mt-4 text-xs text-gray-400 dark:text-gray-500 text-right">
                Auto-refreshes every 10s
            </div>
        </div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">
                Save TTS Settings
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
