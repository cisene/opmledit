<?php

require_once __DIR__ . '/FileCache.php';

/**
 * Everything known about one feed, in one cache file named by its URL:
 * the PodcastIndex record and the feed's own content with its episodes.
 * Each part carries the time it was taken ("at"), as they age separately.
 * See specifications/storage.md, "Cache".
 *
 * Two requests updating the same feed at once can lose one part; it is a
 * cache, so the part is simply fetched again.
 */
final class FeedStore {

  public function __construct(private FileCache $cache) {}

  /**
   * @return ?array ['record' => ?array] when younger than $maxAge; record null means PodcastIndex does not know the feed
   */
  public function record(string $url, int $maxAge): ?array {
    $part = $this->entry($url)['podcastindex'] ?? null;
    return self::fresh($part, $maxAge) ? array('record' => $part['record']) : null;
  }

  public function setRecord(string $url, ?array $record): void {
    $this->update($url, 'podcastindex', array('at' => time(), 'record' => $record));
  }

  /**
   * The feed's content as last fetched, of any age; the caller decides what is fresh.
   */
  public function content(string $url): ?array {
    return $this->entry($url)['content'] ?? null;
  }

  public function setContent(string $url, array $content): void {
    $this->update($url, 'content', array('at' => time()) + $content);
  }

  /**
   * @return ?array ['url' => ?string] when younger than $maxAge; url null means PodcastIndex does not know the guid
   */
  public function guid(string $guid, int $maxAge): ?array {
    $entry = $this->cache->get(self::guidName($guid), $maxAge);
    return $entry === null ? null : array('url' => $entry['value']);
  }

  public function setGuid(string $guid, ?string $url): void {
    $this->cache->set(self::guidName($guid), $url);
  }

  private function entry(string $url): array {
    $entry = $this->cache->get(self::name($url));
    return is_array($entry['value'] ?? null) ? $entry['value'] : array();
  }

  private function update(string $url, string $part, array $value): void {
    $entry = $this->entry($url);
    $entry['url'] = $url;
    $entry[$part] = $value;
    $this->cache->set(self::name($url), $entry);
  }

  private static function fresh(?array $part, int $maxAge): bool {
    return is_array($part) && (int)($part['at'] ?? 0) > time() - $maxAge;
  }

  private static function name(string $url): string {
    return 'feeds/' . hash('sha256', $url);
  }

  private static function guidName(string $guid): string {
    return 'feeds/guid/' . (preg_match('/^[0-9a-f-]{1,64}$/i', $guid) ? strtolower($guid) : hash('sha256', $guid));
  }
}
