/**
 * Listen Reader — Chrome Extension
 * Background Service Worker
 *
 * Handles text-to-speech requests via the Listen API,
 * context menu integration, settings management, and audio playback routing.
 *
 * All voice synthesis is powered by Listen's proprietary speech engine.
 * Audio is generated server-side through the Listen API and streamed to the extension.
 *
 * (c) 2024-2026 SoliCode Ltd. All rights reserved.
 * https://solicode.co.uk
 *
 * This software is proprietary and confidential.
 * Unauthorized copying, distribution, or modification is strictly prohibited.
 */
importScripts('config.js');

function normalizeApiBase(apiBase) {
  return String(apiBase || '').trim().replace(/\/+$/, '');
}

function toAbsoluteAudioUrl(audioUrl, apiBase) {
  const url = String(audioUrl || '').trim();
  if (!url) return '';
  // Already absolute URL
  if (url.startsWith('http://') || url.startsWith('https://')) {
    return url;
  }
  // Relative URL - prepend apiBase
  const base = normalizeApiBase(apiBase);
  if (!base) return url;
  const path = url.startsWith('/') ? url : '/' + url;
  return base + path;
}

function normalizeLanguage(value) {
  return value === 'en' ? 'en' : 'ar';
}

/**
 * Get the current UI language from storage
 */
async function getUiLanguage() {
  return new Promise((resolve) => {
    chrome.storage.local.get({ uiLanguage: '' }, (result) => {
      const lang = result?.uiLanguage;
      if (lang === 'ar' || lang === 'en') {
        resolve(lang);
      } else {
        const browserLang = chrome.i18n.getUILanguage().toLowerCase();
        resolve(browserLang.startsWith('ar') ? 'ar' : 'en');
      }
    });
  });
}

/**
 * Get the appropriate voice label based on UI language
 */
function getVoiceLabel(voiceData, uiLang) {
  if (!voiceData) return '';
  if (uiLang === 'ar' && voiceData.label_ar) {
    return voiceData.label_ar;
  }
  return voiceData.label || '';
}

// Messages in the extension's language (the popup can switch it), cached once per language.
let currentBgLang = 'en';
const BG_MESSAGES = {};

async function warmBgMessages(lang) {
  const language = lang === 'ar' ? 'ar' : 'en';
  currentBgLang = language;
  if (BG_MESSAGES[language]) return;
  try {
    const response = await fetch(chrome.runtime.getURL(`_locales/${language}/messages.json`));
    const json = await response.json();
    BG_MESSAGES[language] = Object.fromEntries(Object.entries(json).map(([key, value]) => [key, value?.message || '']));
  } catch (_error) {
    BG_MESSAGES[language] = {};
  }
}

function bgText(key, fallback) {
  return BG_MESSAGES[currentBgLang]?.[key] || fallback;
}

getUiLanguage().then(warmBgMessages).catch(() => { });
chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'local' && changes.uiLanguage) {
    warmBgMessages(changes.uiLanguage.newValue).catch(() => { });
  }
});

const PACKAGED_API_BASE = normalizeApiBase(self.LISTEN_EXTENSION_CONFIG?.apiBase || '');
const PACKAGED_CONFIG_URL = normalizeApiBase(self.LISTEN_EXTENSION_CONFIG?.configUrl || '');
const REMOTE_CONFIG_ALARM = 'listen_remote_config_refresh';
const REMOTE_CONFIG_CACHE_KEY = 'listen_remote_config_cache';
const REMOTE_CONFIG_TTL_MS = 1000 * 60 * 60 * 6; // 6 hours

const DEFAULT_SETTINGS = {
  apiBase: PACKAGED_API_BASE,
  apiKey: '',
  targetLanguage: 'ar',
  translationTargetLanguage: 'ar',
  voiceKey: '',
  voiceKeyByLanguage: { ar: '', en: '' },
  dialect: '',
  performanceStyle: '',
  emotion: '',
  translate: false,
  autoPlay: true,
};

/**
 * Fetch the extension config from the remote server.
 * This allows the extension to auto-discover domain changes
 * without requiring a new extension build or manual user action.
 */
async function refreshRemoteConfig() {
  try {
    const settings = await getSettings();
    const configUrl = PACKAGED_CONFIG_URL || normalizeApiBase(settings.apiBase);
    if (!configUrl) return;

    const endpoint = `${configUrl}/api/extension/config`;
    const response = await fetch(endpoint, {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });

    if (!response.ok) return;

    const data = await response.json().catch(() => null);
    if (!data?.ok || !data?.config) return;

    const remoteApiBase = normalizeApiBase(data.config.apiBase);
    if (!remoteApiBase) return;

    // Cache the remote config
    await new Promise((resolve) => {
      chrome.storage.local.set({
        [REMOTE_CONFIG_CACHE_KEY]: {
          apiBase: remoteApiBase,
          brandName: data.config.brandName || '',
          version: data.config.version || '',
          fetchedAt: Date.now(),
        },
      }, resolve);
    });

    // If the remote server reports a different apiBase, update stored settings.
    // This enables seamless domain migration.
    const currentApiBase = normalizeApiBase(settings.apiBase);
    if (remoteApiBase && remoteApiBase !== currentApiBase) {
      await saveSettings({ ...settings, apiBase: remoteApiBase });
    }
  } catch (_error) {
    // Silently ignore — extension continues working with last known config.
  }
}

function scheduleRemoteConfigRefresh() {
  if (typeof chrome.alarms !== 'undefined') {
    chrome.alarms.create(REMOTE_CONFIG_ALARM, { periodInMinutes: 360 });
  }
}

if (typeof chrome.alarms !== 'undefined') {
  chrome.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name === REMOTE_CONFIG_ALARM) {
      refreshRemoteConfig();
    }
  });
}

const LAST_STATUS_KEY = 'listen_last_status';
const LAST_STATUS_TTL_MS = 1000 * 60 * 30;
const tabPlaybackTokens = new Map();
let contextMenuInitPromise = null;

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
  const nowToken = Date.now();

  if (typeof tabId !== 'number') {
    return nowToken;
  }

  const current = Number(tabPlaybackTokens.get(tabId) || 0);
  // Use wall-clock based monotonic tokens so content scripts can
  // safely reject stale messages even if the service worker restarts.
  const next = Math.max(nowToken, current + 1);
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
      reject(new Error(bgText('errNoActiveTab', 'No active tab found.')));
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
    throw new Error(bgText('errNoActiveTab', 'No active tab found.'));
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
  if (contextMenuInitPromise) {
    return contextMenuInitPromise;
  }

  const menuTitle = chrome.i18n.getMessage('contextMenuTitle') || 'Listen Selected Text';

  contextMenuInitPromise = new Promise((resolve) => {
    chrome.contextMenus.removeAll(() => {
      // Intentionally ignore removeAll errors (e.g., transient startup state).
      chrome.contextMenus.create({
        id: 'listen-read-selection',
        title: menuTitle,
        contexts: ['selection'],
      }, () => {
        const createError = chrome.runtime.lastError;
        if (!createError) {
          resolve();
          return;
        }

        // Avoid noisy "duplicate id" failures when startup hooks race.
        if (String(createError.message || '').toLowerCase().includes('duplicate id')) {
          chrome.contextMenus.update('listen-read-selection', {
            title: menuTitle,
            contexts: ['selection'],
          }, () => resolve());
          return;
        }

        resolve();
      });
    });
  }).finally(() => {
    contextMenuInitPromise = null;
  });

  return contextMenuInitPromise;
}

chrome.runtime.onInstalled.addListener(() => {
  createContextMenu().catch(() => { });
  scheduleRemoteConfigRefresh();
  refreshRemoteConfig();
});

chrome.runtime.onStartup.addListener(() => {
  createContextMenu().catch(() => { });
  scheduleRemoteConfigRefresh();
  refreshRemoteConfig();
});

chrome.tabs.onRemoved.addListener((tabId) => {
  tabPlaybackTokens.delete(tabId);
});

// Settings, the API key among them, stay in this browser (storage.local): Chrome
// keeps storage.sync in the member's account and copies it to every browser they
// sign in to. Versions before 1.2.2 kept them there, so the first run of this one
// moves them here and takes them out of the account.
const SETTINGS_MOVED_KEY = 'listen_settings_moved_from_sync';
let settingsMove = null;

function moveSettingsFromSync() {
  settingsMove = settingsMove || (async () => {
    try {
      const local = await chrome.storage.local.get(SETTINGS_MOVED_KEY);
      if (local?.[SETTINGS_MOVED_KEY]) {
        return;
      }

      const keys = Object.keys(DEFAULT_SETTINGS);
      const synced = await chrome.storage.sync.get(keys);
      const found = Object.keys(synced || {});
      if (found.length) {
        // What this browser already keeps wins over the copy from the account.
        const kept = await chrome.storage.local.get(found);
        await chrome.storage.local.set({ ...synced, ...kept });
        await chrome.storage.sync.remove(found);
      }
      await chrome.storage.local.set({ [SETTINGS_MOVED_KEY]: true });
    } catch (_error) {
      // Tried again the next time the extension starts.
    }
  })();

  return settingsMove;
}

async function getSettings() {
  await moveSettingsFromSync();
  return new Promise((resolve) => {
    chrome.storage.local.get(DEFAULT_SETTINGS, (stored) => {
      const storedApiBase = normalizeApiBase(stored?.apiBase);
      const apiBase = storedApiBase || PACKAGED_API_BASE;
      const targetLanguage = normalizeLanguage(stored?.targetLanguage);
      const savedPreferred = String(stored?.translationTargetLanguage || '').trim();
      const translationTargetLanguage = (savedPreferred === 'en' || savedPreferred === 'ar')
        ? savedPreferred
        : (stored?.translate === false ? (targetLanguage === 'en' ? 'ar' : 'en') : targetLanguage);
      const storedVoiceKey = String(stored?.voiceKey || '').trim();
      const map = stored?.voiceKeyByLanguage && typeof stored.voiceKeyByLanguage === 'object'
        ? stored.voiceKeyByLanguage
        : {};
      const voiceKeyByLanguage = {
        ar: String(map.ar || '').trim(),
        en: String(map.en || '').trim(),
      };
      if (!voiceKeyByLanguage[targetLanguage] && storedVoiceKey) {
        voiceKeyByLanguage[targetLanguage] = storedVoiceKey;
      }
      const voiceKey = String(voiceKeyByLanguage[targetLanguage] || storedVoiceKey || '').trim();

      resolve({
        ...DEFAULT_SETTINGS,
        ...stored,
        apiBase,
        targetLanguage,
        translationTargetLanguage,
        voiceKeyByLanguage,
        voiceKey,
        dialect: String(stored?.dialect || '').trim(),
        performanceStyle: String(stored?.performanceStyle || '').trim(),
        emotion: String(stored?.emotion || '').trim(),
      });
    });
  });
}

async function saveSettings(settings) {
  // Merged with what is stored: the popup never sends the service address (the
  // server may have moved it), so a save keeps it rather than resetting it.
  const payload = {
    ...(await getSettings()),
    ...settings,
  };

  payload.apiBase = normalizeApiBase(payload.apiBase || PACKAGED_API_BASE);
  payload.apiKey = String(payload.apiKey || '').trim();
  payload.targetLanguage = normalizeLanguage(payload.targetLanguage);
  payload.translationTargetLanguage = normalizeLanguage(
    payload.translationTargetLanguage || payload.targetLanguage,
  );
  const map = payload.voiceKeyByLanguage && typeof payload.voiceKeyByLanguage === 'object'
    ? payload.voiceKeyByLanguage
    : {};
  payload.voiceKeyByLanguage = {
    ar: String(map.ar || '').trim(),
    en: String(map.en || '').trim(),
  };
  payload.translate = Boolean(payload.translate);
  payload.voiceKey = String(payload.voiceKey || '').trim();
  if (payload.voiceKey && !payload.voiceKeyByLanguage[payload.targetLanguage]) {
    payload.voiceKeyByLanguage[payload.targetLanguage] = payload.voiceKey;
  }
  payload.voiceKey = String(payload.voiceKeyByLanguage[payload.targetLanguage] || payload.voiceKey || '').trim();
  payload.autoPlay = payload.autoPlay !== false;
  payload.dialect = String(payload.dialect || '').trim();
  payload.performanceStyle = String(payload.performanceStyle || '').trim();
  payload.emotion = String(payload.emotion || '').trim();

  return new Promise((resolve) => {
    chrome.storage.local.set(payload, () => resolve(payload));
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
    throw new Error(bgText('errAudioDownload', 'Could not download generated audio.'));
  }

  const buffer = await response.arrayBuffer();
  const mimeType = response.headers.get('content-type') || 'audio/mpeg';

  return {
    audioBase64: arrayBufferToBase64(buffer),
    mimeType,
  };
}

// Texts this long are made after the reply, as the server does them
// (listen.audio.force_async_chars): the extension checks on them instead of
// waiting for the audio in the request.
const LONG_TEXT_CHARS = 2500;

// How long to keep checking on a voice: a minute, and a second more for every
// 40 characters of text, at most 15 minutes.
function pollTimeoutMsFor(text) {
  const chars = Array.from(String(text || '')).length;
  return Math.min(15 * 60 * 1000, 60 * 1000 + Math.ceil(chars / 40) * 1000);
}

async function pollJobUntilReady(apiBase, apiKey, jobId, timeoutMs = 60 * 1000) {
  const startedAt = Date.now();
  for (let i = 0; Date.now() - startedAt < timeoutMs; i += 1) {
    // Quick at first, then every few seconds for a long text.
    await sleep(i < 8 ? 700 : Math.min(5000, 1300 + (i - 8) * 250));
    // An extension call keeps the service worker awake while it waits.
    chrome.runtime.getPlatformInfo(() => void chrome.runtime.lastError);

    let response;
    try {
      response = await fetch(`${apiBase}/api/reader/jobs?limit=40`, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          'X-Listen-Key': apiKey,
        'X-Listen-Locale': currentBgLang,
        },
      });
    } catch (networkError) {
      // Continue polling on network hiccups
      continue;
    }

    // A busy or restarting server is waited out like a network hiccup.
    if (response.status === 429 || response.status >= 500) {
      continue;
    }

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.ok === false) {
      throw new Error(extractApiErrorMessage(data, bgText('errStatusCheck', 'Unable to check generation status.')));
    }

    const jobs = Array.isArray(data?.jobs) ? data.jobs : [];
    const match = jobs.find((job) => Number(job?.id) === Number(jobId));
    if (!match) continue;

    if (match.status === 'done' && match.audio_url) {
      return match;
    }

    if (match.status === 'failed') {
      throw new Error(extractApiErrorMessage(match, bgText('errGenerationFailed', 'Voice generation failed.')));
    }
  }

  // Not a failure: the voice may still come, and trying again would be charged again.
  throw new Error(bgText('errQueueTimeout', 'Request timed out. The voice may still be generating - check your history.'));
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
      throw new Error(bgText('errSidePlayer', 'Your voice is ready, but it could not be played here. You will find it in your dashboard history.'));
    }

    response = await sendTabMessage(tabId, message).catch(() => null);
  }

  if (!response?.ok) {
    throw new Error(bgText('errSidePlayer', 'Your voice is ready, but it could not be played here. You will find it in your dashboard history.'));
  }

  return { skipped: false };
}

function playerPageUrl(audioUrl) {
  return chrome.runtime.getURL(`player.html?${new URLSearchParams({ audio: audioUrl })}`);
}

// The voice is made and paid for by now: if the page does not take the side
// player after all, the extension's own player plays it in a new tab.
async function playVoice(tabId, audioUrl, textPreview, voiceLabel = '', requestToken = 0) {
  try {
    await openSidePlayer(tabId, audioUrl, textPreview, voiceLabel, requestToken);
  } catch (_error) {
    const opened = await chrome.tabs.create({ url: playerPageUrl(audioUrl) }).catch(() => null);
    if (!opened) {
      throw new Error(bgText('errSidePlayer', 'Your voice is ready, but it could not be played here. You will find it in your dashboard history.'));
    }
  }
}

async function fetchProfile(overrides = {}) {
  await warmBgMessages(await getUiLanguage());
  const settings = { ...(await getSettings()), ...overrides };
  const apiBase = normalizeApiBase(settings.apiBase);
  const apiKey = String(settings.apiKey || '').trim();

  if (!apiBase) {
    throw new Error(bgText('errNoEndpoint', 'Service endpoint is not configured.'));
  }
  if (!apiKey) {
    throw new Error(bgText('errSetApiKey', 'Set API Key to connect your account.'));
  }

  let response;
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);
    
    response = await fetch(`${apiBase}/api/reader/profile`, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        'X-Listen-Key': apiKey,
        'X-Listen-Locale': currentBgLang,
      },
      signal: controller.signal,
    });
    
    clearTimeout(timeoutId);
  } catch (networkError) {
    if (networkError.name === 'AbortError') {
      throw new Error(bgText('errProfileTimeout', 'Request timed out. Server may be slow or unreachable.'));
    }
    throw new Error(bgText('errNetwork', 'Network error. Check your connection and ensure the server is running.'));
  }

  const data = await response.json().catch(() => ({}));
  if (!response.ok || data?.ok === false) {
    throw new Error(extractApiErrorMessage(data, bgText('errProfileLoad', 'Could not load profile.')));
  }

  return data?.profile || null;
}

async function queueTextForPlayback(text, overrides = {}, runtimeContext = {}) {
  const selectedText = normalizeSelectedText(text);
  if (!selectedText) {
    throw new Error(bgText('errNoTextSelected', 'No text selected. Highlight text first.'));
  }

  const settings = { ...(await getSettings()), ...overrides };
  const apiBase = normalizeApiBase(settings.apiBase);
  const apiKey = String(settings.apiKey || '').trim();
  const uiLang = await getUiLanguage();
  await warmBgMessages(uiLang);

  if (!apiBase) {
    throw new Error(bgText('errNoEndpoint', 'Service endpoint is not configured.'));
  }
  if (!apiKey) {
    throw new Error(bgText('errSetApiKey', 'Set API Key in extension settings.'));
  }

  // The side player opens in the page, and no extension can run in Chrome's own
  // pages or the Web Store: that is found out before the voice is made and charged.
  const playbackTabId = settings.autoPlay
    ? (typeof runtimeContext.tabId === 'number' ? runtimeContext.tabId : await getActiveTabId())
    : null;
  if (settings.autoPlay && !(await ensureContentScriptReady(playbackTabId))) {
    throw new Error(bgText('errSidePlayerPage', 'The side player cannot open on this page, so nothing was made or charged. Try a regular website tab, or turn off "Play voice in side panel".'));
  }

  const selectedTargetLanguage = settings.translate !== false
    ? normalizeLanguage(settings.translationTargetLanguage || settings.targetLanguage)
    : normalizeLanguage(settings.targetLanguage);
  const sourceLanguage = detectLanguage(selectedText);
  const shouldTranslate = settings.translate !== false && sourceLanguage !== selectedTargetLanguage;
  const targetLanguage = shouldTranslate ? selectedTargetLanguage : sourceLanguage;
  const voiceMap = settings.voiceKeyByLanguage && typeof settings.voiceKeyByLanguage === 'object'
    ? settings.voiceKeyByLanguage
    : {};
  const selectedVoiceForTarget = String(
    voiceMap[selectedTargetLanguage] || settings.voiceKey || '',
  ).trim();

  const payload = {
    title: truncate(selectedText, 80),
    content: selectedText,
    target_language: targetLanguage,
    source_type: 'message',
    translate: shouldTranslate,
    // A shorter text is waited for in the request; a long one is made after the
    // reply and checked on below, for as long as its length needs.
    prefer_sync: Array.from(selectedText).length < LONG_TEXT_CHARS,
  };

  const voiceKey = targetLanguage === selectedTargetLanguage ? selectedVoiceForTarget : '';
  if (voiceKey) {
    payload.voice_key = voiceKey;
  }

  // Add dialect if specified
  const dialect = String(settings.dialect || '').trim();
  if (dialect) {
    payload.dialect = dialect;
  }

  // Add HD tier features (performance style and emotion)
  const performanceStyle = String(settings.performanceStyle || '').trim();
  if (performanceStyle) {
    payload.performance_style = performanceStyle;
  }
  const emotion = String(settings.emotion || '').trim();
  if (emotion) {
    payload.emotion = emotion;
  }

  let response;
  try {
    // Use AbortController for timeout (60s for sync voice generation)
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 60000);
    
    response = await fetch(`${apiBase}/api/reader/queue`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Listen-Key': apiKey,
        'X-Listen-Locale': currentBgLang,
      },
      body: JSON.stringify(payload),
      signal: controller.signal,
    });
    
    clearTimeout(timeoutId);
  } catch (networkError) {
    if (networkError.name === 'AbortError') {
      throw new Error(bgText('errQueueTimeout', 'Request timed out. The voice may still be generating - check your history.'));
    }
    throw new Error(bgText('errNetwork', 'Network error. Check your connection and ensure the server is running.'));
  }

  const data = await response.json().catch(() => ({}));
  if (!response.ok || data?.ok === false) {
    throw new Error(extractApiErrorMessage(data, bgText('errGenerationFailed', 'Voice generation failed.')));
  }

  if (data.audio_url) {
    const absoluteAudioUrl = toAbsoluteAudioUrl(data.audio_url, apiBase);
    if (settings.autoPlay) {
      await playVoice(
        playbackTabId,
        absoluteAudioUrl,
        selectedText,
        getVoiceLabel(data?.voice, uiLang),
        Number(runtimeContext.requestToken || 0),
      );
    }

    return {
      status: data.status || 'done',
      audio_url: absoluteAudioUrl,
      voice_label: getVoiceLabel(data?.voice, uiLang),
      message: bgText('msgGenerated', 'Voice generated successfully.'),
    };
  }

  if (Number(data.job_id) > 0) {
    const readyJob = await pollJobUntilReady(apiBase, apiKey, data.job_id, pollTimeoutMsFor(selectedText));
    const absoluteJobAudioUrl = toAbsoluteAudioUrl(readyJob.audio_url, apiBase);

    if (settings.autoPlay) {
      await playVoice(
        playbackTabId,
        absoluteJobAudioUrl,
        selectedText,
        getVoiceLabel(readyJob?.voice, uiLang),
        Number(runtimeContext.requestToken || 0),
      );
    }

    return {
      status: readyJob.status,
      audio_url: absoluteJobAudioUrl,
      voice_label: getVoiceLabel(readyJob?.voice, uiLang),
      message: bgText('msgGenerated', 'Voice generated successfully.'),
    };
  }

  throw new Error(bgText('errUnexpected', 'Unexpected response from API.'));
}

async function readCurrentTabSelection(overrides = {}) {
  const tab = await getActiveTab();
  if (isUnsupportedTabUrl(tab.url)) {
    throw new Error(bgText('errUnsupportedPage', 'Cannot read selection from chrome:// or extension pages. Open a regular website tab and try again.'));
  }

  const selectedText = await readSelectionFromTab(tab.id);

  if (!selectedText) {
    throw new Error(bgText('errNoSelection', 'No selected text found. Highlight text first, then try again.'));
  }

  const requestToken = nextPlaybackToken(tab.id);
  return queueTextForPlayback(selectedText, overrides, { tabId: tab.id, requestToken });
}

async function detectCurrentSelectionLanguage() {
  const tab = await getActiveTab();
  if (isUnsupportedTabUrl(tab.url)) {
    return {
      hasSelection: false,
      language: null,
      text: '',
    };
  }

  const selectedText = await readSelectionFromTab(tab.id);
  if (!selectedText) {
    return {
      hasSelection: false,
      language: null,
      text: '',
    };
  }

  return {
    hasSelection: true,
    language: detectLanguage(selectedText),
    text: selectedText,
  };
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
    const message = String(error?.message || bgText('errGenerationFailed', 'Voice generation failed.'));
    await setLastStatus(message, 'error');
    flashBadge('ERR', '#d9534f', 3000);
  }
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  const respond = async () => {
    if (!message || typeof message !== 'object') {
      return { ok: false, message: bgText('errInvalidRequest', 'Invalid request.') };
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

    if (message.type === 'DETECT_CURRENT_SELECTION_LANGUAGE') {
      const detection = await detectCurrentSelectionLanguage();
      return { ok: true, detection };
    }

    return { ok: false, message: bgText('errUnsupportedAction', 'Unsupported action.') };
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
