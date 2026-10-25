import React from 'react';
import { Audio } from '@remotion/media';
import { getStaticFiles, staticFile } from 'remotion';

// A film's mixed soundtrack (voices, music, effects), once it has been built (mix.py).
export const VoiceTrack: React.FC<{ src: string }> = ({ src }) => {
  const has = getStaticFiles().some((f) => f.name === src);
  return has ? <Audio src={staticFile(src)} /> : null;
};
