@php
    $record = $getRecord();
    $rtl = in_array((string) $record->language, \App\Support\Transcription\TranscriptionSettings::RTL, true);
    $clock = fn (float $s): string => sprintf('%d:%04.1f', intdiv((int) $s, 60), fmod($s, 60));
@endphp

<div style="display: grid; gap: .9rem; max-width: 820px;" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    @foreach ((array) $record->paragraphs as $p)
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400" dir="ltr" style="text-align: {{ $rtl ? 'right' : 'left' }};">
                {{ $record->speakerName((string) ($p['speaker'] ?? 'A')) }} · {{ $clock((float) ($p['start'] ?? 0)) }}–{{ $clock((float) ($p['end'] ?? 0)) }}
            </div>
            <div class="text-sm text-gray-950 dark:text-white" style="line-height: 1.7;">{{ (string) ($p['text'] ?? '') }}</div>
        </div>
    @endforeach
</div>
