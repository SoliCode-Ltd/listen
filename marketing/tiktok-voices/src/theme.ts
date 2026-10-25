// The site's light theme: near-white surfaces, deep ink, one violet accent.
export const C = {
  bg: '#F7F6FB',
  ink: '#0F0E17',
  muted: '#6B6880',
  faint: '#B9B5CA',
  line: '#E6E3F0',
  card: '#FFFFFF',
  accent: '#7659FA',
  accentSoft: '#EFEBFF',
};

export const FONT = 'AlexArabic, AlexLatin, sans-serif';

// The orb palettes from app/Support/Orbs.php, deepest shade first.
export const PALETTES: Record<string, [string, string, string, string]> = {
  listen: ['#24138A', '#7659FA', '#C9BEFF', '#FFE3D3'],
  arabic: ['#5A1F66', '#C2507F', '#F2B544', '#FFEBC9'],
  emotions: ['#7A1450', '#E23C74', '#FF7F66', '#FFC9B6'],
  dialogue: ['#15606B', '#2DB7A6', '#A294FF', '#EEF0FF'],
  places: ['#14295E', '#2672B4', '#46C6CC', '#F6D38A'],
  longform: ['#3B0F4D', '#8E2DE2', '#FF8A3D', '#FFE7B8'],
  starter: ['#124456', '#46B0A2', '#BDEBDA', '#F5FFFA'],
  creator: ['#3A1C8C', '#8B5CF6', '#F0ABFC', '#FFF1FB'],
  pro: ['#46163C', '#C04E7C', '#F2B544', '#FFF1D6'],
  studio_max: ['#17144A', '#3A31B4', '#7E62FF', '#F2C46E'],
};

export const FPS = 60;
export const W = 1080;
export const H = 1920;
