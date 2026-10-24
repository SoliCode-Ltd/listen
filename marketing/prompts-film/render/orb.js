// The Listen orb, drawn by the site's own shader (orb-shader.js, taken from
// public/js/listen-orbs.js at build time) as a pure function of time.
//   ORB.draw(ctx, x, y, diameter, { time, level, lift, colors, grain })
window.ORB = (function () {
  const MAX = 1024;
  const VERT = 'attribute vec2 p; void main(){ gl_Position = vec4(p, 0.0, 1.0); }';
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = MAX;
  const gl = canvas.getContext('webgl', { premultipliedAlpha: true, preserveDrawingBuffer: true, antialias: false, alpha: true });
  const shader = (type, src) => {
    const s = gl.createShader(type);
    gl.shaderSource(s, src);
    gl.compileShader(s);
    if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(s));
    return s;
  };
  const prog = gl.createProgram();
  gl.attachShader(prog, shader(gl.VERTEX_SHADER, VERT));
  gl.attachShader(prog, shader(gl.FRAGMENT_SHADER, window.ORB_FRAG));
  gl.linkProgram(prog);
  gl.useProgram(prog);
  const buf = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, buf);
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
  const pos = gl.getAttribLocation(prog, 'p');
  gl.enableVertexAttribArray(pos);
  gl.vertexAttribPointer(pos, 2, gl.FLOAT, false, 0, 0);
  const loc = {};
  ['uRes', 'uSize', 'uTime', 'uLevel', 'uSeed', 'uLift', 'uGrain', 'uC0', 'uC1', 'uC2', 'uC3'].forEach(k => { loc[k] = gl.getUniformLocation(prog, k); });
  gl.clearColor(0, 0, 0, 0);

  const rgb = hex => { const n = parseInt(hex.replace('#', ''), 16); return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255]; };
  // The site's 'listen' palette (App\Support\Orbs): brand violet, lilac and the site's warm light.
  const LISTEN = ['#24138A', '#7659FA', '#C9BEFF', '#FFE3D3'].map(rgb);

  // Renders the orb at up to MAX px and returns the canvas and the size drawn.
  function render(d, o) {
    const s = Math.max(8, Math.min(MAX, Math.round(d)));
    const c = o.colors || LISTEN;
    gl.viewport(0, 0, s, s);
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.uniform2f(loc.uRes, MAX, MAX);
    gl.uniform1f(loc.uSize, s);
    gl.uniform1f(loc.uTime, o.time || 0);
    gl.uniform1f(loc.uLevel, o.level || 0);
    gl.uniform1f(loc.uSeed, o.seed == null ? 1.3 : o.seed);
    gl.uniform1f(loc.uLift, o.lift || 0);
    gl.uniform1f(loc.uGrain, o.grain == null ? (d <= MAX ? 1 : 0) : o.grain);
    gl.uniform3fv(loc.uC0, c[0]); gl.uniform3fv(loc.uC1, c[1]); gl.uniform3fv(loc.uC2, c[2]); gl.uniform3fv(loc.uC3, c[3]);
    gl.drawArrays(gl.TRIANGLES, 0, 3);
    return s;
  }

  function draw(ctx, x, y, d, o = {}) {
    if (d < 1) return;
    const s = render(d, o);
    // GL draws from the bottom left: the orb is the bottom-left square of the canvas.
    ctx.drawImage(canvas, 0, MAX - s, s, s, x - d / 2, y - d / 2, d, d);
  }

  return { draw, render, canvas, MAX, LISTEN };
})();
