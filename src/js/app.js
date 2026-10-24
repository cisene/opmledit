'use strict';

(function () {
  const STORAGE_KEY = 'opmledit.state';

  const state = load() || { title: 'Podcasts', feeds: [] };

  const $ = (sel) => document.querySelector(sel);
  const feedsEl = $('#feeds');
  const resultsEl = $('#results');
  const feedTpl = $('#feed-tpl');
  const resultTpl = $('#result-tpl');
  const episodeTpl = $('#episode-tpl');
  const PODCAST_NS = 'https://podcastindex.org/namespace/1.0';

  // Last search, kept so the results filter and Add all work without a new request
  let lastResults = [];

  // --- persistence -------------------------------------------------------

  function load() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function save() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {
      status('Could not save to browser storage', true);
    }
  }

  // --- helpers -----------------------------------------------------------

  let statusTimer;
  function status(msg, isError) {
    const el = $('#status');
    el.textContent = msg;
    el.className = isError ? 'error show' : 'show';
    clearTimeout(statusTimer);
    statusTimer = setTimeout(() => { el.className = ''; }, 4000);
  }

  function hasFeed(url, guid) {
    return state.feeds.some((f) => f.xmlUrl === url || (guid && f.podcastGuid === guid));
  }

  function addFeed(feed) {
    if (hasFeed(feed.xmlUrl)) {
      status('Already in list: ' + feed.xmlUrl, true);
      return false;
    }
    state.feeds.push({
      title: feed.title || feed.xmlUrl,
      xmlUrl: feed.xmlUrl,
      htmlUrl: feed.htmlUrl || '',
      image: feed.image || '',
      podcastGuid: feed.podcastGuid || '',
    });
    save();
    render();
    return true;
  }

  function fromPodcastIndex(f) {
    return {
      title: f.channel.title,
      xmlUrl: f.url,
      htmlUrl: f.channel.link,
      image: f.channel.artwork || f.channel.image,
      podcastGuid: f.podcastGuid,
    };
  }

  async function api(params, body) {
    const init = body === undefined ? {} : {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    };
    const res = await fetch('api.php?' + new URLSearchParams(params), init);
    const data = await res.json().catch(() => ({ error: 'Invalid response' }));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  // --- OPML import / export ---------------------------------------------

  function parseOpml(text) {
    const doc = new DOMParser().parseFromString(text, 'text/xml');
    if (doc.querySelector('parsererror')) throw new Error('Not a valid XML file');
    if (doc.documentElement.nodeName.toLowerCase() !== 'opml') throw new Error('Not an OPML file');

    const title = doc.querySelector('head > title');
    const feeds = [];
    // Nested category outlines are flattened; only outlines with a feed URL are kept
    doc.querySelectorAll('outline[xmlUrl]').forEach((o) => {
      const xmlUrl = o.getAttribute('xmlUrl').trim();
      if (!xmlUrl || feeds.some((f) => f.xmlUrl === xmlUrl)) return;
      feeds.push({
        title: o.getAttribute('title') || o.getAttribute('text') || xmlUrl,
        xmlUrl: xmlUrl,
        htmlUrl: o.getAttribute('htmlUrl') || '',
        image: '',
        podcastGuid: o.getAttribute('podcast:guid') || '',
      });
    });
    return { title: title ? title.textContent.trim() : '', feeds: feeds };
  }

  function buildOpml() {
    const doc = document.implementation.createDocument(null, 'opml', null);
    const opml = doc.documentElement;
    opml.setAttribute('version', '2.0');

    const head = doc.createElement('head');
    const title = doc.createElement('title');
    title.textContent = state.title || 'Podcasts';
    const created = doc.createElement('dateCreated');
    created.textContent = new Date().toUTCString();
    head.append(title, created);

    if (state.feeds.some((f) => f.podcastGuid)) {
      opml.setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:podcast', PODCAST_NS);
    }

    const body = doc.createElement('body');
    state.feeds.forEach((f) => {
      const o = doc.createElement('outline');
      o.setAttribute('type', 'rss');
      o.setAttribute('text', f.title);
      o.setAttribute('title', f.title);
      o.setAttribute('xmlUrl', f.xmlUrl);
      if (f.htmlUrl) o.setAttribute('htmlUrl', f.htmlUrl);
      if (f.podcastGuid) o.setAttributeNS(PODCAST_NS, 'podcast:guid', f.podcastGuid);
      body.appendChild(o);
    });

    opml.append(head, body);
    const xml = new XMLSerializer().serializeToString(doc);
    return '<?xml version="1.0" encoding="UTF-8"?>\n' + xml.replace(/></g, '>\n<');
  }

  function download(filename, text) {
    const blob = new Blob([text], { type: 'text/x-opml' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  // --- feed checking -----------------------------------------------------

  // Look up by podcast:guid when known, as it survives a change of feed URL
  function lookupItem(feed) {
    return feed.podcastGuid ? { guid: feed.podcastGuid } : { url: feed.xmlUrl };
  }

  async function checkFeed(feed, li) {
    const out = li.querySelector('.check');
    out.textContent = 'Checking…';
    out.className = 'check muted';
    try {
      const { feed: pi } = await api(Object.assign({ action: 'lookup' }, lookupItem(feed)));
      showCheck(feed, li, pi);
    } catch (e) {
      out.textContent = e.message;
      out.className = 'check error';
    }
  }

  function showCheck(feed, li, pi) {
    const out = li.querySelector('.check');
    if (!pi) {
      out.textContent = 'Not found in PodcastIndex';
      out.className = 'check warn';
      return;
    }
    const image = pi.channel.artwork || pi.channel.image;
    if (!feed.image && image) {
      feed.image = image;
      const art = li.querySelector('.art');
      art.src = image;
      art.hidden = false;
    }
    if (!feed.htmlUrl && pi.channel.link) feed.htmlUrl = pi.channel.link;
    if (!feed.podcastGuid && pi.podcastGuid) feed.podcastGuid = pi.podcastGuid;
    save();

    const updated = pi.lastUpdateTime ? new Date(pi.lastUpdateTime * 1000).toLocaleDateString() : 'unknown';
    if (pi.dead) {
      out.textContent = 'Marked dead in PodcastIndex (last update ' + updated + ')';
      out.className = 'check error';
    } else if (pi.url && pi.url !== feed.xmlUrl) {
      out.textContent = 'Feed has moved to ' + pi.url + ' ';
      out.className = 'check warn';
      const fix = document.createElement('button');
      fix.textContent = 'Use new URL';
      fix.addEventListener('click', () => {
        if (hasFeed(pi.url)) { status('New URL is already in list', true); return; }
        feed.xmlUrl = pi.url;
        save();
        render();
      });
      out.appendChild(fix);
    } else {
      out.textContent = 'OK, last update ' + updated;
      out.className = 'check ok';
    }
  }

  // --- episodes ----------------------------------------------------------

  // Podcasting 2.0 tags worth pointing out on an episode
  const EPISODE_TAGS = { 'podcast:transcript': 'transcript', 'podcast:chapters': 'chapters', 'podcast:value': 'value',
    'podcast:person': 'people', 'podcast:soundbite': 'soundbites', 'podcast:alternateEnclosure': 'alternate media',
    'podcast:location': 'location', 'podcast:socialInteract': 'comments', 'podcast:license': 'license' };

  function duration(seconds) {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
  }

  function episodeItem(ep) {
    const li = episodeTpl.content.firstElementChild.cloneNode(true);
    li.querySelector('.date').textContent = ep.published ? new Date(ep.published * 1000).toLocaleDateString() : '';
    const title = li.querySelector('.title');
    title.textContent = ep.title || '(no title)';
    const href = ep.enclosureUrl || ep.link;
    if (href) title.href = href; else title.removeAttribute('href');
    if (ep.description) title.title = ep.description;
    const meta = [];
    if (ep.season) meta.push('S' + ep.season);
    if (ep.episode) meta.push('E' + ep.episode);
    if (ep.duration) meta.push(duration(ep.duration));
    if (ep.episodeType && ep.episodeType !== 'full') meta.push(ep.episodeType);
    li.querySelector('.meta').textContent = meta.join(' · ');
    const tags = li.querySelector('.tags');
    for (const name of Object.keys(ep.extensions || {})) {
      if (EPISODE_TAGS[name]) {
        const tag = document.createElement('span');
        tag.textContent = EPISODE_TAGS[name];
        tags.appendChild(tag);
      }
    }
    return li;
  }

  function renderEpisodes(li, data) {
    const term = li.querySelector('.episodes-filter').value.trim().toLowerCase();
    const shown = term
      ? data.episodes.filter((ep) => (ep.title + ' ' + (ep.description || '')).toLowerCase().includes(term))
      : data.episodes;
    li.querySelector('.episode-list').replaceChildren(...shown.map(episodeItem));

    let info = data.episodeCount + ' episode' + (data.episodeCount === 1 ? '' : 's');
    if (data.episodes.length < data.episodeCount) info += ', first ' + data.episodes.length + ' shown';
    if (term) info = shown.length + ' of ' + info;
    li.querySelector('.episodes-info').textContent = info;

    const notes = [];
    if (data.stale) notes.push('Could not fetch the feed (' + data.error + '); showing the copy from ' + new Date(data.fetched * 1000).toLocaleString() + '.');
    if (data.fetchedFrom !== data.url) notes.push('Redirected to ' + data.fetchedFrom + '.');
    if (data.repairs.length) notes.push('Repaired: ' + data.repairs.join('; ') + '.');
    const el = li.querySelector('.episodes-notes');
    el.textContent = notes.join(' ');
    el.className = 'episodes-notes ' + (data.stale ? 'warn' : 'muted');
  }

  async function loadEpisodes(feed, li, refresh) {
    const info = li.querySelector('.episodes-info');
    info.textContent = 'Fetching episodes…';
    li.querySelector('.episode-list').replaceChildren();
    li.querySelector('.episodes-notes').textContent = '';
    try {
      const params = { action: 'episodes', url: feed.xmlUrl };
      if (refresh) params.refresh = '1';
      const data = await api(params);
      li.episodes = data;
      renderEpisodes(li, data);
    } catch (e) {
      info.textContent = e.message;
    }
  }

  function toggleEpisodes(feed, li) {
    const panel = li.querySelector('.episodes');
    const button = li.querySelector('.show-episodes');
    panel.hidden = !panel.hidden;
    button.setAttribute('aria-expanded', String(!panel.hidden));
    if (!panel.hidden && !li.episodes) loadEpisodes(feed, li);
  }

  // --- rendering ---------------------------------------------------------

  function render() {
    $('#list-title').value = state.title;
    $('#count').textContent = state.feeds.length + ' feed' + (state.feeds.length === 1 ? '' : 's');
    $('#empty').hidden = state.feeds.length > 0;

    feedsEl.replaceChildren();
    state.feeds.forEach((feed, i) => {
      const li = feedTpl.content.firstElementChild.cloneNode(true);
      const art = li.querySelector('.art');
      if (feed.image) art.src = feed.image; else art.hidden = true;

      const title = li.querySelector('.title');
      title.value = feed.title;
      title.addEventListener('change', () => { feed.title = title.value.trim() || feed.xmlUrl; save(); });

      const url = li.querySelector('.url');
      url.value = feed.xmlUrl;
      url.addEventListener('change', () => {
        const v = url.value.trim();
        if (!url.checkValidity() || !v) { url.value = feed.xmlUrl; status('Invalid URL', true); return; }
        if (v !== feed.xmlUrl && hasFeed(v)) { url.value = feed.xmlUrl; status('Already in list', true); return; }
        feed.xmlUrl = v;
        save();
      });

      const up = li.querySelector('.up');
      const down = li.querySelector('.down');
      up.disabled = i === 0;
      down.disabled = i === state.feeds.length - 1;
      up.addEventListener('click', () => move(i, -1));
      down.addEventListener('click', () => move(i, 1));

      li.querySelector('.lookup').addEventListener('click', () => checkFeed(feed, li));
      li.querySelector('.show-episodes').addEventListener('click', () => toggleEpisodes(feed, li));
      li.querySelector('.episodes-refresh').addEventListener('click', () => loadEpisodes(feed, li, true));
      li.querySelector('.episodes-filter').addEventListener('input', () => { if (li.episodes) renderEpisodes(li, li.episodes); });
      li.querySelector('.remove').addEventListener('click', () => {
        state.feeds.splice(i, 1);
        save();
        render();
      });

      feedsEl.appendChild(li);
    });
  }

  function move(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= state.feeds.length) return;
    [state.feeds[i], state.feeds[j]] = [state.feeds[j], state.feeds[i]];
    save();
    render();
  }

  function resultMeta(f) {
    const parts = [];
    if (f.channel.language) parts.push(f.channel.language);
    const cats = Object.values(f.channel.categories || {});
    if (cats.length) parts.push(cats.join(', '));
    if (f.channel.medium && f.channel.medium !== 'podcast') parts.push(f.channel.medium);
    if (f.episodeCount) parts.push(f.episodeCount + ' episodes');
    if (f.lastUpdateTime) parts.push('updated ' + new Date(f.lastUpdateTime * 1000).toLocaleDateString());
    return parts.join(' · ');
  }

  function shownResults() {
    const term = $('#results-filter').value.trim().toLowerCase();
    if (!term) return lastResults;
    return lastResults.filter((f) => [f.channel.title, f.channel.author, f.url, resultMeta(f)]
      .some((v) => (v || '').toLowerCase().includes(term)));
  }

  function renderResults() {
    const feeds = shownResults();
    resultsEl.replaceChildren();
    if (!feeds.length) {
      const li = document.createElement('li');
      li.className = 'muted';
      li.textContent = 'No results';
      resultsEl.appendChild(li);
      return;
    }
    feeds.forEach((f) => {
      const li = resultTpl.content.firstElementChild.cloneNode(true);
      li.classList.toggle('dead', !!f.dead);
      const art = li.querySelector('.art');
      const image = f.channel.artwork || f.channel.image;
      if (image) art.src = image; else art.hidden = true;
      li.querySelector('.title').textContent = f.channel.title;
      li.querySelector('.author').textContent = f.channel.author || '';
      li.querySelector('.meta').textContent = resultMeta(f);
      li.querySelector('.url').textContent = f.url;

      const btn = li.querySelector('.add');
      if (hasFeed(f.url, f.podcastGuid)) { btn.disabled = true; btn.textContent = 'Added'; }
      btn.addEventListener('click', () => {
        if (addFeed(fromPodcastIndex(f))) { btn.disabled = true; btn.textContent = 'Added'; }
      });
      resultsEl.appendChild(li);
    });
  }

  function showResultsHead(data) {
    const n = data.feeds.length;
    $('#results-count').textContent = data.truncated
      ? 'First ' + n + ' feeds, refine the search for the rest'
      : n + ' feed' + (n === 1 ? '' : 's');
    $('#results-filter').value = '';
    $('#results-head').hidden = false;
  }

  // Show only the inputs the chosen search mode uses
  function updateSearchForm() {
    const by = $('#search-by').value;
    const q = $('#search-q');
    q.hidden = !['term', 'title', 'person'].includes(by);
    q.required = !q.hidden;
    q.placeholder = by === 'person' ? 'Name of a person' : 'Podcast title, author…';
    $('#search-tag').hidden = by !== 'tag';
    $('#filter-val-label').hidden = !['term', 'title'].includes(by);
    if (by === 'category' || by === 'medium') $('#search-filters').open = true;
  }

  async function loadCategories() {
    try {
      const { categories } = await api({ action: 'categories' });
      categories.sort((a, b) => a.name.localeCompare(b.name)).forEach((c) => {
        ['#filter-cat', '#filter-notcat'].forEach((sel) => {
          const opt = document.createElement('option');
          opt.value = c.id;
          opt.textContent = c.name;
          $(sel).appendChild(opt);
        });
      });
    } catch (e) {
      // Category filters stay at "Any"; searching still works
    }
  }

  // --- events ------------------------------------------------------------

  $('#import-file').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    try {
      const parsed = parseOpml(await file.text());
      if (state.feeds.length && !confirm('Replace the current list (' + state.feeds.length + ' feeds)?\nCancel merges the imported feeds instead.')) {
        const before = state.feeds.length;
        parsed.feeds.forEach((f) => { if (!hasFeed(f.xmlUrl)) state.feeds.push(f); });
        status('Merged ' + (state.feeds.length - before) + ' new feeds');
      } else {
        state.title = parsed.title || state.title;
        state.feeds = parsed.feeds;
        status('Imported ' + parsed.feeds.length + ' feeds');
      }
      save();
      render();
    } catch (err) {
      status(err.message, true);
    }
  });

  $('#export').addEventListener('click', () => {
    if (!state.feeds.length) { status('Nothing to export', true); return; }
    const name = (state.title || 'podcasts').replace(/[^\w.-]+/g, '_') + '.opml';
    download(name, buildOpml());
  });

  $('#check-all').addEventListener('click', async () => {
    const items = feedsEl.querySelectorAll('.feed');
    const n = Math.min(state.feeds.length, items.length);
    items.forEach((li) => { li.querySelector('.check').textContent = 'Checking…'; });
    for (let i = 0; i < n; i += 100) {
      const batch = state.feeds.slice(i, Math.min(i + 100, n));
      try {
        const { feeds } = await api({ action: 'lookup_batch' }, { items: batch.map(lookupItem) });
        batch.forEach((feed, j) => showCheck(feed, items[i + j], feeds[j]));
      } catch (err) {
        status(err.message, true);
        return;
      }
    }
    status('Checked ' + n + ' feeds');
  });

  $('#clear').addEventListener('click', () => {
    if (!state.feeds.length || !confirm('Remove all feeds from the list?')) return;
    state.feeds = [];
    save();
    render();
  });

  $('#list-title').addEventListener('change', (e) => {
    state.title = e.target.value.trim();
    save();
  });

  $('#add-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = $('#add-url');
    const url = input.value.trim();
    let feed = { title: url, xmlUrl: url };
    try {
      const { feed: pi } = await api({ action: 'lookup', url: url });
      if (pi) feed = Object.assign(fromPodcastIndex(pi), { xmlUrl: url });
    } catch (err) {
      // Lookup is a nicety; add the bare URL anyway
    }
    if (addFeed(feed)) input.value = '';
  });

  $('#search-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const by = $('#search-by').value;
    const params = {
      action: 'search',
      by: by,
      q: $('#search-q').value.trim(),
      tag: by === 'tag' ? $('#search-tag').value : '',
      cat: $('#filter-cat').value,
      notcat: $('#filter-notcat').value,
      medium: $('#filter-medium').value,
      lang: $('#filter-lang').value.trim(),
      val: ['term', 'title'].includes(by) ? $('#filter-val').value : '',
      clean: $('#filter-clean').checked ? '1' : '',
      alive: $('#filter-dead').checked ? '0' : '1',
    };
    Object.keys(params).forEach((k) => { if (params[k] === '') delete params[k]; });

    resultsEl.replaceChildren();
    $('#results-head').hidden = true;
    const btn = e.target.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Searching…';
    try {
      const data = await api(params);
      lastResults = data.feeds;
      showResultsHead(data);
      renderResults();
    } catch (err) {
      status(err.message, true);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Search';
    }
  });

  $('#search-by').addEventListener('change', updateSearchForm);
  $('#results-filter').addEventListener('input', renderResults);

  $('#add-all').addEventListener('click', () => {
    const toAdd = shownResults().filter((f) => !hasFeed(f.url, f.podcastGuid));
    if (!toAdd.length) { status('All shown results are already in the list'); return; }
    toAdd.forEach((f) => state.feeds.push(Object.assign({ title: f.url, htmlUrl: '', image: '', podcastGuid: '' }, fromPodcastIndex(f))));
    save();
    render();
    renderResults();
    status('Added ' + toAdd.length + ' feeds');
  });

  updateSearchForm();
  loadCategories();
  render();
})();
