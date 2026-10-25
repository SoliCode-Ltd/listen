import React from 'react';
import { Composition, delayRender, continueRender } from 'remotion';
import { Ad } from './Ad';
import { fontsReady } from './fonts';
import { DURATION } from './script';
import { FPS, H, W } from './theme';

const wait = delayRender('fonts');
fontsReady.then(() => continueRender(wait));

export const RemotionRoot: React.FC = () => (
  <Composition id="Ad" component={Ad} width={W} height={H} fps={FPS} durationInFrames={Math.round(DURATION * FPS)} />
);
