/* Quality menu for the player: Auto, fixed qualities and playback speed.
   sources: [{id,label,height,url}] best first. Auto chooses from the connection and screen, and steps down if the video keeps stalling. */
const ZQ = {
  need(h) { return h >= 2160 ? 16 : h >= 1440 ? 9 : h >= 1080 ? 5 : h >= 720 ? 2.8 : h >= 576 ? 1.8 : h >= 480 ? 1.3 : h >= 360 ? 0.9 : 0.6; },   // rough Mbit/s a quality needs
  eff(h) { return h >= 9999 ? 1080 : h; },                                                                                                          // "Original" is treated as full HD
  /** The best source that fits the connection (Mbit/s, 0 = unknown), the screen height in pixels and the data-saver switch. */
  pick(sources, o) {
    if (!sources.length) return null;
    const low = sources[sources.length - 1]; if (o.saveData) return low;
    const dl = o.downlink > 0 ? o.downlink : 4;                       // browsers that cannot measure start at about 720p and adapt
    for (const s of sources) if (ZQ.eff(s.height) <= o.screen * 1.25 && ZQ.need(ZQ.eff(s.height)) <= dl * 0.7) return s;
    return low;
  },
};
function ZQuality(v, sources, ui) {
  sources = sources.slice().sort((a, b) => b.height - a.height);
  let mode = 'auto', cur = null, stalls = [], birth = Date.now(), speed = 1, lastSwitch = Date.now(), lastCheck = 0; const bad = {};
  const nc = navigator.connection || {};
  const auto = () => ZQ.pick(sources, { downlink: nc.downlink || 0, saveData: !!nc.saveData, screen: Math.max(240, Math.round((screen.height || 720) * (window.devicePixelRatio || 1))) });
  const label = () => mode === 'auto' ? 'Auto' + (cur ? ' (' + cur.label + ')' : '') : (cur ? cur.label : '');
  function menu() {
    const row = (txt, on, data) => `<button type="button" role="menuitemradio" aria-checked="${on}" ${data}>${on ? '&#10003; ' : ''}${txt}</button>`;
    ui.menu.innerHTML = (sources.length > 1 ? '<div class="qh">Quality</div>' + row('Auto' + (mode === 'auto' && cur ? ' (' + cur.label + ')' : ''), mode === 'auto', 'data-q="auto"') + sources.map(s => row(s.label, mode !== 'auto' && cur && cur.id === s.id, 'data-q="' + s.id + '"')).join('') : '')
      + '<div class="qh">Speed</div>' + [0.5, 0.75, 1, 1.25, 1.5, 2].map(x => row(x === 1 ? 'Normal' : x + 'x', speed === x, 'data-s="' + x + '"')).join('');
    ui.button.textContent = (sources.length > 1 ? label() : (speed === 1 ? 'Speed' : speed + 'x'));
  }
  async function load(src, resume) {
    const t = resume ? v.currentTime : 0, wasPaused = resume ? v.paused : false; cur = src; lastSwitch = Date.now();
    v.src = src.url; v.playbackRate = speed;
    const go = () => { v.removeEventListener('loadedmetadata', go); if (t > 1) v.currentTime = t; if (!wasPaused) { const p = v.play(); if (p && p.catch) p.catch(() => {}); } };
    v.addEventListener('loadedmetadata', go); menu();
  }
  async function renew() {                                    // links last 15 minutes: ask for fresh ones when needed
    if (!ui.refresh) return false; const fresh = await ui.refresh(); if (!fresh) return false;
    sources = fresh.slice().sort((a, b) => b.height - a.height); const same = cur ? sources.find(s => s.id === cur.id) : null; cur = same || cur; return true;
  }
  async function choose(src) { if (Date.now() - birth > 12 * 60 * 1000) { await renew(); src = sources.find(s => s.id === src.id) || src; birth = Date.now(); } load(src, true); }
  ui.box.hidden = false; ui.box.classList.add('on');
  ui.button.onclick = e => { e.stopPropagation(); ui.menu.hidden = !ui.menu.hidden; if (!ui.menu.hidden) menu(); };
  ui.menu.onclick = async e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.s) { speed = +b.dataset.s; v.playbackRate = speed; }
    if (b.dataset.q) { if (b.dataset.q === 'auto') { mode = 'auto'; const a = auto(); if (a && (!cur || a.id !== cur.id)) await choose(a); } else { mode = 'manual'; const s = sources.find(x => String(x.id) === b.dataset.q); if (s && (!cur || s.id !== cur.id)) await choose(s); } }
    menu(); ui.menu.hidden = true;
  };
  const closer = () => { ui.menu.hidden = true; }; document.addEventListener('click', closer);
  v.addEventListener('waiting', () => {                      // Auto: two stalls within 30 s means the connection is too slow for this quality
    if (mode !== 'auto' || v.seeking) return; const now = Date.now(); stalls = stalls.filter(t => now - t < 30000); stalls.push(now);
    if (stalls.length >= 2) { const i = sources.findIndex(s => cur && s.id === cur.id); if (i >= 0 && i < sources.length - 1) { stalls = []; bad[cur.id] = Date.now() + 5 * 60 * 1000; choose(sources[i + 1]); } }
  });
  v.addEventListener('timeupdate', () => {                   // Auto: after 30 s of smooth buffering, try the next better quality (never one that stalled in the last 5 minutes)
    if (mode !== 'auto' || v.paused || !cur) return; const now = Date.now(); if (now - lastCheck < 15000) return; lastCheck = now;
    stalls = stalls.filter(t => now - t < 60000); if (stalls.length || now - lastSwitch < 60000) return;
    let ahead = 0; for (let i = 0; i < v.buffered.length; i++) if (v.currentTime >= v.buffered.start(i) && v.currentTime <= v.buffered.end(i)) ahead = v.buffered.end(i) - v.currentTime;
    const i = sources.findIndex(s => s.id === cur.id); if (ahead < 30 || i <= 0) return;
    const up = sources[i - 1], screenH = Math.max(240, Math.round((screen.height || 720) * (window.devicePixelRatio || 1)));
    if (ZQ.eff(up.height) <= screenH * 1.25 && !(bad[up.id] > now) && (!nc.downlink || ZQ.need(ZQ.eff(up.height)) <= nc.downlink * 0.7)) choose(up);
  });
  let retried = false;
  v.addEventListener('error', async () => {
    if (!retried && (await renew())) { retried = true; const s = sources.find(x => cur && x.id === cur.id) || sources[0]; load(s, true); return; }
    const code = v.error && v.error.code;
    if (mode === 'auto' && cur) { const i = sources.findIndex(s => s.id === cur.id); if (i >= 0 && i < sources.length - 1) { choose(sources[i + 1]); return; } }
    ui.onError(code === 4 ? 'This video format cannot be played in your browser. Ask the site owner to upload it as MP4 (H.264 + AAC).' : 'The video could not be played. Try again in a moment.');
  });
  v.addEventListener('playing', () => { retried = false; });
  load(auto() || sources[0], false);
  return { destroy() { document.removeEventListener('click', closer); ui.box.hidden = true; ui.menu.hidden = true; }, pick: auto, sources: () => sources };
}
if (typeof module !== 'undefined') module.exports = { ZQ };
