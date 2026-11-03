// Listen review of recorded agent replies (ops only, run by .github/workflows/agent-live-agent-test.yml).
//
// A text model listens to each clip like a native speaker of its dialect and reports, as JSON:
// what was said, any word that is not in the dialect (Modern Standard or another dialect),
// mispronounced or wrong words, glitches (cuts, clicks, stutters, robotic sound), unnatural
// phrasing for a real employee, and a score out of 10 for a business demo. It only listens:
// no audio is made.
//
// Usage: LIVE_KEY=... node scripts/agents/review_calls.mjs out-dir clip-dir [clip-dir...]
// Writes out-dir/review.json. Clips are named <case>-<n>.wav|mp3 (ar-eg-2.mp3, ar-eg-t2-1.wav).

import fs from 'node:fs';
import path from 'node:path';

const KEY = process.env.LIVE_KEY || '';
const [OUT = 'out', ...DIRS] = process.argv.slice(2);
const MODELS = ['gemini-3.8-flash', 'gemini-3.6-flash'];
if (!KEY) { console.error('LIVE_KEY is not set'); process.exit(1); }

const DIALECT = {
  'ar-sa': 'Saudi Arabic, Najdi (Riyadh)',
  'ar-ae': 'Emirati Arabic (Dubai)',
  'ar-eg': 'Egyptian Arabic, Cairene',
  'ar-lb': 'Lebanese Arabic, Southern (Sidon, Tyre)',
  'en-us': 'General American English',
  'en-gb': 'British English (East Anglian)',
  'en-ie': 'Irish English',
};

const SCHEMA = {
  type: 'OBJECT',
  properties: {
    transcript: { type: 'STRING', description: 'Exactly what is said, in the script of the language' },
    not_dialect: { type: 'ARRAY', items: { type: 'STRING' }, description: 'Words or phrases that are not in the expected dialect (Modern Standard Arabic or another dialect), each with what a native would say' },
    wrong_words: { type: 'ARRAY', items: { type: 'STRING' }, description: 'Mispronounced, wrong or made-up words' },
    glitches: { type: 'ARRAY', items: { type: 'STRING' }, description: 'Audio problems: cut words, clicks, stutters, robotic or garbled sound, sudden changes of voice' },
    unnatural: { type: 'ARRAY', items: { type: 'STRING' }, description: 'Phrasing a real employee would not use, repeated sentences, answering its own question' },
    score: { type: 'INTEGER', description: '1 to 10: how well this works as a demo for businesses' },
  },
  required: ['transcript', 'not_dialect', 'wrong_words', 'glitches', 'unnatural', 'score'],
};

async function review(file, dialect) {
  const audio = fs.readFileSync(file).toString('base64');
  const mime = file.endsWith('.mp3') ? 'audio/mp3' : 'audio/wav';
  const prompt = `You are a strict native speaker and dialect coach for ${dialect}. This is one reply of an AI receptionist on a phone call with a customer of a small business. Listen carefully and judge it as a native listener would. Be specific and short; leave a list empty when there is nothing to report. Do not report normal polite phrases of the dialect as problems.`;
  for (const model of MODELS) {
    const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent`, {
      method: 'POST',
      headers: { 'x-goog-api-key': KEY, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        contents: [{ role: 'user', parts: [{ text: prompt }, { inlineData: { mimeType: mime, data: audio } }] }],
        generationConfig: { responseMimeType: 'application/json', responseSchema: SCHEMA, temperature: 0.2 },
      }),
    });
    const text = await res.text();
    if (!res.ok) { console.log(`  ${model}: HTTP ${res.status} ${text.slice(0, 200)}`); continue; }
    try {
      const out = JSON.parse(JSON.parse(text).candidates[0].content.parts[0].text);
      return { model, ...out };
    } catch (e) { console.log(`  ${model}: unreadable answer`); }
  }
  return { error: 'no model answered' };
}

const files = DIRS.flatMap((d) => (fs.existsSync(d) ? fs.readdirSync(d).map((f) => path.join(d, f)) : []))
  .filter((f) => /\.(wav|mp3)$/.test(f))
  // A wav and its mp3 are the same clip: listen to one.
  .filter((f, i, all) => !(f.endsWith('.mp3') && all.includes(f.replace(/\.mp3$/, '.wav'))))
  .sort();
const results = [];
for (const file of files) {
  const id = path.basename(file).replace(/\.(wav|mp3)$/, '');
  const dialect = DIALECT[id.slice(0, 5)];
  if (!dialect) continue;
  const r = await review(file, dialect);
  results.push({ clip: id, from: path.dirname(file), ...r });
  console.log(`${id} (${path.dirname(file)}): score ${r.score ?? '-'}\n  said: ${r.transcript ?? ''}\n  not dialect: ${JSON.stringify(r.not_dialect ?? [])}\n  wrong: ${JSON.stringify(r.wrong_words ?? [])}\n  glitches: ${JSON.stringify(r.glitches ?? [])}\n  unnatural: ${JSON.stringify(r.unnatural ?? [])}`);
}
fs.mkdirSync(OUT, { recursive: true });
fs.writeFileSync(path.join(OUT, 'review.json'), JSON.stringify(results, null, 2));
