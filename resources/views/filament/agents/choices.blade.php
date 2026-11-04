{{-- The setup's questions (agents.choices): each question, the answer the agent follows, and the choices that were offered. --}}
@php
    $choices = array_values(array_filter((array) ($getState() ?? []), 'is_array'));
@endphp

@include('filament.agents.partials.styles')

@if ($choices === [])
    <p class="lsa-muted">No questions were asked.</p>
@else
    <div class="lsa-scroll">
        <table class="lsa-table">
            <thead>
                <tr>
                    <th>Question</th>
                    <th>Answer it follows</th>
                    <th>Choices offered</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($choices as $choice)
                    @php
                        $answer = is_scalar($choice['answer'] ?? null) ? trim((string) $choice['answer']) : '';
                        $options = array_values(array_filter(array_map(
                            fn ($option): string => is_scalar($option) ? trim((string) $option) : '',
                            (array) ($choice['options'] ?? []),
                        ), fn (string $option): bool => $option !== ''));
                    @endphp
                    <tr>
                        <td dir="auto">{{ is_scalar($choice['question'] ?? null) ? $choice['question'] : '—' }}</td>
                        <td dir="auto" class="lsa-strong">{{ $answer !== '' ? $answer : '—' }}</td>
                        <td>
                            @if ($options === [])
                                <span class="lsa-muted">Not kept</span>
                            @else
                                <div class="lsa-items">
                                    @foreach ($options as $option)
                                        <div class="lsa-item" dir="auto">
                                            <span class="lsa-marker">{{ $option === $answer ? '✓' : '•' }}</span>
                                            <span class="lsa-item-text">{{ $option }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
