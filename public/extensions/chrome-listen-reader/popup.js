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
  translate: document.getElementById('translate'),
  autoPlay: document.getElementById('autoPlay'),
  quickText: document.getElementById('quickText'),
  saveBtn: document.getElementById('saveBtn'),
  selectionBtn: document.getElementById('selectionBtn'),
  quickBtn: document.getElementById('quickBtn'),
  status: document.getElementById('status'),
  userAvatar: document.getElementById('userAvatar'),
  userName: document.getElementById('userName'),
  userPlan: document.getElementById('userPlan'),
  charsText: document.getElementById('charsText'),
  requestsText: document.getElementById('requestsText'),
  charsBar: document.getElementById('charsBar'),
  requestsBar: document.getElementById('requestsBar'),
};

const fallbackCatalog = {
  ar: [
    { key: 'voice_ar_f_1', label: 'Sara', gender: 'female' },
    { key: 'voice_ar_f_2', label: 'Mariam', gender: 'female' },
    { key: 'voice_ar_m_1', label: 'Omar', gender: 'male' },
    { key: 'voice_ar_m_2', label: 'Ahmed', gender: 'male' },
  ],
  en: [
    { key: 'voice_en_f_1', label: 'Emma', gender: 'female' },
    { key: 'voice_en_f_2', label: 'Olivia', gender: 'female' },
    { key: 'voice_en_m_1', label: 'Liam', gender: 'male' },
    { key: 'voice_en_m_2', label: 'Noah', gender: 'male' },
  ],
};

let profileData = null;
let apiKeyVerifyTimer = null;
let lastVerifiedApiKey = '';

function setStatus(message, type = '') {
  elements.status.textContent = message || '';
  elements.status.classList.remove('ok', 'error');
  if (type) {
    elements.status.classList.add(type);
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
  return {
    apiKey: elements.apiKey.value,
    targetLanguage: elements.targetLanguage.value,
    voiceKey: elements.voiceKey.value,
    translate: elements.translate.checked,
    autoPlay: elements.autoPlay.checked,
  };
}

function writeSettingsToForm(settings = {}) {
  elements.apiKey.value = settings.apiKey || '';
  elements.targetLanguage.value = settings.targetLanguage === 'en' ? 'en' : 'ar';
  elements.translate.checked = settings.translate !== false;
  elements.autoPlay.checked = settings.autoPlay !== false;
  renderVoiceOptions(settings.voiceKey || '');
}

function voiceCatalog() {
  const fromApi = profileData?.voices?.catalog;
  if (fromApi && typeof fromApi === 'object') {
    return fromApi;
  }

  return fallbackCatalog;
}

function defaults() {
  return profileData?.voices?.defaults || {};
}

function voiceLabel(language, key) {
  const list = Array.isArray(voiceCatalog()?.[language]) ? voiceCatalog()[language] : [];
  const match = list.find((voice) => voice?.key === key);
  return match?.label || '';
}

function formatGender(gender) {
  const text = String(gender || '').trim().toLowerCase();
  if (!text) return '';
  return text.charAt(0).toUpperCase() + text.slice(1);
}

function sortVoices(list) {
  const tierRank = { standard: 1, hd: 2, hd_plus: 3 };
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
  const current = preferredKey || elements.voiceKey.value || fallbackDefault;

  elements.voiceKey.innerHTML = '';

  const autoLabel = voiceLabel(language, fallbackDefault);
  const autoOption = document.createElement('option');
  autoOption.value = '';
  autoOption.textContent = autoLabel ? `Auto (${autoLabel})` : 'Auto (Plan default)';
  elements.voiceKey.appendChild(autoOption);

  for (const voice of list) {
    const option = document.createElement('option');
    option.value = String(voice?.key || '');
    const quality = String(voice?.tier_label || '').trim();
    const baseLabel = formatGender(voice?.gender)
      ? `${voice.label} - ${formatGender(voice.gender)}`
      : String(voice?.label || voice?.key || 'Voice');
    option.textContent = quality ? `${baseLabel} - ${quality}` : baseLabel;
    elements.voiceKey.appendChild(option);
  }

  if (list.some((voice) => voice?.key === current) || current === '') {
    elements.voiceKey.value = current;
  } else {
    elements.voiceKey.value = '';
  }
}

function setProfileCard(profile = null) {
  const name = String(profile?.user?.name || 'Connect your account');
  const plan = String(profile?.plan?.name || '-');
  const voiceTierLabel = String(profile?.plan?.voice_tier_label || '').trim();
  const usage = profile?.usage || {};
  const requestsUnlimited = Boolean(usage?.requests_unlimited);

  const initial = name.trim().charAt(0).toUpperCase() || 'L';
  elements.userAvatar.textContent = initial;
  elements.userName.textContent = name;
  elements.userPlan.textContent = voiceTierLabel ? `Plan: ${plan} (${voiceTierLabel})` : `Plan: ${plan}`;
  elements.charsText.textContent = `Characters: ${Number(usage.total_chars || 0).toLocaleString()} / ${Number(usage.plan_chars || 0).toLocaleString()}`;
  elements.requestsText.textContent = requestsUnlimited
    ? `Requests: ${Number(usage.total_requests || 0).toLocaleString()} / Unlimited`
    : `Requests: ${Number(usage.total_requests || 0).toLocaleString()} / ${Number(usage.plan_requests || 0).toLocaleString()}`;
  elements.charsBar.style.width = `${Math.max(0, Math.min(100, Number(usage.chars_percent || 0)))}%`;
  elements.requestsBar.style.width = `${Math.max(0, Math.min(100, Number(usage.requests_percent || 0)))}%`;
}

async function refreshProfile() {
  const response = await requestBackground({
    type: 'GET_PROFILE',
    settings: readSettingsFromForm(),
  });

  if (!response?.ok) {
    throw new Error(response?.message || 'Could not load profile.');
  }

  profileData = response.profile || null;
  setProfileCard(profileData);
  renderVoiceOptions(elements.voiceKey.value);
}

async function persistSettingsSilently() {
  const response = await requestBackground({
    type: 'SAVE_SETTINGS',
    settings: readSettingsFromForm(),
  });

  if (!response?.ok) {
    throw new Error(response?.message || 'Could not save settings.');
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
    setStatus('Set your API key to load account details.');
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
      setStatus('API key verified.', 'ok');
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
  setStatus('');
  try {
    const response = await requestBackground({ type: 'GET_SETTINGS' });
    if (!response?.ok) {
      throw new Error(response?.message || 'Unable to load settings.');
    }

    const settings = response.settings || {};
    writeSettingsToForm(settings);
    lastVerifiedApiKey = String(settings.apiKey || '').trim();

    if (!String(settings.apiKey || '').trim()) {
      setProfileCard(null);
      setStatus('Set your API key to load account details.');
      await loadLastStatus().catch(() => { });
      return;
    }

    await refreshProfile();
    setStatus('');
    await loadLastStatus().catch(() => { });
  } catch (error) {
    setStatus(error.message || 'Unable to load settings.', 'error');
    await loadLastStatus().catch(() => { });
  }
}

async function saveSettings() {
  setStatus('Saving settings...');
  try {
    const response = await requestBackground({
      type: 'SAVE_SETTINGS',
      settings: readSettingsFromForm(),
    });

    if (!response?.ok) {
      throw new Error(response?.message || 'Save failed.');
    }

    writeSettingsToForm(response.settings || {});
    await refreshProfile();
    lastVerifiedApiKey = String((response.settings || {}).apiKey || '').trim();
    setStatus('Settings saved.', 'ok');
  } catch (error) {
    setStatus(error.message || 'Save failed.', 'error');
  }
}

async function readSelection() {
  setStatus('Reading selected text...');
  try {
    const response = await requestBackground({
      type: 'READ_CURRENT_SELECTION',
      overrides: readSettingsFromForm(),
    });
    if (!response?.ok) {
      throw new Error(response?.message || 'Unable to read selected text.');
    }

    await refreshProfile().catch(() => { });
    setStatus('Voice generated in side panel.', 'ok');
  } catch (error) {
    setStatus(error.message || 'Unable to read selected text.', 'error');
  }
}

async function readQuickText() {
  const text = String(elements.quickText.value || '').trim();
  if (!text) {
    setStatus('Enter quick text first.', 'error');
    return;
  }

  setStatus('Generating voice...');
  try {
    const response = await requestBackground({
      type: 'READ_TEXT',
      text,
      overrides: readSettingsFromForm(),
    });

    if (!response?.ok) {
      throw new Error(response?.message || 'Voice generation failed.');
    }

    await refreshProfile().catch(() => { });
    setStatus('Voice generated in side panel.', 'ok');
  } catch (error) {
    setStatus(error.message || 'Voice generation failed.', 'error');
  }
}

elements.targetLanguage.addEventListener('change', () => {
  renderVoiceOptions(elements.voiceKey.value);
});

elements.apiKey.addEventListener('input', scheduleApiKeyVerify);
elements.apiKey.addEventListener('change', async () => {
  const apiKey = String(elements.apiKey.value || '').trim();

  if (!apiKey) {
    profileData = null;
    lastVerifiedApiKey = '';
    setProfileCard(null);
    setStatus('Set your API key to load account details.');
    return;
  }

  try {
    await refreshProfile();
    await persistSettingsSilently();
    lastVerifiedApiKey = apiKey;
    setStatus('API key verified.', 'ok');
  } catch (error) {
    setStatus(error.message || 'Invalid API key.', 'error');
  }
});

elements.saveBtn.addEventListener('click', saveSettings);
elements.selectionBtn.addEventListener('click', readSelection);
elements.quickBtn.addEventListener('click', readQuickText);

bootstrap();

