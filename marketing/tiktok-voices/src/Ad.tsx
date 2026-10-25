import React from 'react';
import { AbsoluteFill, Sequence, interpolate, useCurrentFrame } from 'remotion';
import { Background } from './Background';
import { EndCard } from './EndCard';
import { Stage } from './Stage';
import { Studio } from './Studio';
import { END, STUDIO, VOICES } from './script';
import { FPS } from './theme';
import { VoiceTrack } from './VoiceTrack';

// The whole ad: the light stage, the voices, the studio how-to, the end card.
export const Ad: React.FC = () => {
  const frame = useCurrentFrame();
  const f = (s: number) => Math.round(s * FPS);
  const stageEnd = VOICES[VOICES.length - 1].end;
  return (
    <AbsoluteFill>
      <Background />
      <Sequence durationInFrames={f(stageEnd)} name="Hook and voices">
        <Stage />
      </Sequence>
      <Sequence from={f(STUDIO.start)} durationInFrames={f(STUDIO.end - STUDIO.start)} name="Studio how-to">
        <Studio duration={STUDIO.end - STUDIO.start} steps={STUDIO.steps} />
      </Sequence>
      <Sequence from={f(END.start)} name="End card">
        <EndCard title={END.title} question={END.question} />
      </Sequence>
      <VoiceTrack />
      {/* a soft fade from white at the very start, so the first frame is already composed */}
      <AbsoluteFill style={{ backgroundColor: '#fff', opacity: interpolate(frame, [0, 6], [0.6, 0], { extrapolateRight: 'clamp' }), pointerEvents: 'none' }} />
    </AbsoluteFill>
  );
};
