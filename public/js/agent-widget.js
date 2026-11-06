/*
 * Listen: the call button and its call card on a business's website, loaded by
 * listen-agent.js with the agent's settings. Everything sits in a shadow root,
 * so the website's styles never reach it and it never reaches the website's.
 * The call itself is ListenAgentCall (agent-call.js), loaded on first use.
 *
 * The owner styles it (Design tab): what the call shows (an orb that moves
 * with the voice, the brand's waves, or a slim bar), the button's icon, text,
 * shape and size, its colours, the card's title, corners and font, the corner
 * it sits in and light or dark. ListenAgentWidget.preview() draws the same
 * button inside the Design tab, with a pretend call, so the owner sees it move.
 */
(function () {
  'use strict';
  if (window.ListenAgentWidget) return;

  var EASE = 'cubic-bezier(.22,1,.36,1)';
  var CSS = [
    ':host{all:initial}',
    '.lva{position:fixed;bottom:20px;z-index:2147483000;font-family:var(--font);font-size:15px;line-height:1.45;color:var(--text);-webkit-font-smoothing:antialiased;',
    '  --font:system-ui,-apple-system,"Segoe UI",Tahoma,"Noto Sans Arabic","Geeza Pro",Arial,sans-serif;',
    '  --bg:#ffffff;--text:#16132F;--muted:#615C80;--line:rgba(23,20,74,.12);--soft:rgba(23,20,74,.05);--shadow:0 26px 60px -20px rgba(23,20,74,.42),0 2px 8px rgba(23,20,74,.08);',
    '  --r-card:26px;--r-btn:999px;--h:52px;--ico:38px;--fs:15px;--room:calc(100vh - var(--h) - 60px)}',
    '@supports (height:100dvh){.lva{--room:calc(100dvh - var(--h) - 60px)}}',
    '.lva[data-theme="dark"]{--bg:#17142E;--text:#F3F1FF;--muted:#B9B2DD;--line:rgba(255,255,255,.12);--soft:rgba(255,255,255,.06);--shadow:0 26px 60px -20px rgba(0,0,0,.75),0 2px 8px rgba(0,0,0,.35)}',
    '.lva[data-font="rounded"]{--font:ui-rounded,"SF Pro Rounded","Nunito","Varela Round","Tajawal","Noto Sans Arabic",system-ui,sans-serif}',
    '.lva[data-font="site"]{--font:var(--site-font)}',
    '.lva[data-radius="square"]{--r-card:10px;--r-btn:10px}.lva[data-radius="soft"]{--r-card:18px;--r-btn:14px}',
    '.lva[data-size="s"]{--h:44px;--ico:32px;--fs:14px}.lva[data-size="l"]{--h:60px;--ico:44px;--fs:16.5px}',
    '.lva.is-preview{position:absolute}',
    '.lva *,.lva *::before,.lva *::after{box-sizing:border-box}',
    '.lva [hidden]{display:none !important}',
    '.lva button{font:inherit;color:inherit;cursor:pointer;-webkit-tap-highlight-color:transparent}',
    '.lva button:focus-visible,.lva a:focus-visible{outline:3px solid color-mix(in srgb,var(--a) 45%,transparent);outline-offset:2px}',
    '.lva.is-right{right:20px}.lva.is-left{left:20px}',
    '.lva svg{display:block}',

    /* The button: the accent circle with its icon, then the words. */
    '.lva-dock{position:relative;display:flex;justify-content:flex-end}.lva.is-left .lva-dock{justify-content:flex-start}',
    '.lva-launch{display:flex;align-items:center;gap:10px;height:var(--h);padding:0 18px 0 calc((var(--h) - var(--ico)) / 2);border:1px solid var(--line);border-radius:var(--r-btn);background:var(--bg);box-shadow:var(--shadow);font-weight:700;font-size:var(--fs);white-space:nowrap;',
    '  transition:transform .3s ' + EASE + ',box-shadow .3s ease,opacity .25s ease;animation:lva-enter .55s ' + EASE + ' both}',
    '.lva[dir="rtl"] .lva-launch{padding:0 calc((var(--h) - var(--ico)) / 2) 0 18px}',
    '.lva-launch.is-round,.lva[dir="rtl"] .lva-launch.is-round{width:var(--h);padding:0;justify-content:center;background:var(--a);border-color:transparent}',
    '.lva-launch.is-round .lva-ico{width:100%;height:100%}',
    '.lva-launch.is-round .lva-ico svg{width:40%;height:40%}',
    '.lva-launch:hover{transform:translateY(-2px);box-shadow:0 30px 64px -20px rgba(23,20,74,.5),0 2px 8px rgba(23,20,74,.1)}',
    '.lva-launch:active{transform:scale(.97)}',
    '.lva-ico{position:relative;display:grid;place-items:center;width:var(--ico);height:var(--ico);border-radius:var(--r-btn);background:var(--a);color:var(--a-ink);flex:0 0 auto;transition:background .3s ease}',
    '.lva[data-radius="round"] .lva-ico{border-radius:50%}',
    '.lva-ico svg{width:46%;height:46%;fill:none;stroke:currentColor;stroke-width:2.1;stroke-linecap:round;stroke-linejoin:round}',
    '.lva-ico svg.is-fill{fill:currentColor;stroke:none}',
    '.lva-ico.is-orb{background:transparent;overflow:hidden}',
    /* A soft ring once the button arrives, so it is noticed, then never again. */
    '.lva-ico::after{content:"";position:absolute;inset:0;border-radius:inherit;box-shadow:0 0 0 0 color-mix(in srgb,var(--a) 55%,transparent);animation:lva-hello 1.6s ease-out .8s 2}',
    /* On a call (card closed): moving bars instead of the icon, and the time instead of the words. */
    '.lva-eq{display:none;align-items:center;gap:3px;height:42%}',
    '.lva-eq i{width:3px;height:40%;border-radius:2px;background:currentColor;animation:lva-eq .9s ease-in-out infinite}',
    '.lva-eq i:nth-child(2){animation-delay:-.3s}.lva-eq i:nth-child(3){animation-delay:-.6s}',
    '.lva.is-live .lva-launch .lva-eq{display:flex}.lva.is-live .lva-launch .lva-ico > svg,.lva.is-live .lva-launch .lva-ico > .lva-mini{display:none}',
    '.lva.is-live .lva-ico{background:#E2445F;color:#fff}',
    '.lva.is-speaking .lva-eq i{animation-duration:.55s}',
    '.lva-time{font-variant-numeric:tabular-nums;font-weight:800}',

    /* The card. */
    '.lva-panel{position:absolute;bottom:calc(100% + 14px);width:344px;max-width:calc(100vw - 24px);max-height:var(--room);padding:18px 18px 14px;border-radius:var(--r-card);background:var(--bg);border:1px solid var(--line);box-shadow:var(--shadow);display:flex;flex-direction:column;gap:12px;',
    '  transform-origin:bottom var(--side);opacity:0;transform:translateY(12px) scale(.94);pointer-events:none;visibility:hidden;transition:opacity .24s ease,transform .42s ' + EASE + ',visibility 0s linear .42s}',
    '.lva.is-right .lva-panel{right:0}.lva.is-left .lva-panel{left:0}',
    '.lva.is-open .lva-panel{opacity:1;transform:none;pointer-events:auto;visibility:visible;transition:opacity .24s ease,transform .42s ' + EASE + ',visibility 0s}',
    '.lva-head{display:flex;align-items:center;gap:10px;flex:none}',
    /* The agent's photo, small, next to its name. */
    '.lva-face{width:42px;height:42px;border-radius:50%;flex:0 0 auto;background:var(--soft);box-shadow:0 0 0 2px var(--bg),0 0 0 3.5px var(--c2)}',
    '.lva-face img{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block}',
    '.lva-who{flex:1;min-width:0;display:grid;gap:1px}',
    '.lva-who strong{font-size:16px;font-weight:800;line-height:1.3;overflow-wrap:anywhere}',
    '.lva-who small{color:var(--muted);font-size:12.5px}',
    '.lva-pill{display:none;align-items:center;gap:6px;height:26px;padding:0 10px;border-radius:999px;background:var(--soft);font-size:12px;font-weight:700;font-variant-numeric:tabular-nums}',
    '.lva-pill i{width:7px;height:7px;border-radius:50%;background:#E2445F;animation:lva-blink 1.4s ease-in-out infinite}',
    '.lva.is-live .lva-pill{display:inline-flex}',
    '.lva-x{width:34px;height:34px;margin:-4px;border-radius:50%;border:0;background:transparent;display:grid;place-items:center;color:var(--muted);flex:0 0 auto}',
    '.lva-x:hover{background:var(--soft);color:var(--text)}',
    '.lva-x svg,.lva-call svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',

    /* The orb: one, in the middle, moving with the voice. */
    '.lva-stage{display:grid;place-items:center;flex:0 3 164px;min-height:118px}',
    '.lva-orbwrap{position:relative;width:112px;height:112px}',
    '.lva-orb{position:absolute;inset:0;border-radius:50%;overflow:hidden;isolation:isolate;',
    '  background:radial-gradient(120% 120% at 32% 24%,var(--c3) 0%,var(--c2) 46%,var(--c1) 100%);',
    '  box-shadow:inset 0 -12px 26px rgba(0,0,0,.28),inset 0 10px 20px rgba(255,255,255,.22),0 20px 44px -18px var(--c2);',
    '  transform:scale(var(--s,1));transition:transform .12s linear}',
    '.lva-orb::before{content:"";position:absolute;inset:-35%;background:conic-gradient(from 0deg,transparent 0 12%,var(--c3) 26%,transparent 42%,var(--c4) 62%,transparent 80%);filter:blur(14px);opacity:.55;mix-blend-mode:screen;animation:lva-spin 11s linear infinite}',
    '.lva-orb::after{content:"";position:absolute;top:9%;left:19%;width:46%;height:30%;border-radius:50%;background:radial-gradient(ellipse at 50% 40%,rgba(255,255,255,.78),transparent 72%)}',
    '.lva.is-speaking .lva-orb::before{animation-duration:3.4s;opacity:.85}',
    '.lva.is-connecting .lva-orb{animation:lva-breathe 1.4s ease-in-out infinite}',
    '.lva-rings i{position:absolute;inset:0;border-radius:50%;border:2px solid var(--c3);opacity:0}',
    // The orb moves with the voice inside the ball only, as every orb on the site does: no rings spread round it.
    '.lva.is-speaking .lva-rings i{animation:none}',
    '.lva-spin{position:absolute;inset:-10px;border-radius:50%;border:2px solid transparent;border-top-color:var(--c2);opacity:0;transition:opacity .3s ease}',
    '.lva.is-connecting .lva-spin{opacity:1;animation:lva-spin .9s linear infinite}',
    '.lva-mini{position:absolute;inset:0;border-radius:50%;overflow:hidden;background:radial-gradient(120% 120% at 32% 24%,var(--c3) 0%,var(--c2) 46%,var(--c1) 100%);box-shadow:inset 0 -5px 10px rgba(0,0,0,.25)}',
    '.lva-mini::after{content:"";position:absolute;top:10%;left:20%;width:44%;height:30%;border-radius:50%;background:radial-gradient(ellipse at 50% 40%,rgba(255,255,255,.75),transparent 72%)}',

    /* The waves: the brand mark, its bars moving with the voice. */
    '.lva-wave{display:flex;align-items:center;justify-content:center;gap:7px;height:96px}',
    '.lva-wave i{display:block;width:7px;height:var(--rest,12px);border-radius:7px;background:linear-gradient(180deg,var(--c3),var(--c2));transition:height .08s linear}',
    '.lva:not(.is-live) .lva-wave i{animation:lva-idle 2.6s ease-in-out infinite}',
    '.lva-wave i:nth-child(2n){animation-delay:-.7s}.lva-wave i:nth-child(3n){animation-delay:-1.4s}',

    '.lva-state{margin:0;text-align:center;font-weight:700;font-size:15px;min-height:22px;transition:opacity .2s ease}',
    '.lva-state.is-error{color:#D0335F}',
    /* What was said: the last lines, older ones fading at the top. */
    /* The chat grows smoothly as lines come (agent-widget.js smoothly) and scrolls gently to the newest. */
    '.lva-lines{display:flex;flex-direction:column;gap:10px;flex:0 1 auto;min-height:0;max-height:150px;overflow:auto;overscroll-behavior:contain;scrollbar-width:none;padding:14px 2px 2px;-webkit-mask-image:linear-gradient(to bottom,transparent 0,#000 22px);mask-image:linear-gradient(to bottom,transparent 0,#000 22px)}',
    '.lva-lines::-webkit-scrollbar{display:none}',
    '.lva-lines:empty{display:none}',
    '.lva-lines>:first-child{margin-top:auto}',
    '.lva-line{padding:10px 14px;border-radius:18px;background:var(--soft);font-size:14px;line-height:1.65;max-width:86%;align-self:flex-start;overflow-wrap:anywhere;animation:lva-in .42s ' + EASE + ' both}',
    '.lva[data-radius="square"] .lva-line{border-radius:8px}',
    '.lva-line.is-caller{align-self:flex-end;background:transparent;border:1px solid var(--line)}',
    /* A change of speaker gets more room than two lines of the same one. */
    '.lva-line.is-agent+.lva-line.is-caller,.lva-line.is-caller+.lva-line.is-agent,.lva-line.is-caller+.lva-line.is-links,.lva-line.is-links+.lva-line.is-caller{margin-top:8px}',
    /* While the agent speaks, three dots in its bubble, which then holds its words. */
    '.lva-line.is-typing{display:inline-flex;align-items:center;gap:5px;min-height:42px;padding:0 16px}',
    '.lva-line.is-typing i{width:6px;height:6px;border-radius:50%;background:var(--text);opacity:.28;animation:lva-dot 1.2s ease-in-out infinite}',
    '.lva-line.is-typing i:nth-child(2){animation-delay:.16s}.lva-line.is-typing i:nth-child(3){animation-delay:.32s}',
    /* The agent's words as it says them, each fading in as it comes (onWords). */
    '.lva-w{animation:lva-word .34s ease both}',
    /* A line written again (the caller's from their voice, the agent's in Arabic letters) settles in softly. */
    '.lva-line.is-fixed{animation:lva-fix .5s ease}',
    /* The business's own links, written in the chat when the caller asks for them (show_links). */
    '.lva-line.is-links{display:grid;gap:4px;padding:6px;min-width:62%}',
    /* Room for every link at once, with the line that follows them. */
    '.lva-lines.has-links{max-height:min(290px,46vh)}',
    '.lva-link{display:flex;align-items:center;gap:8px;min-height:36px;padding:6px 10px;border-radius:10px;background:var(--bg);border:1px solid var(--line);color:var(--text) !important;text-decoration:none !important;font-size:13px;font-weight:700;direction:ltr;text-align:left;overflow:hidden;transition:border-color .18s ease,background .18s ease}',
    '.lva-link:hover{border-color:var(--a)}',
    '.lva-link:focus-visible{outline:2px solid var(--a);outline-offset:2px}',
    '.lva-link span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.lva-link svg{flex:none;width:15px;height:15px;fill:none;stroke:var(--a);stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',
    '.lva-barlinks{display:flex;align-items:flex-start;gap:6px;padding:6px;width:min(300px,calc(100vw - 40px))}',
    '.lva-barlinks>div{flex:1;min-width:0}.lva-barlinks .lva-line{max-width:none;min-width:0;background:none;padding:0;animation:none}',
    '.lva-barlinks .lva-x{flex:none;width:30px;height:30px}',
    '.lva-buttons{display:flex;gap:8px;justify-content:center;align-items:center;flex:none}',
    '.lva-call{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 24px;border:0;border-radius:var(--r-btn);background:var(--a);color:var(--a-ink) !important;font-weight:800;font-size:15.5px;box-shadow:0 0 0 1px var(--line),0 12px 26px -12px var(--a);transition:background .25s ease,transform .18s ease,box-shadow .25s ease}',
    '.lva-call:hover{background:var(--a-2)}',
    '.lva-call:active{transform:scale(.97)}',
    '.lva-call.is-live{background:#E2445F;color:#fff !important;box-shadow:0 12px 26px -12px #E2445F}',
    '.lva-powered{align-self:center;display:inline-block;padding:4px 10px;color:var(--muted);font-size:11.5px;font-weight:700;text-decoration:none;border-radius:8px}',
    '.lva-powered:hover{color:var(--text)}',

    /* Every line busy: the orb breathes slowly while the caller waits, their place under it. */
    '.lva-callview{display:contents}',
    /* Waiting: the orb a little smaller and breathing slowly, a ring round it filling over the minute
       the call button waits, the caller's place in a pill, then the way out. */
    '.lva-orbwrap{transition:transform .5s ' + EASE + '}',
    '.lva.is-waiting .lva-orbwrap{transform:scale(.84)}',
    '.lva.is-waiting .lva-orb{animation:lva-breathe 3s ease-in-out infinite}',
    '.lva.is-waiting .lva-wave i{animation:lva-idle 3s ease-in-out infinite}',
    '.lva-ring{position:absolute;inset:-11px;width:calc(100% + 22px);height:calc(100% + 22px);transform:rotate(-90deg);opacity:0;transition:opacity .35s ease;pointer-events:none}',
    '.lva-ring circle{fill:none;stroke-width:2.6}',
    '.lva-ring .t{stroke:var(--line)}',
    '.lva-ring .p{stroke:var(--a);stroke-linecap:round;stroke-dasharray:100;stroke-dashoffset:100}',
    '.lva.is-waiting .lva-ring{opacity:1}',
    '.lva.is-waiting .lva-ring .p{animation:lva-fill 60s linear forwards}',
    '.lva-pos{align-self:center;display:inline-flex;align-items:center;gap:7px;height:28px;padding:0 12px;margin:2px 0 -4px;border-radius:999px;background:color-mix(in srgb,var(--a) 13%,transparent);color:var(--text);font-size:12.5px;font-weight:800;animation:lva-in .35s ' + EASE + '}',
    '.lva-pos i{width:7px;height:7px;border-radius:50%;background:var(--a);animation:lva-blink 1.4s ease-in-out infinite}',
    '.lva-sub{margin:-6px 0 0;text-align:center;color:var(--muted);font-size:13.5px;line-height:1.5;text-wrap:balance}',
    '.lva-sub:empty{display:none}',
    '.lva.is-waiting .lva-buttons{flex-direction:column;align-items:stretch;gap:4px;margin-top:2px}',
    '.lva-leave{width:100%}',
    '.lva-call.is-quiet{background:transparent;color:var(--muted) !important;box-shadow:none;min-height:42px;font-weight:700;font-size:14.5px}',
    '.lva-call.is-quiet:hover{background:var(--soft);color:var(--text) !important}',
    /* Leave a number: in the card, or above the slim bar. */
    '.lva-slot{display:grid;gap:10px;animation:lva-in .35s ' + EASE + '}',
    '.lva-slot h3{margin:0;font-size:16.5px;font-weight:800;line-height:1.35;text-align:center;text-wrap:balance}',
    '.lva-slot p{margin:0;color:var(--muted);font-size:13.5px;line-height:1.5;text-align:center;text-wrap:balance}',
    '.lva-form{display:grid;gap:10px}',
    '.lva-field{display:grid;gap:5px;font-size:12.5px;font-weight:700;color:var(--muted)}',
    '.lva-field input{font:inherit;font-size:15.5px;font-weight:600;color:var(--text);width:100%;height:46px;padding:0 14px;border-radius:14px;border:1px solid var(--line);background:var(--bg);outline:none;transition:border-color .2s ease,box-shadow .2s ease}',
    '.lva[data-radius="square"] .lva-field input{border-radius:8px}',
    '.lva-field input:focus{border-color:var(--a);box-shadow:0 0 0 3px color-mix(in srgb,var(--a) 24%,transparent)}',
    '.lva-field input[aria-invalid="true"]{border-color:#D0335F}',
    '.lva-slot .lva-err{color:#D0335F;font-weight:700}',
    '.lva-slot .lva-call{width:100%;margin-top:2px}',
    '.lva-slot .lva-call:disabled{opacity:.7;cursor:progress}',
    '.lva-tick{justify-self:center;display:grid;place-items:center;width:56px;height:56px;border-radius:50%;background:color-mix(in srgb,var(--a) 14%,transparent);color:var(--a);animation:lva-pop .45s ' + EASE + ' both}',
    '.lva-tick svg{width:26px;height:26px;fill:none;stroke:currentColor;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}',
    '.lva-slot bdi{color:var(--text);font-weight:700}',
    '.lva-bartip .lva-slot{min-width:260px}',

    /* The slim bar: the button itself becomes the call, no card. */
    '.lva-bar{display:none;align-items:center;gap:10px;height:var(--h);padding:0 calc((var(--h) - var(--ico)) / 2);border:1px solid var(--line);border-radius:var(--r-btn);background:var(--bg);box-shadow:var(--shadow);animation:lva-pop .38s ' + EASE + ' both}',
    '.lva.is-barlive .lva-bar{display:flex}.lva.is-barlive .lva-launch{display:none}',
    '.lva-bar .lva-viz{position:relative;display:grid;place-items:center;width:var(--ico);height:var(--ico);flex:0 0 auto}',
    '.lva-bar .lva-viz .lva-orbwrap{width:100%;height:100%}',
    '.lva-bar .lva-viz .lva-orb{box-shadow:inset 0 -5px 10px rgba(0,0,0,.25)}',
    '.lva-bar .lva-viz .lva-orb::before{filter:blur(6px)}',
    '.lva-bar .lva-viz .lva-rings i{border-width:1.5px}',
    '.lva-bar .lva-viz .lva-spin{inset:-5px}',
    '.lva-bar .lva-wave{height:var(--ico);gap:3px}.lva-bar .lva-wave i{width:3.5px}',
    '.lva-barinfo{display:grid;min-width:92px;line-height:1.25}',
    '.lva-barinfo b{font-size:13.5px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}',
    '.lva-barinfo span{font-size:12px;color:var(--muted);font-variant-numeric:tabular-nums}',
    '.lva-bar .lva-end{width:var(--ico);height:var(--ico);min-height:0;padding:0;border-radius:50%}',
    '.lva-end{display:grid;place-items:center;border:0;background:#E2445F;color:#fff !important}',
    '.lva-end svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',
    '.lva-bartip{position:absolute;bottom:calc(100% + 10px);max-width:min(320px,calc(100vw - 40px));padding:9px 13px;border-radius:14px;background:var(--bg);border:1px solid var(--line);box-shadow:var(--shadow);font-size:13.5px;animation:lva-in .3s ' + EASE + '}',
    '.lva.is-right .lva-bartip{right:0}.lva.is-left .lva-bartip{left:0}',
    '.lva-bartip.is-error{color:#D0335F}',

    '@media (max-width:480px){.lva{bottom:14px}.lva.is-right{right:14px}.lva.is-left{left:14px}.lva:not(.is-preview):not(.is-inline) .lva-panel{position:fixed;left:10px !important;right:10px !important;bottom:calc(var(--h) + 26px);width:auto;max-width:none}}',
    '@keyframes lva-enter{from{opacity:0;transform:translateY(14px) scale(.96)}}',
    '@keyframes lva-pop{from{opacity:0;transform:scale(.92)}}',
    '@keyframes lva-hello{to{box-shadow:0 0 0 12px transparent}}',
    '@keyframes lva-spin{to{transform:rotate(360deg)}}',
    '@keyframes lva-breathe{0%,100%{transform:scale(.97)}50%{transform:scale(1.03)}}',
    '@keyframes lva-ring{0%{transform:scale(1);opacity:.55}100%{transform:scale(1.6);opacity:0}}',
    '@keyframes lva-eq{0%,100%{height:30%}50%{height:100%}}',
    '@keyframes lva-idle{0%,100%{height:var(--rest,12px)}50%{height:calc(var(--rest,12px) * 1.35)}}',
    '@keyframes lva-blink{0%,100%{opacity:1}50%{opacity:.35}}',
    '@keyframes lva-in{from{opacity:0;transform:translateY(8px) scale(.985)}}',
    '@keyframes lva-dot{0%,70%,100%{opacity:.22;transform:none}35%{opacity:.75;transform:translateY(-2px)}}',
    '@keyframes lva-fix{from{opacity:.4}}',
    '@keyframes lva-word{from{opacity:0}}',
    '@keyframes lva-fill{to{stroke-dashoffset:0}}',
    '@media (prefers-reduced-motion:reduce){.lva *,.lva *::before,.lva *::after{animation:none !important;transition:none !important}}',
    /* The link page: the same card, open in the page, with no button to open it and nothing to close. */
    '.lva.is-inline,.lva.is-inline.is-right,.lva.is-inline.is-left{position:relative;inset:auto;z-index:auto;display:flex;justify-content:center;width:100%}',
    '.lva.is-inline .lva-dock{display:none}',
    '.lva.is-inline .lva-panel{position:relative;inset:auto;width:min(400px,100%);max-width:none;max-height:none;opacity:1;transform:none;pointer-events:auto;visibility:visible;transition:none}',
    '.lva.is-inline .lva-head .lva-x{display:none}',
    '.lva.is-inline .lva-lines,.lva.is-inline .lva-lines.has-links{max-height:min(420px,46vh)}',
    // The link page of an agent that is not taking calls: the button stays, dimmed, under the reason.
    '.lva.is-inline .lva-call:disabled,.lva.is-inline .lva-call:disabled:hover{background:var(--a);opacity:.45;cursor:not-allowed;box-shadow:none;transform:none}',
  ].join('\n');

  var ICONS = {
    phone: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.1 3.5h3.1l1.6 4.1-2 1.3a11.3 11.3 0 0 0 5.3 5.3l1.3-2 4.1 1.6v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 3.1 5.7a2 2 0 0 1 2-2.2z"/></svg>',
    mic: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>',
    headset: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="13" width="4.5" height="7" rx="2"/><rect x="16.5" y="13" width="4.5" height="7" rx="2"/><path d="M20 20c0 1-1.5 1.8-4 1.8h-2"/></svg>',
    waves: '<svg viewBox="0 0 24 24" class="is-fill" aria-hidden="true"><rect x="2" y="10.5" width="2.4" height="3" rx="1.2"/><rect x="6.2" y="7.5" width="2.4" height="9" rx="1.2"/><rect x="10.8" y="3.5" width="2.4" height="17" rx="1.2"/><rect x="15.4" y="7.5" width="2.4" height="9" rx="1.2"/><rect x="19.6" y="10.5" width="2.4" height="3" rx="1.2"/></svg>',
    micOff: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M9 9v3a3 3 0 0 0 5.1 2.1M15 9.3V6a3 3 0 0 0-5.7-1.3"/><path d="M19 11a7 7 0 0 1-1.2 3.9M5 11a7 7 0 0 0 10.8 5.9M12 18v3"/></svg>',
    end: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 15.2c5-4.6 13-4.6 18 0l-2.3 2.7-3.7-1.4v-2.8a12.4 12.4 0 0 0-6 0v2.8l-3.7 1.4z"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
    link: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/></svg>',
    callback: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.1 3.5h3.1l1.6 4.1-2 1.3a11.3 11.3 0 0 0 5.3 5.3l1.3-2 4.1 1.6v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 3.1 5.7a2 2 0 0 1 2-2.2zM15 3h6v6M21 3l-6.5 6.5"/></svg>',
  };
  var WAVE = [0.3, 0.5, 0.72, 0.9, 1, 0.9, 0.72, 0.5, 0.3];

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function hexRgb(h) {
    var m = /^#?([0-9a-f]{6})$/i.exec(String(h || ''));
    if (!m) return null;
    var n = parseInt(m[1], 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  // Text on the accent colour: white on a deep colour, ink on a light one.
  function inkOn(h) {
    var c = hexRgb(h);
    if (!c) return '#fff';
    var l = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * l(c[0]) + 0.7152 * l(c[1]) + 0.0722 * l(c[2]) > 0.42 ? '#16132F' : '#fff';
  }
  function darker(h) {
    var c = hexRgb(h);
    if (!c) return h;
    return 'rgb(' + c.map(function (v) { return Math.round(v * 0.86); }).join(',') + ')';
  }
  function isDarkPage() {
    var pick = function (node) {
      if (!node) return null;
      var c = getComputedStyle(node).backgroundColor;
      var m = c && c.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/);
      if (!m || (m[4] !== undefined && +m[4] < 0.5)) return null;
      return (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255 < 0.45;
    };
    var v = pick(document.body);
    if (v === null) v = pick(document.documentElement);
    return v === true;
  }
  function visitorId() {
    try {
      var id = localStorage.getItem('listen_agent_visitor');
      if (!id) { id = Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem('listen_agent_visitor', id); }
      return id;
    } catch (e) { return ''; }
  }
  function clock(s) { return ('0' + Math.floor(s / 60)).slice(-2) + ':' + ('0' + (s % 60)).slice(-2); }

  /*
   * One button. opts.preview: drawn inside opts.into (the Design tab), with a
   * pretend call instead of a real one.
   */
  function build(cfg, ctx, opts) {
    opts = opts || {};
    var preview = !!opts.preview;
    var host = el('div');
    host.setAttribute(preview ? 'data-listen-agent-preview' : 'data-listen-agent', '');
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var style = el('style');
    style.textContent = CSS;
    root.appendChild(style);
    var box = el('div', 'lva');
    root.appendChild(box);
    (opts.into || document.body).appendChild(host);

    var text, look, states;
    var stateNow = 'idle', call = null, starting = false, muted = false, pendingError = '', open = false;
    // The link page (ListenAgentWidget.page): the card open in the page, never closed.
    var inline = !!opts.inline;
    if (inline) open = true;
    var startedAt = 0, tick = 0, demo = null;
    // Every line busy: the caller's place in line, then the number they left (waiting, busy, sent).
    var waitPos = 0, sentTo = null;
    var parts = {};

    function render(next) {
      cfg = next || cfg;
      text = cfg.text || {};
      // Settings a cache kept from before this sentence existed still say it, in the card's
      // language (the same words as agents.network_error).
      if (!text.offline) text.offline = cfg.language === 'en' ? "Couldn't connect. Check your internet connection and try again." : 'تعذّر الاتصال. تحقّق من اتصالك بالإنترنت ثم حاول مرة أخرى.';
      states = text.states || {};
      look = cfg.look || {};
      if (inline && look.style === 'bar') { var asCard = {}; for (var lk in look) asCard[lk] = look[lk]; asCard.style = 'orb'; look = asCard; }
      var colors = cfg.colors || ['#2A1A8F', '#7659FA', '#C9BEFF', '#F4F1FF'];
      var pageRtl = opts.rtl != null ? !!opts.rtl : (getComputedStyle(document.documentElement).direction === 'rtl' || document.documentElement.dir === 'rtl');
      var side = (look.position === 'start') !== pageRtl ? 'left' : 'right';
      var theme = look.theme === 'dark' || (look.theme !== 'light' && (opts.dark != null ? opts.dark : isDarkPage())) ? 'dark' : 'light';
      var accent = hexRgb(look.accent) ? look.accent : '#7659FA';

      box.className = 'lva is-' + side + (preview ? ' is-preview' : '') + (inline ? ' is-inline' : '');
      box.dir = cfg.dir === 'rtl' ? 'rtl' : 'ltr';
      box.lang = cfg.language === 'en' ? 'en' : 'ar';
      box.setAttribute('data-theme', theme);
      box.setAttribute('data-style', look.style || 'orb');
      box.setAttribute('data-size', look.size || 'm');
      box.setAttribute('data-radius', look.radius || 'round');
      box.setAttribute('data-font', look.font || 'system');
      box.style.setProperty('--side', side);
      box.style.setProperty('--a', accent);
      box.style.setProperty('--a-2', darker(accent));
      box.style.setProperty('--a-ink', inkOn(accent));
      box.style.setProperty('--site-font', opts.siteFont || getComputedStyle(document.body).fontFamily || 'inherit');
      if (preview && opts.into) {
        var btnH = { s: 44, m: 52, l: 60 }[look.size] || 52;
        box.style.setProperty('--room', Math.max(300, (opts.into.clientHeight || 580) - btnH - 48) + 'px');
      }
      ['--c1', '--c2', '--c3', '--c4'].forEach(function (v, i) { box.style.setProperty(v, colors[i] || colors[colors.length - 1]); });
      box.innerHTML = '';
      parts = {};
      var bar = look.style === 'bar';

      // The voice: one orb, or the waves.
      function voice(small) {
        if (look.style === 'waves') {
          var w = el('span', 'lva-wave');
          w.setAttribute('aria-hidden', 'true');
          var n = small ? 5 : WAVE.length;
          for (var b = 0; b < n; b++) {
            var i = el('i');
            var k = small ? [0.4, 0.75, 1, 0.75, 0.4][b] : WAVE[b];
            i.style.setProperty('--rest', (small ? 5 + 12 * k : 8 + 22 * k).toFixed(1) + 'px');
            i.__k = k;
            w.appendChild(i);
          }
          return w;
        }
        var wrap = el('span', 'lva-orbwrap');
        wrap.setAttribute('aria-hidden', 'true');
        var rings = el('span', 'lva-rings');
        rings.appendChild(el('i')); rings.appendChild(el('i'));
        wrap.appendChild(rings);
        wrap.appendChild(el('i', 'lva-spin'));
        wrap.appendChild(el('i', 'lva-orb'));
        if (!small) {
          var ring = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
          ring.setAttribute('class', 'lva-ring');
          ring.setAttribute('viewBox', '0 0 120 120');
          ring.innerHTML = '<circle class="t" cx="60" cy="60" r="58"/><circle class="p" cx="60" cy="60" r="58" pathLength="100"/>';
          wrap.appendChild(ring);
        }
        return wrap;
      }

      // The card (orb and waves).
      if (!bar) {
        var panel = el('div', 'lva-panel');
        panel.id = 'lva-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', text.title || cfg.business || '');
        var head = el('div', 'lva-head');
        var who = el('div', 'lva-who');
        var title = el('strong', '', text.title || cfg.business || cfg.name);
        title.dir = 'auto';
        who.appendChild(title);
        who.appendChild(el('small', '', text.subtitle || ''));
        var pill = el('span', 'lva-pill');
        pill.appendChild(el('i'));
        parts.pillTime = el('span', '', '00:00');
        pill.appendChild(parts.pillTime);
        var close = el('button', 'lva-x');
        close.type = 'button';
        close.setAttribute('aria-label', text.close || 'Close');
        close.innerHTML = ICONS.close;
        close.addEventListener('click', function () { setOpen(false); });
        // The agent's photo next to its name (an https or Listen address only).
        var photo = look.photo !== false && cfg.avatar && (/^https:\/\//.test(cfg.avatar) || (ctx.origin && cfg.avatar.indexOf(ctx.origin + '/') === 0)) ? cfg.avatar : null;
        if (photo) { var face = el('span', 'lva-face'); var img = el('img'); img.alt = ''; img.src = photo; face.appendChild(img); head.appendChild(face); }
        head.appendChild(who); head.appendChild(pill); head.appendChild(close);
        var stage = el('div', 'lva-stage');
        parts.voice = voice(false);
        stage.appendChild(parts.voice);
        parts.state = el('p', 'lva-state', states.idle || '');
        parts.state.setAttribute('aria-live', 'polite');
        parts.lines = el('div', 'lva-lines');
        // The chat follows the newest line until the caller scrolls up to read (not while it scrolls itself).
        parts.lines.addEventListener('scroll', function () {
          var box = parts.lines;
          if (!box || Date.now() - (box.__auto || 0) < 800) return;
          box.__pinned = box.scrollHeight - box.scrollTop - box.clientHeight < 40;
        }, { passive: true });
        parts.lines.setAttribute('aria-live', 'polite');
        parts.lines.hidden = look.captions === false;
        // One button: start, then hang up (no mute: a caller who is done simply ends the call).
        var buttons = el('div', 'lva-buttons');
        parts.call = el('button', 'lva-call');
        parts.call.type = 'button';
        parts.callIcon = el('span');
        parts.callIcon.style.display = 'contents';
        parts.callLabel = el('span', '', text.start);
        parts.call.appendChild(parts.callIcon); parts.call.appendChild(parts.callLabel);
        parts.call.addEventListener('click', toggleCall);
        buttons.appendChild(parts.call);
        // Every line busy: the caller's place under the line, and a way to leave a number instead.
        parts.pos = el('span', 'lva-pos');
        parts.pos.hidden = true;
        parts.pos.appendChild(el('i'));
        parts.posText = el('span');
        parts.pos.appendChild(parts.posText);
        parts.sub = el('p', 'lva-sub');
        parts.leave = leaveButton();
        parts.leave.hidden = true;
        buttons.insertBefore(parts.leave, buttons.firstChild);
        // The call (or the wait), or in its place the form to leave a number and the thanks.
        parts.callBox = el('div', 'lva-callview');
        [stage, parts.pos, parts.state, parts.sub, parts.lines, buttons].forEach(function (n) { parts.callBox.appendChild(n); });
        parts.slot = el('div', 'lva-slot');
        parts.slot.hidden = true;
        [head, parts.callBox, parts.slot, powered()].forEach(function (n) { panel.appendChild(n); });
        box.appendChild(panel);
      }

      // The button.
      var dock = el('div', 'lva-dock');
      var round = look.shape === 'round' || !text.label;
      var launch = el('button', 'lva-launch' + (round ? ' is-round' : ''));
      launch.type = 'button';
      launch.setAttribute('aria-label', text.label || text.title || cfg.business || '');
      if (!bar) { launch.setAttribute('aria-expanded', open ? 'true' : 'false'); launch.setAttribute('aria-controls', 'lva-panel'); }
      var ico = el('span', 'lva-ico');
      if (look.icon === 'orb') { ico.classList.add('is-orb'); ico.appendChild(el('i', 'lva-mini')); }
      else ico.innerHTML = ICONS[look.icon] || ICONS.phone;
      var eq = el('span', 'lva-eq');
      eq.appendChild(el('i')); eq.appendChild(el('i')); eq.appendChild(el('i'));
      ico.appendChild(eq);
      launch.appendChild(ico);
      parts.launchLabel = el('span', '', text.label || '');
      if (!round) launch.appendChild(parts.launchLabel);
      launch.addEventListener('click', function () {
        if (bar) toggleCall(); else setOpen(!open);
      });
      launch.addEventListener('pointerenter', function () { if (!preview) loadCall().catch(function () {}); }, { once: true });
      dock.appendChild(launch);
      parts.launch = launch;

      // The slim bar: the button becomes the call.
      if (bar) {
        var b = el('div', 'lva-bar');
        b.setAttribute('role', 'group');
        b.setAttribute('aria-label', text.title || cfg.business || '');
        var viz = el('span', 'lva-viz');
        parts.voice = voice(true);
        viz.appendChild(parts.voice);
        var info = el('span', 'lva-barinfo');
        parts.barTitle = el('b', '', text.title || cfg.business || '');
        parts.barTitle.dir = 'auto';
        parts.barState = el('span', '', states.connecting || '');
        parts.barState.setAttribute('aria-live', 'polite');
        info.appendChild(parts.barTitle); info.appendChild(parts.barState);
        var end = el('button', 'lva-end');
        end.type = 'button';
        end.setAttribute('aria-label', text.end || 'End');
        end.innerHTML = ICONS.end;
        end.addEventListener('click', toggleCall);
        b.appendChild(viz); b.appendChild(info); b.appendChild(end);
        dock.appendChild(b);
        // Above the bar: a problem, the caller's place in line, or the form to leave a number.
        parts.tip = el('div', 'lva-bartip');
        parts.tip.hidden = true;
        parts.tipText = el('span');
        parts.tipText.setAttribute('aria-live', 'polite');
        parts.leave = leaveButton();
        parts.leave.hidden = true;
        parts.slot = el('div', 'lva-slot');
        parts.slot.hidden = true;
        parts.tip.appendChild(parts.tipText); parts.tip.appendChild(parts.leave); parts.tip.appendChild(parts.slot);
        dock.appendChild(parts.tip);
        // The business's own links, when the caller asks for them (the bar has no chat to write them in).
        parts.barLinks = el('div', 'lva-bartip lva-barlinks');
        parts.barLinks.hidden = true;
        parts.barLinksBody = el('div');
        var shut = el('button', 'lva-x');
        shut.type = 'button';
        shut.setAttribute('aria-label', text.close || 'Close');
        shut.innerHTML = ICONS.close;
        shut.addEventListener('click', function () { parts.barLinks.hidden = true; });
        parts.barLinks.appendChild(parts.barLinksBody); parts.barLinks.appendChild(shut);
        dock.appendChild(parts.barLinks);
      }
      box.appendChild(dock);
      box.classList.toggle('is-open', open && !bar);
      paintState();
      if (inline && cfg.available === false && parts.call) {
        parts.call.disabled = true;
        if (parts.state) { parts.state.hidden = false; parts.state.textContent = text.paused || ''; }
      }
    }

    function linkButton(label, onClick) {
      var b = el('button', 'lva-call is-quiet', label || '');
      b.type = 'button';
      b.addEventListener('click', onClick);
      return b;
    }
    function leaveButton() {
      var b = el('button', 'lva-call lva-leave');
      b.type = 'button';
      b.innerHTML = ICONS.callback;
      b.appendChild(el('span', '', lineText().leave || ''));
      b.addEventListener('click', leaveNumber);
      return b;
    }
    function powered() {
      var a = el('a', 'lva-powered', text.powered || 'Powered by Listen');
      a.href = (ctx.origin || '') + '/';
      a.target = '_blank';
      a.rel = 'noopener';
      return a;
    }

    function setOpen(v) {
      if (look.style === 'bar' || inline) return;
      open = v;
      box.classList.toggle('is-open', v);
      parts.launch.setAttribute('aria-expanded', v ? 'true' : 'false');
      if (v) setTimeout(function () { parts.call && parts.call.focus(); }, 60); else if (!preview) parts.launch.focus();
      timer();
    }
    root.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) setOpen(false); });

    function live() { return stateNow === 'connecting' || stateNow === 'listening' || stateNow === 'speaking'; }

    function paintState(message) {
      var on = live(), waiting = stateNow === 'waiting', form = stateNow === 'busy' || stateNow === 'sent';
      var msg = message || (stateNow === 'mic' ? text.mic : waiting ? (lineText().waiting || '') : endedText() || states[stateNow] || '');
      // Before and after a call the owner may hide the line under the orb (look.ready_show).
      var quiet = !on && !waiting && stateNow !== 'error' && stateNow !== 'mic' && !message && look.ready_show === false;
      box.classList.toggle('is-live', on);
      box.classList.toggle('is-waiting', waiting);
      box.classList.toggle('is-connecting', stateNow === 'connecting');
      box.classList.toggle('is-speaking', stateNow === 'speaking');
      box.classList.toggle('is-barlive', look.style === 'bar' && (on || waiting || form || stateNow === 'error' || stateNow === 'mic'));
      if (parts.state) {
        parts.state.hidden = quiet;
        parts.state.textContent = quiet ? '' : msg;
        parts.state.classList.toggle('is-error', stateNow === 'error' || stateNow === 'mic');
      }
      if (parts.pos) { parts.pos.hidden = !waiting; parts.posText.textContent = waiting ? waitText() : ''; }
      if (parts.sub) parts.sub.textContent = waiting ? (lineText().hold || '') : '';
      if (parts.leave) parts.leave.hidden = !waiting;
      if (parts.callBox) parts.callBox.hidden = form;
      if (parts.slot) parts.slot.hidden = !form;
      if (parts.call) {
        // While waiting the button quietly gives up the wait; "leave my number" is the main way on.
        parts.call.classList.toggle('is-live', on);
        parts.call.classList.toggle('is-quiet', waiting);
        parts.callLabel.textContent = waiting ? (lineText().cancel || text.end) : on ? text.end : text.start;
        parts.callIcon.innerHTML = waiting ? '' : on ? ICONS.end : ICONS.phone;
      }
      if (parts.barState) parts.barState.textContent = on && startedAt && stateNow !== 'connecting' ? clock(elapsed()) : (form ? '' : msg);
      if (parts.tip) {
        var err = stateNow === 'error' || stateNow === 'mic';
        parts.tip.hidden = !(err || waiting || form);
        parts.tip.classList.toggle('is-error', err);
        parts.tipText.hidden = form;
        parts.tipText.textContent = err ? msg : waiting ? waitText() : '';
      }
    }
    function lineText() { return (text && text.lines) || {}; }
    function backText() { return (text && text.callback) || {}; }
    // "You're next", or how many callers are ahead (the words for 1 to 10 come ready, Arabic plurals too).
    function waitText() {
      var L = lineText(), ahead = L.ahead || {}, k = Math.max(0, waitPos - 1);
      if (k < 1) return L.next || '';
      return ahead[k] || String(ahead.many || '').replace(':count', k);
    }
    function leaveNumber() {
      if (call && call.giveUp) call.giveUp(); else setState('busy');
    }

    // Still busy (or the caller would rather not wait): their name and number, so the business calls back.
    function showForm() {
      var B = backText();
      parts.slot.innerHTML = '';
      var f = el('form', 'lva-form');
      f.noValidate = true;
      var name = field(B.name, 'text', 'name', 80), phone = field(B.phone, 'tel', 'tel', 24);
      phone.input.dir = 'ltr';
      phone.input.inputMode = 'tel';
      phone.input.required = true;
      var err = el('p', 'lva-err');
      err.hidden = true;
      err.setAttribute('role', 'alert');
      var send = el('button', 'lva-call', B.send || '');
      send.type = 'submit';
      var bad = function (m, onPhone) {
        err.textContent = m || '';
        err.hidden = !m;
        send.disabled = false;
        send.textContent = B.send || '';
        phone.input.setAttribute('aria-invalid', onPhone ? 'true' : 'false');
        if (onPhone) phone.input.focus();
      };
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var number = phone.input.value.trim(), who = name.input.value.trim();
        if ((number.match(/[0-9٠-٩۰-۹]/g) || []).length < 6) { bad(B.invalid_phone, true); return; }
        send.disabled = true;
        send.textContent = B.sending || '';
        err.hidden = true;
        fetch(ctx.origin + '/agent-api/callbacks', {
          method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
          body: JSON.stringify(ctx.slug ? { slug: ctx.slug, visitor: visitorId(), name: who, phone: number } : { key: ctx.key, visitor: visitorId(), name: who, phone: number }),
        }).then(function (r) {
          return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok && d.ok, d: d || {} }; });
        }).then(function (res) {
          if (!res.ok) { bad(res.d.message || B.not_now, res.d.error === 'phone'); return; }
          sentTo = { name: who, phone: res.d.phone || number };
          setState('sent');
        }, function () { bad(B.not_now); });
      });
      [name.label, phone.label, err, send].forEach(function (n) { f.appendChild(n); });
      [el('h3', '', B.title || ''), el('p', '', B.text || ''), f, linkButton(B.again, function () { setState('idle'); toggleCall(); })]
        .forEach(function (n) { parts.slot.appendChild(n); });
      if (look.style !== 'bar' && !open) setOpen(true);
      setTimeout(function () { name.input.focus(); }, 90);
    }
    function field(label, type, autocomplete, max) {
      var l = el('label', 'lva-field');
      var input = el('input');
      input.type = type;
      input.autocomplete = autocomplete;
      input.maxLength = max;
      if (type === 'text') input.dir = 'auto';
      l.appendChild(el('span', '', label || ''));
      l.appendChild(input);
      return { label: l, input: input };
    }
    function showSent() {
      var B = backText();
      parts.slot.innerHTML = '';
      var mark = el('span', 'lva-tick');
      mark.innerHTML = ICONS.check;
      var title = sentTo && sentTo.name ? String(B.sent_title_named || B.sent_title || '').replace(':name', sentTo.name) : (B.sent_title || '');
      var h = el('h3', '', title);
      h.dir = 'auto';
      // ":business will call you back on :phone.", the number kept left to right.
      var p = el('p'), words = String(B.sent_text || '').split(':phone');
      p.appendChild(document.createTextNode(words[0] || ''));
      if (words.length > 1) {
        var num = el('bdi', '', sentTo ? sentTo.phone : '');
        num.dir = 'ltr';
        p.appendChild(num);
        p.appendChild(document.createTextNode(words[1] || ''));
      }
      var done = el('button', 'lva-call', B.close || text.close || '');
      done.type = 'button';
      done.addEventListener('click', function () {
        if (look.style === 'bar') { setState('idle'); return; }
        setOpen(false);
        setTimeout(function () { if (stateNow === 'sent') setState('idle'); }, 450);
      });
      [mark, h, p, done].forEach(function (n) { parts.slot.appendChild(n); });
      setTimeout(function () { done.focus(); }, 60);
    }
    var endedFor = 0;
    function elapsed() { return startedAt ? Math.max(0, Math.floor((Date.now() - startedAt) / 1000)) : 0; }
    // "Call ended after 01:24", in place of "Call ended", once a call that was answered is over.
    function endedText() { return stateNow === 'ended' && endedFor >= 1 && text.ended_after ? String(text.ended_after).replace(':time', clock(endedFor)) : ''; }
    function setState(s, message) {
      if (s === 'error' && !message && pendingError) message = pendingError;
      // The internet dropped during the call: said in the card's language, not "something went wrong".
      if (s === 'error' && !message && typeof navigator !== 'undefined' && navigator.onLine === false) message = text.offline || '';
      if (s !== 'error') pendingError = '';
      var was = live(), before = stateNow;
      // How long the call that just ended lasted, said with "Call ended" until the next one.
      endedFor = s === 'ended' ? elapsed() : 0;
      stateNow = s;
      // The agent's dots: while it speaks, and a moment after (its words can follow its voice).
      if (s === 'speaking') showTyping();
      else if (s === 'listening') hideTyping(4500);
      else hideTyping(0);
      if (live() && !was) {
        startedAt = 0;
        clearInterval(tick);
        tick = setInterval(timer, 1000);
      }
      if ((s === 'listening' || s === 'speaking') && !startedAt) startedAt = Date.now();
      if (s === 'busy' && before !== 'busy') showForm();
      if (s === 'sent') showSent();
      // Waiting for a line is part of the call (it can still be given up); after it, all is reset.
      if (!live() && s !== 'waiting') {
        clearInterval(tick);
        startedAt = 0;
        call = null;
        muted = false;
        level(0, 0);
        if (look.style === 'bar' && (s === 'ended' || s === 'idle')) setTimeout(function () { if (!live()) { box.classList.remove('is-barlive'); } }, 900);
      }
      paintState(message);
      timer();
    }
    function timer() {
      var t = clock(elapsed());
      if (parts.pillTime) parts.pillTime.textContent = t;
      if (live() && startedAt) {
        if (parts.launchLabel && !open) parts.launchLabel.textContent = t;
        if (parts.barState && stateNow !== 'connecting') parts.barState.textContent = t;
      } else if (parts.launchLabel) parts.launchLabel.textContent = text.label || '';
    }

    // The voice level (0..1) moves the orb or the waves; the caller's voice moves them gently.
    function level(a, c) {
      a = Math.min(1, a || 0); c = Math.min(1, c || 0);
      var v = parts.voice;
      if (!v) return;
      if (look.style !== 'waves') {
        var orbEl = v.querySelector('.lva-orb');
        if (orbEl) orbEl.style.setProperty('--s', (1 + a * 0.12 + c * 0.04).toFixed(3));
        return;
      }
      var bars = v.children, t = Date.now() / 95, small = look.style === 'bar';
      var lvl = Math.max(a, c * 0.5);
      for (var i = 0; i < bars.length; i++) {
        var k = bars[i].__k || 0.5;
        if (!live() || lvl < 0.01) { bars[i].style.height = ''; continue; }
        var h = (small ? 5 + 12 * k : 8 + 22 * k) + lvl * (small ? 18 : 62) * k * (0.55 + 0.45 * Math.abs(Math.sin(t + i * 1.7)));
        bars[i].style.height = h.toFixed(1) + 'px';
      }
    }
    /*
     * The chat changes without jumps: its height moves smoothly to the new one (to its most, then
     * it scrolls), and it follows the newest line unless the caller scrolled up to read.
     */
    var typing = null, typingOff = 0;
    function smoothly(change) {
      var box = parts.lines;
      if (!box) { change(); return; }
      var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
      var follow = box.__pinned !== false;
      // What shows now (also in the middle of the last change), then the height the change needs.
      var before = box.offsetHeight;
      clearTimeout(box.__grow);
      box.style.transition = 'none';
      box.style.height = '';
      change();
      var after = box.offsetHeight;
      if (!reduce && after !== before) {
        box.style.height = before + 'px';
        void box.offsetHeight;
        box.style.transition = 'height .32s ' + EASE;
        box.style.height = after + 'px';
        box.__grow = setTimeout(function () { box.style.height = ''; box.style.transition = ''; }, 360);
      } else {
        box.style.transition = '';
      }
      if (follow) {
        box.__auto = Date.now();
        box.__pinned = true;
        if (reduce || !box.scrollTo) box.scrollTop = box.scrollHeight;
        else box.scrollTo({ top: box.scrollHeight, behavior: 'smooth' });
      }
    }
    // Three dots in the agent's bubble while it speaks; its words take their place.
    function showTyping() {
      clearTimeout(typingOff);
      // Not under words already coming (a short pause in the voice), only before a line starts.
      if (typing || liveView || !parts.lines || look.captions === false || parts.lines.hidden) return;
      smoothly(function () {
        typing = el('div', 'lva-line is-agent is-typing');
        typing.setAttribute('aria-hidden', 'true');
        typing.innerHTML = '<i></i><i></i><i></i>';
        parts.lines.appendChild(typing);
      });
    }
    function hideTyping(later) {
      clearTimeout(typingOff);
      if (!typing) return;
      // The agent's line can come a moment after its voice ends (written again, or after the caller's).
      if (later) { typingOff = setTimeout(function () { hideTyping(false); }, later); return; }
      var dots = typing;
      typing = null;
      smoothly(function () { if (dots.parentNode) dots.parentNode.removeChild(dots); });
    }
    // The agent's words as it says them (onWords): the dots become its bubble, which grows a word at
    // a time; its turn (onTurn, t.live) ends it in place. A dropped line (wrong language) goes away.
    var liveView = null;
    function addWords(node, from, to) {
      if (to.indexOf(from) !== 0 || !from) { node.textContent = to; return; }
      if (to.length > from.length) node.appendChild(el('span', 'lva-w', to.slice(from.length)));
    }
    function words(w) {
      if (!parts.lines || look.captions === false) return;
      if (w.gone) {
        if (liveView && liveView.n === w.n) {
          var old = liveView.el;
          liveView = null;
          smoothly(function () { if (old.parentNode) old.parentNode.removeChild(old); });
        }
        return;
      }
      smoothly(function () {
        if (!liveView || liveView.n !== w.n) {
          var n = typing;
          typing = null;
          clearTimeout(typingOff);
          if (n) n.innerHTML = '';
          else { n = el('div'); parts.lines.appendChild(n); }
          n.className = 'lva-line is-agent';
          n.dir = 'auto';
          // Read out once it is whole (its turn), not word by word.
          n.setAttribute('aria-hidden', 'true');
          liveView = { n: w.n, el: n, text: '' };
        }
        addWords(liveView.el, liveView.text, w.text);
        liveView.text = w.text;
      });
    }
    // The caller's line from the moment they start it (onHear): dots in their bubble while they speak
    // and while it is written again from their voice (onHold), in its place, until its words come with
    // its turn. A line that came to nothing takes its dots away.
    var hearing = null;
    function hear(on) {
      if (!parts.lines || look.captions === false) return;
      if (!on) {
        var old = hearing;
        hearing = null;
        if (old) smoothly(function () { if (old.parentNode) old.parentNode.removeChild(old); });
        return;
      }
      if (hearing) return;
      // They started speaking now: after everything said before (an answer they talk over included).
      smoothly(function () {
        var n = el('div', 'lva-line is-caller is-typing');
        n.setAttribute('aria-hidden', 'true');
        n.innerHTML = '<i></i><i></i><i></i>';
        parts.lines.appendChild(n);
        hearing = n;
      });
    }
    function hold(t) {
      if (t.role !== 'caller') return;
      if (!hearing) hear(true);
      t.view = hearing;
      hearing = null;
    }
    function line(t) {
      if (!parts.lines || look.captions === false) return;
      smoothly(function () {
        var n;
        if (t.role === 'caller' && !t.view && hearing) { t.view = hearing; hearing = null; }
        if (t.view && t.view.parentNode === parts.lines) {
          // Its place was kept (onHold): the dots give way to its words.
          n = t.view;
          n.className = 'lva-line is-' + t.role;
          n.removeAttribute('aria-hidden');
          n.textContent = '';
          n.appendChild(el('span', 'lva-w', t.text));
        } else if (t.role === 'agent' && t.live && liveView && liveView.n === t.live) {
          // Shown as it was said: the line ends where it is, with any last words.
          n = liveView.el;
          addWords(n, liveView.text, t.text);
          liveView = null;
          n.removeAttribute('aria-hidden');
        } else if (t.role === 'agent' && typing) {
          // The dots become the agent's words, in the same bubble.
          n = typing;
          typing = null;
          clearTimeout(typingOff);
          n.className = 'lva-line is-agent';
          n.removeAttribute('aria-hidden');
          n.textContent = '';
          n.appendChild(el('span', 'lva-w', t.text));
        } else {
          n = el('div', 'lva-line is-' + t.role, t.text);
          // A caller line comes before the agent's answer that has started (its dots or its words).
          var after = (liveView && liveView.el) || typing;
          if (t.role !== 'agent' && after && after.parentNode === parts.lines) parts.lines.insertBefore(n, after);
          else parts.lines.appendChild(n);
        }
        n.dir = 'auto';
        // The line written again (the caller's from their voice, the agent's in Arabic letters) replaces this one (onFix).
        t.view = n;
        trimLines();
      });
    }
    function fixLine(t) {
      if (!t.view) return;
      smoothly(function () {
        t.view.textContent = t.text;
        t.view.classList.remove('is-fixed');
        void t.view.offsetWidth;
        t.view.classList.add('is-fixed');
      });
    }
    // The business's own links the agent wrote in the chat (show_links): they show also when the
    // words of the call do not (captions off), and stay while newer lines come and go.
    function linksLine(t) {
      if (!parts.lines && !parts.barLinks) return;
      var n = el('div', 'lva-line is-links');
      (t.links || []).forEach(function (l) {
        if (!/^https?:\/\//i.test(String(l.url || ''))) return;
        var a = el('a', 'lva-link');
        a.href = l.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer nofollow';
        a.dir = 'ltr';
        a.innerHTML = ICONS.link;
        a.appendChild(el('span', '', l.label || l.url));
        n.appendChild(a);
      });
      if (!n.children.length) return;
      t.view = n;
      // The slim bar has no chat: the links wait above it, until closed or the next call.
      if (!parts.lines) {
        parts.barLinksBody.innerHTML = '';
        parts.barLinksBody.appendChild(n);
        parts.barLinks.hidden = false;
        return;
      }
      parts.lines.hidden = false;
      smoothly(function () {
        parts.lines.classList.add('has-links');
        if (typing && typing.parentNode === parts.lines) parts.lines.insertBefore(n, typing);
        else parts.lines.appendChild(n);
        trimLines();
      });
    }
    // The whole call stays, scrolled to the newest; only a very long one lets its oldest lines go.
    function trimLines() {
      var lines = parts.lines;
      while (lines.children.length > 80) {
        var old = null;
        for (var i = 0; i < lines.children.length && !old; i++) if (!lines.children[i].classList.contains('is-links')) old = lines.children[i];
        lines.removeChild(old || lines.firstChild);
      }
    }

    // The real call.
    function loadCall() {
      if (window.ListenAgentCall) return Promise.resolve();
      if (loadCall.p) return loadCall.p;
      loadCall.p = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = ctx.callScript;
        s.async = true;
        s.onload = function () { window.ListenAgentCall ? resolve() : reject(new Error('call')); };
        s.onerror = function () { loadCall.p = null; reject(new Error('call')); };
        (document.head || document.documentElement).appendChild(s);
      });
      return loadCall.p;
    }
    function toggleCall() {
      if (preview) { demo ? demo.stop() : runDemo(); return; }
      if (inline && cfg.available === false) return;
      if (call) { call.end(); return; }
      if (starting) return;
      starting = true;
      typing = null;
      liveView = null;
      hearing = null;
      clearTimeout(typingOff);
      if (parts.lines) parts.lines.__pinned = true;
      if (parts.lines) { parts.lines.innerHTML = ''; parts.lines.hidden = look.captions === false; parts.lines.classList.remove('has-links'); }
      if (parts.barLinks) parts.barLinks.hidden = true;
      setState('connecting');
      loadCall().then(function () {
        starting = false;
        call = window.ListenAgentCall.start({
          begin: function () {
            return fetch(ctx.origin + '/agent-api/calls', {
              method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
              body: JSON.stringify(ctx.slug ? { slug: ctx.slug, page_url: location.href, visitor: visitorId() } : { key: ctx.key, page_url: location.href, visitor: visitorId(), caller: ctx.caller || undefined }),
            }).then(function (r) { return r.json().catch(function () { return {}; }); });
          },
          onState: function (s) { setState(s); },
          onTurn: line,
          onWords: words,
          onHold: hold,
          onHear: hear,
          onFix: fixLine,
          onLinks: linksLine,
          onLevel: level,
          onError: function (msg, err) { pendingError = (err && err.offline ? text.offline : msg) || ''; },
          // Every line busy: the caller's place, kept fresh while they wait (a minute at most).
          onWait: function (w) { waitPos = (w && w.position) || 0; if (stateNow === 'waiting') paintState(); },
          patience: 60,
        });
      }, function () { starting = false; setState('error'); });
    }

    // The pretend call in the Design tab: no sound, the button and the voice move as in a call.
    function runDemo() {
      var sample = (cfg.text && cfg.text.sample) || [];
      var raf = 0, timers = [], speakingNow = false, alive = true;
      var at = function (ms, fn) { timers.push(setTimeout(function () { if (alive) fn(); }, ms)); };
      typing = null;
      liveView = null;
      clearTimeout(typingOff);
      if (parts.lines) parts.lines.innerHTML = '';
      setState('connecting');
      var loop = function () {
        if (!alive) return;
        var t = Date.now();
        level(speakingNow ? 0.32 + 0.3 * Math.abs(Math.sin(t / 150)) * (0.65 + 0.35 * Math.sin(t / 610)) : 0, speakingNow ? 0 : 0.12 + 0.1 * Math.abs(Math.sin(t / 240)));
        raf = requestAnimationFrame(loop);
      };
      loop();
      var t = 900, seq = 0;
      sample.forEach(function (turn) {
        var text = String(turn.text || ''), said = text.split(' '), lasts = Math.max(1600, said.length * 260);
        if (turn.role !== 'agent') {
          at(t, function () { speakingNow = false; setState('listening'); line(turn); });
          t += lasts;
          return;
        }
        // The agent's words come as it says them, as in a call.
        var n = 'demo-' + (++seq), first = 340;
        at(t, function () { speakingNow = true; setState('speaking'); });
        said.forEach(function (w, i) { at(t + first + i * 260, function () { words({ n: n, text: said.slice(0, i + 1).join(' ') }); }); });
        at(t + first + said.length * 260, function () { line({ role: 'agent', text: text, live: n }); });
        t += Math.max(lasts, first + said.length * 260 + 300);
      });
      at(t, function () { speakingNow = false; setState('listening'); });
      demo = {
        stop: function () {
          alive = false; timers.forEach(clearTimeout); cancelAnimationFrame(raf); demo = null;
          setState('ended');
          setTimeout(function () { if (!demo) setState('idle'); }, 1200);
        },
      };
      at(t + 2500, function () { if (demo) demo.stop(); });
    }

    render(cfg);
    return {
      update: function (next) { render(next); },
      open: function (o) {
        if (look.style !== 'bar') setOpen(true);
        if (o && o.call && !call && !starting && !demo) toggleCall();
      },
      demo: function (on) { if (on && !demo) { if (look.style !== 'bar') setOpen(true); runDemo(); } else if (!on && demo) demo.stop(); },
      destroy: function () { if (demo) demo.stop(); clearInterval(tick); host.remove(); },
    };
  }

  /* Who is calling, as the website signed it on the button's script tag (CallerIdentity). The
     loader reads it too, but a browser or the CDN can still serve an older loader that does not
     (it is cached for a long time), and this file is always the current one. */
  function signedCaller(key) {
    var tags = document.querySelectorAll('script[data-agent][data-caller-signature]');
    for (var i = 0; i < tags.length; i++) {
      var s = tags[i];
      if (String(s.getAttribute('data-agent') || '').trim() !== key) continue;
      return {
        email: String(s.getAttribute('data-caller-email') || ''),
        name: String(s.getAttribute('data-caller-name') || ''),
        expires: String(s.getAttribute('data-caller-expires') || ''),
        signature: String(s.getAttribute('data-caller-signature') || '')
      };
    }
    return null;
  }

  function mount(cfg, ctx) {
    if (!document.body || document.querySelector('[data-listen-agent]')) return;
    if (ctx && !ctx.caller) ctx.caller = signedCaller(ctx.key);
    var w = build(cfg, ctx, {});
    // The page can open it too ("Talk to our agent"), and start the call at once.
    window.ListenAgentWidget.open = function (o) { w.open(o); };
    try { document.dispatchEvent(new CustomEvent('listen-agent:ready')); } catch (e) {}
    return w;
  }

  /* The Design tab's preview: the same button, drawn inside `into`. */
  function preview(into, cfg, opts) {
    opts = opts || {};
    return build(cfg, { origin: opts.origin || '' }, { preview: true, into: into, rtl: opts.rtl, dark: opts.dark, siteFont: opts.siteFont });
  }

  /* The link page: the same card, open in `into`, its calls asked for by the link (o.slug). */
  function page(into, cfg, o) {
    o = o || {};
    return build(cfg, { origin: o.origin || '', slug: o.slug || '', callScript: o.callScript || '' }, { inline: true, into: into, dark: o.dark });
  }

  window.ListenAgentWidget = { mount: mount, preview: preview, page: page };
})();
