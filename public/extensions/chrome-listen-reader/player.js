const params = new URLSearchParams(window.location.search);
const audioUrl = params.get('audio') || '';
const textPreview = params.get('text') || 'Now playing selected text.';

const audioEl = document.getElementById('audio');
const previewEl = document.getElementById('textPreview');

previewEl.textContent = textPreview;

if (!audioUrl) {
  previewEl.textContent = 'Audio URL is missing.';
} else {
  audioEl.src = audioUrl;
  audioEl.play().catch(() => {
    // Autoplay may be blocked by browser policies.
  });
}

