/**
 * Listen Reader — Chrome Extension
 * Popup UI Controller
 *
 * Manages the extension popup interface: API key configuration,
 * voice/language selection, profile display, and quick text input.
 * Communicates with the background service worker for all operations.
 *
 * All voice synthesis is powered by Listen's proprietary speech engine.
 *
 * (c) 2024-2026 SoliCode Ltd. All rights reserved.
 * https://solicode.co.uk
 *
 * This software is proprietary and confidential.
 * Unauthorized copying, distribution, or modification is strictly prohibited.
 */
const elements = {
  apiKey: document.getElementById('apiKey'),
  targetLanguage: document.getElementById('targetLanguage'),
  voiceKey: document.getElementById('voiceKey'),
  dialect: document.getElementById('dialect'),
  studioPanel: document.getElementById('studioPanel'),
  performanceStyle: document.getElementById('performanceStyle'),
  emotion: document.getElementById('emotion'),
  translate: document.getElementById('translate'),
  translateLabel: document.getElementById('translateLabel'),
  autoPlay: document.getElementById('autoPlay'),
  quickText: document.getElementById('quickText'),
  charCounter: document.getElementById('charCounter'),
  charCount: document.getElementById('charCount'),
  charLimit: document.getElementById('charLimit'),
  saveBtn: document.getElementById('saveBtn'),
  selectionBtn: document.getElementById('selectionBtn'),
  quickBtn: document.getElementById('quickBtn'),
  status: document.getElementById('status'),
  playerLink: document.getElementById('playerLink'),
  userAvatar: document.getElementById('userAvatar'),
  userName: document.getElementById('userName'),
  userPlan: document.getElementById('userPlan'),
  creditsValue: document.getElementById('creditsValue'),
  minutesText: document.getElementById('minutesText'),
  todayText: document.getElementById('todayText'),
  creditsNote: document.getElementById('creditsNote'),
  planLink: document.getElementById('planLink'),
  dashboardLink: document.getElementById('dashboardLink'),
  creditEstimate: document.getElementById('creditEstimate'),
  requestsText: document.getElementById('requestsText'),
  charsBar: document.getElementById('charsBar'),
  requestsBar: document.getElementById('requestsBar'),
  langSwitcher: document.getElementById('langSwitcher'),
};

// ===== i18n LOCALIZATION =====
let currentUiLang = 'en';
let translations = {};

// Load translations from JSON files (chrome.i18n only works with browser locale)
async function loadTranslations(lang) {
  try {
    const response = await fetch(chrome.runtime.getURL(`_locales/${lang}/messages.json`));
    const messages = await response.json();
    translations = {};
    for (const [key, value] of Object.entries(messages)) {
      translations[key] = value.message || '';
    }
    return translations;
  } catch (error) {
    console.warn('Failed to load translations:', error);
    return {};
  }
}

function t(key, fallback = '') {
  return translations[key] || fallback || key;
}

// A message with $NAME$ placeholders filled in.
function tf(key, fallback, values = {}) {
  let text = t(key, fallback);
  for (const [name, value] of Object.entries(values)) {
    text = text.split(`$${name.toUpperCase()}$`).join(String(value));
  }
  return text;
}

function formatNumber(value, digits = 0) {
  // Western digits in both languages, as on the website.
  return Number(value || 0).toLocaleString('en-US', {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  });
}

function applyTranslations() {
  // Translate elements with data-i18n attribute
  document.querySelectorAll('[data-i18n]').forEach((el) => {
    const key = el.getAttribute('data-i18n');
    const translated = t(key, '');
    if (translated) el.textContent = translated;
  });

  // Translate placeholders
  document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
    const key = el.getAttribute('data-i18n-placeholder');
    const translated = t(key, '');
    if (translated) el.placeholder = translated;
  });
}

function setUiDirection(lang) {
  const isRtl = lang === 'ar';
  document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
  document.documentElement.lang = lang;
}

async function loadUiLanguage() {
  return new Promise((resolve) => {
    chrome.storage.local.get({ uiLanguage: '' }, (result) => {
      // If no preference saved, detect from browser locale
      let lang = result.uiLanguage;
      if (!lang) {
        const browserLang = chrome.i18n.getUILanguage().toLowerCase();
        lang = browserLang.startsWith('ar') ? 'ar' : 'en';
      }
      currentUiLang = lang;
      resolve(lang);
    });
  });
}

async function saveUiLanguage(lang) {
  return new Promise((resolve) => {
    chrome.storage.local.set({ uiLanguage: lang }, resolve);
  });
}

function updateLangSwitcher() {
  if (elements.langSwitcher) {
    elements.langSwitcher.textContent = currentUiLang === 'ar' ? 'English' : 'عربي';
  }
}

async function switchLanguage() {
  const newLang = currentUiLang === 'ar' ? 'en' : 'ar';
  currentUiLang = newLang;
  await saveUiLanguage(newLang);
  await loadTranslations(newLang);
  setUiDirection(newLang);
  applyTranslations();
  updateLangSwitcher();
  // Re-render dynamic content with new translations
  const currentDialect = elements.dialect?.value || '';
  const currentVoice = elements.voiceKey?.value || '';
  renderDialectOptions(currentDialect);
  renderStudioOptions(elements.performanceStyle?.value || '', elements.emotion?.value || '');
  // Re-render voice options with translated labels
  renderVoiceOptions(currentVoice);
  // Update translate direction label
  updateTranslateLabel();
  // Re-apply profile card with new translations, then reload it so the plan name follows.
  setProfileCard(profileData);
  updateCharCounter();
  if (String(elements.apiKey.value || '').trim()) {
    refreshProfile().catch(() => { });
  }
}

let profileData = null;
let apiKeyVerifyTimer = null;
let lastVerifiedApiKey = '';
let preferredTranslateLanguage = 'ar';
const preferredVoiceKeyByLanguage = { ar: '', en: '' };

function setStatus(message, type = '') {
  elements.status.textContent = message || '';
  elements.status.classList.remove('ok', 'error');
  if (type) {
    elements.status.classList.add(type);
  }
  // The link to a voice goes with the message that it is ready.
  setLink(elements.playerLink, '');
}

// With the side panel on, the voice plays there; with it off, the voice is ready
// to play in the extension's own player, in a new tab.
function showVoiceReady(result, panelOn) {
  if (panelOn) {
    setStatus(t('statusGenerated', 'Voice generated in side panel.'), 'ok');
    return;
  }

  setStatus(t('statusReady', 'Your voice is ready.'), 'ok');
  const audioUrl = String(result?.audio_url || '').trim();
  if (audioUrl) {
    setLink(elements.playerLink, chrome.runtime.getURL(`player.html?${new URLSearchParams({ audio: audioUrl })}`));
  }
}

function requestBackground(message) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(message, (response) => {
      if (chrome.runtime.lastError) {
        reject(new Error(chrome.runtime.lastError.message));
        return;
      }

      resolve(response || {});
    });
  });
}

function readSettingsFromForm() {
  const targetLanguage = normalizeLanguage(elements.targetLanguage.value);
  if (elements.translate.checked) {
    preferredTranslateLanguage = targetLanguage;
  }

  const selectedVoiceKey = String(elements.voiceKey.value || '').trim();
  if (selectedVoiceKey) {
    preferredVoiceKeyByLanguage[targetLanguage] = selectedVoiceKey;
  }

  return {
    apiKey: elements.apiKey.value,
    targetLanguage,
    translationTargetLanguage: preferredTranslateLanguage,
    voiceKey: preferredVoiceKeyByLanguage[targetLanguage] || selectedVoiceKey,
    voiceKeyByLanguage: {
      ar: String(preferredVoiceKeyByLanguage.ar || '').trim(),
      en: String(preferredVoiceKeyByLanguage.en || '').trim(),
    },
    dialect: String(elements.dialect?.value || '').trim(),
    performanceStyle: String(elements.performanceStyle?.value || '').trim(),
    emotion: String(elements.emotion?.value || '').trim(),
    translate: elements.translate.checked,
    autoPlay: elements.autoPlay.checked,
  };
}

function normalizeLanguage(value) {
  return value === 'en' ? 'en' : 'ar';
}

function oppositeLanguage(value) {
  return normalizeLanguage(value) === 'en' ? 'ar' : 'en';
}

function resolvePreferredTranslateLanguage(settings = {}) {
  const savedPreferred = String(settings.translationTargetLanguage || '').trim();
  if (savedPreferred === 'en' || savedPreferred === 'ar') {
    return savedPreferred;
  }

  const savedTarget = normalizeLanguage(settings.targetLanguage);
  if (settings.translate === false) {
    // Migration-safe fallback: in non-translation mode target usually mirrors source language.
    return oppositeLanguage(savedTarget);
  }

  return savedTarget;
}

function resolveVoiceKeyByLanguage(settings = {}) {
  const map = settings.voiceKeyByLanguage && typeof settings.voiceKeyByLanguage === 'object'
    ? settings.voiceKeyByLanguage
    : {};
  const currentLanguage = normalizeLanguage(settings.targetLanguage);
  const fallbackVoice = String(settings.voiceKey || '').trim();
  const resolved = {
    ar: String(map.ar || '').trim(),
    en: String(map.en || '').trim(),
  };

  if (!resolved[currentLanguage] && fallbackVoice) {
    resolved[currentLanguage] = fallbackVoice;
  }

  return resolved;
}

function rememberCurrentVoiceSelection(language = normalizeLanguage(elements.targetLanguage.value)) {
  const selectedVoiceKey = String(elements.voiceKey.value || '').trim();
  if (!selectedVoiceKey) {
    return;
  }

  preferredVoiceKeyByLanguage[normalizeLanguage(language)] = selectedVoiceKey;
}

function translateDirectionLabel(language) {
  return language === 'en'
    ? t('translateToEnglish', 'Translate Arabic → English speaking')
    : t('translateToArabic', 'Translate English → Arabic speaking');
}

function updateTranslateLabel() {
  if (!(elements.translateLabel instanceof HTMLElement)) {
    return;
  }

  const language = normalizeLanguage(elements.targetLanguage.value);
  elements.translateLabel.textContent = translateDirectionLabel(language);
}

function studio() {
  return profileData?.voices?.studio || {};
}

function studioLabel(row) {
  if (!row) return '';
  if (currentUiLang === 'ar') return row.label_ar || row.label || row.value;
  return row.label_en || row.label || row.value;
}

// A select filled from the account's catalog; "Default (…)" keeps the site's default.
function fillStudioSelect(select, rows, defaultValue, preferred) {
  if (!select) return;
  const list = Array.isArray(rows) ? rows : [];
  const defaultRow = list.find((row) => row?.value === defaultValue);
  select.innerHTML = '';
  const first = document.createElement('option');
  first.value = '';
  first.textContent = defaultRow
    ? tf('defaultChoice', 'Default ($NAME$)', { name: studioLabel(defaultRow) })
    : t('autoDefault', 'Default');
  select.appendChild(first);
  for (const row of list) {
    const option = document.createElement('option');
    option.value = String(row?.value || '');
    option.textContent = studioLabel(row);
    select.appendChild(option);
  }
  select.value = list.some((row) => row?.value === preferred) ? preferred : '';
}

function renderDialectOptions(preferredDialect = '') {
  const language = normalizeLanguage(elements.targetLanguage.value);
  fillStudioSelect(
    elements.dialect,
    studio().dialects?.[language],
    studio().defaults?.[language]?.dialect,
    preferredDialect,
  );
}

function renderStudioOptions(preferredStyle = '', preferredEmotion = '') {
  const language = normalizeLanguage(elements.targetLanguage.value);
  const defaults = studio().defaults?.[language] || {};
  fillStudioSelect(elements.performanceStyle, studio().performance_styles, defaults.performance_style, preferredStyle);
  fillStudioSelect(elements.emotion, studio().emotions, defaults.emotion, preferredEmotion);
}

function updateStudioPanel() {
  if (!elements.studioPanel) return;
  // Only show studio panel if:
  // 1. Profile is loaded (API key valid)
  // 2. Plan is HD tier (not free/standard)
  // Premium voices only, as in the dashboard.
  const isPremium = String(profileData?.plan?.voice_tier || 'standard') === 'hd';
  elements.studioPanel.style.display = profileData?.plan && isPremium ? 'block' : 'none';
}

// About what a text costs, the same estimate as the dashboard (15 credits a second of audio).
function estimateCreditsFor(text) {
  const value = String(text || '').trim();
  const length = Array.from(value).length;
  if (!length) return 0;
  const words = (value.match(/[\p{L}\p{N}']+/gu) || []).length;
  return Math.max(3, Math.ceil(Math.max(words / 2.6, length / 15))) * 15;
}

function updateCharCounter() {
  if (!elements.quickText || !elements.charCount || !elements.charCounter) return;
  // Counted like the server: trimmed, one per character.
  const value = String(elements.quickText.value || '').trim();
  const length = Array.from(value).length;
  const maxChars = Number(profileData?.plan?.max_chars_per_request || 0);

  elements.charCount.textContent = formatNumber(length);
  elements.charLimit.textContent = maxChars > 0 ? formatNumber(maxChars) : '-';

  elements.charCounter.classList.remove('warning', 'danger');
  if (maxChars > 0 && length > maxChars) {
    elements.charCounter.classList.add('danger');
  } else if (maxChars > 0 && length > maxChars * 0.9) {
    elements.charCounter.classList.add('warning');
  }

  if (!elements.creditEstimate) return;
  const cost = estimateCreditsFor(value);
  const usage = profileData?.usage;
  elements.creditEstimate.classList.remove('short');
  if (!cost || !usage) {
    elements.creditEstimate.textContent = '';
    return;
  }
  const left = Number(usage.remaining_chars || 0) - cost;
  if (left < 0) {
    elements.creditEstimate.classList.add('short');
    elements.creditEstimate.textContent = t('estimateShort', 'Not enough credits for this text');
    return;
  }
  elements.creditEstimate.textContent = `${tf('estimateCost', 'About $CREDITS$ credits for this text', { credits: formatNumber(cost) })} · ${tf('estimateAfter', 'Credits after: $LEFT$', { left: formatNumber(left) })}`;
}

function writeSettingsToForm(settings = {}) {
  const translateEnabled = settings.translate !== false;
  const targetLanguage = normalizeLanguage(settings.targetLanguage);
  preferredTranslateLanguage = resolvePreferredTranslateLanguage(settings);
  const voiceKeys = resolveVoiceKeyByLanguage(settings);
  preferredVoiceKeyByLanguage.ar = voiceKeys.ar;
  preferredVoiceKeyByLanguage.en = voiceKeys.en;

  elements.apiKey.value = settings.apiKey || '';
  elements.targetLanguage.value = translateEnabled ? preferredTranslateLanguage : targetLanguage;
  elements.translate.checked = translateEnabled;
  elements.autoPlay.checked = settings.autoPlay !== false;
  renderStudioOptions(settings.performanceStyle || '', settings.emotion || '');
  updateTranslateLabel();
  renderVoiceOptions(voiceKeys[normalizeLanguage(elements.targetLanguage.value)] || settings.voiceKey || '');
  renderDialectOptions(settings.dialect || '');
  updateStudioPanel();
  updateCharCounter();
}

function voiceCatalog() {
  const fromApi = profileData?.voices?.catalog;
  return fromApi && typeof fromApi === 'object' ? fromApi : {};
}

function defaults() {
  return profileData?.voices?.defaults || {};
}

function voiceLabel(language, key) {
  const list = Array.isArray(voiceCatalog()?.[language]) ? voiceCatalog()[language] : [];
  const match = list.find((voice) => voice?.key === key);
  if (!match) return '';
  // Return Arabic label when UI is Arabic
  return currentUiLang === 'ar' && match.label_ar ? match.label_ar : match.label;
}

function getVoiceDisplayLabel(voice) {
  if (!voice) return '';
  // Return Arabic label when UI is Arabic
  return currentUiLang === 'ar' && voice.label_ar ? voice.label_ar : voice.label;
}

function formatGender(gender) {
  const text = String(gender || '').trim().toLowerCase();
  if (!text) return '';
  // Return Arabic gender when UI is Arabic
  if (currentUiLang === 'ar') {
    if (text === 'male') return 'ذكر';
    if (text === 'female') return 'أنثى';
  }
  return text.charAt(0).toUpperCase() + text.slice(1);
}

function sortVoices(list) {
  const tierRank = { standard: 1, hd: 2 };
  return [...list].sort((a, b) => {
    const aRank = tierRank[String(a?.tier || 'standard')] ?? 99;
    const bRank = tierRank[String(b?.tier || 'standard')] ?? 99;
    if (aRank !== bRank) return aRank - bRank;

    const byLabel = String(a?.label || '').localeCompare(String(b?.label || ''), undefined, { sensitivity: 'base' });
    if (byLabel !== 0) return byLabel;

    return String(a?.key || '').localeCompare(String(b?.key || ''), undefined, { sensitivity: 'base' });
  });
}

function renderVoiceOptions(preferredKey = '') {
  const language = elements.targetLanguage.value === 'en' ? 'en' : 'ar';
  const raw = Array.isArray(voiceCatalog()?.[language]) ? voiceCatalog()[language] : [];
  const list = sortVoices(raw);
  const fallbackDefault = defaults()?.[language] || '';
  const remembered = String(preferredVoiceKeyByLanguage[language] || '').trim();
  const current = String(preferredKey || remembered || fallbackDefault || '').trim();

  elements.voiceKey.innerHTML = '';

  const autoLabel = voiceLabel(language, fallbackDefault);
  const autoOption = document.createElement('option');
  autoOption.value = '';
  autoOption.textContent = autoLabel ? `${t('auto', 'Auto')} (${autoLabel})` : t('autoPlanDefault', 'Auto (Plan default)');
  elements.voiceKey.appendChild(autoOption);

  for (const voice of list) {
    const option = document.createElement('option');
    option.value = String(voice?.key || '');
    const quality = String(voice?.tier_label || '').trim();
    const voiceDisplayName = getVoiceDisplayLabel(voice);
    const baseLabel = formatGender(voice?.gender)
      ? `${voiceDisplayName} - ${formatGender(voice.gender)}`
      : String(voiceDisplayName || voice?.key || t('speaker', 'Speaker'));
    option.textContent = quality ? `${baseLabel} - ${quality}` : baseLabel;
    elements.voiceKey.appendChild(option);
  }

  // The member's own cloned voices, when their plan includes cloning.
  const cloned = Array.isArray(profileData?.voices?.cloned) ? profileData.voices.cloned : [];
  if (cloned.length) {
    const group = document.createElement('optgroup');
    group.label = t('yourVoices', 'Your cloned voices');
    for (const voice of cloned) {
      const option = document.createElement('option');
      option.value = String(voice?.key || '');
      option.textContent = String(voice?.label || voice?.key || '');
      group.appendChild(option);
    }
    elements.voiceKey.appendChild(group);
  }

  if (list.some((voice) => voice?.key === current) || cloned.some((voice) => voice?.key === current) || current === '') {
    elements.voiceKey.value = current;
  } else {
    elements.voiceKey.value = '';
  }

  const selectedVoiceKey = String(elements.voiceKey.value || '').trim();
  if (selectedVoiceKey) {
    preferredVoiceKeyByLanguage[language] = selectedVoiceKey;
  } else if (list.some((voice) => String(voice?.key || '') === String(fallbackDefault || ''))) {
    preferredVoiceKeyByLanguage[language] = String(fallbackDefault || '').trim();
  }
}

function setLink(link, url) {
  if (!link) return;
  if (url) {
    link.href = url;
    link.hidden = false;
  } else {
    link.hidden = true;
  }
}

function setProfileCard(profile = null) {
  const defaultName = t('connectAccount', 'Connect your account');
  const name = String(profile?.user?.name || defaultName);
  const plan = String(profile?.plan?.name || '-');
  const voiceTierLabel = String(profile?.plan?.voice_tier_label || '').trim();
  const usage = profile?.usage || {};
  const planCredits = Number(usage.plan_chars || 0);
  const left = Math.max(0, Number(usage.remaining_chars || 0));
  const hasCredits = planCredits > 0 || usage.mode === 'pack';

  const initial = name.trim().charAt(0).toUpperCase() || 'L';
  elements.userAvatar.textContent = initial;
  elements.userName.textContent = name;
  elements.userPlan.textContent = voiceTierLabel
    ? `${t('planLabel', 'Plan:')} ${plan} (${voiceTierLabel})`
    : `${t('planLabel', 'Plan:')} ${plan}`;

  // Credits left of the month's credits, like the dashboard; the bar shows what is left.
  elements.creditsValue.textContent = profile
    ? tf('creditsOf', '$LEFT$ of $TOTAL$', { left: formatNumber(left), total: formatNumber(planCredits) })
    : '-';
  elements.charsBar.style.width = planCredits > 0 ? `${Math.max(0, Math.min(100, (left / planCredits) * 100))}%` : '0%';

  const minutesTotal = Number(usage.minutes_limit || 0);
  elements.minutesText.textContent = profile && minutesTotal > 0
    ? tf('minutesUsed', '$USED$ of $TOTAL$ voice minutes used', {
      used: formatNumber(Number(usage.seconds_used || 0) / 60, 1),
      total: formatNumber(minutesTotal),
    })
    : '';

  const dailyLimit = Number(usage.daily_requests_limit || 0);
  const dailyUsed = Number(usage.daily_requests_used || 0);
  elements.todayText.textContent = profile && dailyLimit > 0
    ? tf('todayUsed', 'Today: $USED$ of $LIMIT$ generations', { used: formatNumber(dailyUsed), limit: formatNumber(dailyLimit) })
    : '';

  elements.requestsText.textContent = profile
    ? `${t('requestsLabel', 'Generations this month:')} ${formatNumber(usage.total_requests)} / ${usage.requests_unlimited ? t('unlimited', 'Unlimited') : formatNumber(usage.plan_requests)}`
    : '';
  elements.requestsBar && (elements.requestsBar.style.width = `${Math.max(0, Math.min(100, Number(usage.requests_percent || 0)))}%`);

  // The one thing the member should know right now.
  let note = '';
  let warn = false;
  if (profile && !hasCredits) {
    note = t('noCredits', 'Your plan has no credits yet.');
    warn = true;
  } else if (profile && dailyLimit > 0 && dailyUsed >= dailyLimit) {
    note = t('dailyReached', 'You reached today\u2019s limit. You can generate again tomorrow.');
    warn = true;
  } else if (profile && usage.mode === 'pack' && usage.pack_expires_at) {
    const date = new Date(usage.pack_expires_at);
    note = tf('packEnds', 'Your credits last until $DATE$', {
      date: date.toLocaleDateString(currentUiLang === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short', year: 'numeric' }),
    });
  } else if (profile && Number(usage.gift_chars || 0) > 0) {
    note = tf('giftNote', 'Includes $COUNT$ gift credits this month', { count: formatNumber(usage.gift_chars) });
  } else if (profile) {
    note = t('creditsHint', '900 credits = 1 minute of audio. You are charged for the audio you get.');
  }
  elements.creditsNote.textContent = note;
  elements.creditsNote.classList.toggle('warn', warn);

  const links = profile?.links || {};
  const needsPlan = profile && (!hasCredits || left <= 0);
  setLink(elements.planLink, needsPlan ? (links.pricing || links.billing) : links.manage);
  if (elements.planLink) {
    elements.planLink.textContent = needsPlan ? t('choosePlan', 'Choose a plan') : t('managePlan', 'Manage plan');
    elements.planLink.classList.toggle('primary', Boolean(needsPlan));
  }
  setLink(elements.dashboardLink, links.dashboard);
}

async function refreshProfile() {
  const response = await requestBackground({
    type: 'GET_PROFILE',
    settings: readSettingsFromForm(),
  });

  if (!response?.ok) {
    throw new Error(response?.message || t('errProfileLoad', 'Could not load profile.'));
  }

  profileData = response.profile || null;
  setProfileCard(profileData);
  renderVoiceOptions(elements.voiceKey.value);
  renderDialectOptions(elements.dialect?.value || '');
  renderStudioOptions(elements.performanceStyle?.value || '', elements.emotion?.value || '');
  updateStudioPanel();
  updateCharCounter();
}

async function persistSettingsSilently() {
  const response = await requestBackground({
    type: 'SAVE_SETTINGS',
    settings: readSettingsFromForm(),
  });

  if (!response?.ok) {
    throw new Error(response?.message || t('errInvalidRequest', 'Could not save settings.'));
  }
}

function scheduleApiKeyVerify() {
  if (apiKeyVerifyTimer) {
    clearTimeout(apiKeyVerifyTimer);
    apiKeyVerifyTimer = null;
  }

  const apiKey = String(elements.apiKey.value || '').trim();
  if (!apiKey) {
    profileData = null;
    lastVerifiedApiKey = '';
    setProfileCard(null);
    setStatus(t('statusSetApiKey', 'Set your API key to load account details.'));
    return;
  }

  if (apiKey.length < 20) {
    return;
  }

  apiKeyVerifyTimer = setTimeout(async () => {
    const currentApiKey = String(elements.apiKey.value || '').trim();
    if (!currentApiKey || currentApiKey === lastVerifiedApiKey) {
      return;
    }

    try {
      await refreshProfile();
      await persistSettingsSilently();
      lastVerifiedApiKey = currentApiKey;
      setStatus(t('statusApiKeyVerified', 'API key verified.'), 'ok');
    } catch (_error) {
      // Keep typing flow smooth; explicit actions still show hard errors.
    }
  }, 320);
}

async function loadLastStatus() {
  const response = await requestBackground({ type: 'GET_LAST_STATUS' });
  if (!response?.ok) return;

  const status = response?.status;
  const message = String(status?.message || '').trim();
  if (!message) return;

  const type = status?.type === 'ok' ? 'ok' : (status?.type === 'error' ? 'error' : '');
  await requestBackground({ type: 'CLEAR_LAST_STATUS' }).catch(() => { });
  if (type !== 'error') {
    return;
  }

  setStatus(message, type);
}

async function bootstrap() {
  // Load UI language and translations
  await loadUiLanguage();
  await loadTranslations(currentUiLang);
  setUiDirection(currentUiLang);
  applyTranslations();
  updateLangSwitcher();
  
  setStatus('');
  try {
    const response = await requestBackground({ type: 'GET_SETTINGS' });
    if (!response?.ok) {
      throw new Error(response?.message || t('errProfileLoad', 'Unable to load settings.'));
    }

    const settings = response.settings || {};
    writeSettingsToForm(settings);
    await syncLanguageToCurrentSelection().catch(() => { });
    lastVerifiedApiKey = String(settings.apiKey || '').trim();

    if (!String(settings.apiKey || '').trim()) {
      setProfileCard(null);
      setStatus(t('statusSetApiKey', 'Set your API key to load account details.'));
      await loadLastStatus().catch(() => { });
      return;
    }

    await refreshProfile();
    setStatus('');
    await loadLastStatus().catch(() => { });
  } catch (error) {
    setStatus(error.message || t('errProfileLoad', 'Unable to load settings.'), 'error');
    await loadLastStatus().catch(() => { });
  }
}

async function saveSettings() {
  setStatus(t('statusSaving', 'Saving settings...'));
  try {
    const response = await requestBackground({
      type: 'SAVE_SETTINGS',
      settings: readSettingsFromForm(),
    });

    if (!response?.ok) {
      throw new Error(response?.message || t('errInvalidRequest', 'Save failed.'));
    }

    writeSettingsToForm(response.settings || {});
    await refreshProfile();
    lastVerifiedApiKey = String((response.settings || {}).apiKey || '').trim();
    setStatus(t('statusSaved', 'Settings saved.'), 'ok');
  } catch (error) {
    setStatus(error.message || t('errInvalidRequest', 'Save failed.'), 'error');
  }
}

async function readSelection() {
  setStatus(t('statusReading', 'Reading selected text...'));
  try {
    await syncLanguageToCurrentSelection().catch(() => { });

    const overrides = readSettingsFromForm();
    const response = await requestBackground({
      type: 'READ_CURRENT_SELECTION',
      overrides,
    });
    if (!response?.ok) {
      throw new Error(response?.message || t('errNoSelection', 'Unable to read selected text.'));
    }

    await refreshProfile().catch(() => { });
    showVoiceReady(response.result, overrides.autoPlay);
  } catch (error) {
    // Refresh profile to show accurate usage even after errors
    await refreshProfile().catch(() => { });
    const msg = String(error?.message || '').toLowerCase();
    // Improve error messages for common network issues
    if (msg.includes('timed out')) {
      setStatus(t('statusTimeout', 'Request timed out. Check your dashboard - voice may have been generated.'), 'error');
    } else if (msg.includes('failed to fetch') || msg.includes('networkerror') || msg.includes('network error')) {
      setStatus(t('statusNetworkError', 'Network error. If usage changed, check dashboard for the generated audio.'), 'error');
    } else {
      setStatus(error.message || t('errNoSelection', 'Unable to read selected text.'), 'error');
    }
  }
}

async function readQuickText() {
  const text = String(elements.quickText.value || '').trim();
  if (!text) {
    setStatus(t('statusNoText', 'Enter quick text first.'), 'error');
    return;
  }

  setStatus(t('statusGenerating', 'Generating voice...'));
  try {
    const overrides = readSettingsFromForm();
    const response = await requestBackground({
      type: 'READ_TEXT',
      text,
      overrides,
    });

    if (!response?.ok) {
      throw new Error(response?.message || t('errGenerationFailed', 'Voice generation failed.'));
    }

    await refreshProfile().catch(() => { });
    showVoiceReady(response.result, overrides.autoPlay);
  } catch (error) {
    // Refresh profile to show accurate usage even after errors
    await refreshProfile().catch(() => { });
    const msg = String(error?.message || '').toLowerCase();
    // Improve error messages for common network issues
    if (msg.includes('timed out')) {
      setStatus(t('statusTimeout', 'Request timed out. Check your dashboard - voice may have been generated.'), 'error');
    } else if (msg.includes('failed to fetch') || msg.includes('networkerror') || msg.includes('network error')) {
      setStatus(t('statusNetworkError', 'Network error. If usage changed, check dashboard for the generated audio.'), 'error');
    } else {
      setStatus(error.message || t('errGenerationFailed', 'Voice generation failed.'), 'error');
    }
  }
}

async function getCurrentSelectionLanguage() {
  const response = await requestBackground({ type: 'DETECT_CURRENT_SELECTION_LANGUAGE' });
  if (!response?.ok || !response?.detection?.hasSelection) {
    return null;
  }

  const language = normalizeLanguage(response?.detection?.language);
  return language;
}

async function syncLanguageToCurrentSelection(fallbackLanguage = null) {
  if (elements.translate.checked) {
    return false;
  }

  const selectionLanguage = await getCurrentSelectionLanguage();
  if (!selectionLanguage) {
    const fallback = fallbackLanguage ? normalizeLanguage(fallbackLanguage) : null;
    if (!fallback) return false;

    const currentLanguage = normalizeLanguage(elements.targetLanguage.value);
    if (currentLanguage === fallback) return false;

    rememberCurrentVoiceSelection(currentLanguage);
    elements.targetLanguage.value = fallback;
    updateTranslateLabel();
    renderVoiceOptions('');
    await persistSettingsSilently();
    return true;
  }

  const currentLanguage = normalizeLanguage(elements.targetLanguage.value);
  if (currentLanguage === selectionLanguage) {
    return false;
  }

  rememberCurrentVoiceSelection(currentLanguage);
  elements.targetLanguage.value = selectionLanguage;
  updateTranslateLabel();
  renderVoiceOptions('');
  await persistSettingsSilently();
  return true;
}

async function restorePreferredTranslationLanguage() {
  if (!elements.translate.checked) {
    return false;
  }

  const currentLanguage = normalizeLanguage(elements.targetLanguage.value);
  const desiredLanguage = normalizeLanguage(preferredTranslateLanguage);
  if (currentLanguage === desiredLanguage) {
    return false;
  }

  rememberCurrentVoiceSelection(currentLanguage);
  elements.targetLanguage.value = desiredLanguage;
  updateTranslateLabel();
  renderVoiceOptions('');
  await persistSettingsSilently();
  return true;
}

elements.targetLanguage.addEventListener('change', () => {
  if (elements.translate.checked) {
    preferredTranslateLanguage = normalizeLanguage(elements.targetLanguage.value);
  }

  updateTranslateLabel();
  renderVoiceOptions('');
  renderDialectOptions('');
  if (!elements.translate.checked) {
    syncLanguageToCurrentSelection()
      .then((changed) => {
        if (!changed) return persistSettingsSilently();
        return null;
      })
      .catch(() => { });
    return;
  }

  persistSettingsSilently().catch(() => { });
});

elements.translate.addEventListener('change', () => {
  if (elements.translate.checked) {
    restorePreferredTranslationLanguage()
      .then((changed) => {
        if (!changed) return persistSettingsSilently();
        return null;
      })
      .catch(() => { });
    return;
  }

  rememberCurrentVoiceSelection(normalizeLanguage(elements.targetLanguage.value));
  preferredTranslateLanguage = normalizeLanguage(elements.targetLanguage.value);
  syncLanguageToCurrentSelection(oppositeLanguage(preferredTranslateLanguage))
    .then((changed) => {
      if (!changed) return persistSettingsSilently();
      return null;
    })
    .catch(() => { });
});

elements.voiceKey.addEventListener('change', () => {
  rememberCurrentVoiceSelection();
  persistSettingsSilently().catch(() => { });
});

elements.apiKey.addEventListener('input', scheduleApiKeyVerify);
elements.apiKey.addEventListener('change', async () => {
  const apiKey = String(elements.apiKey.value || '').trim();

  if (!apiKey) {
    profileData = null;
    lastVerifiedApiKey = '';
    setProfileCard(null);
    setStatus(t('statusSetApiKey', 'Set your API key to load account details.'));
    return;
  }

  try {
    await refreshProfile();
    await persistSettingsSilently();
    lastVerifiedApiKey = apiKey;
    setStatus(t('statusApiKeyVerified', 'API key verified.'), 'ok');
  } catch (error) {
    setStatus(error.message || t('errSetApiKey', 'Invalid API key.'), 'error');
  }
});

elements.saveBtn.addEventListener('click', saveSettings);
elements.selectionBtn.addEventListener('click', readSelection);
elements.quickBtn.addEventListener('click', readQuickText);

// Character counter for quick text
if (elements.quickText) {
  elements.quickText.addEventListener('input', updateCharCounter);
}

// Studio panel controls
if (elements.performanceStyle) {
  elements.performanceStyle.addEventListener('change', () => persistSettingsSilently().catch(() => {}));
}
if (elements.emotion) {
  elements.emotion.addEventListener('change', () => persistSettingsSilently().catch(() => {}));
}
if (elements.dialect) {
  elements.dialect.addEventListener('change', () => persistSettingsSilently().catch(() => {}));
}

// Language switcher
if (elements.langSwitcher) {
  elements.langSwitcher.addEventListener('click', switchLanguage);
}

bootstrap();
