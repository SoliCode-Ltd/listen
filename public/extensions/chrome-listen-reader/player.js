/**
 * Listen Reader — Chrome Extension
 * Standalone Audio Player
 *
 * (c) 2024-2026 SoliCode Ltd. All rights reserved.
 * https://solicode.co.uk
 */
const params = new URLSearchParams(window.location.search);
const audioUrl = params.get('audio') || '';
const msg = (key, fallback) => (chrome?.i18n?.getMessage?.(key) || fallback);
const textPreview = params.get('text') || msg('playerNowPlaying', 'Now playing selected text.');
document.documentElement.dir = (chrome?.i18n?.getUILanguage?.() || '').toLowerCase().startsWith('ar') ? 'rtl' : 'ltr';
document.getElementById('playerTitle').textContent = msg('extName', 'Listen Reader');
document.getElementById('playerHint').textContent = msg('playerKeepOpen', 'Keep this tab open while listening.');

const audioEl = document.getElementById('audio');
const previewEl = document.getElementById('textPreview');

previewEl.textContent = textPreview;

if (!audioUrl) {
  previewEl.textContent = msg('playerMissing', 'Audio URL is missing.');
} else {
  audioEl.src = audioUrl;
  audioEl.play().catch(() => {
    // Autoplay may be blocked by browser policies.
  });
}

