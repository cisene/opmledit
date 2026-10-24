<?php

require_once __DIR__ . '/Feed.php';
require_once __DIR__ . '/FileCache.php';

class PodcastIndexException extends RuntimeException {}

/**
 * Client for the PodcastIndex.org API, see https://podcastindex-org.github.io/docs-api/
 */
class PodcastIndexApi {

  public function __construct(
    private string $baseUrl,
    private string $key,
    private string $secret,
    private string $userAgent,
    private int $timeout = 15,
    private ?FileCache $cache = null,
    // Seconds an answer is reused, per cache folder (search, feeds, categories); 0 or missing is not cached
    private array $cacheSeconds = array(),
  ) {
    if ($key === '' || $secret === '') {
      throw new InvalidArgumentException('PodcastIndex API key and secret are required');
    }
    if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
      throw new InvalidArgumentException('PodcastIndex API URL is not a valid URL');
    }
    if ($userAgent === '') {
      throw new InvalidArgumentException('User agent is required');
    }
    $this->baseUrl = rtrim($baseUrl, '/');
  }

  public static function fromConfig(array $config, ?FileCache $cache = null): self {
    return new self(
      (string)($config['podcastindex_api_url'] ?? ''),
      (string)($config['podcastindex_key'] ?? ''),
      (string)($config['podcastindex_secret'] ?? ''),
      (string)($config['user_agent'] ?? ''),
      cache: $cache,
      cacheSeconds: $config['cache_seconds'] ?? array(),
    );
  }

  /**
   * Free text search in title, author and owner.
   *
   * @param array<string, string> $filters val (value block type), clean ('1')
   * @return Feed[]
   */
  public function searchByTerm(string $term, int $max = 1000, array $filters = array()): array {
    return $this->search('/search/byterm', $term, $max, $filters);
  }

  /**
   * @param array<string, string> $filters as for searchByTerm()
   * @return Feed[]
   */
  public function searchByTitle(string $title, int $max = 1000, array $filters = array()): array {
    return $this->search('/search/bytitle', $title, $max, $filters);
  }

  /**
   * Episodes mentioning a person, reduced to their feeds in order of first mention.
   *
   * @return Feed[]
   */
  public function searchByPerson(string $person, int $max = 1000): array {
    $res = $this->cachedSearch('/search/byperson', array('q' => $person, 'max' => $max));
    $byId = array();
    foreach ($res['items'] ?? array() as $item) {
      $id = (int)($item['feedId'] ?? 0);
      if ($id <= 0 || isset($byId[$id])) {
        continue;
      }
      $byId[$id] = array(
        'id'       => $id,
        'url'      => $item['feedUrl'] ?? '',
        'title'    => $item['feedTitle'] ?? '',
        'author'   => $item['feedAuthor'] ?? null,
        'image'    => $item['feedImage'] ?? null,
        'language' => $item['feedLanguage'] ?? null,
        'itunesId' => $item['feedItunesId'] ?? null,
      );
    }
    return $this->feeds(array_values($byId));
  }

  /**
   * @param array<string, string> $filters cat, notcat, lang as PodcastIndex takes them
   * @return Feed[]
   */
  public function trending(int $max = 1000, array $filters = array()): array {
    $res = $this->cachedSearch('/podcasts/trending', array('max' => $max) + self::only($filters, 'cat', 'notcat', 'lang'));
    return $this->feeds($res['feeds'] ?? array());
  }

  /**
   * Most recently updated feeds.
   *
   * @param array<string, string> $filters cat, notcat, lang
   * @return Feed[]
   */
  public function recentFeeds(int $max = 1000, array $filters = array()): array {
    $res = $this->cachedSearch('/recent/feeds', array('max' => $max) + self::only($filters, 'cat', 'notcat', 'lang'));
    return $this->feeds($res['feeds'] ?? array());
  }

  /**
   * @return Feed[]
   */
  public function byMedium(string $medium, int $max = 1000): array {
    $res = $this->cachedSearch('/podcasts/bymedium', array('medium' => $medium, 'max' => $max));
    return $this->feeds($res['feeds'] ?? array());
  }

  /**
   * One page of feeds using a namespace tag, sorted by feed id from $startAt.
   *
   * @param string $tag 'value' or 'valueTimeSplit'
   * @return array{feeds: Feed[], nextStartAt: ?int}
   */
  public function byTag(string $tag, int $startAt, int $max = 5000): array {
    $res = $this->cachedSearch('/podcasts/bytag', array('max' => $max, 'start_at' => $startAt), array('podcast-' . $tag));
    $next = (int)($res['nextStartAt'] ?? 0);
    return array('feeds' => $this->feeds($res['feeds'] ?? array()), 'nextStartAt' => $next > $startAt ? $next : null);
  }

  /**
   * @return array<int, string> category id => name
   */
  public function categories(): array {
    // PodcastIndex returns the categories under "feeds"
    $res = $this->cached('categories/list', 'categories', fn() => $this->request('/categories/list', array()));
    $categories = array();
    foreach ($res['feeds'] ?? array() as $item) {
      if (isset($item['id'], $item['name'])) {
        $categories[(int)$item['id']] = (string)$item['name'];
      }
    }
    return $categories;
  }

  public function feedByUrl(string $url): ?Feed {
    return $this->cachedFeed('url/' . hash('sha256', $url), fn() => $this->request('/podcasts/byfeedurl', array('url' => $url)));
  }

  public function feedById(int $id): ?Feed {
    return $this->cachedFeed('id/' . $id, fn() => $this->request('/podcasts/byfeedid', array('id' => $id)));
  }

  public function feedByGuid(string $guid): ?Feed {
    return $this->cachedFeed(self::guidName($guid), fn() => $this->request('/podcasts/byguid', array('guid' => $guid)));
  }

  public function feedByItunesId(int $itunesId): ?Feed {
    return $this->cachedFeed('itunes/' . $itunesId, fn() => $this->request('/podcasts/byitunesid', array('id' => $itunesId)));
  }

  /**
   * Answers from the cache where it can, asks PodcastIndex in one call for the rest.
   *
   * @param string[] $guids
   * @return array<string, Feed> by requested guid; guids not found are missing
   */
  public function feedsByGuids(array $guids): array {
    $cache = $this->cacheFor('feeds');
    $found = array();
    $missing = array();
    foreach (array_unique($guids) as $guid) {
      $entry = $cache?->get('feeds/' . self::guidName($guid), $this->cacheSeconds['feeds']);
      if ($entry === null) {
        $missing[] = $guid;
      } elseif ($entry['value'] !== null) {
        $found[$guid] = self::toFeed($entry['value']);
      }
    }
    if (!$missing) {
      return array_filter($found);
    }

    $res = $this->request('/podcasts/batch/byguid', array(), array(), $missing);
    $byGuid = array();
    foreach ($res['feeds'] ?? array() as $item) {
      if (is_array($item) && isset($item['id'], $item['podcastGuid'])) {
        $byGuid[strtolower((string)$item['podcastGuid'])] = $item;
      }
    }
    foreach ($missing as $guid) {
      $data = $byGuid[strtolower($guid)] ?? null;
      $cache?->set('feeds/' . self::guidName($guid), $data);
      $found[$guid] = $data === null ? null : self::toFeed($data);
    }
    return array_filter($found);
  }

  /**
   * Caches the raw PodcastIndex record, or null when the feed is not known.
   */
  private function cachedFeed(string $name, callable $fetch): ?Feed {
    $data = $this->cached('feeds/' . $name, 'feeds', function () use ($fetch) {
      // Lookups return an empty array for "feed" when nothing matches
      $data = $fetch()['feed'] ?? null;
      return is_array($data) && isset($data['id']) ? $data : null;
    });
    return $data === null ? null : Feed::fromPodcastIndex($data);
  }

  /**
   * Search answers are cached whole, named by a hash of the request.
   */
  private function cachedSearch(string $path, array $params, array $flags = array()): array {
    $name = 'search/' . hash('sha256', $path . '?' . http_build_query($params) . '#' . implode('&', $flags));
    return $this->cached($name, 'search', fn() => $this->request($path, $params, $flags));
  }

  private function cached(string $name, string $kind, callable $fetch): mixed {
    $cache = $this->cacheFor($kind);
    return $cache ? $cache->remember($name, $this->cacheSeconds[$kind], $fetch) : $fetch();
  }

  private function cacheFor(string $kind): ?FileCache {
    return ($this->cacheSeconds[$kind] ?? 0) > 0 ? $this->cache : null;
  }

  private static function guidName(string $guid): string {
    return 'guid/' . (preg_match('/^[0-9a-f-]{1,64}$/i', $guid) ? strtolower($guid) : hash('sha256', $guid));
  }

  private static function toFeed(array $data): ?Feed {
    try {
      return Feed::fromPodcastIndex($data);
    } catch (InvalidArgumentException $e) {
      return null;
    }
  }

  /**
   * @return Feed[]
   */
  private function search(string $path, string $q, int $max, array $filters): array {
    $params = array('q' => $q, 'max' => $max) + self::only($filters, 'val');
    $flags = array('similar');
    if (!empty($filters['clean'])) {
      $flags[] = 'clean';
    }
    $res = $this->cachedSearch($path, $params, $flags);
    return $this->feeds($res['feeds'] ?? array());
  }

  private static function only(array $values, string ...$keys): array {
    return array_filter(array_intersect_key($values, array_flip($keys)), fn($v) => $v !== '' && $v !== null);
  }

  /**
   * Skips records that do not make valid value objects rather than failing the whole result.
   *
   * @return Feed[]
   */
  private function feeds(array $items): array {
    $feeds = array();
    foreach ($items as $item) {
      try {
        $feeds[] = Feed::fromPodcastIndex($item);
      } catch (InvalidArgumentException $e) {
        continue;
      }
    }
    return $feeds;
  }

  /**
   * @param string[] $flags parameters PodcastIndex wants without a value (e.g. "similar")
   * @param ?array $json body for a POST request
   */
  private function request(string $path, array $params, array $flags = array(), ?array $json = null): array {
    $now = time();
    $headers = array(
      'X-Auth-Key: ' . $this->key,
      'X-Auth-Date: ' . $now,
      'Authorization: ' . sha1($this->key . $this->secret . $now),
      'User-Agent: ' . $this->userAgent,
    );

    $query = implode('&', array_filter(array(http_build_query($params), implode('&', array_map('rawurlencode', $flags)))));
    $ch = curl_init($this->baseUrl . $path . ($query === '' ? '' : '?' . $query));
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_TIMEOUT        => $this->timeout,
    ));
    if ($json !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
      curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, array('Content-Type: application/json')));
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false) {
      throw new PodcastIndexException('Request failed: ' . curl_error($ch));
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
      throw new PodcastIndexException("Invalid response (HTTP $status)");
    }
    if ($status >= 400 || ($json['status'] ?? 'true') === 'false') {
      throw new PodcastIndexException($json['description'] ?? "HTTP $status", $status);
    }
    return $json;
  }
}
