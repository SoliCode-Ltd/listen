import React from 'react';
import { Audio } from '@remotion/media';
import { getStaticFiles, staticFile } from 'remotion';

// The mixed soundtrack (voices, music, effects), once it has been built (public/audio/mix.wav).
export const VoiceTrack: React.FC = () => {
  const has = getStaticFiles().some((f) => f.name === 'audio/mix.wav');
  return has ? <Audio src={staticFile('audio/mix.wav')} /> : null;
};
