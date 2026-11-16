/* Shared site pieces: header, footer, API helper, poster cards. */
const Z = {
  cfg: { name: 'Zuzoflix', whatsapp: '', takedown_email: '', payments_enabled: false, categories: [] },
  esc: s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])),
  async api(path, opt) {
    try { const r = await fetch('/api/' + path, Object.assign({ headers: { 'Content-Type': 'application/json', 'X-Zuzo': '1' } }, opt || {})); return await r.json(); }
    catch (e) { return { ok: false, message: 'No connection. Check your internet and try again.' }; }
  },
  card(m) {
    return `<a class="card" href="/watch/${encodeURIComponent(m.slug)}"><div class="pz"><div class="ph"></div>${m.poster ? `<img loading="lazy" alt="${Z.esc(m.title)}" src="${Z.esc(m.poster)}" onerror="this.remove()">` : ''}${m.translator ? `<span class="badge">${Z.esc(m.translator)}</span>` : ''}${m.type === 'series' ? '<span class="tag">SERIES</span>' : ''}</div><div class="t">${Z.esc(m.title)}</div></a>`;
  },
  row(r) {
    return `<section class="row wrap"><div class="hd"><h3>${Z.esc(r.title)}</h3><a class="more" href="${Z.esc(r.more)}">See more</a></div><div class="lane">${r.items.map(Z.card).join('')}</div></section>`;
  },
  async boot(active) {
    const c = await Z.api('config'); if (c && c.ok) Object.assign(Z.cfg, c);
    document.title = (document.title ? document.title + ' | ' : '') + Z.cfg.name;
    const cats = (Z.cfg.categories || []).map(x => `<a href="/browse.html?cat=${encodeURIComponent(x)}">${Z.esc(x)}</a>`).join('') + (Z.cfg.genres || []).filter(x => !(Z.cfg.categories || []).includes(x)).map(x => `<a href="/trending.html?genre=${encodeURIComponent(x)}">${Z.esc(x)}</a>`).join('');
    document.body.insertAdjacentHTML('afterbegin', `<header class="top"><div class="wrap in"><a class="brand" href="/">${Z.esc(Z.cfg.name)}</a>
      <nav class="nav" id="nav"><a href="/" class="${active === 'home' ? 'on' : ''}">Home</a><a href="/trending.html" class="${active === 'movies' ? 'on' : ''}">Movies</a><a href="/trending.html?kind=tv" class="${active === 'series' ? 'on' : ''}">Series</a>
      ${cats ? `<div class="dd"><button type="button">Categories</button><div class="menu">${cats}</div></div>` : ''}</nav>
      <div class="tools"><button class="ibtn" id="sb" aria-label="Search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></button><button class="burger" id="bg" aria-label="Menu"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button></div></div>
      <div class="wrap"><form class="sbar" id="sbar" action="/browse.html"><input name="q" placeholder="Search by title, translator or category" autocomplete="off"></form></div></header>`);
    /* Footer in the style of TMDB's: a dark band, brand column first, then link columns, then the credit line. */
    const E = Z.esc, wa = Z.cfg.whatsapp, mail = Z.cfg.takedown_email;
    document.body.insertAdjacentHTML('beforeend', `<footer class="foot"><div class="wrap fgrid">
      <div class="fbrand"><a href="/" class="flogo">${E(Z.cfg.name)}</a><p>Watch translated and local films, free.</p>${wa ? `<a class="fwa" href="https://wa.me/${E(wa)}" target="_blank" rel="noopener">WhatsApp +${E(wa)}</a>` : ''}</div>
      <div><h4>The basics</h4><a href="/about.html">About and rights</a><a href="/submit.html">Submit your film</a><a href="/report.html">Report a problem</a><a href="/terms.html">Terms of use</a><a href="/privacy.html">Privacy policy</a></div>
      <div><h4>Browse</h4><a href="/trending.html">Movies</a><a href="/trending.html?kind=tv">Series</a></div>
      <div><h4>Contact</h4>${mail ? `<a href="mailto:${E(mail)}">${E(mail)}</a>` : ''}${wa ? `<a href="https://wa.me/${E(wa)}" target="_blank" rel="noopener">WhatsApp us</a>` : ''}<a href="/report.html">Copyright complaints</a></div></div>
      <div class="wrap fbase"><span>&copy; ${new Date().getFullYear()} ${E(Z.cfg.name)}</span></div></footer>`);
    if (Z.cfg.whatsapp) document.body.insertAdjacentHTML('beforeend', `<a class="wa" href="https://wa.me/${Z.esc(Z.cfg.whatsapp)}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg viewBox="0 0 32 32"><path d="M16 3C9 3 3.4 8.6 3.4 15.6c0 2.3.6 4.4 1.7 6.3L3 29l7.3-1.9a12.6 12.6 0 0 0 5.7 1.4c7 0 12.6-5.6 12.6-12.6S23 3 16 3zm0 22.9c-1.8 0-3.6-.5-5.1-1.4l-.4-.2-4.3 1.1 1.2-4.2-.3-.4a10.3 10.3 0 1 1 8.9 5.1zm5.7-7.7c-.3-.2-1.9-.9-2.2-1-.3-.1-.5-.2-.7.2l-1 1.2c-.2.2-.4.2-.7.1-1.9-.9-3.1-1.7-4.3-3.8-.3-.6.3-.5.9-1.7.1-.2 0-.4 0-.5l-1-2.3c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1.1 1.1-1.1 2.6s1.1 3 1.3 3.2c.2.2 2.2 3.4 5.3 4.7 2 .8 2.7.9 3.7.7.6-.1 1.9-.8 2.2-1.5.3-.8.3-1.4.2-1.5-.1-.2-.3-.3-.6-.4z"/></svg></a>`);
    document.getElementById('bg').onclick = () => document.getElementById('nav').classList.toggle('open');
    document.getElementById('sb').onclick = () => { const b = document.getElementById('sbar'); b.classList.toggle('open'); if (b.classList.contains('open')) b.querySelector('input').focus(); };
  }
};
