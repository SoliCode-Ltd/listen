{{-- Illustrated person for the hero artwork (drawn, not a real person). The open mouth shows only while they speak. --}}
@props(['who' => 'hala'])
<svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
@switch($who)
  @case('hala')
    {{-- Woman in a hijab --}}
    <path d="M8 124 C12 94 32 82 60 82 C88 82 108 94 112 124 Z" fill="#5B3DF5"/>
    <path d="M28 62 C28 34 42 18 60 18 C78 18 92 34 92 62 C92 82 80 96 60 98 C40 96 28 82 28 62 Z" fill="#7B5CFF"/>
    <path d="M34 62 C34 40 45 27 60 27 C75 27 86 40 86 62 C86 78 76 89 60 90 C44 89 34 78 34 62 Z" fill="#9C86FF" opacity=".55"/>
    <ellipse cx="60" cy="58" rx="19" ry="22" fill="#E9B894"/>
    <path d="M47 50 Q52 47 56 50" stroke="#3A2418" stroke-width="2" fill="none" stroke-linecap="round"/>
    <path d="M64 50 Q68 47 73 50" stroke="#3A2418" stroke-width="2" fill="none" stroke-linecap="round"/>
    <circle cx="52" cy="56" r="2.4" fill="#2A1A12"/><circle cx="68" cy="56" r="2.4" fill="#2A1A12"/>
    <circle cx="48" cy="65" r="3.5" fill="#F08A7A" opacity=".35"/><circle cx="72" cy="65" r="3.5" fill="#F08A7A" opacity=".35"/>
    <path class="vs-mouth-closed" d="M54 69 Q60 73 66 69" stroke="#A2483C" stroke-width="2.2" fill="none" stroke-linecap="round"/>
    <ellipse class="vs-mouth-open" cx="60" cy="70" rx="4.6" ry="3.6" fill="#8E3A30"/>
    @break
  @case('omar')
    {{-- Man with a short beard --}}
    <path d="M8 124 C12 94 32 82 60 82 C88 82 108 94 112 124 Z" fill="#F2F1F7"/>
    <path d="M48 82 L60 96 L72 82 Z" fill="#D9D6E6"/>
    <rect x="51" y="68" width="18" height="18" rx="7" fill="#B9794F"/>
    <ellipse cx="60" cy="52" rx="21" ry="24" fill="#C98A5E"/>
    <path d="M38 50 C37 30 47 22 60 22 C74 22 84 30 82 50 C79 40 71 34 60 34 C49 34 41 40 38 50 Z" fill="#1F1A19"/>
    <path d="M39 54 C40 72 49 80 60 80 C71 80 80 72 81 54 C78 64 71 69 60 69 C49 69 42 64 39 54 Z" fill="#2A2321"/>
    <path d="M46 45 Q51 42 56 45" stroke="#1F1A19" stroke-width="2.4" fill="none" stroke-linecap="round"/>
    <path d="M64 45 Q69 42 74 45" stroke="#1F1A19" stroke-width="2.4" fill="none" stroke-linecap="round"/>
    <circle cx="51" cy="52" r="2.4" fill="#1F1A19"/><circle cx="69" cy="52" r="2.4" fill="#1F1A19"/>
    <path class="vs-mouth-closed" d="M54 64 Q60 67 66 64" stroke="#F2D3C0" stroke-width="2.2" fill="none" stroke-linecap="round"/>
    <ellipse class="vs-mouth-open" cx="60" cy="65" rx="4.8" ry="3.6" fill="#7A2E28"/>
    @break
  @case('nadia')
    {{-- Woman with curly hair --}}
    <path d="M14 124 C18 96 36 86 60 86 C84 86 102 96 106 124 Z" fill="#12A15F"/>
    <g fill="#3A2216">
      <circle cx="40" cy="40" r="15"/><circle cx="60" cy="30" r="17"/><circle cx="80" cy="40" r="15"/>
      <circle cx="36" cy="60" r="13"/><circle cx="84" cy="60" r="13"/><circle cx="40" cy="78" r="10"/><circle cx="80" cy="78" r="10"/>
    </g>
    <rect x="52" y="70" width="16" height="18" rx="7" fill="#7E4E33"/>
    <ellipse cx="60" cy="56" rx="19" ry="22" fill="#8F5A3C"/>
    <circle cx="52" cy="55" r="2.3" fill="#1E120C"/><circle cx="68" cy="55" r="2.3" fill="#1E120C"/>
    <path class="vs-mouth-closed" d="M54 67 Q60 71 66 67" stroke="#4E2A1E" stroke-width="2.2" fill="none" stroke-linecap="round"/>
    <ellipse class="vs-mouth-open" cx="60" cy="68" rx="4.4" ry="3.4" fill="#4A1F18"/>
    @break
  @case('karim')
    {{-- Man with glasses --}}
    <path d="M14 124 C18 96 36 86 60 86 C84 86 102 96 106 124 Z" fill="#3B6FE0"/>
    <rect x="52" y="68" width="16" height="20" rx="7" fill="#E2B08C"/>
    <ellipse cx="60" cy="54" rx="20" ry="23" fill="#F0C8A6"/>
    <path d="M39 50 C38 31 48 24 61 24 C74 24 83 32 81 48 C76 38 68 35 58 36 C49 37 43 42 39 50 Z" fill="#4A3426"/>
    <g fill="none" stroke="#1F1A2E" stroke-width="2.2"><circle cx="51" cy="54" r="6.5"/><circle cx="69" cy="54" r="6.5"/><path d="M57.5 54 H62.5"/></g>
    <circle cx="51" cy="54" r="2" fill="#1F1A2E"/><circle cx="69" cy="54" r="2" fill="#1F1A2E"/>
    <path class="vs-mouth-closed" d="M54 67 Q60 70 66 67" stroke="#B5624E" stroke-width="2.2" fill="none" stroke-linecap="round"/>
    <ellipse class="vs-mouth-open" cx="60" cy="68" rx="4.4" ry="3.4" fill="#8E3A30"/>
    @break
  @default
    {{-- Older man in a white shemagh --}}
    <path d="M10 124 C14 94 34 84 60 84 C86 84 106 94 110 124 Z" fill="#F7F6FB"/>
    <path d="M26 66 C26 36 40 20 60 20 C80 20 94 36 94 66 L100 104 L76 90 L60 92 L44 90 L20 104 Z" fill="#FFFFFF"/>
    <path d="M30 40 C40 28 80 28 90 40" stroke="#1F1A19" stroke-width="5" fill="none" stroke-linecap="round"/>
    <ellipse cx="60" cy="60" rx="18" ry="21" fill="#B6764C"/>
    <path d="M43 62 C44 78 51 86 60 86 C69 86 76 78 77 62 C74 70 68 74 60 74 C52 74 46 70 43 62 Z" fill="#C9C6CF"/>
    <circle cx="52" cy="57" r="2.2" fill="#1F1A19"/><circle cx="68" cy="57" r="2.2" fill="#1F1A19"/>
    <path d="M47 51 Q52 49 56 51" stroke="#8A8790" stroke-width="2" fill="none" stroke-linecap="round"/>
    <path d="M64 51 Q68 49 73 51" stroke="#8A8790" stroke-width="2" fill="none" stroke-linecap="round"/>
    <path class="vs-mouth-closed" d="M55 69 Q60 71 65 69" stroke="#6E3B2A" stroke-width="2" fill="none" stroke-linecap="round"/>
    <ellipse class="vs-mouth-open" cx="60" cy="70" rx="4.2" ry="3.2" fill="#5E241C"/>
@endswitch
</svg>
