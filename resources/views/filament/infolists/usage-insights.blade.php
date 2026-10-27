{{-- One member's credit period on the Monthly usage details page. --}}
@php($insights = $getState())
@php($summary = $insights['summary'])
<div style="display: grid; gap: 1.25rem;">
    <dl class="text-sm" style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 10rem), 1fr));">
        @foreach ([
            'Generations made' => number_format($summary['made']),
            'Failed' => number_format($summary['failed']).($summary['attempts'] > 0 ? ' ('.number_format($summary['failed'] / $summary['attempts'] * 100, 1).'%)' : ''),
            'Days with a generation' => number_format($summary['days']),
            'Average length' => $summary['made'] > 0 ? number_format($summary['seconds'] / $summary['made'], 1).' s' : '—',
            'Credits per generation' => $summary['made'] > 0 ? number_format($summary['chars'] / $summary['made']) : '—',
            'First' => $summary['first']?->format('j M Y, H:i') ?? '—',
            'Latest' => $summary['last']?->format('j M Y, H:i') ?? '—',
        ] as $label => $value)
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="font-medium tabular-nums" style="margin-top: 0.125rem;">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @include('filament.partials.usage-lists', ['breakdowns' => $insights['breakdowns']])
</div>
