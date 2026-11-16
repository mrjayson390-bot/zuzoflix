/* Trending films from TMDB: information only. A film that is also on Zuzoflix links to its page; the rest open a details box with a trailer. */
window.ZT = {
  items: {},
  /* Loads trending pages 1..n (each page is kept by the server for 6 hours) and returns every film once, in TMDB's order. */
  async loadAll(n, kind) {
    const pages = await Promise.all(Array.from({ length: n }, (_, i) => Z.api('trending?page=' + (i + 1) + (kind === 'tv' ? '&kind=tv' : '')).catch(() => null))), seen = new Set(), all = [];
    pages.forEach(p => (p && p.ok && p.enabled ? p.items : []).forEach(i => { if (!seen.has(i.tmdb_id)) { seen.add(i.tmdb_id); all.push(i); } }));
    return all;
  },
  /* Groups films under the genres TMDB gave them: a film appears in every genre it belongs to. */
  byGenre(all) { const g = {}; all.forEach(i => (i.genres || []).forEach(n => (g[n] = g[n] || []).push(i))); return g; },
  card(i) {
    ZT.items[i.tmdb_id] = i; const on = !!i.slug;
    return `<a class="card tcard" href="${on ? '/watch/' + encodeURIComponent(i.slug) : '#'}" data-t="${i.tmdb_id}"><div class="pz"><div class="ph"></div>${i.poster ? `<img loading="lazy" alt="${Z.esc(i.title)}" src="${Z.esc(i.poster)}" onerror="this.remove()">` : ''}${i.rating > 0 ? `<span class="badge">&#9733; ${i.rating.toFixed(1)}</span>` : ''}<span class="tag ${on ? '' : 'info'}">${on ? 'WATCH HERE' : 'INFO ONLY'}</span></div><div class="t">${Z.esc(i.title)}${i.year ? ' (' + Z.esc(i.year) + ')' : ''}</div></a>`;
  },
  bind(root) {
    root.querySelectorAll('.tcard').forEach(a => a.addEventListener('click', e => { if (a.getAttribute('href') !== '#') return; e.preventDefault(); ZT.open(ZT.items[a.dataset.t]); }));
  },
  async open(i) {
    const o = document.createElement('div'); o.className = 'modal';
    o.innerHTML = `<div class="mbox tbox"><button type="button" class="mx" aria-label="Close">&times;</button><div class="tin">${i.poster ? `<img alt="" src="${Z.esc(i.poster)}">` : ''}<div><h3>${Z.esc(i.title)}${i.year ? ' (' + Z.esc(i.year) + ')' : ''}</h3>
      <div class="tmrow">${i.rating > 0 ? `<span class="rate">&#9733; ${i.rating.toFixed(1)}</span><span class="note">${i.votes.toLocaleString()} votes</span>` : ''}</div>
      <p>${Z.esc(i.overview || 'No description yet.')}</p><p class="note">This ${i.kind === 'tv' ? 'series' : 'film'} is not shown on Zuzoflix. This page gives information and the trailer only.</p><div id="tdx" class="note">Loading trailer...</div>
      <p class="tmcredit"><img src="/assets/img/tmdb.svg" alt="TMDB" onerror="this.remove()"></p></div></div></div>`;
    o.onclick = e => { if (e.target === o || e.target.classList.contains('mx')) o.remove(); }; document.body.appendChild(o);
    const x = await Z.api('trending/' + i.tmdb_id + '/details' + (i.kind === 'tv' ? '?kind=tv' : '')), box = o.querySelector('#tdx'); if (!box) return;
    if (x.ok && x.enabled && x.trailer) { box.innerHTML = '<button type="button" class="btn ghost" id="ttr">&#9658; Watch trailer</button>'; box.querySelector('#ttr').onclick = () => { o.querySelector('.tin').insertAdjacentHTML('afterend', `<iframe class="tfr" src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(x.trailer)}?autoplay=1&rel=0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Trailer"></iframe>`); box.remove(); }; }
    else box.textContent = 'No trailer is listed for this film.';
  }
};
