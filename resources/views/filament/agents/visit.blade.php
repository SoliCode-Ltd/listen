{{-- The conversations of one visit, in order: what each was, how long, what it used and cost, and a link to its own page (AgentVisits). A list, so it reads on a phone without scrolling sideways. --}}
@php
    $record = $getRecord();
    $R = \App\Filament\Resources\AgentConversationResource::class;
    $A = \App\Filament\Resources\AgentResource::class;
    $parts = $R::visit($record);
    $cost = (float) $parts->sum(fn ($part) => (float) $part->cost_usd);
@endphp

@include('filament.agents.partials.styles')

<ul class="lsa-visit">
    @foreach ($parts as $part)
        <li class="{{ $part->is($record) ? 'is-here' : '' }}">
            <div class="lsa-visit-what">
                <span class="lsa-strong">{{ $R::partName($part) }}</span>
                <span class="lsa-muted">{{ $part->started_at?->format('j M, H:i:s') }} · {{ $R::CHANNELS[$part->channel] ?? $part->channel }}</span>
            </div>
            <dl class="lsa-visit-figs">
                <div><dt>Length</dt><dd>{{ $R::isChat($part) ? $A::plural($R::replies($part), 'reply', 'replies') : $A::clock($part->isLive() ? $R::secondsSoFar($part) : (int) $part->duration_seconds) }}</dd></div>
                <div><dt>Credits</dt><dd>{{ $part->free ? 'Free' : number_format((int) $part->credits) }}</dd></div>
                <div><dt>Cost to us</dt><dd>{{ $part->cost_usd !== null ? '$'.number_format((float) $part->cost_usd, 5) : '—' }}</dd></div>
                <div><dt>How it stands</dt><dd>{{ $R::STATUS_BADGES[$R::statusKey($part)] ?? $part->status }}{{ $R::endReasonLabel($part->end_reason) ? ' · '.$R::endReasonLabel($part->end_reason) : '' }}</dd></div>
            </dl>
            <div class="lsa-visit-link">
                @if ($part->is($record))
                    <span class="lsa-tag">this page</span>
                @else
                    <a class="lsa-link" href="{{ $R::getUrl('view', ['record' => $part]) }}">Open its page</a>
                @endif
            </div>
        </li>
    @endforeach
    <li class="is-total">
        <div class="lsa-visit-what">
            <span class="lsa-strong">The visit</span>
            <span class="lsa-muted">{{ $A::plural($parts->count(), 'conversation') }}</span>
        </div>
        <dl class="lsa-visit-figs">
            <div><dt>Length</dt><dd>{{ $R::visitLength($record) }}</dd></div>
            <div><dt>Credits</dt><dd>{{ number_format((int) $parts->sum('credits')) }}</dd></div>
            <div><dt>Cost to us</dt><dd>${{ number_format($cost, 5) }}</dd></div>
            <div></div>
        </dl>
        <div class="lsa-visit-link"></div>
    </li>
</ul>
