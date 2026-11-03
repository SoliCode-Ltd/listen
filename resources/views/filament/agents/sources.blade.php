{{-- What an agent was taught from (agent_sources): its website, the owner's notes and corrections, files. --}}
@php
    $sources = $getRecord()->sources()->orderBy('id')->get();
    $kinds = ['website' => 'Website', 'text' => 'Typed by the owner', 'file' => 'File', 'sheet' => 'Sheet'];
    $statuses = ['ready' => 'Ready', 'pending' => 'Waiting', 'failed' => 'Failed'];
    $statusColor = fn (?string $status): string => match ($status) {
        'ready' => 'success',
        'failed' => 'danger',
        'pending' => 'warning',
        default => 'gray',
    };
@endphp

@include('filament.agents.partials.styles')

@if ($sources->isEmpty())
    <p class="lsa-muted">No sources: it knows only what is written in "What it knows".</p>
@else
    <div class="lsa-scroll">
        <table class="lsa-table">
            <thead>
                <tr>
                    <th>Kind</th>
                    <th>Title or address</th>
                    <th>Characters</th>
                    <th>Status</th>
                    <th>Read</th>
                    <th>Problem</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sources as $source)
                    <tr>
                        <td class="lsa-nowrap">
                            <x-filament::badge color="gray">{{ $kinds[$source->kind] ?? \Illuminate\Support\Str::headline((string) $source->kind) }}</x-filament::badge>
                        </td>
                        <td>
                            @if (filled($source->title))
                                <div class="lsa-strong" dir="auto">{{ $source->title }}</div>
                            @endif
                            @if (filled($source->url))
                                @if ($href = \App\Filament\Resources\AgentConversationResource::safeUrl($source->url))
                                    <a class="lsa-link" href="{{ $href }}" target="_blank" rel="noopener noreferrer">{{ $source->url }}</a>
                                @else
                                    <div>{{ $source->url }}</div>
                                @endif
                            @endif
                            @if (filled($source->path))
                                <div class="lsa-mono lsa-muted">{{ $source->path }}</div>
                            @endif
                            @if (filled($source->content))
                                <details class="lsa-details">
                                    <summary>Show the text it learned</summary>
                                    <div class="lsa-pre lsa-box" dir="auto">{{ \Illuminate\Support\Str::limit((string) $source->content, 20000) }}</div>
                                </details>
                            @endif
                        </td>
                        <td class="lsa-num">{{ number_format((int) $source->chars) }}</td>
                        <td class="lsa-nowrap">
                            <x-filament::badge :color="$statusColor($source->status)">{{ $statuses[$source->status] ?? \Illuminate\Support\Str::headline((string) $source->status) }}</x-filament::badge>
                        </td>
                        <td class="lsa-nowrap">{{ $source->read_at?->format('j M Y, H:i') ?? '—' }}</td>
                        <td @class(['lsa-error' => filled($source->error)])>{{ $source->error ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
