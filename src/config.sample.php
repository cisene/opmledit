<?php

// Copy to config.php and fill in. Credentials from https://api.podcastindex.org/
// Limits and cache times are in data/config.json, see specifications/config.md
$config = array(
  // Public URL of this site
  'site_url'             => 'https://opml.b19.se/',

  'podcastindex_api_url' => 'https://api.podcastindex.org/api/1.0',
  'podcastindex_key'     => '',
  'podcastindex_secret'  => '',

  // Server side storage, outside the web root
  'data_dir'             => __DIR__ . '/../data',
);

// PodcastIndex asks clients to identify themselves
$config['user_agent'] = 'opmledit/0.1 (+' . $config['site_url'] . ')';
