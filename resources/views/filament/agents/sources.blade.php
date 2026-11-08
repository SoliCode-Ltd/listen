{{-- What an agent was taught from (agent_sources): its website, the owner's notes and corrections, files, its links' newest posts, and every page of its website. --}}
@php
    $sources = $getRecord()->sources()->orderBy('id')->get();
    $kinds = ['website' => 'Website', 'text' => 'Typed by the owner', 'file' => 'File', 'sheet' => 'Spreadsheet', 'link' => 'Link (news and posts)', 'page' => 'Website page (looked up on calls)'];
    $statuses = ['ready' => 'Ready', 'pending' => 'Waiting', 'reading' => 'Reading', 'failed' => 'Failed'];
    $statusColor = fn (?string $status): string => match ($status) {
        'ready' => 'success',
        'failed' => 'danger',
        'pending', 'reading' => 'warning',
        default => 'gray',
    };
    // An uploaded file's reason, in words (agents.files.reasons), and a file only part of which fits.
    $problem = fn ($source): ?string => match (true) {
        $source->error === 'partial' => 'Only the first part fits',
        in_array($source->error, \App\Services\Agents\FileReader::REASONS, true) => trans('agents.files.reasons.'.$source->error, [], 'en'),
        default => $source->error,
    };
    // A link's reading (AgentLinkReads): its posts with their dates, or its page's text, in words, never as stored data.
    $linkRead = function ($source): ?array {
        if ($source->kind !== \App\Models\AgentSource::LINK || ! filled($source->content)) {
            return null;
        }
        $read = json_decode((string) $source->content, true);

        return is_array($read) ? $read : null;
    };
    // The agent's own website as a link with no news feed: nothing to read here, its pages are read as the website.
    $noFeed = fn ($source): bool => ($linkRead($source)['kind'] ?? null) === 'none';
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
                            @if ($noFeed($source))
                                <div class="lsa-muted">The agent's own website: it has no news feed, so there is nothing new to read from this link. Its pages are read as the website (the rows marked Website and Website page).</div>
                            @elseif ($read = $linkRead($source))
                                <details class="lsa-details">
                                    <summary>{{ ($read['kind'] ?? '') === 'posts' ? 'Show the posts it read ('.count($read['items'] ?? []).')' : 'Show the text it learned' }}</summary>
                                    <div class="lsa-box">
                                        @if (($read['kind'] ?? '') === 'posts')
                                            @forelse ($read['items'] ?? [] as $item)
                                                <div class="lsa-pre" dir="auto"><span class="lsa-muted">{{ $item['at'] ?? '' }}</span> {{ $item['text'] ?? '' }}</div>
                                            @empty
                                                <div class="lsa-muted">No posts from the last {{ \App\Services\Agents\LinkReader::DAYS }} days.</div>
                                            @endforelse
                                        @else
                                            @if (filled($read['title'] ?? null))
                                                <div class="lsa-strong" dir="auto">{{ $read['title'] }}</div>
                                            @endif
                                            <div class="lsa-pre" dir="auto">{{ \Illuminate\Support\Str::limit((string) ($read['text'] ?? ''), 20000) ?: '—' }}</div>
                                        @endif
                                    </div>
                                </details>
                            @elseif (filled($source->content))
                                <details class="lsa-details">
                                    <summary>Show the text it learned</summary>
                                    <div class="lsa-pre lsa-box" dir="auto">{{ \Illuminate\Support\Str::limit((string) $source->content, 20000) }}</div>
                                </details>
                            @endif
                        </td>
                        <td class="lsa-num">{{ number_format((int) $source->chars) }}</td>
                        <td class="lsa-nowrap">
                            @if ($noFeed($source))
                                <x-filament::badge color="gray">No news feed</x-filament::badge>
                            @else
                                <x-filament::badge :color="$statusColor($source->status)">{{ $statuses[$source->status] ?? \Illuminate\Support\Str::headline((string) $source->status) }}</x-filament::badge>
                            @endif
                        </td>
                        <td class="lsa-nowrap">{{ $source->read_at?->format('j M Y, H:i') ?? '—' }}</td>
                        <td @class(['lsa-error' => filled($source->error) && $source->error !== 'partial'])>{{ $problem($source) ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
