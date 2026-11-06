{{-- A call's recording (CallRecordings): the caller and the agent together, as the caller's browser heard them. --}}
@php
  $call = $getRecord();
  $has = \App\Support\Agents\CallRecordings::has($call);
  $url = $has ? route('admin.agent-calls.recording', ['call' => $call->public_id]) : null;
@endphp
@if($has)
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px">
    <audio controls preload="metadata" src="{{ $url }}" style="width:100%;max-width:640px"></audio>
    <a href="{{ $url }}?download=1" class="text-sm font-medium text-primary-600 hover:underline">Download</a>
  </div>
  <p class="text-sm text-gray-500" style="margin-top:6px">{{ number_format($call->recording_bytes / 1048576, 2) }} MB · {{ $call->recording_parts }} {{ $call->recording_parts === 1 ? 'part' : 'parts' }} · {{ $call->recording_type }}</p>
@else
  <p class="text-sm text-gray-500">No recording for this call.{{ $call->started_at && $call->started_at->lt(now()->subDays(\App\Support\Agents\CallRecordings::KEEP_DAYS)) ? ' Recordings are kept '.\App\Support\Agents\CallRecordings::KEEP_DAYS.' days.' : ' Calls are recorded from this release on, in browsers that can record.' }}</p>
@endif
