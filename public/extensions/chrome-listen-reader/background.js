importScripts('config.js');

function normalizeApiBase(apiBase) {
  return String(apiBase || '').trim().replace(/\/+$/, '');
}

const PACKAGED_API_BASE = normalizeApiBase(self.LISTEN_EXTENSION_CONFIG?.apiBase || '');

const DEFAULT_SETTINGS = {
  apiBase: PACKAGED_API_BASE,
  apiKey: '',
  targetLanguage: 'ar',
  voiceKey: '',
  translate: false,
  sourceType: 'message',
  autoPlay: true,
};

const LAST_STATUS_KEY = 'listen_last_status';
const LAST_STATUS_TTL_MS = 1000 * 60 * 30;
const tabPlaybackTokens = new Map();

function setLastStatus(message, type = '') {
  const text = String(message || '').trim();
  const status = text ? {
    message: text,
    type: type === 'ok' ? 'ok' : (type === 'error' ? 'error' : ''),
    at: Date.now(),
  } : null;

  return new Promise((resolve) => {
    if (!status) {
      chrome.storage.local.remove(LAST_STATUS_KEY, () => resolve(null));
      return;
    }

    chrome.storage.local.set({ [LAST_STATUS_KEY]: status }, () => resolve(status));
  });
}

function getLastStatus() {
  return new Promise((resolve) => {
    chrome.storage.local.get([LAST_STATUS_KEY], (data) => {
      const status = data?.[LAST_STATUS_KEY];
      if (!status || typeof status !== 'object') {
        resolve(null);
        return;
      }

      const message = String(status.message || '').trim();
      if (!message) {
        resolve(null);
        return;
      }

      const type = status.type === 'ok' ? 'ok' : (status.type === 'error' ? 'error' : '');
      const at = Number(status.at || 0);
      if (at > 0 && (Date.now() - at) > LAST_STATUS_TTL_MS) {
        chrome.storage.local.remove(LAST_STATUS_KEY, () => resolve(null));
        return;
      }

      resolve({
        message,
        type,
        at: at || Date.now(),
      });
    });
  });
}

function nextPlaybackToken(tabId) {
  if (typeof tabId !== 'number') {
    return Date.now();
  }

  const current = Number(tabPlaybackTokens.get(tabId) || 0);
  const next = current + 1;
  tabPlaybackTokens.set(tabId, next);
  return next;
}

function isLatestPlaybackToken(tabId, token) {
  if (typeof tabId !== 'number') {
    return true;
  }

  return Number(tabPlaybackTokens.get(tabId) || 0) === Number(token || 0);
}

function setBadge(text, color) {
  chrome.action.setBadgeText({ text: text || '' });
  if (color) {
    chrome.action.setBadgeBackgroundColor({ color });
  }
}

function flashBadge(text, color, timeoutMs = 2000) {
  setBadge(text, color);
  setTimeout(() => setBadge('', null), timeoutMs);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function sendTabMessage(tabId, message) {
  return new Promise((resolve, reject) => {
    if (typeof tabId !== 'number') {
      reject(new Error('No active tab found.'));
      return;
    }

    chrome.tabs.sendMessage(tabId, message, (response) => {
      if (chrome.runtime.lastError) {
        reject(new Error(chrome.runtime.lastError.message));
        return;
      }

      resolve(response || {});
    });
  });
}

function isUnsupportedTabUrl(url) {
  const value = String(url || '').trim().toLowerCase();
  if (!value) {
    return false;
  }

  return value.startsWith('chrome://')
    || value.startsWith('chrome-extension://')
    || value.startsWith('edge://')
    || value.startsWith('about:')
    || value.startsWith('view-source:')
    || value.startsWith('devtools://');
}

async function getActiveTab() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab || typeof tab.id !== 'number') {
    throw new Error('No active tab found.');
  }

  return tab;
}

async function getActiveTabId() {
  const tab = await getActiveTab();
  return tab.id;
}

async function injectContentScript(tabId) {
  if (typeof tabId !== 'number') {
    return false;
  }

  try {
    await chrome.scripting.executeScript({
      target: { tabId },
      files: ['content.js'],
    });
    return true;
  } catch (_error) {
    return false;
  }
}

async function ensureContentScriptReady(tabId) {
  const ping = await sendTabMessage(tabId, { type: 'PING' }).catch(() => null);
  if (ping?.ok) {
    return true;
  }

  const injected = await injectContentScript(tabId);
  if (!injected) {
    return false;
  }

  const retryPing = await sendTabMessage(tabId, { type: 'PING' }).catch(() => null);
  return Boolean(retryPing?.ok);
}

async function readSelectionViaScript(tabId) {
  if (typeof tabId !== 'number') {
    return '';
  }

  const results = await chrome.scripting.executeScript({
    target: { tabId, allFrames: true },
    func: () => {
      const direct = String(window.getSelection?.().toString() || '').trim();
      if (direct) {
        return {
          text: direct,
          hasFocus: Boolean(document.hasFocus?.()),
          frame: window.top === window ? 'top' : 'child',
        };
      }

      const active = document.activeElement;
      if (active instanceof HTMLTextAreaElement) {
        const start = Number(active.selectionStart);
        const end = Number(active.selectionEnd);
        if (Number.isFinite(start) && Number.isFinite(end) && end > start) {
          return {
            text: String(active.value || '').slice(start, end).trim(),
            hasFocus: Boolean(document.hasFocus?.()),
            frame: window.top === window ? 'top' : 'child',
          };
        }
      }

      if (active instanceof HTMLInputElement) {
        const type = String(active.type || '').toLowerCase();
        if (!['text', 'search', 'url', 'tel', 'email'].includes(type)) {
          return '';
        }

        const start = Number(active.selectionStart);
        const end = Number(active.selectionEnd);
        if (Number.isFinite(start) && Number.isFinite(end) && end > start) {
          return {
            text: String(active.value || '').slice(start, end).trim(),
            hasFocus: Boolean(document.hasFocus?.()),
            frame: window.top === window ? 'top' : 'child',
          };
        }
      }

      return {
        text: '',
        hasFocus: Boolean(document.hasFocus?.()),
        frame: window.top === window ? 'top' : 'child',
      };
    },
  }).catch(() => []);

  const selections = Array.isArray(results)
    ? results
      .map((item) => ({
        text: String(item?.result?.text || '').trim(),
        hasFocus: Boolean(item?.result?.hasFocus),
        frame: String(item?.result?.frame || ''),
      }))
      .filter((item) => item.text)
    : [];

  if (!selections.length) {
    return '';
  }

  const focused = selections.find((item) => item.hasFocus);
  if (focused) {
    return focused.text;
  }

  const topFrame = selections.find((item) => item.frame === 'top');
  if (topFrame) {
    return topFrame.text;
  }

  return selections.sort((a, b) => b.text.length - a.text.length)[0].text;
}

async function readSelectionFromTab(tabId) {
  const ready = await ensureContentScriptReady(tabId);
  if (ready) {
    const firstTry = await sendTabMessage(tabId, { type: 'GET_SELECTION' }).catch(() => null);
    const firstText = String(firstTry?.text || '').trim();
    if (firstText) {
      return firstText;
    }
  }

  return readSelectionViaScript(tabId);
}

function detectLanguage(text) {
  return /[\u0600-\u06FF]/u.test(String(text || '')) ? 'ar' : 'en';
}

function truncate(input, max = 140) {
  const text = String(input || '').trim();
  if (text.length <= max) return text;
  return text.slice(0, max - 3) + '...';
}

function extractApiErrorMessage(data, fallback = 'Request failed.') {
  if (!data || typeof data !== 'object') {
    return fallback;
  }

  const primaryMessage = String(data.message || '').trim();
  const isGenericValidationMessage = primaryMessage.toLowerCase() === 'the given data was invalid.';

  if (!isGenericValidationMessage) {
    const direct = [data.message, data.error_message, data.error];
    for (const value of direct) {
      const message = String(value || '').trim();
      if (message) {
        return message;
      }
    }
  }

  const errors = data.errors;
  if (errors && typeof errors === 'object') {
    for (const value of Object.values(errors)) {
      if (Array.isArray(value)) {
        const first = value.find((entry) => String(entry || '').trim());
        const message = String(first || '').trim();
        if (message) {
          return message;
        }
      }

      const message = String(value || '').trim();
      if (message) {
        return message;
      }
    }
  }

  if (primaryMessage) {
    return primaryMessage;
  }

  return fallback;
}

function normalizeSelectedText(input) {
  return String(input || '')
    .replace(/\s+/g, ' ')
    .trim();
}

function createContextMenu() {
  chrome.contextMenus.removeAll(() => {
    chrome.contextMenus.create({
      id: 'listen-read-selection',
      title: 'Listen selected text',
      contexts: ['selection'],
    });
  });
}

chrome.runtime.onInstalled.addListener(() => {
  createContextMenu();
});

chrome.runtime.onStartup.addListener(() => {
  createContextMenu();
});

chrome.tabs.onRemoved.addListener((tabId) => {
  tabPlaybackTokens.delete(tabId);
});

function getSettings() {
  return new Promise((resolve) => {
    chrome.storage.sync.get(DEFAULT_SETTINGS, (stored) => {
      const storedApiBase = normalizeApiBase(stored?.apiBase);
      const apiBase = storedApiBase || PACKAGED_API_BASE;

      resolve({
        ...DEFAULT_SETTINGS,
        ...stored,
        apiBase,
      });
    });
  });
}

function saveSettings(settings) {
  const payload = {
    ...DEFAULT_SETTINGS,
    ...settings,
  };

  payload.apiBase = normalizeApiBase(payload.apiBase || PACKAGED_API_BASE);
  payload.apiKey = String(payload.apiKey || '').trim();
  payload.targetLanguage = payload.targetLanguage === 'en' ? 'en' : 'ar';
  payload.sourceType = ['email', 'message', 'file'].includes(payload.sourceType) ? payload.sourceType : 'message';
  payload.translate = Boolean(payload.translate);
  payload.voiceKey = String(payload.voiceKey || '').trim();
  payload.autoPlay = payload.autoPlay !== false;

  return new Promise((resolve) => {
    chrome.storage.sync.set(payload, () => resolve(payload));
  });
}

function arrayBufferToBase64(buffer) {
  const bytes = new Uint8Array(buffer);
  const chunkSize = 0x8000;
  let binary = '';

  for (let i = 0; i < bytes.length; i += chunkSize) {
    const chunk = bytes.subarray(i, i + chunkSize);
    binary += String.fromCharCode(...chunk);
  }

  return btoa(binary);
}

async function fetchAudioPacket(audioUrl) {
  const response = await fetch(String(audioUrl || ''), {
    method: 'GET',
    headers: { Accept: 'audio/mpeg,audio/*,*/*' },
  });

  if (!response.ok) {
    throw new Error('Could not download generated audio.');
  }

  const buffer = await response.arrayBuffer();
  const mimeType = response.headers.get('content-type') || 'audio/mpeg';

  return {
    audioBase64: arrayBufferToBase64(buffer),
    mimeType,
  };
}

async function pollJobUntilReady(apiBase, apiKey, jobId) {
  for (let i = 0; i < 18; i += 1) {
    await sleep(i < 5 ? 1200 : 2200);

    const response = await fetch(`${apiBase}/api/reader/jobs?limit=40`, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        'X-Listen-Key': apiKey,
      },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.ok === false) {
      throw new Error(extractApiErrorMessage(data, 'Unable to check generation status.'));
    }

    const jobs = Array.isArray(data?.jobs) ? data.jobs : [];
    const match = jobs.find((job) => Number(job?.id) === Number(jobId));
    if (!match) continue;

    if (match.status === 'done' && match.audio_url) {
      return match;
    }

    if (match.status === 'failed') {
      throw new Error(extractApiErrorMessage(match, 'Voice generation failed.'));
    }
  }

  throw new Error('Voice generation is taking too long. Please try again.');
}

async function openSidePlayer(tabId, audioUrl, textPreview, voiceLabel = '', requestToken = 0) {
  if (!isLatestPlaybackToken(tabId, requestToken)) {
    return { skipped: true };
  }

  const packet = await fetchAudioPacket(audioUrl);
  if (!isLatestPlaybackToken(tabId, requestToken)) {
    return { skipped: true };
  }

  const message = {
    type: 'PLAY_AUDIO',
    audioBase64: packet.audioBase64,
    mimeType: packet.mimeType,
    text: truncate(textPreview || '', 280),
    voiceLabel: String(voiceLabel || ''),
    requestToken: Number(requestToken || 0),
  };

  if (!isLatestPlaybackToken(tabId, requestToken)) {
    return { skipped: true };
  }

  let response = await sendTabMessage(tabId, message).catch(() => null);
  if (!response?.ok) {
    const ready = await ensureContentScriptReady(tabId);
    if (!ready) {
      throw new Error('Could not open side player in this page. Try in a regular website tab.');
    }

    response = await sendTabMessage(tabId, message).catch(() => null);
  }

  if (!response?.ok) {
    throw new Error('Could not open side player in this page. Try in a regular website tab.');
  }

  return { skipped: false };
}

async function fetchProfile(overrides = {}) {
  const settings = { ...(await getSettings()), ...overrides };
  const apiBase = normalizeApiBase(settings.apiBase);
  const apiKey = String(settings.apiKey || '').trim();

  if (!apiBase) {
    throw new Error('Service endpoint is not configured.');
  }
  if (!apiKey) {
    throw new Error('Set API Key to connect your account.');
  }

  const response = await fetch(`${apiBase}/api/reader/profile`, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      'X-Listen-Key': apiKey,
    },
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok || data?.ok === false) {
    throw new Error(extractApiErrorMessage(data, 'Could not load profile.'));
  }

  return data?.profile || null;
}

async function queueTextForPlayback(text, overrides = {}, runtimeContext = {}) {
  const selectedText = normalizeSelectedText(text);
  if (!selectedText) {
    throw new Error('No text selected. Highlight text first.');
  }

  const settings = { ...(await getSettings()), ...overrides };
  const apiBase = normalizeApiBase(settings.apiBase);
  const apiKey = String(settings.apiKey || '').trim();

  if (!apiBase) {
    throw new Error('Service endpoint is not configured.');
  }
  if (!apiKey) {
    throw new Error('Set API Key in extension settings.');
  }

  const selectedTargetLanguage = settings.targetLanguage === 'en' ? 'en' : 'ar';
  const sourceLanguage = detectLanguage(selectedText);
  const shouldTranslate = settings.translate !== false && sourceLanguage !== selectedTargetLanguage;
  const targetLanguage = shouldTranslate ? selectedTargetLanguage : sourceLanguage;

  const payload = {
    title: truncate(selectedText, 80),
    content: selectedText,
    target_language: targetLanguage,
    source_type: ['email', 'message', 'file'].includes(settings.sourceType) ? settings.sourceType : 'message',
    translate: shouldTranslate,
  };

  const voiceKey = targetLanguage === selectedTargetLanguage
    ? String(settings.voiceKey || '').trim()
    : '';
  if (voiceKey) {
    payload.voice_key = voiceKey;
  }

  const response = await fetch(`${apiBase}/api/reader/queue`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Listen-Key': apiKey,
    },
    body: JSON.stringify(payload),
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok || data?.ok === false) {
    throw new Error(extractApiErrorMessage(data, 'Voice generation failed.'));
  }

  if (data.audio_url) {
    if (settings.autoPlay) {
      const tabId = typeof runtimeContext.tabId === 'number'
        ? runtimeContext.tabId
        : await getActiveTabId();
      await openSidePlayer(
        tabId,
        data.audio_url,
        selectedText,
        data?.voice?.label || '',
        Number(runtimeContext.requestToken || 0),
      );
    }

    return {
      status: data.status || 'done',
      audio_url: data.audio_url,
      voice_label: data?.voice?.label || '',
      message: 'Voice generated successfully.',
    };
  }

  if (Number(data.job_id) > 0) {
    const readyJob = await pollJobUntilReady(apiBase, apiKey, data.job_id);

    if (settings.autoPlay) {
      const tabId = typeof runtimeContext.tabId === 'number'
        ? runtimeContext.tabId
        : await getActiveTabId();
      await openSidePlayer(
        tabId,
        readyJob.audio_url,
        selectedText,
        readyJob?.voice?.label || '',
        Number(runtimeContext.requestToken || 0),
      );
    }

    return {
      status: readyJob.status,
      audio_url: readyJob.audio_url,
      voice_label: readyJob?.voice?.label || '',
      message: 'Voice generated successfully.',
    };
  }

  throw new Error('Unexpected response from API.');
}

async function readCurrentTabSelection(overrides = {}) {
  const tab = await getActiveTab();
  if (isUnsupportedTabUrl(tab.url)) {
    throw new Error('Cannot read selection from chrome:// or extension pages. Open a regular website tab and try again.');
  }

  const selectedText = await readSelectionFromTab(tab.id);

  if (!selectedText) {
    throw new Error('No selected text found. Highlight text first, then try again.');
  }

  const requestToken = nextPlaybackToken(tab.id);
  return queueTextForPlayback(selectedText, overrides, { tabId: tab.id, requestToken });
}

chrome.contextMenus.onClicked.addListener(async (info, tab) => {
  if (info.menuItemId !== 'listen-read-selection') {
    return;
  }

  try {
    const selected = String(info.selectionText || '').trim();
    const tabId = typeof tab?.id === 'number' ? tab.id : null;
    const requestToken = tabId !== null ? nextPlaybackToken(tabId) : Date.now();
    await queueTextForPlayback(selected, {}, { tabId: tabId ?? undefined, requestToken });
    flashBadge('OK', '#2bb673');
  } catch (error) {
    const message = String(error?.message || 'Voice generation failed.');
    await setLastStatus(message, 'error');
    flashBadge('ERR', '#d9534f', 3000);
  }
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  const respond = async () => {
    if (!message || typeof message !== 'object') {
      return { ok: false, message: 'Invalid request.' };
    }

    if (message.type === 'GET_SETTINGS') {
      const settings = await getSettings();
      return { ok: true, settings };
    }

    if (message.type === 'SAVE_SETTINGS') {
      const saved = await saveSettings(message.settings || {});
      return { ok: true, settings: saved };
    }

    if (message.type === 'GET_PROFILE') {
      const profile = await fetchProfile(message.settings || {});
      return { ok: true, profile };
    }

    if (message.type === 'GET_LAST_STATUS') {
      const status = await getLastStatus();
      return { ok: true, status };
    }

    if (message.type === 'CLEAR_LAST_STATUS') {
      await setLastStatus('', '');
      return { ok: true };
    }

    if (message.type === 'READ_TEXT') {
      const tabId = await getActiveTabId();
      const requestToken = nextPlaybackToken(tabId);
      const result = await queueTextForPlayback(
        message.text || '',
        message.overrides || {},
        { tabId, requestToken },
      );
      flashBadge('OK', '#2bb673');
      return { ok: true, result };
    }

    if (message.type === 'READ_CURRENT_SELECTION') {
      const result = await readCurrentTabSelection(message.overrides || {});
      flashBadge('OK', '#2bb673');
      return { ok: true, result };
    }

    return { ok: false, message: 'Unsupported action.' };
  };

  respond()
    .then((payload) => sendResponse(payload))
    .catch(async (error) => {
      await setLastStatus(String(error?.message || 'Unexpected error.'), 'error');
      flashBadge('ERR', '#d9534f', 3000);
      sendResponse({ ok: false, message: error?.message || 'Unexpected error.' });
    });

  return true;
});
