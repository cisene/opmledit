<?php

// JSON endpoint for the browser. Keeps the PodcastIndex secret server side.

include_once "config.php";
require_once __DIR__ . '/lib/PodcastIndexApi.php';
require_once __DIR__ . '/lib/FeedSearch.php';
require_once __DIR__ . '/lib/Config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

function respond($code, $data) {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function fail($code, $error, $message) {
  respond($code, array('error' => $message, 'code' => $error));
}

function validFeedUrl($url) {
  return filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url);
}

function validGuid($guid) {
  return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $guid) === 1;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
  // Browsers send Origin on POST; another site must not use this API with a visitor's browser
  $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
  $site = parse_url($config['site_url'] ?? '');
  $expected = isset($site['scheme'], $site['host'])
    ? $site['scheme'] . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '')
    : null;
  if ($origin !== null && $origin !== $expected) {
    fail(403, 'forbidden', 'Request from another site');
  }
}

try {
  $config = Config::load($config);
  $api = PodcastIndexApi::fromConfig($config, new FileCache($config['data_dir'] . '/cache'));
} catch (InvalidArgumentException | RuntimeException $e) {
  fail(500, 'config', 'Configuration error: ' . $e->getMessage());
}

$action = $_GET['action'] ?? '';

try {
  switch ($action) {
    case 'search':
      $search = new FeedSearch($api, $config['podcastindex_max_results']);
      try {
        respond(200, $search->run($_GET));
      } catch (InvalidArgumentException $e) {
        fail(400, 'bad_request', $e->getMessage());
      }

    case 'categories':
      $categories = array();
      foreach ($api->categories() as $id => $name) {
        $categories[] = array('id' => $id, 'name' => $name);
      }
      respond(200, array('categories' => $categories));

    case 'lookup':
      $guid = trim($_GET['guid'] ?? '');
      if ($guid !== '') {
        if (!validGuid($guid)) {
          fail(400, 'bad_request', 'Invalid podcast GUID');
        }
        respond(200, array('feed' => $api->feedByGuid($guid)));
      }
      $url = trim($_GET['url'] ?? '');
      if (!validFeedUrl($url)) {
        fail(400, 'bad_request', 'Invalid feed URL');
      }
      respond(200, array('feed' => $api->feedByUrl($url)));

    case 'lookup_batch':
      if ($method !== 'POST') {
        fail(400, 'bad_request', 'Use POST');
      }
      $body = json_decode((string)file_get_contents('php://input'), true);
      $items = $body['items'] ?? null;
      if (!is_array($items) || count($items) > 100) {
        fail(400, 'bad_request', 'Send up to 100 items');
      }
      $guids = array();
      foreach ($items as $item) {
        $guid = trim((string)($item['guid'] ?? ''));
        $url = trim((string)($item['url'] ?? ''));
        if (!($guid !== '' ? validGuid($guid) : validFeedUrl($url))) {
          fail(400, 'bad_request', 'Each item needs a valid guid or url');
        }
        if ($guid !== '') {
          $guids[] = strtolower($guid);
        }
      }
      $byGuid = $api->feedsByGuids($guids);
      $feeds = array();
      foreach ($items as $item) {
        $guid = strtolower(trim((string)($item['guid'] ?? '')));
        $feeds[] = $guid !== '' ? ($byGuid[$guid] ?? null) : $api->feedByUrl(trim($item['url']));
      }
      respond(200, array('feeds' => $feeds));

    default:
      fail(400, 'bad_request', 'Unknown action');
  }
} catch (PodcastIndexException $e) {
  fail(502, 'upstream', 'PodcastIndex: ' . $e->getMessage());
}
