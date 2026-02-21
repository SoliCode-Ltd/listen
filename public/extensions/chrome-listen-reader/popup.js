const elements = {
  apiKey: document.getElementById('apiKey'),
  targetLanguage: document.getElementById('targetLanguage'),
  sourceType: document.getElementById('sourceType'),
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
    { key: 'dai', label: 'Dai', gender: 'female' },
    { key: 'rami', label: 'Rami', gender: 'male' },
    { key: 'maha', label: 'Maha', gender: 'female' },
    { key: 'kareem', label: 'Kareem', gender: 'male' },
  ],
  en: [
    { key: 'ember', label: 'Ember', gender: 'female' },
    { key: 'cade', label: 'Cade', gender: 'male' },
    { key: 'vexa', label: 'Vexa', gender: 'female' },
    { key: 'lyric', label: 'Lyric', gender: 'female' },
    { key: 'orbit', label: 'Orbit', gender: 'male' },
    { key: 'quill', label: 'Quill', gender: 'male' },
  ],
};

let profileData = null;

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
    sourceType: elements.sourceType.value,
    voiceKey: elements.voiceKey.value,
    translate: elements.translate.checked,
    autoPlay: elements.autoPlay.checked,
  };
}

function writeSettingsToForm(settings = {}) {
  elements.apiKey.value = settings.apiKey || '';
  elements.targetLanguage.value = settings.targetLanguage === 'en' ? 'en' : 'ar';
  elements.sourceType.value = ['email', 'message', 'file'].includes(settings.sourceType) ? settings.sourceType : 'message';
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

function renderVoiceOptions(preferredKey = '') {
  const language = elements.targetLanguage.value === 'en' ? 'en' : 'ar';
  const list = Array.isArray(voiceCatalog()?.[language]) ? voiceCatalog()[language] : [];
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
    option.textContent = formatGender(voice?.gender)
      ? `${voice.label} - ${formatGender(voice.gender)}`
      : String(voice?.label || voice?.key || 'Voice');
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
  const usage = profile?.usage || {};
  const requestsUnlimited = Boolean(usage?.requests_unlimited);

  const initial = name.trim().charAt(0).toUpperCase() || 'L';
  elements.userAvatar.textContent = initial;
  elements.userName.textContent = name;
  elements.userPlan.textContent = `Plan: ${plan}`;
  elements.charsText.textContent = `Characters: ${Number(usage.total_chars || 0).toLocaleString()} / ${Number(usage.plan_chars || 0).toLocaleString()}`;
  elements.requestsText.textContent = requestsUnlimited
    ? `Requests: ${Number(usage.total_requests || 0).toLocaleString()} / Unlimited`
    : `Requests: ${Number(usage.total_requests || 0).toLocaleString()} / ${Number(usage.plan_requests || 0).toLocaleString()}`;
  elements.charsBar.style.width = `${Math.max(0, Math.min(100, Number(usage.chars_percent || 0)))}%`;
  elements.requestsBar.style.width = requestsUnlimited
    ? '100%'
    : `${Math.max(0, Math.min(100, Number(usage.requests_percent || 0)))}%`;
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

async function bootstrap() {
  setStatus('');
  try {
    const response = await requestBackground({ type: 'GET_SETTINGS' });
    if (!response?.ok) {
      throw new Error(response?.message || 'Unable to load settings.');
    }

    const settings = response.settings || {};
    writeSettingsToForm(settings);

    if (!String(settings.apiKey || '').trim()) {
      setProfileCard(null);
      setStatus('Set your API key to load account details.');
      return;
    }

    await refreshProfile();
    setStatus('');
  } catch (error) {
    setStatus(error.message || 'Unable to load settings.', 'error');
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

    await refreshProfile().catch(() => {});
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

    await refreshProfile().catch(() => {});
    setStatus('Voice generated in side panel.', 'ok');
  } catch (error) {
    setStatus(error.message || 'Voice generation failed.', 'error');
  }
}

elements.targetLanguage.addEventListener('change', () => {
  renderVoiceOptions(elements.voiceKey.value);
});

elements.apiKey.addEventListener('change', async () => {
  if (!String(elements.apiKey.value || '').trim()) {
    setProfileCard(null);
    setStatus('Set your API key to load account details.');
    return;
  }

  try {
    await refreshProfile();
    setStatus('API key verified.', 'ok');
  } catch (_error) {
    // Keep silent while typing; explicit actions show errors.
  }
});

elements.saveBtn.addEventListener('click', saveSettings);
elements.selectionBtn.addEventListener('click', readSelection);
elements.quickBtn.addEventListener('click', readQuickText);

bootstrap();

