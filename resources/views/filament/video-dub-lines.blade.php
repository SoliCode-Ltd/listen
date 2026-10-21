@php($lines = (array) ($getRecord()->report['lines'] ?? []))
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-start text-gray-500 dark:text-gray-400">
                <th class="py-1 pe-3 text-start font-medium">At</th>
                <th class="py-1 pe-3 text-start font-medium">Who</th>
                <th class="py-1 pe-3 text-start font-medium">Said in the video</th>
                <th class="py-1 pe-3 text-start font-medium">Dubbed line</th>
                <th class="py-1 pe-3 text-start font-medium">Speed</th>
                <th class="py-1 text-start font-medium">Level</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr class="border-t border-gray-200 dark:border-white/10 align-top">
                    <td class="py-2 pe-3 whitespace-nowrap font-mono text-xs">{{ number_format((float) ($line['start'] ?? 0), 2) }}–{{ number_format((float) ($line['end'] ?? 0), 2) }} s
                        @if(isset($line['heard_start']) && abs((float) $line['heard_start'] - (float) $line['start']) >= 0.05)
                            <br><span class="text-gray-500">set to the speech, was {{ number_format((float) $line['heard_start'], 2) }}</span>
                        @endif
                        @if(! empty($line['heard_again']))
                            <br><span class="text-gray-500">heard again: left out the first time</span>
                        @endif
                        @if(! empty($line['as_said']))
                            <br><span class="text-gray-500">read as it was said (a quotation)</span>
                        @endif
                        @if(! empty($line['written']))
                            <br><span class="text-gray-500">dubbed from what was heard</span>
                        @endif
                        @if(! empty($line['placed_whole']))
                            <br><span class="text-gray-500">take placed whole (not cut at its pauses)</span>
                        @endif
                        @if(! empty($line['quiet']))
                            <br><span class="text-danger-600">not voiced: the speech engine failed on it twice</span>
                        @endif
                    </td>
                    <td class="py-2 pe-3 whitespace-nowrap text-xs">{{ $line['speaker'] ?? '—' }}{{ isset($line['voice']) ? ' · '.$line['voice'] : '' }}@if(! empty($line['emotion']))<br><span class="text-gray-500">{{ $line['emotion'] }}</span>@endif</td>
                    <td class="py-2 pe-3" dir="auto">{{ $line['original'] ?? '' }}</td>
                    <td class="py-2 pe-3" dir="auto">{{ $line['dubbed'] ?? '' }}</td>
                    <td class="py-2 pe-3 whitespace-nowrap">{{ ($line['tempo'] ?? 1) > 1 ? '×'.$line['tempo'] : '—' }}</td>
                    <td class="py-2 whitespace-nowrap">{{ isset($line['gain_db']) && abs((float) $line['gain_db']) >= 0.1 ? sprintf('%+.1f dB', $line['gain_db']) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
