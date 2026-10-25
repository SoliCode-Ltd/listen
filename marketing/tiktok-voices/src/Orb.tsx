import React, { useLayoutEffect, useRef } from 'react';

// The site's voice orb (public/js/listen-orbs.js), same shader, drawn for one moment: the time,
// the voice's loudness (rings ripple out with it), the palette and a seed. A pure function of
// its props, so every frame of the render agrees.
const VERT = 'attribute vec2 p; void main(){ gl_Position = vec4(p, 0.0, 1.0); }';
const FRAG = `precision highp float;
uniform vec2 uRes; uniform float uSize; uniform float uTime; uniform float uLevel; uniform float uSeed; uniform float uLift;
uniform vec3 uC0; uniform vec3 uC1; uniform vec3 uC2; uniform vec3 uC3;
float hash(vec3 p){ p = fract(p * 0.1031); p += dot(p, p.zyx + 31.32); return fract((p.x + p.y) * p.z); }
float noise(vec3 x){
  vec3 i = floor(x); vec3 f = fract(x); f = f * f * (3.0 - 2.0 * f);
  return mix(mix(mix(hash(i), hash(i + vec3(1,0,0)), f.x), mix(hash(i + vec3(0,1,0)), hash(i + vec3(1,1,0)), f.x), f.y),
             mix(mix(hash(i + vec3(0,0,1)), hash(i + vec3(1,0,1)), f.x), mix(hash(i + vec3(0,1,1)), hash(i + vec3(1,1,1)), f.x), f.y), f.z);
}
float fbm(vec3 p){ float a = 0.55; float s = 0.0; for (int i = 0; i < 3; i++){ s += a * noise(p); p = p * 2.07 + vec3(1.7, 9.2, 3.1); a *= 0.45; } return s; }
void main(){
  vec2 uv = (gl_FragCoord.xy / uSize) * 2.0 - 1.0;
  float r = length(uv);
  if (r > 1.0) { gl_FragColor = vec4(0.0); return; }
  vec3 n = vec3(uv, sqrt(max(0.0, 1.0 - r * r)));
  float a = uTime * 0.16 + uSeed;
  vec3 q = vec3(n.x * cos(a) - n.z * sin(a), n.y, n.x * sin(a) + n.z * cos(a));
  vec3 s = q * 0.7 + uSeed;
  vec3 w = vec3(fbm(s + vec3(0.0, 0.0, uTime * 0.11)), fbm(s + vec3(5.2, 1.3, 2.8 + uTime * 0.05)), fbm(s + vec3(1.7, 9.2, uTime * 0.08)));
  float v = fbm(s * 0.8 + 1.7 * w);
  float ring = sin(r * 17.0 + (w.x - 0.5) * 7.0 - uTime * 5.0);
  v += ring * 0.1 * uLevel * smoothstep(0.08, 0.4, r) * (1.0 - r * 0.6);
  v = clamp((v - 0.26) / 0.42, 0.0, 1.0);
  vec3 col = mix(uC0, uC1, smoothstep(0.0, 0.38, v));
  col = mix(col, uC2, smoothstep(0.35, 0.8, v));
  col = mix(col, uC3, smoothstep(0.75, 1.0, v) * 0.85);
  vec3 L = normalize(vec3(-0.45, 0.55, 0.8));
  float diff = dot(n, L) * 0.5 + 0.5;
  col *= 0.8 + 0.32 * diff;
  float glow = pow(max(dot(n, L), 0.0), 6.0);
  col = mix(col, uC3, glow * 0.22);
  float rim = pow(1.0 - n.z, 2.6);
  col = mix(col, mix(uC2, uC3, 0.6), rim * 0.55);
  col += uLevel * 0.05 * uC3;
  col = mix(col, mix(uC2, uC3, 0.7), uLift);
  float g = hash(vec3(floor(gl_FragCoord.xy), floor(uTime * 10.0)));
  col += (g - 0.5) * 0.05;
  float edge = 1.0 - smoothstep(1.0 - 1.6 / uSize, 1.0, r);
  gl_FragColor = vec4(clamp(col, 0.0, 1.0) * edge, edge);
}`;

const rgb = (hex: string): [number, number, number] => {
  const n = parseInt(hex.replace('#', ''), 16);
  return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
};

// Two palettes mixed (t: 0..1), channel by channel: the orb changes colour between voices.
export const mixPalette = (a: string[], b: string[], t: number): string[] =>
  a.map((ha, i) => {
    const [r1, g1, b1] = rgb(ha), [r2, g2, b2] = rgb(b[i]);
    const c = (x: number, y: number) => Math.round((x + (y - x) * t) * 255).toString(16).padStart(2, '0');
    return `#${c(r1, r2)}${c(g1, g2)}${c(b1, b2)}`;
  });

type GLState = { gl: WebGLRenderingContext; loc: Record<string, WebGLUniformLocation | null> };

export const Orb: React.FC<{
  size: number; palette: string[]; time: number; level?: number; seed?: number; lift?: number; style?: React.CSSProperties;
}> = ({ size, palette, time, level = 0, seed = 1.3, lift = 0, style }) => {
  const ref = useRef<HTMLCanvasElement>(null);
  const st = useRef<GLState | null>(null);
  useLayoutEffect(() => {
    const canvas = ref.current;
    if (!canvas) return;
    if (!st.current) {
      const gl = canvas.getContext('webgl', { premultipliedAlpha: true, preserveDrawingBuffer: true, antialias: false, alpha: true });
      if (!gl) return;
      const sh = (type: number, src: string) => { const s = gl.createShader(type)!; gl.shaderSource(s, src); gl.compileShader(s); return s; };
      const prog = gl.createProgram()!;
      gl.attachShader(prog, sh(gl.VERTEX_SHADER, VERT));
      gl.attachShader(prog, sh(gl.FRAGMENT_SHADER, FRAG));
      gl.linkProgram(prog);
      gl.useProgram(prog);
      const buf = gl.createBuffer();
      gl.bindBuffer(gl.ARRAY_BUFFER, buf);
      gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
      const pos = gl.getAttribLocation(prog, 'p');
      gl.enableVertexAttribArray(pos);
      gl.vertexAttribPointer(pos, 2, gl.FLOAT, false, 0, 0);
      const loc: GLState['loc'] = {};
      ['uRes', 'uSize', 'uTime', 'uLevel', 'uSeed', 'uLift', 'uC0', 'uC1', 'uC2', 'uC3'].forEach((k) => { loc[k] = gl.getUniformLocation(prog, k); });
      st.current = { gl, loc };
    }
    const { gl, loc } = st.current;
    const px = canvas.width;
    gl.viewport(0, 0, px, px);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.uniform2f(loc.uRes, px, px);
    gl.uniform1f(loc.uSize, px);
    gl.uniform1f(loc.uTime, time);
    gl.uniform1f(loc.uLevel, level);
    gl.uniform1f(loc.uSeed, seed);
    gl.uniform1f(loc.uLift, lift);
    const p = palette.map(rgb);
    gl.uniform3fv(loc.uC0, p[0]); gl.uniform3fv(loc.uC1, p[1]); gl.uniform3fv(loc.uC2, p[2]); gl.uniform3fv(loc.uC3, p[3]);
    gl.drawArrays(gl.TRIANGLES, 0, 3);
  });
  return <canvas ref={ref} width={Math.round(size)} height={Math.round(size)} style={{ width: size, height: size, ...style }} />;
};
