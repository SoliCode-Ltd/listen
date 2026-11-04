// Live voice test for Listen agents (ops only, run by .github/workflows/agent-voice-test.yml).
//
// For each case it mints a short-lived key with the staging key (the way the
// agent widget will), opens a real-time voice session with it, asks two
// questions as a caller would, and keeps what the agent said:
//   out/<case>-<turn>.wav       the reply, 24 kHz mono
//   out/results.json            delay to the first sound, reply length, what it
//                               said (transcript) and the tokens each turn used
//
// Usage: LIVE_KEY=... node scripts/agents/live_voice_test.mjs out
// Needs Node 22+ (built-in WebSocket and fetch). Makes about a minute of audio.

import fs from 'node:fs';
import path from 'node:path';

const KEY = process.env.LIVE_KEY || '';
const OUT = process.argv[2] || 'out';
const MODEL = process.env.LIVE_MODEL || 'gemini-3.8-live';
const API = 'https://generativelanguage.googleapis.com/v1beta';
const WS = 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContentConstrained';

if (!KEY) {
  console.error('LIVE_KEY is not set');
  process.exit(1);
}
fs.mkdirSync(OUT, { recursive: true });

const FACTS = [
  'Listen (listenai.studio) turns text into natural speech in many Arabic dialects and English accents.',
  'Monthly plans: Basic $4 (20 minutes), Starter $9 (100 minutes), Creator $22 (200 minutes), Pro $49 (500 minutes), Studio Max $99 (2,000 minutes).',
  'Payment: card, Apple Pay, Google Pay, InstaPay or Vodafone Cash.',
  'If you do not know something, say the team will call back and ask for the caller\'s name and phone number. Never invent anything.',
].join(' ');

const CASES = [
  {
    id: 'eg-female', dialect: 'Egyptian', voice: 'Callirrhoe',
    style: 'You are Sumaya, the voice receptionist of Listen. Speak natural Egyptian Arabic (Cairo), warm and polite, one or two short sentences each time.',
    turns: ['السلام عليكم، عايز أعرف أسعار الباقات عندكم إيه؟', 'طب ينفع أدفع بفودافون كاش؟ وأقدر ألغي في أي وقت؟'],
  },
  {
    id: 'eg-male', dialect: 'Egyptian', voice: 'Iapetus',
    style: 'You are Karim, the voice receptionist of Listen. Speak natural Egyptian Arabic (Cairo), friendly and confident, one or two short sentences each time.',
    turns: ['ألو، مساء الخير، هو أنتو بتعملوا تعليق صوتي للإعلانات؟', 'طيب أرخص باقة بكام وفيها كام دقيقة؟'],
  },
  {
    id: 'sa-female', dialect: 'Saudi', voice: 'Achernar',
    style: 'You are Fatima, the voice receptionist of Listen. Speak natural Saudi Arabic (the everyday white Saudi dialect), warm and polite, one or two short sentences each time.',
    turns: ['السلام عليكم، أبغى أعرف وش أسعار الباقات عندكم؟', 'طيب أقدر أدفع بأبل باي؟ وهل فيه أصوات باللهجة السعودية؟'],
  },
  {
    id: 'sa-male', dialect: 'Saudi', voice: 'Algieba',
    style: 'You are Turki, the voice receptionist of Listen. Speak natural Saudi Arabic (the everyday white Saudi dialect), friendly and confident, one or two short sentences each time.',
    turns: ['هلا والله، عندكم خدمة تسوي فويس أوفر لإعلان متجري؟', 'كم سعر باقة البرو؟ وكم دقيقة فيها؟'],
  },
];

function wav(pcm, rate = 24000) {
  const h = Buffer.alloc(44);
  h.write('RIFF', 0); h.writeUInt32LE(36 + pcm.length, 4); h.write('WAVE', 8);
  h.write('fmt ', 12); h.writeUInt32LE(16, 16); h.writeUInt16LE(1, 20); h.writeUInt16LE(1, 22);
  h.writeUInt32LE(rate, 24); h.writeUInt32LE(rate * 2, 28); h.writeUInt16LE(2, 32); h.writeUInt16LE(16, 34);
  h.write('data', 36); h.writeUInt32LE(pcm.length, 40);
  return Buffer.concat([h, pcm]);
}

async function mintToken() {
  const now = Date.now();
  const t0 = performance.now();
  const res = await fetch(`${API}/auth_tokens`, {
    method: 'POST',
    headers: { 'x-goog-api-key': KEY, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      uses: 1,
      expireTime: new Date(now + 10 * 60 * 1000).toISOString(),
      newSessionExpireTime: new Date(now + 2 * 60 * 1000).toISOString(),
    }),
  });
  const body = await res.text();
  if (!res.ok) throw new Error(`token HTTP ${res.status}: ${body.slice(0, 300)}`);
  const name = JSON.parse(body).name;
  if (!name) throw new Error('token reply had no name');
  return { token: name, ms: Math.round(performance.now() - t0) };
}

function runCase(c, token) {
  return new Promise((resolve) => {
    const result = { id: c.id, dialect: c.dialect, voice: c.voice, model: MODEL, turns: [], error: null };
    const ws = new WebSocket(`${WS}?access_token=${encodeURIComponent(token)}`);
    let turn = -1;
    let cur = null;
    const timer = setTimeout(() => { result.error = result.error || 'timed out'; try { ws.close(); } catch {} }, 90000);
    const t0 = performance.now();

    const next = () => {
      if (cur) {
        const pcm = Buffer.concat(cur.chunks);
        const file = `${c.id}-${turn + 1}.wav`;
        fs.writeFileSync(path.join(OUT, file), wav(pcm));
        result.turns.push({
          asked: cur.asked, file, said: cur.said.trim(),
          first_sound_ms: cur.firstSound, done_ms: cur.done, audio_seconds: +(pcm.length / 48000).toFixed(2),
          usage: cur.usage,
        });
      }
      turn++;
      if (turn >= c.turns.length) { clearTimeout(timer); ws.close(); return; }
      cur = { asked: c.turns[turn], chunks: [], said: '', firstSound: null, done: null, usage: null, sent: performance.now() };
      ws.send(JSON.stringify({ realtimeInput: { text: c.turns[turn] } }));
    };

    ws.onopen = () => {
      result.connect_ms = Math.round(performance.now() - t0);
      ws.send(JSON.stringify({
        setup: {
          model: `models/${MODEL}`,
          generationConfig: {
            responseModalities: ['AUDIO'],
            speechConfig: { voiceConfig: { prebuiltVoiceConfig: { voiceName: c.voice } } },
          },
          systemInstruction: { parts: [{ text: `${c.style} ${FACTS}` }] },
          outputAudioTranscription: {},
        },
      }));
    };
    ws.onmessage = async (ev) => {
      const raw = typeof ev.data === 'string' ? ev.data : Buffer.from(await ev.data.arrayBuffer()).toString('utf8');
      let msg;
      try { msg = JSON.parse(raw); } catch { return; }
      if (msg.setupComplete) { result.setup_ms = Math.round(performance.now() - t0); next(); return; }
      if (msg.usageMetadata && cur) cur.usage = msg.usageMetadata;
      const sc = msg.serverContent;
      if (!sc || !cur) return;
      for (const p of sc.modelTurn?.parts || []) {
        if (p.inlineData?.data) {
          if (cur.firstSound === null) cur.firstSound = Math.round(performance.now() - cur.sent);
          cur.chunks.push(Buffer.from(p.inlineData.data, 'base64'));
        }
      }
      if (sc.outputTranscription?.text) cur.said += sc.outputTranscription.text;
      if (sc.turnComplete) {
        cur.done = Math.round(performance.now() - cur.sent);
        // usageMetadata can arrive just after turnComplete: wait a moment for it.
        setTimeout(next, 600);
      }
    };
    ws.onerror = (e) => { result.error = `socket error: ${e?.message || 'unknown'}`; };
    ws.onclose = (e) => {
      clearTimeout(timer);
      if (e.code !== 1000 && e.code !== 1005 && !result.error) result.error = `closed ${e.code} ${String(e.reason || '').slice(0, 200)}`;
      resolve(result);
    };
  });
}

const results = { model: MODEL, ran_at: new Date().toISOString(), cases: [] };
for (const c of CASES) {
  try {
    const { token, ms } = await mintToken();
    const r = await runCase(c, token);
    r.token_ms = ms;
    results.cases.push(r);
    console.log(`${c.id}: ${r.error ? 'ERROR ' + r.error : 'ok'}; ` + r.turns.map(t => `first sound ${t.first_sound_ms} ms, ${t.audio_seconds} s`).join(' | '));
  } catch (e) {
    results.cases.push({ id: c.id, error: String(e.message || e) });
    console.log(`${c.id}: ERROR ${e.message || e}`);
  }
}
fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
const ok = results.cases.filter(c => !c.error).length;
console.log(`done: ${ok}/${results.cases.length} cases`);
process.exit(ok > 0 ? 0 : 1);
