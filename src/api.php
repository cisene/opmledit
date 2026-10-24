<?php

// JSON endpoint for the browser. Keeps the PodcastIndex secret server side.

include_once "config.php";
require_once __DIR__ . '/lib/PodcastIndexApi.php';

header('Content-Type: application/json; charset=utf-8');

function respond($code, $data) {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $api = PodcastIndexApi::fromConfig($config);
} catch (InvalidArgumentException $e) {
  respond(500, array('error' => 'Configuration error: ' . $e->getMessage()));
}

$action = $_GET['action'] ?? '';

try {
  switch ($action) {
    case 'search':
      $q = trim($_GET['q'] ?? '');
      if ($q === '' || mb_strlen($q) > 200) {
        respond(400, array('error' => 'Invalid search term'));
      }
      respond(200, array('feeds' => $api->searchByTerm($q)));

    case 'lookup':
      $url = trim($_GET['url'] ?? '');
      if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        respond(400, array('error' => 'Invalid feed URL'));
      }
      respond(200, array('feed' => $api->feedByUrl($url)));

    default:
      respond(400, array('error' => 'Unknown action'));
  }
} catch (PodcastIndexException $e) {
  respond(502, array('error' => 'PodcastIndex: ' . $e->getMessage()));
}
