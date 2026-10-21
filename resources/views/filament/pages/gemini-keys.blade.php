<x-filament-panels::page>
    @php
        $stats = $this->getKeysStats();
    @endphp

    {{-- Summary Section --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-filament::section>
            <div class="text-center">
                <p class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $stats['total_keys'] }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-400">Total Keys</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <p class="text-3xl font-bold {{ $stats['available_keys'] > 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                    {{ $stats['available_keys'] }}
                </p>
                <p class="text-sm text-gray-600 dark:text-gray-400">Available Now</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <p class="text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $stats['total_rpm_remaining'] }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-400">Total RPM Available</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <p class="text-3xl font-bold text-info-600 dark:text-info-400">{{ $stats['total_rpd_remaining'] }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-400">Total RPD Available</p>
            </div>
        </x-filament::section>
    </div>

    {{-- Add New Key Section --}}
    <x-filament::section class="mb-6">
        <x-slot name="heading">Add New API Key</x-slot>
        <x-slot name="description">
            Get API keys from <a href="https://aistudio.google.com/apikey" target="_blank" class="text-primary-600 dark:text-primary-400 underline">Google AI Studio</a>
        </x-slot>

        <div class="flex gap-4 items-end">
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Gemini API Key
                </label>
                <input 
                    type="password" 
                    wire:model="newApiKey"
                    placeholder="Paste your API key"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500"
                />
            </div>
            <x-filament::button wire:click="addKey" icon="heroicon-o-plus">
                Add Key
            </x-filament::button>
        </div>

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Keys are auto-numbered (GEMINI_TTS_API_KEY, GEMINI_TTS_API_KEY_2, etc.)
        </p>
    </x-filament::section>

    {{-- API Keys Table --}}
    <x-filament::section>
        <x-slot name="heading">API Keys Status</x-slot>
        <x-slot name="description">
            Real-time usage per key. Limits: RPM = 10/min, TPM = 10K tokens/min, RPD = 100/day
        </x-slot>

        @if(count($stats['keys']) === 0)
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                No API keys configured. Add your first key above.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left py-3 px-4 font-medium text-gray-700 dark:text-gray-300">Key Name</th>
                            <th class="text-left py-3 px-4 font-medium text-gray-700 dark:text-gray-300">Masked Key</th>
                            <th class="text-center py-3 px-4 font-medium text-gray-700 dark:text-gray-300">RPM (Requests/Min)</th>
                            <th class="text-center py-3 px-4 font-medium text-gray-700 dark:text-gray-300">TPM (Tokens/Min)</th>
                            <th class="text-center py-3 px-4 font-medium text-gray-700 dark:text-gray-300">RPD (Requests/Day)</th>
                            <th class="text-center py-3 px-4 font-medium text-gray-700 dark:text-gray-300">Status</th>
                            <th class="text-right py-3 px-4 font-medium text-gray-700 dark:text-gray-300">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stats['keys'] as $keyData)
                            @php
                                $rpmPct = $this->calculatePercentage($keyData['stats']['rpm_used'], $keyData['stats']['rpm_limit']);
                                $tpmPct = $this->calculatePercentage($keyData['stats']['tpm_used'], $keyData['stats']['tpm_limit']);
                                $rpdPct = $this->calculatePercentage($keyData['stats']['rpd_used'], $keyData['stats']['rpd_limit']);
                            @endphp
                            <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                <td class="py-3 px-4">
                                    <span class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">
                                        {{ $keyData['name'] }}
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="font-mono text-xs text-gray-500 dark:text-gray-400">
                                        {{ $keyData['masked_key'] }}
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex flex-col items-center">
                                        <span class="text-xs mb-1">{{ $keyData['stats']['rpm_used'] }}/{{ $keyData['stats']['rpm_limit'] }}</span>
                                        <div class="w-20 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                            <div 
                                                class="h-full {{ $rpmPct >= 90 ? 'bg-danger-500' : ($rpmPct >= 70 ? 'bg-warning-500' : 'bg-success-500') }}"
                                                style="width: {{ $rpmPct }}%"
                                            ></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex flex-col items-center">
                                        <span class="text-xs mb-1">{{ number_format($keyData['stats']['tpm_used']) }}/{{ number_format($keyData['stats']['tpm_limit']) }}</span>
                                        <div class="w-20 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                            <div 
                                                class="h-full {{ $tpmPct >= 90 ? 'bg-danger-500' : ($tpmPct >= 70 ? 'bg-warning-500' : 'bg-success-500') }}"
                                                style="width: {{ $tpmPct }}%"
                                            ></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex flex-col items-center">
                                        <span class="text-xs mb-1">{{ $keyData['stats']['rpd_used'] }}/{{ $keyData['stats']['rpd_limit'] }}</span>
                                        <div class="w-20 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                            <div 
                                                class="h-full {{ $rpdPct >= 90 ? 'bg-danger-500' : ($rpdPct >= 70 ? 'bg-warning-500' : 'bg-success-500') }}"
                                                style="width: {{ $rpdPct }}%"
                                            ></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    @if($keyData['stats']['is_exhausted'])
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400">
                                            Exhausted
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400">
                                            Available
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-right">
                                    @if(count($stats['keys']) > 1)
                                        <button 
                                            wire:click="removeKey('{{ $keyData['name'] }}')"
                                            wire:confirm="Are you sure you want to remove this API key?"
                                            class="text-danger-600 hover:text-danger-800 dark:text-danger-400 dark:hover:text-danger-300"
                                            title="Remove Key"
                                        >
                                            <x-heroicon-o-trash class="w-5 h-5" />
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex justify-between items-center">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    RPM/TPM counters reset every minute. RPD resets at midnight.
                </p>
                <x-filament::button wire:click="resetCounters" color="gray" size="sm" icon="heroicon-o-arrow-path">
                    Reset All Counters
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>

    {{-- Rate Limit Info --}}
    <x-filament::section class="mt-6">
        <x-slot name="heading">Gemini 2.5 Flash TTS Rate Limits</x-slot>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <h4 class="font-semibold text-gray-700 dark:text-gray-300">RPM (Requests/Min)</h4>
                <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">10</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Per API key</p>
            </div>
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <h4 class="font-semibold text-gray-700 dark:text-gray-300">TPM (Tokens/Min)</h4>
                <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">10,000</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">~40,000 characters per key/min</p>
            </div>
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <h4 class="font-semibold text-gray-700 dark:text-gray-300">RPD (Requests/Day)</h4>
                <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">100</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Per API key, resets at midnight</p>
            </div>
        </div>

        <div class="mt-4 p-4 bg-info-50 dark:bg-info-900/20 rounded-lg border border-info-200 dark:border-info-800">
            <p class="text-sm text-info-800 dark:text-info-300">
                <strong>Tip:</strong> To increase your daily capacity, add more API keys from different Google accounts. 
                Each key provides 100 additional requests per day. The system automatically rotates between keys when one is exhausted.
            </p>
        </div>
    </x-filament::section>

    {{-- Auto-refresh --}}
    <div wire:poll.10s class="hidden"></div>
</x-filament-panels::page>
