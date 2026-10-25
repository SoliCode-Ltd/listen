import React from 'react';
import { AbsoluteFill, staticFile, useCurrentFrame } from 'remotion';
import { C, FPS } from './theme';

// The light stage: the site's near-white, two very soft pools of brand light drifting slowly
// (violet and the warm peach of the Listen orb), and a fine film grain so it reads as a surface.
export const Background: React.FC<{ glow?: string }> = ({ glow = '#C9BEFF' }) => {
  const t = useCurrentFrame() / FPS;
  return (
    <AbsoluteFill style={{ backgroundColor: C.bg }}>
      <AbsoluteFill
        style={{
          background: `radial-gradient(900px 900px at ${50 + 8 * Math.sin(t * 0.35)}% ${34 + 4 * Math.cos(t * 0.3)}%, ${glow}55, transparent 70%),
            radial-gradient(800px 800px at ${78 + 6 * Math.cos(t * 0.27)}% ${82 + 5 * Math.sin(t * 0.33)}%, #FFE3D366, transparent 70%),
            radial-gradient(700px 700px at ${14 + 6 * Math.sin(t * 0.22)}% ${70 + 6 * Math.cos(t * 0.25)}%, #DCD4FF55, transparent 70%)`,
        }}
      />
      <AbsoluteFill
        style={{
          backgroundImage: `url(${staticFile('brand/grain.png')})`,
          backgroundSize: '512px 512px',
          backgroundPosition: `${Math.floor(t * 12) * 37 % 512}px ${Math.floor(t * 12) * 91 % 512}px`,
          opacity: 0.07,
          mixBlendMode: 'multiply',
        }}
      />
    </AbsoluteFill>
  );
};
