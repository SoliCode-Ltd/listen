import { loadFont } from '@remotion/fonts';
import { staticFile } from 'remotion';

// Alexandria, the site's font: its Arabic file, and its Latin file for the web address.
export const fontsReady = Promise.all([
  loadFont({ family: 'AlexArabic', url: staticFile('fonts/alexandria-arabic.woff2'), weight: '300 800', display: 'block' }),
  loadFont({ family: 'AlexLatin', url: staticFile('fonts/alexandria-latin.woff2'), weight: '300 800', display: 'block' }),
]);
