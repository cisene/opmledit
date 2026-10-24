<?php

require_once __DIR__ . '/Channel.php';

/**
 * An RSS feed as indexed by PodcastIndex: where it lives and how it is doing.
 * What the feed describes is held in $channel.
 */
final readonly class Feed implements JsonSerializable {

  public function __construct(
    public string $url,
    public Channel $channel,
    public ?int $id = null,
    public ?string $originalUrl = null,
    public ?string $podcastGuid = null,
    public ?int $itunesId = null,
    public ?string $contentType = null,
    public ?DateTimeImmutable $lastUpdateTime = null,
    public ?DateTimeImmutable $lastCrawlTime = null,
    public ?int $lastHttpStatus = null,
    public bool $dead = false,
    public ?int $episodeCount = null,
  ) {
    if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1) {
      throw new InvalidArgumentException("Feed URL is not an http(s) URL: $url");
    }
    if ($id !== null && $id <= 0) {
      throw new InvalidArgumentException("Feed id must be positive: $id");
    }
  }

  public static function fromPodcastIndex(array $data): self {
    return new self(
      url:            trim((string)($data['url'] ?? '')),
      channel:        Channel::fromPodcastIndex($data),
      id:             self::int($data['id'] ?? null),
      originalUrl:    self::str($data['originalUrl'] ?? null),
      podcastGuid:    self::str($data['podcastGuid'] ?? null),
      itunesId:       self::int($data['itunesId'] ?? null),
      contentType:    self::str($data['contentType'] ?? null),
      // Trending results only carry the newest episode's time
      lastUpdateTime: self::time($data['lastUpdateTime'] ?? $data['newestItemPublishTime'] ?? null),
      lastCrawlTime:  self::time($data['lastCrawlTime'] ?? null),
      lastHttpStatus: self::int($data['lastHttpStatus'] ?? null),
      dead:           (bool)($data['dead'] ?? false),
      episodeCount:   self::int($data['episodeCount'] ?? null),
    );
  }

  /**
   * True when PodcastIndex knows the feed under a different URL than $url.
   */
  public function hasMovedFrom(string $url): bool {
    return $this->url !== $url;
  }

  public function equals(Feed $other): bool {
    return ($this->id !== null && $this->id === $other->id) || $this->url === $other->url;
  }

  public function jsonSerialize(): array {
    $data = get_object_vars($this);
    // Unix seconds keep the client side simple
    $data['lastUpdateTime'] = $this->lastUpdateTime?->getTimestamp();
    $data['lastCrawlTime'] = $this->lastCrawlTime?->getTimestamp();
    return $data;
  }

  private static function str(mixed $value): ?string {
    $value = trim((string)$value);
    return $value === '' ? null : $value;
  }

  private static function int(mixed $value): ?int {
    return (is_numeric($value) && (int)$value > 0) ? (int)$value : null;
  }

  private static function time(mixed $value): ?DateTimeImmutable {
    $ts = self::int($value);
    return $ts === null ? null : (new DateTimeImmutable('@' . $ts));
  }
}
