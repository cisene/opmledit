'use strict';

(function () {
  const STORAGE_KEY = 'opmledit.state';

  const state = load() || { title: 'Podcasts', feeds: [] };

  const $ = (sel) => document.querySelector(sel);
  const feedsEl = $('#feeds');
  const resultsEl = $('#results');
  const feedTpl = $('#feed-tpl');
  const resultTpl = $('#result-tpl');

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

  function hasFeed(url) {
    return state.feeds.some((f) => f.xmlUrl === url);
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
    });
    save();
    render();
    return true;
  }

  async function api(params) {
    const res = await fetch('api.php?' + new URLSearchParams(params));
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

    const body = doc.createElement('body');
    state.feeds.forEach((f) => {
      const o = doc.createElement('outline');
      o.setAttribute('type', 'rss');
      o.setAttribute('text', f.title);
      o.setAttribute('title', f.title);
      o.setAttribute('xmlUrl', f.xmlUrl);
      if (f.htmlUrl) o.setAttribute('htmlUrl', f.htmlUrl);
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

  async function checkFeed(feed, li) {
    const out = li.querySelector('.check');
    out.textContent = 'Checking…';
    out.className = 'check muted';
    try {
      const { feed: pi } = await api({ action: 'lookup', url: feed.xmlUrl });
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
    } catch (e) {
      out.textContent = e.message;
      out.className = 'check error';
    }
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

  function renderResults(feeds) {
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
      const art = li.querySelector('.art');
      const image = f.channel.artwork || f.channel.image;
      if (image) art.src = image; else art.hidden = true;
      li.querySelector('.title').textContent = f.channel.title;
      li.querySelector('.author').textContent = f.channel.author || '';
      li.querySelector('.url').textContent = f.url;

      const btn = li.querySelector('.add');
      if (hasFeed(f.url)) { btn.disabled = true; btn.textContent = 'Added'; }
      btn.addEventListener('click', () => {
        const ok = addFeed({ title: f.channel.title, xmlUrl: f.url, htmlUrl: f.channel.link, image: image });
        if (ok) { btn.disabled = true; btn.textContent = 'Added'; }
      });
      resultsEl.appendChild(li);
    });
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
    // Sequential to stay polite to the API
    for (let i = 0; i < state.feeds.length && i < items.length; i++) {
      await checkFeed(state.feeds[i], items[i]);
    }
    status('Checked ' + items.length + ' feeds');
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
      if (pi) feed = { title: pi.channel.title, xmlUrl: url, htmlUrl: pi.channel.link, image: pi.channel.artwork || pi.channel.image };
    } catch (err) {
      // Lookup is a nicety; add the bare URL anyway
    }
    if (addFeed(feed)) input.value = '';
  });

  $('#search-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const q = $('#search-q').value.trim();
    if (!q) return;
    resultsEl.replaceChildren();
    const btn = e.target.querySelector('button');
    btn.disabled = true;
    try {
      const { feeds } = await api({ action: 'search', q: q });
      renderResults(feeds);
    } catch (err) {
      status(err.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  render();
})();
