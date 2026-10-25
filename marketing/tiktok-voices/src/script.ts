// The ad's script: six speakers from the voice library, each in their own dialect, each with a
// different feeling, each doing something people make with إسمع. Times are in seconds; the
// voice clips are made the way the studio's Generate button makes them, from the input shown
// on screen (marketing/tiktok-voices/generate.php), and the times below come from them.

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
  start: number;         // when the voice starts in the ad (s)
  end: number;
  words?: number[];      // when each word starts (s, from the start of the clip)
  sounds?: number[];     // when each sound tag is heard (s, from the start of the clip)
  size?: number;         // the line's text size (default 68)
};

export const HOOK = { title: 'اكتب أي جملة.', sub: 'واسمعها بأي لهجة…\nوبأي إحساس.', end: 3.0 };

// Each clip is made in the studio from its input, the speaker and the dialect, nothing else.
export const VOICES: Voice[] = [
  { slug: 'sumaya-excited', name: 'سمية', avatar: 'ar-sumaya', flag: 'eg', dialect: 'مصرية (قاهرية)', use: 'إعلان سوشيال', palette: 'emotions',
    input: '[متحمس] يا جماعة الحقوا!\n<ضحكة خفيفة> العرض ده\nمش هيتكرر تاني… بجد!', start: 3.0, end: 5.7 },
  { slug: 'abdulaziz-proud', name: 'عبدالعزيز', avatar: 'ar-abdulaziz', flag: 'sa', dialect: 'سعودي (قصيمي)', use: 'فيلم براند', palette: 'pro',
    input: '[بفخر] من قلب نجد…\nللعالم كله.\nهذي قصتنا.', start: 5.7, end: 8.4 },
  { slug: 'sawsan-calm', name: 'سوسن', avatar: 'ar-sawsan', flag: 'lb', dialect: 'لبنانية', use: 'قصص أطفال', palette: 'longform',
    input: '[هادئ] كان في مرّة…\nبنت زغيرة، بتحلم\nتغنّي عالمسرح.', start: 8.4, end: 11.1 },
  { slug: 'abdelilah-surprised', name: 'عبد الإله', avatar: 'ar-abdelilah', flag: 'ma', dialect: 'مغربي (مراكشي)', use: 'كوميديا', palette: 'arabic',
    input: '[متفاجئ] واش بصّح؟\n<ضحكة> لا لا،\nهادشي بزاف عليّا!', start: 11.1, end: 13.8 },
  { slug: 'sundus-sad', name: 'سندس', avatar: 'ar-sundus', flag: 'iq', dialect: 'عراقية (بغدادية)', use: 'دراما', palette: 'dialogue',
    input: '[حزين] <تنهيدة>\nما توقعت يوم…\nتروح وتخليني وحدي.', start: 13.8, end: 16.5 },
  { slug: 'hamed-whisper', name: 'حامد', avatar: 'ar-hamed', flag: 'ae', dialect: 'إماراتي', use: 'إثارة', palette: 'places',
    input: '[هامس] سمعت؟\nفي حد ورا الباب…\nلا تتحرك.', start: 16.5, end: 19.2 },
];

// The studio how-to, then the end card.
export const STUDIO = { start: 19.2, end: 28.7, steps: ['اختار اللهجة', 'اكتب جملتك', 'زوّد إحساس', 'وصوت ضحكة', 'ودوس توليد'] };
export const END = { start: 28.7, end: 31.7, title: 'جرّب لهجتك', question: 'إنت لهجتك إيه؟ اكتبها في الكومنتات' };
export const DURATION = END.end;
