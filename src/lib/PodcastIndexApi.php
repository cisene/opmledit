<?php

require_once __DIR__ . '/Feed.php';

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

  public static function fromConfig(array $config): self {
    return new self(
      (string)($config['podcastindex_api_url'] ?? ''),
      (string)($config['podcastindex_key'] ?? ''),
      (string)($config['podcastindex_secret'] ?? ''),
      (string)($config['user_agent'] ?? ''),
    );
  }

  /**
   * @return Feed[]
   */
  public function searchByTerm(string $term, int $max = 25): array {
    $res = $this->request('/search/byterm', array('q' => $term, 'max' => $max));
    return $this->feeds($res['feeds'] ?? array());
  }

  /**
   * @return Feed[]
   */
  public function searchByTitle(string $title, int $max = 25): array {
    $res = $this->request('/search/bytitle', array('q' => $title, 'max' => $max));
    return $this->feeds($res['feeds'] ?? array());
  }

  public function feedByUrl(string $url): ?Feed {
    return $this->feed($this->request('/podcasts/byfeedurl', array('url' => $url)));
  }

  public function feedById(int $id): ?Feed {
    return $this->feed($this->request('/podcasts/byfeedid', array('id' => $id)));
  }

  public function feedByGuid(string $guid): ?Feed {
    return $this->feed($this->request('/podcasts/byguid', array('guid' => $guid)));
  }

  public function feedByItunesId(int $itunesId): ?Feed {
    return $this->feed($this->request('/podcasts/byitunesid', array('id' => $itunesId)));
  }

  // Lookups return an empty array for "feed" when nothing matches
  private function feed(array $res): ?Feed {
    $data = $res['feed'] ?? null;
    if (!is_array($data) || !isset($data['id'])) {
      return null;
    }
    return Feed::fromPodcastIndex($data);
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

  private function request(string $path, array $params): array {
    $now = time();
    $headers = array(
      'X-Auth-Key: ' . $this->key,
      'X-Auth-Date: ' . $now,
      'Authorization: ' . sha1($this->key . $this->secret . $now),
      'User-Agent: ' . $this->userAgent,
    );

    $ch = curl_init($this->baseUrl . $path . '?' . http_build_query($params));
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_TIMEOUT        => $this->timeout,
    ));
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
