// The ad's script: six speakers from the voice library, each in their own dialect, each with a
// different feeling, each doing something people make with إسمع. Times are in seconds; the
// voice clips are made by `php artisan tts:showcase --set=ad --ad=tiktok-voices --generate`
// (marketing/tiktok-voices/lines.php) and the times below come from them (build step).

export type Voice = {
  slug: string;          // the clip
  name: string;          // the speaker's name in Arabic
  avatar: string;        // public/speakers/<avatar>.webp
  flag: string;          // public/flags/<flag>.svg
  dialect: string;       // as the site labels it
  emotion: string;       // the feeling, in the studio's words
  use: string;           // what it's for
  palette: string;       // the orb's colours (theme.PALETTES)
  text: string;          // what is said (live sound tags removed); \n where the line breaks on screen
  start: number;         // when the voice starts in the ad (s)
  end: number;
  words?: number[];      // when each word starts (s, from the start of the clip)
  size?: number;         // the line's text size, when it needs more rows than two (default 74)
};

export const HOOK = { title: 'اكتب أي جملة.', sub: 'واسمعها بأي لهجة…\nوبأي إحساس.', end: 3.0 };

export const VOICES: Voice[] = [
  { slug: 'sumaya-excited', name: 'سمية', avatar: 'ar-sumaya', flag: 'eg', dialect: 'مصرية (قاهرية)', emotion: 'متحمسة', use: 'إعلان سوشيال', palette: 'emotions',
    text: 'يا جماعة الحقوا!\nالعرض ده مش هيتكرر\nتاني… بجد!', size: 68, start: 3.0, end: 5.7 },
  { slug: 'abdulaziz-proud', name: 'عبدالعزيز', avatar: 'ar-abdulaziz', flag: 'sa', dialect: 'سعودي (قصيمي)', emotion: 'فخور', use: 'فيلم براند', palette: 'pro',
    text: 'من قلب نجد… للعالم كله.\nهذي قصتنا.', start: 5.7, end: 8.4 },
  { slug: 'sawsan-warm', name: 'سوسن', avatar: 'ar-sawsan', flag: 'lb', dialect: 'لبنانية', emotion: 'حنونة', use: 'قصة', palette: 'longform',
    text: 'كان في مرّة… بنت زغيرة،\nبتحلم تغنّي عالمسرح.', start: 8.4, end: 11.1 },
  { slug: 'abdelilah-laugh', name: 'عبد الإله', avatar: 'ar-abdelilah', flag: 'ma', dialect: 'مغربي (مراكشي)', emotion: 'بيضحك', use: 'كوميديا', palette: 'arabic',
    text: 'واش بصّح؟\nلا لا، هادشي بزاف عليّا!', start: 11.1, end: 13.8 },
  { slug: 'sundus-sad', name: 'سندس', avatar: 'ar-sundus', flag: 'iq', dialect: 'عراقية (بغدادية)', emotion: 'حزينة', use: 'دراما', palette: 'dialogue',
    text: 'ما توقعت يوم…\nتروح وتخليني وحدي.', start: 13.8, end: 16.5 },
  { slug: 'hamed-whisper', name: 'حامد', avatar: 'ar-hamed', flag: 'ae', dialect: 'إماراتي', emotion: 'يوشوش', use: 'إثارة', palette: 'places',
    text: 'سمعت؟ في حد ورا الباب…\nلا تتحرك.', start: 16.5, end: 19.2 },
];

// The studio how-to, then the end card.
export const STUDIO = { start: 19.2, end: 28.7, steps: ['اختار اللهجة', 'اكتب جملتك', 'زوّد إحساس', 'وصوت ضحكة', 'ودوس توليد'] };
export const END = { start: 28.7, end: 31.7, title: 'جرّب لهجتك', question: 'إنت لهجتك إيه؟ اكتبها في الكومنتات' };
export const DURATION = END.end;
