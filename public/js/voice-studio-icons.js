/*
 * Listen's own icons for the voice studio, in the brand colours instead of
 * system emoji: round faces for feelings and sounds ("f-…") and rounded
 * tiles with a line drawing for styles and pacing ("g-…"). Both are drawn
 * in the flat brand purple with white features, so they look the same on every
 * device, in light and dark themes.
 *
 *   VoiceStudioIcons.svg('f-laugh')  → '<svg class="vs-ico" …>'
 *
 * The icon for each choice is set in App\Support\StudioChoices.
 */
(function () {
  'use strict';

  var W = '#FFFFFF';
  var INK = 'var(--vs-ico-ink, #7659FA)';
  var DEEP = '#3A1FB8';
  var BRAND = '#7659FA';

  // ---------- Faces: eyes, mouth, brows and extras on a brand circle ----------
  var EYES = {
    dot: '<circle cx="11" cy="13" r="2" fill="' + W + '"/><circle cx="21" cy="13" r="2" fill="' + W + '"/>',
    happy: '<path d="M8.5 14q2.5-3.6 5 0M18.5 14q2.5-3.6 5 0" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/>',
    closed: '<path d="M8.5 12.5q2.5 3 5 0M18.5 12.5q2.5 3 5 0" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/>',
    half: '<path d="M8.3 13.2h5.4M18.3 13.2h5.4" stroke="' + W + '" stroke-width="2" stroke-linecap="round"/><path d="M9.2 13.4q1.8 2 3.6 0M19.2 13.4q1.8 2 3.6 0" fill="' + W + '"/>',
    wide: '<circle cx="11" cy="13" r="3.2" fill="' + W + '"/><circle cx="21" cy="13" r="3.2" fill="' + W + '"/><circle cx="11" cy="13.3" r="1.4" fill="' + DEEP + '"/><circle cx="21" cy="13.3" r="1.4" fill="' + DEEP + '"/>',
    up: '<circle cx="11" cy="13" r="3" fill="' + W + '"/><circle cx="21" cy="13" r="3" fill="' + W + '"/><circle cx="11.4" cy="11.6" r="1.3" fill="' + DEEP + '"/><circle cx="21.4" cy="11.6" r="1.3" fill="' + DEEP + '"/>',
    side: '<circle cx="11" cy="13" r="3" fill="' + W + '"/><circle cx="21" cy="13" r="3" fill="' + W + '"/><circle cx="12.5" cy="13.4" r="1.3" fill="' + DEEP + '"/><circle cx="22.5" cy="13.4" r="1.3" fill="' + DEEP + '"/>',
    squint: '<path d="M8.6 10.8l4.2 2.4-4.2 2.4M23.4 10.8l-4.2 2.4 4.2 2.4" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
    wink: '<circle cx="11" cy="13" r="2" fill="' + W + '"/><path d="M18.5 14q2.5-3.6 5 0" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/>',
    star: '<path d="M11 9.6l1.1 2.3 2.5.3-1.9 1.7.5 2.5-2.2-1.3-2.2 1.3.5-2.5-1.9-1.7 2.5-.3zM21 9.6l1.1 2.3 2.5.3-1.9 1.7.5 2.5-2.2-1.3-2.2 1.3.5-2.5-1.9-1.7 2.5-.3z" fill="' + W + '"/>',
    heart: '<path d="M11 16.2s-3.4-2-3.4-4.3a1.8 1.8 0 0 1 3.4-.9 1.8 1.8 0 0 1 3.4.9c0 2.3-3.4 4.3-3.4 4.3zM21 16.2s-3.4-2-3.4-4.3a1.8 1.8 0 0 1 3.4-.9 1.8 1.8 0 0 1 3.4.9c0 2.3-3.4 4.3-3.4 4.3z" fill="' + W + '"/>',
    cool: '<path d="M5.5 11.2h21" stroke="' + DEEP + '" stroke-width="1.6" stroke-linecap="round"/><path d="M7 11h7.4v2.2a3.2 3.2 0 0 1-3.2 3.2h-1a3.2 3.2 0 0 1-3.2-3.2zM17.6 11H25v2.2a3.2 3.2 0 0 1-3.2 3.2h-1a3.2 3.2 0 0 1-3.2-3.2z" fill="' + DEEP + '"/><path d="M9 12.4h2M19.6 12.4h2" stroke="' + W + '" stroke-width="1" stroke-linecap="round" opacity=".7"/>'
  };
  var MOUTHS = {
    smile: '<path d="M10.5 19.5q5.5 5.5 11 0" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/>',
    smallsmile: '<path d="M12.6 20.4q3.4 2.6 6.8 0" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/>',
    grin: '<path d="M10 19h12q0 6.4-6 6.4T10 19z" fill="' + W + '"/>',
    laugh: '<path d="M8.6 18.2h14.8q0 8.6-7.4 8.6T8.6 18.2z" fill="' + W + '"/><path d="M12.4 24.2q3.6-2.4 7.2 0q-3.6 2.8-7.2 0z" fill="' + DEEP + '" opacity=".35"/>',
    flat: '<path d="M12 21.5h8" stroke="' + W + '" stroke-width="2" stroke-linecap="round"/>',
    frown: '<path d="M11 24q5-5.2 10 0" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/>',
    o: '<circle cx="16" cy="22" r="1.9" fill="' + W + '"/>',
    O: '<ellipse cx="16" cy="22.6" rx="3.2" ry="3.8" fill="' + W + '"/><ellipse cx="16" cy="23.4" rx="1.8" ry="2.2" fill="' + DEEP + '" opacity=".35"/>',
    wavy: '<path d="M10.5 22.2q1.4-1.8 2.75 0t2.75 0 2.75 0 2.75 0" stroke="' + W + '" stroke-width="1.9" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
    smirk: '<path d="M12.2 22.2q5 1.2 8.8-2.8" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/>',
    tongue: '<path d="M10.5 19.5q5.5 5.5 11 0" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/><path d="M15.2 22.3h4v1.6a2 2 0 0 1-4 0z" fill="' + W + '"/>',
    kiss: '<path d="M14.6 19.4q2.8-.6 2.2 1.6-.4 1 .6 1.2-1.2.4.4 1.4.6 1.6-2.6 1.6" stroke="' + W + '" stroke-width="1.9" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
    teeth: '<rect x="10" y="19.2" width="12" height="5.4" rx="2.7" fill="' + W + '"/><path d="M10.4 21.9h11.2M14 19.4v5M18 19.4v5" stroke="' + DEEP + '" stroke-width=".9" opacity=".45"/>'
  };
  var BROWS = {
    angry: '<path d="M7.8 8.4l5.2 2.2M24.2 8.4L19 10.6" stroke="' + W + '" stroke-width="2" stroke-linecap="round"/>',
    worried: '<path d="M7.8 10.4l5-2.2M24.2 10.4l-5-2.2" stroke="' + W + '" stroke-width="2" stroke-linecap="round"/>',
    raised: '<path d="M8.6 9h4.4" stroke="' + W + '" stroke-width="2" stroke-linecap="round"/><path d="M18.8 8.4q2.6-2.4 5 0" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/>',
    up: '<path d="M8.4 8.6q2.6-2.2 5 0M18.6 8.6q2.6-2.2 5 0" stroke="' + W + '" stroke-width="2" fill="none" stroke-linecap="round"/>',
    straight: '<path d="M8.4 9h4.8M18.8 9h4.8" stroke="' + W + '" stroke-width="2" stroke-linecap="round"/>'
  };
  var TEAR = '#BFD4FF';
  var EXTRAS = {
    tear: '<path d="M22.6 16.4q2.3 3.2 0 4.8-2.3-1.6 0-4.8z" fill="' + TEAR + '"/>',
    tears: '<path d="M9.4 16.4q2.3 3.2 0 4.8-2.3-1.6 0-4.8zM22.6 16.4q2.3 3.2 0 4.8-2.3-1.6 0-4.8z" fill="' + TEAR + '"/>',
    sweat: '<path d="M26 4.6q2.6 3.6 0 5.4-2.6-1.8 0-5.4z" fill="' + TEAR + '" stroke="' + INK + '" stroke-width=".8"/>',
    blush: '<ellipse cx="8.2" cy="18.4" rx="2.3" ry="1.3" fill="' + W + '" opacity=".38"/><ellipse cx="23.8" cy="18.4" rx="2.3" ry="1.3" fill="' + W + '" opacity=".38"/>',
    zzz: '<path d="M23 2.6h3.4l-3.4 3.8h3.4M27.4 6.6h2.2l-2.2 2.4h2.2" stroke="' + INK + '" stroke-width="1.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
    sparkle: '<path d="M27 1.6q.5 2.9 3.4 3.4-2.9.5-3.4 3.4-.5-2.9-3.4-3.4 2.9-.5 3.4-3.4z" fill="' + INK + '"/>',
    waves: '<path d="M28.4 12.4q1.6 3.6 0 7.2M30.6 10.4q2.6 5.6 0 11.2" stroke="' + INK + '" stroke-width="1.5" fill="none" stroke-linecap="round"/>',
    steam: '<path d="M3 5.4q1.6-1.4 3.2 0t3.2 0M22.6 5.4q1.6-1.4 3.2 0t3.2 0" stroke="' + INK + '" stroke-width="1.5" fill="none" stroke-linecap="round"/>',
    puff: '<path d="M22.6 22.4h4.6M23.6 25.4h5.6M22.6 19.4h3.2" stroke="' + INK + '" stroke-width="1.5" stroke-linecap="round"/>',
    heart: '<path d="M27 9.6s-3.8-2.2-3.8-4.8a2 2 0 0 1 3.8-1 2 2 0 0 1 3.8 1c0 2.6-3.8 4.8-3.8 4.8z" fill="' + INK + '"/>',
    broken: '<path d="M27 9.6s-3.8-2.2-3.8-4.8a2 2 0 0 1 3.8-1 2 2 0 0 1 3.8 1c0 2.6-3.8 4.8-3.8 4.8z" fill="' + INK + '"/><path d="M27 3.9l-1 2 1.6 1-1 2.6" stroke="' + W + '" stroke-width="1" fill="none" stroke-linejoin="round"/>',
    question: '<path d="M25 3.6a2.4 2.4 0 1 1 3.2 2.3c-.8.3-1.2.8-1.2 1.6" stroke="' + INK + '" stroke-width="1.6" fill="none" stroke-linecap="round"/><circle cx="27" cy="10.2" r="1" fill="' + INK + '"/>',
    excl: '<path d="M28 2.4v5" stroke="' + INK + '" stroke-width="2" stroke-linecap="round"/><circle cx="28" cy="10" r="1.1" fill="' + INK + '"/>',
    shh: '<rect x="14.7" y="16.2" width="2.8" height="10.4" rx="1.4" fill="#E6E0FF" stroke="' + DEEP + '" stroke-width=".6" stroke-opacity=".35"/>',
    note: '<path d="M26 9.4V3.2l4-.9v5.4" stroke="' + INK + '" stroke-width="1.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="24.9" cy="9.4" r="1.4" fill="' + INK + '"/><circle cx="28.9" cy="7.8" r="1.4" fill="' + INK + '"/>'
  };

  // id: [eyes, mouth, brows, extras…]
  var FACES = {
    neutral: ['dot', 'flat'], smile: ['dot', 'smile'], grin: ['happy', 'grin'], laugh: ['happy', 'laugh'],
    chuckle: ['happy', 'smile', null, 'blush'], giggle: ['closed', 'grin', null, 'blush'], cackle: ['squint', 'laugh'],
    starstruck: ['star', 'grin'], hopeful: ['up', 'smallsmile', null, 'sparkle'], playful: ['wink', 'tongue'],
    cool: ['cool', 'smirk'], proud: ['closed', 'smirk', 'up'], grateful: ['closed', 'smallsmile', null, 'heart'],
    determined: ['dot', 'flat', 'angry'], amazed: ['wide', 'O', 'up', 'sparkle'], relieved: ['closed', 'smallsmile', null, 'sweat'],
    curious: ['dot', 'o', 'raised', 'question'], calm: ['closed', 'smallsmile'], tender: ['closed', 'smile', null, 'blush'],
    empathetic: ['dot', 'smallsmile', 'worried', 'heart'], love: ['heart', 'smile'], nostalgic: ['closed', 'smallsmile', null, 'tear'],
    reassuring: ['happy', 'smallsmile', null, 'heart'], apologetic: ['dot', 'flat', 'worried', 'sweat'], dreamy: ['closed', 'o', null, 'sparkle'],
    shy: ['dot', 'wavy', null, 'blush'], sleepy: ['half', 'flat', null, 'zzz'], formal: ['dot', 'flat', 'straight'],
    serious: ['half', 'flat', 'straight'], mysterious: ['side', 'smirk'], suspense: ['side', 'wavy', 'worried'],
    sarcastic: ['half', 'smirk', 'raised'], mocking: ['squint', 'tongue'], menacing: ['dot', 'grin', 'angry'], bored: ['half', 'flat'],
    sad: ['dot', 'frown', 'worried'], heartbroken: ['closed', 'frown', 'worried', 'broken'], disappointed: ['closed', 'frown'],
    angry: ['dot', 'frown', 'angry'], frustrated: ['squint', 'teeth', null, 'steam'], anxious: ['dot', 'wavy', 'worried', 'sweat'],
    worried: ['dot', 'wavy', 'worried'], fear: ['wide', 'teeth', 'worried'], panic: ['wide', 'O', 'worried', 'sweat'],
    cheerful: ['happy', 'grin', null, 'blush'], triumph: ['happy', 'laugh', 'up', 'sparkle'], innocent: ['wide', 'smallsmile', 'up', 'blush'],
    sincere: ['dot', 'smallsmile', 'up', 'heart'], lonely: ['closed', 'flat', 'worried', 'tear'], guilty: ['side', 'wavy', 'worried', 'sweat'],
    jealous: ['side', 'frown', 'angry'], furious: ['squint', 'teeth', 'angry', 'steam'], defiant: ['dot', 'smirk', 'angry'],
    resigned: ['half', 'flat', 'worried', 'puff'], whistle: ['closed', 'o', 'up', 'note'], sniff: ['closed', 'flat', null, 'puff'],
    hiccup: ['wide', 'o', null, 'excl'], gulp: ['wide', 'wavy', 'worried', 'sweat'], snore: ['closed', 'O', null, 'zzz'],
    burp: ['squint', 'O', null, 'puff'], kiss: ['closed', 'kiss', null, 'heart'], thinking: ['side', 'flat', 'raised', 'question'],
    agree: ['happy', 'smallsmile'], hesitant: ['up', 'wavy', 'worried'], elder: ['half', 'smallsmile', 'straight', 'sparkle'],
    surprised: ['wide', 'o', 'up', 'excl'], embarrassed: ['squint', 'teeth', null, 'blush'], disgusted: ['half', 'wavy', 'angry'],
    sigh: ['closed', 'o', null, 'puff'], gasp: ['wide', 'O', 'up'], pant: ['closed', 'O', null, 'sweat'], shh: ['dot', 'flat', null, 'shh'],
    shout: ['squint', 'O', null, 'waves'], scream: ['wide', 'O', 'worried', 'waves'], shriek: ['wide', 'O', 'up', 'excl'],
    cheer: ['happy', 'laugh', null, 'sparkle'], cough: ['squint', 'o', null, 'puff'], sneeze: ['squint', 'O', null, 'puff'],
    throat: ['closed', 'flat', null, 'puff'], yawn: ['closed', 'O', null, 'zzz'], snort: ['squint', 'smirk', null, 'puff'],
    tsk: ['half', 'flat', 'straight', 'puff'], pff: ['half', 'o', null, 'puff'], cry: ['closed', 'frown', 'worried', 'tears'],
    sob: ['closed', 'O', 'worried', 'tears'], whimper: ['dot', 'wavy', 'worried', 'tear'], groan: ['closed', 'wavy', 'worried'],
    moan: ['closed', 'o', 'worried'], growl: ['dot', 'teeth', 'angry'], grunt: ['squint', 'flat'], hiss: ['squint', 'teeth', 'angry']
  };

  function face(id) {
    var spec = FACES[id] || FACES.neutral;
    var out = '<circle cx="16" cy="16" r="14" fill="' + BRAND + '"/>';
    if (spec[2]) out += BROWS[spec[2]] || '';
    out += (EYES[spec[0]] || EYES.dot) + (MOUTHS[spec[1]] || MOUTHS.flat);
    for (var i = 3; i < spec.length; i++) out += EXTRAS[spec[i]] || '';
    return out;
  }

  // ---------- Glyphs: a line drawing on a brand tile (24px grid) ----------
  var GLYPHS = {
    auto: 'M12 3l1.8 4.6L18.5 9l-4.7 1.6L12 15l-1.8-4.4L5.5 9l4.7-1.4zM18.5 14.6l.9 2.2 2.1.8-2.1.8-.9 2.2-.9-2.2-2.1-.8 2.1-.8zM5.5 15.6l.7 1.6 1.6.6-1.6.6-.7 1.6-.7-1.6-1.6-.6 1.6-.6z',
    wave: 'M2 12h2.5l2-5 3 10 3-14 3 14 2.5-8 1.5 3H22',
    mic: '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M9 21h6"/>',
    news: '<rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="M7 9h5M7 12.5h10M7 16h10"/><rect x="14.5" y="7.6" width="3" height="2.6" rx=".5"/>',
    volume: 'M4 9.5v5h3.5l5 4v-13l-5 4zM16 9.2a4 4 0 0 1 0 5.6M18.6 6.6a7.6 7.6 0 0 1 0 10.8',
    tag: '<path d="M3 12.2V4h8.2L21 13.8 13.8 21z"/><circle cx="7.6" cy="8.2" r="1.5"/>',
    tv: '<rect x="3" y="6" width="18" height="12.5" rx="2"/><path d="M8.5 21.5h7M9 2.5l3 3.5 3-3.5"/>',
    diamond: 'M6.5 4h11L22 9.5 12 21 2 9.5zM2 9.5h20M9.5 4l-1.6 5.5L12 21l4.1-11.5L14.5 4',
    'phone-heart': '<rect x="6" y="2" width="12" height="20" rx="2.6"/><path d="M12 15.4s-3-1.8-3-4a1.6 1.6 0 0 1 3-.8 1.6 1.6 0 0 1 3 .8c0 2.2-3 4-3 4zM10.5 19h3"/>',
    rocket: '<path d="M12 2.5c3 2 5 6 4 11l-4 3-4-3c-1-5 1-9 4-11z"/><circle cx="12" cy="9" r="1.6"/><path d="M8.3 12.6l-3 3.2 1.2 3 3-1.8M15.7 12.6l3 3.2-1.2 3-3-1.8M10.4 19.4l1.6 2.6 1.6-2.6"/>',
    book: 'M2.5 5h6a3.5 3.5 0 0 1 3.5 3.5V20a2.8 2.8 0 0 0-2.8-2.8H2.5zM21.5 5h-6A3.5 3.5 0 0 0 12 8.5V20a2.8 2.8 0 0 1 2.8-2.8h6.7z',
    feather: 'M20.2 3.8a6 6 0 0 0-8.5 0L5 10.5V19h8.5l6.7-6.7a6 6 0 0 0 0-8.5zM16 8 2 22M17.5 15H9',
    headphones: '<path d="M3.5 17.5V12a8.5 8.5 0 0 1 17 0v5.5"/><rect x="3" y="13.5" width="4.2" height="7" rx="1.6"/><rect x="16.8" y="13.5" width="4.2" height="7" rx="1.6"/>',
    star: 'M12 2.8l2.8 5.8 6.4.8-4.7 4.4 1.2 6.3L12 17l-5.7 3.1 1.2-6.3-4.7-4.4 6.4-.8z',
    moon: '<path d="M19.5 14.6A8 8 0 0 1 9.4 4.5a8.5 8.5 0 1 0 10.1 10.1z"/><path d="M17 3.5v3M15.5 5h3"/>',
    quill: 'M4 17.5c2.6 0 4-1.8 4-4.6V7.5H4V13h3.6M14 17.5c2.6 0 4-1.8 4-4.6V7.5h-4V13h3.6',
    search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5 21 21"/>',
    ghost: '<path d="M5 21V11a7 7 0 0 1 14 0v10l-2.4-1.8-2.3 1.8-2.3-1.8-2.3 1.8-2.3-1.8z"/><path d="M9.6 10.6h.01M14.4 10.6h.01" stroke-width="2.6"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a13.5 13.5 0 0 1 0 18 13.5 13.5 0 0 1 0-18"/>',
    radio: '<rect x="3" y="8" width="18" height="12.5" rx="2"/><path d="M7 8 17 3.5M6.5 12.5h4M6.5 16h4"/><circle cx="15.6" cy="14.2" r="2.5"/>',
    ball: '<circle cx="12" cy="12" r="9"/><path d="M12 8l3.4 2.5-1.3 4H9.9l-1.3-4zM12 3v5M20.6 9.4l-5.2 1.1M17.4 19.4l-3.3-4.9M6.6 19.4l3.3-4.9M3.4 9.4l5.2 1.1"/>',
    clapper: '<rect x="3" y="9.5" width="18" height="11" rx="1.6"/><path d="M3.4 9.5 4.6 5l15.6-1.8.9 4.6zM8.6 4.6l2 4.2M13.8 4l2 4.2"/>',
    film: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7.5 4v16M16.5 4v16M3 9h4.5M3 15h4.5M16.5 9H21M16.5 15H21"/>',
    chat2: 'M3 4.5h11v8.5H7.5L3 16.4zM10 16h6.5l4.5 3.4V8.5h-3.5',
    masks: '<path d="M3 4h9v6.2a4.5 4.5 0 0 1-9 0zM12 10.5h9v5.5a4.5 4.5 0 0 1-9 0"/><path d="M5.6 7.4h1.2M8.6 7.4h1.2M5.8 11.4q1.7 1.4 3.4 0M14.6 13.6h1.2M17.6 13.6h1.2M14.8 18.2q1.7-1.4 3.4 0"/>',
    briefcase: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5.2A2.2 2.2 0 0 1 11.2 3h1.6A2.2 2.2 0 0 1 15 5.2V7M3 12.5h18M10.5 12.5v2h3v-2"/>',
    cap: 'M2 9.2 12 4.4l10 4.8-10 4.8zM6 11.2v5c3 2.6 9 2.6 12 0v-5M22 9.2V14',
    bulb: 'M9 18h6M10 21h4M12 3a6 6 0 0 0-3.6 10.8c.7.6 1.1 1.3 1.1 2.2h5c0-.9.4-1.6 1.1-2.2A6 6 0 0 0 12 3z',
    bot: '<rect x="4" y="8" width="16" height="12" rx="3.4"/><path d="M12 4.6V8M9.5 16.6h5"/><circle cx="12" cy="3.6" r="1"/><path d="M9 12.6h.01M15 12.6h.01" stroke-width="2.8"/>',
    headset: '<path d="M4.5 14v-2a7.5 7.5 0 0 1 15 0v2"/><rect x="3" y="12.5" width="4" height="6.5" rx="1.5"/><rect x="17" y="12.5" width="4" height="6.5" rx="1.5"/><path d="M19 19c0 1.8-1.8 2.8-4.6 2.8H12"/>',
    phone: 'M5.2 3h3.6l1.9 4.8-2.4 1.5a11 11 0 0 0 6.4 6.4l1.5-2.4 4.8 1.9v3.6a2 2 0 0 1-2.1 2A16.5 16.5 0 0 1 3.2 5.1 2 2 0 0 1 5.2 3z',
    bell: 'M6 16.5V11a6 6 0 0 1 12 0v5.5l2 2H4zM10 21.5h4',
    map: 'M3 6.2 9 3.4l6 2.8 6-2.8v14.4l-6 2.8-6-2.8-6 2.8zM9 3.4v14.4M15 6.2v14.4',
    chat: 'M4 4h16v12H9.2L4 20zM8 8.8h8M8 12h5',
    spotlight: '<path d="M5 3l3.6 6.6h6.8L19 3"/><ellipse cx="12" cy="18.6" rx="7.6" ry="2.6"/><path d="M8.6 9.6 5.2 16.4M15.4 9.6l3.4 6.8"/>',
    flame: 'M12 2.5c.8 4 6 6 6 11.5a6 6 0 0 1-12 0c0-3 1.8-5 3-6 0 2 .9 3.2 2.2 3.2 0-3.6-.6-5.8.8-8.7z',
    podium: '<path d="M6 11h12l-1.8 10H7.8zM8.4 21h7.2"/><rect x="9.6" y="2.6" width="4.8" height="6" rx="2.4"/><path d="M12 8.6V11"/>',
    party: 'M3.5 20.5 8.4 6.8l8.8 8.8zM13.5 3.5l1 2M19 5.4l-1.8 1.8M20.6 10.6h-2.2M16.6 2.4v1.8M7 13.4l3.6 3.6',
    gamepad: '<rect x="2" y="7" width="20" height="11" rx="5.5"/><path d="M7 10.4v4.2M4.9 12.5h4.2M15.4 11.4h.01M17.8 13.8h.01" />',
    lotus: 'M12 20.5c-4.2 0-8-2-9-6 3 0 6.2 1.2 9 4 2.8-2.8 6-4 9-4-1 4-4.8 6-9 6zM12 18.5c-2.2-3.2-2.2-8.4 0-12.5 2.2 4.1 2.2 9.3 0 12.5z',
    ear: 'M7 9a5.5 5.5 0 1 1 11 0c0 3.2-3 4-3 7a3.5 3.5 0 0 1-6.2 2.2M10 9.6a2.5 2.5 0 1 1 5 0c0 1.6-1.6 2.1-1.6 3.6',
    lantern: 'M12 2v2.2M9 4.2h6M8 7.2h8l1 3v6.6l-1 3H8l-1-3v-6.6zM7 10.2h10M7 16.8h10M12 12.2v2.8',
    breath: 'M3 8.2h9.4a2.8 2.8 0 1 0-2.8-2.8M3 12h14.4a2.8 2.8 0 1 1-2.8 2.8M3 15.8h6',
    'breath-heavy': 'M3 6.6h9.4a2.8 2.8 0 1 0-2.8-2.8M3 10.4h15.4a2.8 2.8 0 1 1-2.8 2.8M3 14.2h7M3 18h10.6a2.2 2.2 0 1 1-2.2 2.2',
    exhale: 'M3 9h11a3 3 0 1 0-3-3M3 13h7M13.4 13H21M6.4 17h9.6',
    pause: '<rect x="6" y="4" width="4" height="16" rx="1.6"/><rect x="14" y="4" width="4" height="16" rx="1.6"/>',
    hourglass: 'M6 3h12M6 21h12M7 3c0 5 5 6 5 9s-5 4-5 9M17 3c0 5-5 6-5 9s5 4 5 9',
    slow: 'M4 17.5a8 8 0 1 1 16 0M12 17.5 7.6 13.4M4 17.5h2M18 17.5h2',
    fast: 'M4 17.5a8 8 0 1 1 16 0M12 17.5l4.8-4.8M4 17.5h2M18 17.5h2',
    emphasis: '<path d="M12 3.6v11" stroke-width="2.6"/><path d="M12 19.6h.01" stroke-width="3.4"/><path d="M5.4 7.4 3.6 6M18.6 7.4 20.4 6M5 12H3M19 12h2"/>',
    note: '<path d="M9 18V5.2l11-2.2v13"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="16" r="2.5"/>',
    clap: '<path d="M7.5 13.5 12 9a1.4 1.4 0 0 1 2 2l-3 3M10 12l4.6-4.6a1.4 1.4 0 0 1 2 2L12 14M12.6 13.4l3.4-3.4a1.4 1.4 0 0 1 2 2l-4.6 4.6a5 5 0 0 1-7 0l-.6-.6a4 4 0 0 1 0-5.6L8 8.2a1.4 1.4 0 0 1 2 2"/><path d="M15 3.5l.6 1.8M19 5.6l-1.4 1.2M20.6 9.6h-1.8"/>',
    low: '<path d="M4 9.5v5h3.5l5 4v-13l-5 4z"/><path d="M17 8v8M14.6 13.6 17 16l2.4-2.4"/>',
    high: '<path d="M4 9.5v5h3.5l5 4v-13l-5 4z"/><path d="M17 16V8M14.6 10.4 17 8l2.4 2.4"/>',
    sliders: 'M5 4v16M12 4v16M19 4v16M3 15h4M10 8h4M17 13h4',
    echo: 'M4 12h.01M8 9a4.2 4.2 0 0 1 0 6M11.5 6.5a7.8 7.8 0 0 1 0 11M15 4a11.4 11.4 0 0 1 0 16M18.5 7a8 8 0 0 1 0 10',
    hall: 'M3 20h18M4 9.5 12 4l8 5.5M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 9.5h18',
    cave: 'M2.5 20 7 9.5l3 3.5 3.5-8.5L21.5 20zM10.5 20a3 3 0 0 1 6 0',
    megaphone: 'M3 10.5v3.5a1 1 0 0 0 1 1h2l9 4.5V5.5L6 10H4a1 1 0 0 0-1 .5zM6.5 15l1.4 4.5h2.6l-1.2-3.4M18 9.5a3.5 3.5 0 0 1 0 5',
    far: '<circle cx="6" cy="6" r="1.8"/><path d="M6 8.5v5.5M6 10.5l-2.4 2M6 10.5l2.4 2M6 14l-2 4.5M6 14l2 4.5"/><path d="M13 12h8M17.5 9l3.5 3-3.5 3" />',
    tape: '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/><circle cx="8.5" cy="11" r="2"/><circle cx="15.5" cy="11" r="2"/><path d="M10.5 11h3M7 18.5l1.5-3h7l1.5 3"/>',
    room: '<rect x="3.5" y="4" width="17" height="16" rx="1.5"/><path d="M9.5 20v-6.5h5V20M6.8 7.5h3.4v3.4H6.8z"/>',
    bath: 'M3 12h18v2.5a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5zM5.5 12V5.5a2 2 0 0 1 3.8-.9M7 19.5 6 21M17 19.5l1 1.5',
    church: 'M12 2.5v4M10.3 4.2h3.4M5 21v-9l7-5.5 7 5.5v9M3.5 21h17M10 21v-4a2 2 0 0 1 4 0v4',
    stadium: '<ellipse cx="12" cy="8" rx="9" ry="3.5"/><path d="M3 8v8c0 1.9 4 3.5 9 3.5s9-1.6 9-3.5V8M7.5 11.2v7.6M16.5 11.2v7.6"/>',
    car: '<path d="M3.5 15.5v-3.2L5.6 7h12.8l2.1 5.3v3.2zM3.5 12.3h17"/><circle cx="7.5" cy="16.5" r="2"/><circle cx="16.5" cy="16.5" r="2"/>',
    wall: '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 9.3h18M3 14.7h18M9 4v5.3M15 9.3v5.4M9 14.7V20"/>',
    walkie: '<rect x="6.5" y="7" width="11" height="14" rx="2"/><path d="M9 7V2.5M9.5 11h5M9.5 14h5M9.5 17h5"/>',
    vinyl: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/><path d="M12 5.5a6.5 6.5 0 0 1 6.5 6.5"/>',
    water: 'M2.5 9c2 0 2-1.5 4.5-1.5S9 9 11.5 9s2-1.5 4.5-1.5S18 9 21.5 9M2.5 14c2 0 2-1.5 4.5-1.5S9 14 11.5 14s2-1.5 4.5-1.5S18 14 21.5 14M2.5 19c2 0 2-1.5 4.5-1.5S9 19 11.5 19s2-1.5 4.5-1.5S18 19 21.5 19',
    monotone: 'M3 12h18M3 8h2.5M3 16h2.5M18.5 8H21M18.5 16H21'
  };

  function glyph(name) {
    var g = GLYPHS[name] || GLYPHS.wave;
    var body = g.charAt(0) === '<' ? g : '<path d="' + g + '"/>';
    return '<rect x="1.5" y="1.5" width="29" height="29" rx="9" fill="' + BRAND + '"/>'
      + '<g transform="translate(5 5) scale(.917)" fill="none" stroke="' + W + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + body + '</g>';
  }

  function svg(id, extraClass) {
    var name = String(id || '');
    var inner = name.indexOf('g-') === 0 ? glyph(name.slice(2)) : face(name.indexOf('f-') === 0 ? name.slice(2) : 'neutral');
    return '<svg class="vs-ico' + (extraClass ? ' ' + extraClass : '') + '" viewBox="0 0 32 32" aria-hidden="true" focusable="false">' + inner + '</svg>';
  }

  window.VoiceStudioIcons = {
    svg: svg,
    has: function (id) {
      var name = String(id || '');
      return name.indexOf('g-') === 0 ? !!GLYPHS[name.slice(2)] : name.indexOf('f-') === 0 && !!FACES[name.slice(2)];
    },
    all: function () {
      return Object.keys(FACES).map(function (k) { return 'f-' + k; }).concat(Object.keys(GLYPHS).map(function (k) { return 'g-' + k; }));
    }
  };
})();
