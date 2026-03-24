<div class="space-y-4">
    {{-- Summary --}}
    <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <span class="font-medium text-gray-500 dark:text-gray-400">{{ __('ui.admin.suspicious.total_linked') }}:</span>
                <span class="ml-1 font-semibold">{{ $users->count() }}</span>
            </div>
            <div>
                <span class="font-medium text-gray-500 dark:text-gray-400">{{ __('ui.admin.suspicious.group_total_chars') }}:</span>
                <span class="ml-1 font-semibold {{ $users->sum('demo_chars') >= $charAllowance ? 'text-red-600 dark:text-red-400' : '' }}">
                    {{ number_format($users->sum('demo_chars')) }} / {{ number_format($charAllowance) }}
                </span>
            </div>
            <div class="col-span-2">
                <span class="font-medium text-gray-500 dark:text-gray-400">{{ __('ui.admin.suspicious.shared_ips') }}:</span>
                <span class="ml-1 font-mono text-xs">
                    @php
                        $allIps = $users->flatMap(fn ($u) => array_filter([$u->registration_ip, $u->last_login_ip]))->unique()->sort();
                    @endphp
                    {{ $allIps->implode(', ') }}
                </span>
            </div>
        </div>
    </div>

    {{-- Linked Users Table --}}
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">ID</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.admin.suspicious.name') }}</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.admin.suspicious.email') }}</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.admin.suspicious.auth_method') }}</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.admin.suspicious.registration_ip') }}</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.admin.suspicious.last_login_ip') }}</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">{{ __('ui.admin.suspicious.demo_chars') }}</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.admin.suspicious.status') }}</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('ui.admin.suspicious.registered_at') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($users->sortByDesc('demo_chars') as $user)
                    <tr class="{{ $user->id === $sourceUser->id ? 'bg-amber-50 dark:bg-amber-900/20' : '' }}">
                        <td class="px-3 py-2 font-mono text-xs">{{ $user->id }}</td>
                        <td class="px-3 py-2">
                            {{ $user->name }}
                            @if ($user->id === $sourceUser->id)
                                <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-200">current</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $user->email }}</td>
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                {{ match($user->auth_provider) {
                                    'phone' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                                    'firebase_google' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                    default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
                                } }}">
                                {{ match($user->auth_provider) {
                                    'phone' => 'Phone',
                                    'firebase_google' => 'Firebase Google',
                                    default => 'Email',
                                } }}
                            </span>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $user->registration_ip ?? '-' }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $user->last_login_ip ?? '-' }}</td>
                        <td class="px-3 py-2 text-right font-mono">
                            <span class="{{ $user->demo_chars > 0 ? 'font-semibold' : 'text-gray-400' }}">
                                {{ number_format($user->demo_chars) }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            @if ($user->isBlocked())
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-200">{{ __('ui.admin.users.blocked') }}</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">{{ __('ui.admin.users.active') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $user->created_at->format('M d, Y H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
