<?php

require_once __DIR__ . '/FeedStore.php';
require_once __DIR__ . '/FeedParser.php';
require_once __DIR__ . '/UrlFetcher.php';

/**
 * A feed's own channel and episodes, fetched on demand and kept in the feed's
 * cache entry. See specifications/episodes.md.
 */
final class FeedReader {

  public function __construct(
    private UrlFetcher $fetcher,
    private ?FeedStore $store,
    private int $maxAge,
    private int $maxEpisodes,
  ) {}

  /**
   * @return array{url: string, fetched: int, stale: bool, error?: string, repairs: string[],
   *               channel: array, episodes: array[], episodeCount: int}
   * @throws FetchException when the feed cannot be fetched and nothing is cached
   * @throws InvalidArgumentException when the feed cannot be read and nothing is cached
   * @param bool $refresh ask the feed's server even when the cached copy is fresh
   */
  public function read(string $url, bool $refresh = false): array {
    $cached = $this->store?->content($url);
    if ($cached !== null && !$refresh && (int)$cached['at'] > time() - $this->maxAge) {
      return self::answer($url, $cached);
    }

    // Ask the server whether the feed changed since it was cached
    $conditions = array_filter(array(
      'If-None-Match'     => $cached['etag'] ?? null,
      'If-Modified-Since' => $cached['lastModified'] ?? null,
    ));
    try {
      $res = $this->fetcher->fetch($url, $conditions);
      if ($res['status'] === 304 && $cached !== null) {
        $this->store->setContent($url, array_diff_key($cached, array('at' => 1)));
        return self::answer($url, array('at' => time()) + $cached);
      }
      if ($res['status'] !== 200) {
        throw new FetchException('The server answered HTTP ' . $res['status']);
      }
      $content = $this->parse($res);
    } catch (FetchException | InvalidArgumentException $e) {
      if ($cached === null) {
        throw $e;
      }
      return self::answer($url, $cached, $e->getMessage());
    }

    $this->store?->setContent($url, $content);
    return self::answer($url, array('at' => time()) + $content);
  }

  private function parse(array $res): array {
    $repairs = array();
    $charset = preg_match('/charset=["\']?([A-Za-z0-9._:-]+)/i', $res['headers']['content-type'] ?? '', $m) ? $m[1] : null;
    $doc = XmlRepair::load(XmlRepair::feedText($res['body'], $charset, $repairs), $repairs);
    $feed = FeedParser::parse($doc, $this->maxEpisodes);
    return array(
      'fetchedFrom'  => $res['url'],
      'etag'         => $res['headers']['etag'] ?? null,
      'lastModified' => $res['headers']['last-modified'] ?? null,
      'repairs'      => $repairs,
      'channel'      => $feed['channel'],
      'episodes'     => array_map(fn(Episode $e) => $e->jsonSerialize(), $feed['episodes']),
      'episodeCount' => $feed['episodeCount'],
    );
  }

  private static function answer(string $url, array $content, ?string $error = null): array {
    $answer = array(
      'url'          => $url,
      'fetchedFrom'  => $content['fetchedFrom'] ?? $url,
      'fetched'      => (int)$content['at'],
      'stale'        => $error !== null,
      'repairs'      => $content['repairs'] ?? array(),
      'channel'      => $content['channel'] ?? array(),
      'episodes'     => $content['episodes'] ?? array(),
      'episodeCount' => (int)($content['episodeCount'] ?? 0),
    );
    if ($error !== null) {
      $answer['error'] = $error;
    }
    return $answer;
  }
}
