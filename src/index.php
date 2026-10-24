<?php

include_once "config.php";

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OPML Edit</title>
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
  <header>
    <h1>OPML Edit</h1>
    <div class="toolbar">
      <label class="button">Import OPML<input type="file" id="import-file" accept=".opml,.xml,text/xml" hidden></label>
      <button id="export">Export OPML</button>
      <button id="check-all">Check all feeds</button>
      <button id="clear" class="danger">Clear</button>
    </div>
  </header>

  <main>
    <section id="list-section">
      <div class="section-head">
        <input type="text" id="list-title" placeholder="List title" aria-label="List title">
        <span id="count"></span>
      </div>
      <form id="add-form">
        <input type="url" id="add-url" placeholder="Add feed by URL" required>
        <button type="submit">Add</button>
      </form>
      <ol id="feeds"></ol>
      <p id="empty" class="muted">No feeds yet. Import an OPML file, add a URL, or search PodcastIndex.</p>
    </section>

    <section id="search-section">
      <h2>Search PodcastIndex</h2>
      <form id="search-form">
        <input type="search" id="search-q" placeholder="Podcast title, author…" required>
        <button type="submit">Search</button>
      </form>
      <ul id="results"></ul>
    </section>
  </main>

  <div id="status" role="status" aria-live="polite"></div>

  <template id="feed-tpl">
    <li class="feed">
      <img class="art" alt="" loading="lazy">
      <div class="info">
        <input class="title" type="text" aria-label="Title">
        <input class="url" type="url" aria-label="Feed URL">
        <span class="check muted"></span>
      </div>
      <div class="actions">
        <button class="up" title="Move up">↑</button>
        <button class="down" title="Move down">↓</button>
        <button class="lookup" title="Check against PodcastIndex">Check</button>
        <button class="remove danger" title="Remove">✕</button>
      </div>
    </li>
  </template>

  <template id="result-tpl">
    <li class="result">
      <img class="art" alt="" loading="lazy">
      <div class="info">
        <strong class="title"></strong>
        <span class="author muted"></span>
        <span class="url muted"></span>
      </div>
      <button class="add">Add</button>
    </li>
  </template>

  <script src="js/app.js"></script>
</body>
</html>
