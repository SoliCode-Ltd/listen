/**
 * Listen Reader — Chrome Extension
 * Content Script
 *
 * Injected into web pages to provide text selection reading and
 * an embedded side-panel audio player. Handles audio playback,
 * progress tracking, speed control, and drag-to-reposition.
 *
 * All voice synthesis is powered by Listen's proprietary speech engine.
 *
 * (c) 2024-2026 SoliCode Ltd. All rights reserved.
 * https://solicode.co.uk
 *
 * This software is proprietary and confidential.
 * Unauthorized copying, distribution, or modification is strictly prohibited.
 */
(() => {
  if (window.__listenReaderContentLoaded) {
    return;
  }
  window.__listenReaderContentLoaded = true;

  const PANEL_ID = '__listen_side_player';
  const STYLE_ID = '__listen_side_player_style';

  const SPEED_OPTIONS = [
    { label: '1x', playbackRate: 1.0 },
    // Keep labels familiar, but tune effective rates for clarity.
    { label: '1.25x', playbackRate: 1.08 },
    { label: '1.5x', playbackRate: 1.16 },
    { label: '2x', playbackRate: 1.28 },
  ];

  const state = {
    panel: null,
    audio: null,
    isPlaying: false,
    speedIndex: 0,
    objectUrl: null,
    progressTimer: null,
    waveTimer: null,
    activeRequestToken: 0,
    appliedRequestToken: 0,
    playbackSession: 0,
    audioInstanceId: 0,
    dragPointerId: null,
    dragOffsetX: 0,
    dragOffsetY: 0,
    hasCustomPosition: false,
    resizeBound: false,
  };

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function formatTime(seconds) {
    const safe = Math.max(0, Number(seconds || 0));
    const min = Math.floor(safe / 60);
    const sec = Math.floor(safe % 60);
    return `${String(min).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
  }

  function pinPanelToViewport(panel, left, top) {
    if (!(panel instanceof HTMLElement)) {
      return;
    }

    const margin = 6;
    const rect = panel.getBoundingClientRect();
    const maxLeft = Math.max(margin, window.innerWidth - rect.width - margin);
    const maxTop = Math.max(margin, window.innerHeight - rect.height - margin);
    const nextLeft = clamp(Number(left || 0), margin, maxLeft);
    const nextTop = clamp(Number(top || 0), margin, maxTop);

    panel.style.left = `${nextLeft}px`;
    panel.style.top = `${nextTop}px`;
    panel.style.right = 'auto';
    panel.style.transform = 'none';
    state.hasCustomPosition = true;
  }

  function keepPanelInViewport() {
    if (!state.hasCustomPosition || !(state.panel instanceof HTMLElement)) {
      return;
    }

    const rect = state.panel.getBoundingClientRect();
    pinPanelToViewport(state.panel, rect.left, rect.top);
  }

  function injectStyle() {
    if (document.getElementById(STYLE_ID)) {
      return;
    }

    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
      #${PANEL_ID} {
        position: fixed;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        z-index: 2147483647;
        width: min(380px, calc(100vw - 16px));
        border-radius: 14px;
        border: 1px solid rgba(146, 168, 205, 0.35);
        background: linear-gradient(180deg, rgba(11, 18, 33, 0.98), rgba(10, 16, 29, 0.98));
        box-shadow: 0 22px 52px rgba(0, 0, 0, 0.42);
        color: #f4f8ff;
        font-family: Inter, Segoe UI, Roboto, Arial, sans-serif;
        overflow: hidden;
        backdrop-filter: blur(9px);
        opacity: 0;
        pointer-events: none;
        transition: opacity 180ms ease;
        user-select: none;
      }
      #${PANEL_ID}.open {
        opacity: 1;
        pointer-events: auto;
      }
      #${PANEL_ID} .listen-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 11px 12px 10px;
        border-bottom: 1px solid rgba(146, 168, 205, 0.2);
        background: linear-gradient(90deg, rgba(251, 131, 8, 0.15), rgba(89, 6, 178, 0.22));
        cursor: grab;
        touch-action: none;
      }
      #${PANEL_ID} .listen-head.dragging {
        cursor: grabbing;
      }
      #${PANEL_ID} .listen-brand {
        font-size: 13px;
        font-weight: 700;
      }
      #${PANEL_ID} .listen-close {
        width: 30px;
        height: 30px;
        border: 1px solid rgba(146, 168, 205, 0.25);
        background: rgba(15, 24, 42, 0.95);
        color: #d7e2f7;
        border-radius: 8px;
        display: inline-grid;
        place-items: center;
        cursor: pointer;
        transition: background 160ms ease, border-color 160ms ease, transform 120ms ease;
      }
      #${PANEL_ID} .listen-close:hover {
        background: rgba(24, 37, 62, 0.98);
        border-color: rgba(181, 201, 236, 0.45);
      }
      #${PANEL_ID} .listen-close:active {
        transform: scale(0.97);
      }
      #${PANEL_ID} .listen-close svg {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.9;
        stroke-linecap: round;
        stroke-linejoin: round;
        pointer-events: none;
      }
      #${PANEL_ID} .listen-body {
        padding: 12px;
        display: grid;
        gap: 9px;
      }
      #${PANEL_ID} .listen-voice {
        font-size: 13px;
        font-weight: 600;
        color: #f7f9ff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      #${PANEL_ID} .listen-text {
        font-size: 12px;
        color: #9db1d4;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      #${PANEL_ID} .listen-wave {
        height: 40px;
        border-radius: 10px;
        border: 1px solid rgba(146, 168, 205, 0.23);
        background: rgba(13, 22, 40, 0.9);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        padding: 0 7px;
      }
      #${PANEL_ID} .listen-wave span {
        width: 4px;
        height: 8px;
        border-radius: 999px;
        background: linear-gradient(180deg, #fb8308, #5906b2);
        transition: height 100ms linear;
      }
      #${PANEL_ID} .listen-progress {
        width: 100%;
        accent-color: #fb8308;
      }
      #${PANEL_ID} .listen-controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
      }
      #${PANEL_ID} .listen-controls-left {
        display: flex;
        align-items: center;
        gap: 8px;
      }
      #${PANEL_ID} .listen-play {
        border: 0;
        border-radius: 8px;
        padding: 8px 11px;
        font-size: 12px;
        font-weight: 600;
        color: #fff;
        background: linear-gradient(90deg, #fb8308, #5906b2);
        cursor: pointer;
      }
      #${PANEL_ID} .listen-speed {
        border: 1px solid rgba(146, 168, 205, 0.3);
        border-radius: 8px;
        padding: 7px 10px;
        min-width: 52px;
        font-size: 12px;
        font-weight: 700;
        color: #d7e2f7;
        background: rgba(15, 24, 42, 0.95);
        cursor: pointer;
      }
      #${PANEL_ID} .listen-speed:hover {
        border-color: rgba(181, 201, 236, 0.45);
        background: rgba(24, 37, 62, 0.98);
      }
      #${PANEL_ID} .listen-download {
        border: 1px solid rgba(146, 168, 205, 0.25);
        border-radius: 8px;
        padding: 7px 10px;
        font-size: 12px;
        font-weight: 600;
        color: #d7e2f7;
        text-decoration: none;
        background: rgba(15, 24, 42, 0.95);
      }
      #${PANEL_ID} .listen-download[aria-disabled='true'] {
        opacity: 0.45;
        pointer-events: none;
      }
      #${PANEL_ID} .listen-time {
        font-size: 11px;
        color: #9db1d4;
        min-width: 86px;
        text-align: right;
        font-variant-numeric: tabular-nums;
      }
      @media (max-width: 520px) {
        #${PANEL_ID} {
          width: calc(100vw - 12px);
        }
      }
    `;

    document.documentElement.appendChild(style);
  }

  function currentSpeedOption() {
    const index = clamp(Number(state.speedIndex || 0), 0, SPEED_OPTIONS.length - 1);
    return SPEED_OPTIONS[index] || SPEED_OPTIONS[0];
  }

  function getDuration() {
    const duration = Number(state.audio?.duration || 0);
    return Number.isFinite(duration) ? duration : 0;
  }

  function getCurrentOffset() {
    const current = Number(state.audio?.currentTime || 0);
    return Number.isFinite(current) ? current : 0;
  }

  function updatePlayButton() {
    const playBtn = state.panel?.querySelector('[data-role="play"]');
    if (playBtn instanceof HTMLButtonElement) {
      playBtn.textContent = state.isPlaying ? 'Pause' : 'Play';
    }
  }

  function updateSpeedButton() {
    const speedBtn = state.panel?.querySelector('[data-role="speed"]');
    if (speedBtn instanceof HTMLButtonElement) {
      speedBtn.textContent = currentSpeedOption().label;
    }
  }

  function updateTimeUi() {
    const timeEl = state.panel?.querySelector('[data-role="time"]');
    const rangeEl = state.panel?.querySelector('[data-role="range"]');
    if (!(timeEl instanceof HTMLElement) || !(rangeEl instanceof HTMLInputElement)) {
      return;
    }

    const duration = getDuration();
    const current = clamp(getCurrentOffset(), 0, duration);
    rangeEl.max = String(duration || 0);
    rangeEl.value = String(current);
    timeEl.textContent = `${formatTime(current)} / ${formatTime(duration)}`;
  }

  function stopWave() {
    if (state.waveTimer) {
      clearInterval(state.waveTimer);
      state.waveTimer = null;
    }
  }

  function runWave(active) {
    const bars = Array.from(state.panel?.querySelectorAll('.listen-wave span') || []);
    stopWave();

    if (!active || !bars.length) {
      bars.forEach((bar) => {
        bar.style.height = '8px';
      });
      return;
    }

    state.waveTimer = setInterval(() => {
      bars.forEach((bar) => {
        bar.style.height = `${8 + Math.floor(Math.random() * 24)}px`;
      });
    }, 120);
  }

  function stopProgressTimer() {
    if (state.progressTimer) {
      clearInterval(state.progressTimer);
      state.progressTimer = null;
    }
  }

  function startProgressTimer() {
    stopProgressTimer();
    state.progressTimer = setInterval(() => {
      updateTimeUi();
    }, 120);
  }

  function nextPlaybackSession() {
    state.playbackSession = Number(state.playbackSession || 0) + 1;
    return state.playbackSession;
  }

  function applyPlaybackRate() {
    if (!state.audio) {
      return;
    }

    const preferred = clamp(Number(currentSpeedOption().playbackRate || 1), 0.9, 1.4);

    if ('preservesPitch' in state.audio) {
      state.audio.preservesPitch = true;
    }
    if ('webkitPreservesPitch' in state.audio) {
      state.audio.webkitPreservesPitch = true;
    }
    if ('mozPreservesPitch' in state.audio) {
      state.audio.mozPreservesPitch = true;
    }

    state.audio.defaultPlaybackRate = preferred;
    state.audio.playbackRate = preferred;
  }

  function onPlaybackEnded() {
    state.isPlaying = false;
    stopProgressTimer();
    runWave(false);
    updatePlayButton();
    updateTimeUi();
  }

  function createAudioElement() {
    const audio = new Audio();
    const audioId = Number(state.audioInstanceId || 0) + 1;
    state.audioInstanceId = audioId;
    audio.preload = 'auto';

    audio.addEventListener('ended', () => {
      if (audio !== state.audio || audioId !== state.audioInstanceId) {
        return;
      }
      onPlaybackEnded();
    });
    audio.addEventListener('loadedmetadata', () => {
      if (audio !== state.audio || audioId !== state.audioInstanceId) {
        return;
      }
      applyPlaybackRate();
      updateTimeUi();
    });
    audio.addEventListener('play', () => {
      if (audio !== state.audio || audioId !== state.audioInstanceId) {
        return;
      }
      applyPlaybackRate();
    });
    audio.addEventListener('ratechange', () => {
      if (audio !== state.audio || audioId !== state.audioInstanceId) {
        return;
      }
      updateSpeedButton();
    });

    return audio;
  }

  function flushAudioElement() {
    if (!state.audio) {
      return;
    }

    nextPlaybackSession();
    const audio = state.audio;
    state.audio = null;

    try {
      audio.pause();
    } catch (_error) {
      // Ignore media pause errors on restricted pages.
    }

    try {
      audio.currentTime = 0;
    } catch (_error) {
      // Ignore seek errors on detached media elements.
    }

    try {
      audio.removeAttribute('src');
      audio.load();
    } catch (_error) {
      // Ignore cleanup errors; element is no longer active in state.
    }
  }

  function ensureAudioElement() {
    if (state.audio) {
      return state.audio;
    }

    state.audio = createAudioElement();
    return state.audio;
  }

  function pausePlayback({ resetPosition = false } = {}) {
    const audio = ensureAudioElement();
    nextPlaybackSession();
    audio.pause();
    if (resetPosition) {
      try {
        audio.currentTime = 0;
      } catch (_error) {
        // Ignore media seek errors in pages with unusual media policies.
      }
    }
    state.isPlaying = false;
    stopProgressTimer();
    runWave(false);
    updatePlayButton();
    updateTimeUi();
  }

  async function playFrom(offsetSeconds = null) {
    const audio = ensureAudioElement();
    if (!audio.src) {
      return;
    }

    const playbackSession = Number(state.playbackSession || 0);
    if (offsetSeconds !== null && Number.isFinite(offsetSeconds)) {
      const duration = getDuration();
      audio.currentTime = clamp(Number(offsetSeconds), 0, duration || Number(offsetSeconds));
    } else {
      const duration = getDuration();
      const current = getCurrentOffset();
      if (duration > 0 && current >= Math.max(0, duration - 0.05)) {
        audio.currentTime = 0;
      }
    }

    applyPlaybackRate();

    try {
      await audio.play();
      if (playbackSession !== Number(state.playbackSession || 0)) {
        return;
      }
      state.isPlaying = true;
      updatePlayButton();
      runWave(true);
      startProgressTimer();
      updateTimeUi();
    } catch (_error) {
      if (playbackSession !== Number(state.playbackSession || 0)) {
        return;
      }
      state.isPlaying = false;
      updatePlayButton();
      runWave(false);
      stopProgressTimer();
      updateTimeUi();
    }
  }

  function cyclePlaybackRate() {
    const current = clamp(Number(state.speedIndex || 0), 0, SPEED_OPTIONS.length - 1);
    state.speedIndex = (current + 1) % SPEED_OPTIONS.length;
    applyPlaybackRate();
    updateSpeedButton();
  }

  function base64ToArrayBuffer(base64) {
    const binary = atob(String(base64 || ''));
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
  }

  function getMimeExtension(mimeType) {
    const value = String(mimeType || '').toLowerCase();
    if (value.includes('wav')) return 'wav';
    if (value.includes('ogg')) return 'ogg';
    if (value.includes('mp4') || value.includes('m4a')) return 'm4a';
    if (value.includes('mpeg') || value.includes('mp3')) return 'mp3';
    return 'mp3';
  }

  function sanitizeFilePart(input, fallback) {
    const clean = String(input || '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
    return clean || fallback;
  }

  function buildDownloadName(voiceLabel, textPreview, mimeType) {
    const voicePart = sanitizeFilePart(voiceLabel || 'voice', 'voice');
    const textPart = sanitizeFilePart(String(textPreview || '').slice(0, 36), 'audio');
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const ext = getMimeExtension(mimeType);
    return `listen-${voicePart}-${textPart}-${stamp}.${ext}`;
  }

  function setMediaSource(arrayBuffer, mimeType, voiceLabel, textPreview) {
    const downloadEl = state.panel?.querySelector('[data-role="download"]');
    if (!(downloadEl instanceof HTMLAnchorElement)) {
      return;
    }

    pausePlayback({ resetPosition: true });
    flushAudioElement();
    const audio = ensureAudioElement();

    if (state.objectUrl) {
      URL.revokeObjectURL(state.objectUrl);
      state.objectUrl = null;
    }

    const blob = new Blob([arrayBuffer], { type: String(mimeType || 'audio/mpeg') });
    state.objectUrl = URL.createObjectURL(blob);

    audio.pause();
    audio.src = state.objectUrl;
    audio.load();
    applyPlaybackRate();

    downloadEl.href = state.objectUrl;
    downloadEl.download = buildDownloadName(voiceLabel, textPreview, mimeType);
    downloadEl.setAttribute('aria-disabled', 'false');
  }

  function ensurePanel() {
    injectStyle();

    let panel = document.getElementById(PANEL_ID);
    if (panel) {
      state.panel = panel;
      return panel;
    }

    panel = document.createElement('aside');
    panel.id = PANEL_ID;
    panel.innerHTML = `
      <div class="listen-head">
        <div class="listen-brand">Listen Voice</div>
        <button class="listen-close" type="button" data-role="close" title="Close" aria-label="Close">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 6l12 12"></path>
            <path d="M18 6l-12 12"></path>
          </svg>
        </button>
      </div>
      <div class="listen-body">
        <div class="listen-voice" data-role="voice">Voice: -</div>
        <div class="listen-text" data-role="text"></div>
        <div class="listen-wave">
          <span></span><span></span><span></span><span></span><span></span>
          <span></span><span></span><span></span><span></span><span></span>
        </div>
        <input class="listen-progress" data-role="range" type="range" min="0" value="0" step="0.01" />
        <div class="listen-controls">
          <div class="listen-controls-left">
            <button class="listen-play" data-role="play" type="button">Play</button>
            <button class="listen-speed" data-role="speed" type="button">1x</button>
            <a class="listen-download" data-role="download" href="#" aria-disabled="true">Download</a>
          </div>
          <div class="listen-time" data-role="time">00:00 / 00:00</div>
        </div>
      </div>
    `;

    state.panel = panel;

    const closeBtn = panel.querySelector('[data-role="close"]');
    const headEl = panel.querySelector('.listen-head');
    const playBtn = panel.querySelector('[data-role="play"]');
    const speedBtn = panel.querySelector('[data-role="speed"]');
    const downloadEl = panel.querySelector('[data-role="download"]');
    const rangeEl = panel.querySelector('[data-role="range"]');

    if (closeBtn instanceof HTMLButtonElement) {
      closeBtn.addEventListener('click', (event) => {
        event.preventDefault();
        panel.classList.remove('open');
        pausePlayback();
      });
    }

    if (playBtn instanceof HTMLButtonElement) {
      playBtn.addEventListener('click', () => {
        if (state.isPlaying) {
          pausePlayback();
          return;
        }
        playFrom();
      });
    }

    if (speedBtn instanceof HTMLButtonElement) {
      speedBtn.addEventListener('click', () => {
        cyclePlaybackRate();
      });
    }

    if (downloadEl instanceof HTMLAnchorElement) {
      downloadEl.addEventListener('click', (event) => {
        if (downloadEl.getAttribute('aria-disabled') === 'true') {
          event.preventDefault();
        }
      });
    }

    if (rangeEl instanceof HTMLInputElement) {
      rangeEl.addEventListener('input', () => {
        const audio = ensureAudioElement();
        const nextOffset = clamp(Number(rangeEl.value || 0), 0, getDuration());
        audio.currentTime = nextOffset;
        updateTimeUi();
      });
    }

    if (headEl instanceof HTMLElement) {
      headEl.addEventListener('pointerdown', (event) => {
        const isPrimaryPointer = event.pointerType !== 'mouse' || event.button === 0;
        if (!isPrimaryPointer) {
          return;
        }

        if (closeBtn instanceof HTMLElement && closeBtn.contains(event.target)) {
          return;
        }

        const rect = panel.getBoundingClientRect();
        pinPanelToViewport(panel, rect.left, rect.top);

        const pinnedRect = panel.getBoundingClientRect();
        state.dragPointerId = event.pointerId;
        state.dragOffsetX = event.clientX - pinnedRect.left;
        state.dragOffsetY = event.clientY - pinnedRect.top;
        headEl.classList.add('dragging');

        if (headEl.setPointerCapture) {
          try {
            headEl.setPointerCapture(event.pointerId);
          } catch (_error) {
            // Ignore if pointer capture is unavailable in this page context.
          }
        }

        event.preventDefault();
      });

      headEl.addEventListener('pointermove', (event) => {
        if (state.dragPointerId !== event.pointerId) {
          return;
        }

        const nextLeft = event.clientX - state.dragOffsetX;
        const nextTop = event.clientY - state.dragOffsetY;
        pinPanelToViewport(panel, nextLeft, nextTop);
        event.preventDefault();
      });

      const finishDrag = (event) => {
        if (state.dragPointerId !== event.pointerId) {
          return;
        }

        state.dragPointerId = null;
        headEl.classList.remove('dragging');

        if (headEl.releasePointerCapture) {
          try {
            headEl.releasePointerCapture(event.pointerId);
          } catch (_error) {
            // Ignore release failures.
          }
        }
      };

      headEl.addEventListener('pointerup', finishDrag);
      headEl.addEventListener('pointercancel', finishDrag);
    }

    document.documentElement.appendChild(panel);
    if (!state.resizeBound) {
      window.addEventListener('resize', () => keepPanelInViewport(), { passive: true });
      state.resizeBound = true;
    }
    ensureAudioElement();
    updatePlayButton();
    updateSpeedButton();
    updateTimeUi();
    return panel;
  }

  async function playInSidePanel(payload) {
    const panel = ensurePanel();
    const token = Number(payload?.requestToken || 0);
    if (Number.isFinite(token) && token > 0) {
      if (token <= state.appliedRequestToken) {
        return;
      }
      if (token < state.activeRequestToken) {
        return;
      }
      state.activeRequestToken = token;
      state.appliedRequestToken = token;
    }

    const voiceEl = panel.querySelector('[data-role="voice"]');
    const textEl = panel.querySelector('[data-role="text"]');
    if (!(voiceEl instanceof HTMLElement) || !(textEl instanceof HTMLElement)) {
      throw new Error('Player UI is not available.');
    }

    const base64 = String(payload?.audioBase64 || '').trim();
    if (!base64) {
      throw new Error('Missing audio payload.');
    }

    const voiceLabel = String(payload?.voiceLabel || '').trim();
    const text = String(payload?.text || '').trim();
    const mimeType = String(payload?.mimeType || 'audio/mpeg').trim();

    voiceEl.textContent = `Voice: ${voiceLabel || 'Auto'}`;
    textEl.textContent = text || 'Audio ready';

    const arrayBuffer = base64ToArrayBuffer(base64);
    setMediaSource(arrayBuffer, mimeType, voiceLabel, text);

    panel.classList.add('open');
    state.isPlaying = false;
    stopProgressTimer();
    runWave(false);
    updatePlayButton();
    updateSpeedButton();
    updateTimeUi();

    await playFrom(0);
  }

  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (!message || typeof message !== 'object') {
      sendResponse({ ok: false, message: 'Invalid message.' });
      return;
    }

    if (message.type === 'PING') {
      sendResponse({ ok: true });
      return;
    }

    if (message.type === 'GET_SELECTION') {
      const selectedText = (window.getSelection && window.getSelection().toString()) || '';
      sendResponse({ ok: true, text: String(selectedText || '').trim() });
      return;
    }

    if (message.type === 'PLAY_AUDIO') {
      playInSidePanel(message)
        .then(() => sendResponse({ ok: true }))
        .catch((error) => sendResponse({ ok: false, message: error?.message || 'Unable to open player.' }));
      return true;
    }

    sendResponse({ ok: false, message: 'Unsupported action.' });
  });
})();
