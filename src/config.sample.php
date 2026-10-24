<?php

// Copy to config.php and fill in. Credentials from https://api.podcastindex.org/
$config = array(
  // Public URL of this site
  'site_url'             => 'https://opml.b19.se/',

  'podcastindex_api_url' => 'https://api.podcastindex.org/api/1.0',
  'podcastindex_key'     => '',
  'podcastindex_secret'  => '',
);

// PodcastIndex asks clients to identify themselves
$config['user_agent'] = 'opmledit/0.1 (+' . $config['site_url'] . ')';
