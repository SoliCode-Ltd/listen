// The ad's script: six speakers from the voice library, each in their own dialect, each with a
// different feeling, each doing something people make with إسمع. Each clip was made in the studio
// itself (pick the speaker, type the input shown on screen, press Generate) and is kept as it came
// out; the times (seconds) come from the clips: `uv run build_vo.py` writes src/timeline.json.
import timeline from './timeline.json';

export type Voice = {
  slug: string;          // the clip
  name: string;          // the speaker's name in Arabic
  avatar: string;        // public/speakers/<avatar>.webp
  flag: string;          // public/flags/<flag>.svg
  dialect: string;       // as the site labels it
  use: string;           // what it's for
  palette: string;       // the orb's colours (theme.PALETTES)
  // Exactly what was typed in the studio to make the clip: a feeling tag in square brackets
  // ([متحمس]), sound tags in angle brackets (<ضحكة>), the words. \n where the line breaks on screen.
  input: string;
  size?: number;         // the line's text size (default 68)
};

type Timed = { start: number; audio: number; end: number; words: number[]; sounds: number[] };
// start/end: when the voice is on screen; audio: when it starts speaking; words and sounds: when
// each word and sound tag is heard, from the start of its speech
export type TimedVoice = Voice & Timed;
const T = timeline as unknown as { hook: { end: number }; voices: (Timed & { slug: string })[];
  studio: { start: number; end: number; result: number; replay: number }; end: { start: number; end: number }; duration: number };

export const HOOK = { title: 'اكتب أي جملة.', sub: 'واسمعها بأي لهجة…\nوبأي إحساس.', end: T.hook.end };

// Each clip is made in the studio from its input, the speaker and the dialect, nothing else.
const CAST: Voice[] = [
  { slug: 'sumaya-excited', name: 'سمية', avatar: 'ar-sumaya', flag: 'eg', dialect: 'مصرية (قاهرية)', use: 'إعلان سوشيال', palette: 'emotions',
    input: '[متحمس] يا جماعة الحقوا!\n<ضحكة خفيفة> العرض ده\nمش هيتكرر تاني… بجد!' },
  { slug: 'abdulaziz-proud', name: 'عبدالعزيز', avatar: 'ar-abdulaziz', flag: 'sa', dialect: 'سعودي (قصيمي)', use: 'فيلم براند', palette: 'pro',
    input: '[بفخر] من قلب نجد…\nللعالم كله.\nهذي قصتنا.' },
  { slug: 'sawsan-calm', name: 'سوسن', avatar: 'ar-sawsan', flag: 'lb', dialect: 'لبنانية', use: 'قصص أطفال', palette: 'longform',
    input: '[هادئ] كان في مرّة…\nبنت زغيرة، عم تحلم\nتغنّي عالمسرح.' },
  { slug: 'abdelilah-surprised', name: 'عبد الإله', avatar: 'ar-abdelilah', flag: 'ma', dialect: 'مغربي (مراكشي)', use: 'كوميديا', palette: 'arabic',
    input: '[متفاجئ] واش بصّح؟\n<ضحكة> لا لا،\nهادشي بزاف عليّا!' },
  { slug: 'sundus-sad', name: 'سندس', avatar: 'ar-sundus', flag: 'iq', dialect: 'عراقية (بغدادية قديمة)', use: 'دراما', palette: 'dialogue',
    input: '[حزين] <تنهيدة>\nما توقعت يوم…\nتروح وتخليني وحدي.' },
  { slug: 'hamed-whisper', name: 'حامد', avatar: 'ar-hamed', flag: 'ae', dialect: 'إماراتي', use: 'إثارة', palette: 'places',
    input: '[هامس] سمعت؟\nفي حد ورا الباب…\nلا تتحرك.' },
];

export const VOICES: TimedVoice[] = CAST.map((v) => ({ ...v, ...T.voices.find((x) => x.slug === v.slug)! }));

// The studio how-to (Sumaya's clip being made), then the end card while it plays on.
export const STUDIO = { start: T.studio.start, end: T.studio.end, result: T.studio.result,
  steps: ['اختار الصوت واللهجة', 'اكتب جملتك', 'زوّد إحساس', 'وصوت ضحكة', 'ودوس توليد', 'واسمع!'] };
export const END = { start: T.end.start, end: T.end.end, title: 'جرّب لهجتك', question: 'إنت لهجتك إيه؟ اكتبها في الكومنتات' };
export const DURATION = T.duration;
