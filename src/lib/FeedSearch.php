<?php

require_once __DIR__ . '/PodcastIndexApi.php';

/**
 * Searches PodcastIndex in one of several modes, takes in all results the API
 * gives, and applies filters PodcastIndex does not support on every call.
 * See specifications/api.md, "Search".
 */
final class FeedSearch {

  public const MODES = array('term', 'title', 'person', 'category', 'medium', 'tag', 'recent');
  public const MEDIUMS = array('podcast', 'music', 'video', 'film', 'audiobook', 'newsletter', 'blog');
  public const TAGS = array('value', 'valueTimeSplit');
  public const VALUE_TYPES = array('any', 'lightning', 'hive', 'webmonetization');

  // Most PodcastIndex calls return at most 1000 results; podcasts/bytag pages up to 5000
  private const API_MAX = 1000;
  private const TAG_PAGE = 5000;
  private const TAG_MAX_PAGES = 10;

  public function __construct(
    private PodcastIndexApi $api,
    private int $maxResults = 1000,
  ) {
    if ($maxResults < 1) {
      throw new InvalidArgumentException('Max results must be positive');
    }
  }

  /**
   * @param array<string, string> $params request parameters, see api.md
   * @return array{feeds: Feed[], total: int, truncated: bool}
   * @throws InvalidArgumentException for invalid parameters, with a message for the user
   */
  public function run(array $params): array {
    $p = self::validate($params);
    $max = min($this->maxResults, self::API_MAX);
    $passOn = array_filter(array('cat' => $p['cat'], 'notcat' => $p['notcat'], 'lang' => $p['lang']));

    $truncated = false;
    switch ($p['by']) {
      case 'term':
      case 'title':
        $filters = array('val' => $p['val'], 'clean' => $p['clean'] ? '1' : '');
        $feeds = $p['by'] === 'term'
          ? $this->api->searchByTerm($p['q'], $max, $filters)
          : $this->api->searchByTitle($p['q'], $max, $filters);
        $truncated = count($feeds) >= $max;
        break;
      case 'person':
        // Counted in episodes, not feeds, so a full answer can still be fewer feeds
        $feeds = $this->api->searchByPerson($p['q'], self::API_MAX);
        break;
      case 'category':
        $feeds = $this->api->trending($max, $passOn);
        $truncated = count($feeds) >= $max;
        break;
      case 'recent':
        $feeds = $this->api->recentFeeds($max, $passOn);
        $truncated = count($feeds) >= $max;
        break;
      case 'medium':
        $feeds = $this->api->byMedium($p['medium'], $max);
        $truncated = count($feeds) >= $max;
        break;
      case 'tag':
        return $this->byTag($p);
    }

    $feeds = $this->filter($feeds, $p);
    if (count($feeds) > $this->maxResults) {
      $feeds = array_slice($feeds, 0, $this->maxResults);
      $truncated = true;
    }
    return array('feeds' => $feeds, 'total' => count($feeds), 'truncated' => $truncated);
  }

  /**
   * Follows nextStartAt until there are no more pages or enough feeds pass the filters.
   */
  private function byTag(array $p): array {
    $feeds = array();
    $startAt = 1;
    for ($page = 0; $page < self::TAG_MAX_PAGES && $startAt !== null; $page++) {
      $res = $this->api->byTag($p['tag'], $startAt, self::TAG_PAGE);
      $feeds = array_merge($feeds, $this->filter($res['feeds'], $p));
      $startAt = $res['nextStartAt'];
      if (!$res['feeds'] || count($feeds) >= $this->maxResults) {
        break;
      }
    }
    $truncated = count($feeds) > $this->maxResults || ($startAt !== null && count($feeds) >= $this->maxResults);
    $feeds = array_slice($feeds, 0, $this->maxResults);
    return array('feeds' => $feeds, 'total' => count($feeds), 'truncated' => $truncated);
  }

  /**
   * @param Feed[] $feeds
   * @return Feed[]
   */
  private function filter(array $feeds, array $p): array {
    $cat = self::tokens($p['cat']);
    $notcat = self::tokens($p['notcat']);
    $lang = self::tokens($p['lang']);

    return array_values(array_filter($feeds, function (Feed $feed) use ($p, $cat, $notcat, $lang): bool {
      $c = $feed->channel;
      if ($p['alive'] && $feed->dead) return false;
      if ($p['clean'] && $c->explicit) return false;
      if ($p['medium'] !== '' && $p['by'] !== 'medium' && strtolower((string)$c->medium) !== $p['medium']) return false;
      if ($cat && !self::inCategories($c->categories, $cat)) return false;
      if ($notcat && self::inCategories($c->categories, $notcat)) return false;
      if ($lang && !self::hasLanguage($c->language, $lang)) return false;
      return true;
    }));
  }

  /**
   * @param array<int, string> $categories
   * @param string[] $tokens lowercase ids or names
   */
  private static function inCategories(array $categories, array $tokens): bool {
    foreach ($categories as $id => $name) {
      if (in_array((string)$id, $tokens, true) || in_array(strtolower($name), $tokens, true)) {
        return true;
      }
    }
    return false;
  }

  /**
   * "en" matches "en" and "en-US"; "unknown" matches feeds without a language.
   *
   * @param string[] $tokens lowercase language codes
   */
  private static function hasLanguage(?string $language, array $tokens): bool {
    $language = strtolower(trim((string)$language));
    if ($language === '') {
      return in_array('unknown', $tokens, true);
    }
    $primary = explode('-', $language)[0];
    return in_array($language, $tokens, true) || in_array($primary, $tokens, true);
  }

  /** @return string[] */
  private static function tokens(string $list): array {
    return array_values(array_filter(array_map(fn($t) => strtolower(trim($t)), explode(',', $list)), 'strlen'));
  }

  private static function validate(array $params): array {
    $str = fn(string $name, int $maxLength = 200) => mb_substr(trim((string)($params[$name] ?? '')), 0, $maxLength);
    $flag = fn(string $name, bool $default) => isset($params[$name]) && $params[$name] !== ''
      ? !in_array(strtolower((string)$params[$name]), array('0', 'false', 'no', 'off'), true)
      : $default;

    $p = array(
      'by'     => $str('by') ?: 'term',
      'q'      => $str('q'),
      'cat'    => $str('cat'),
      'notcat' => $str('notcat'),
      'lang'   => $str('lang'),
      'medium' => strtolower($str('medium')),
      'tag'    => $str('tag'),
      'val'    => strtolower($str('val')),
      'clean'  => $flag('clean', false),
      'alive'  => $flag('alive', true),
    );

    if (!in_array($p['by'], self::MODES, true)) {
      throw new InvalidArgumentException('Unknown search mode');
    }
    if (in_array($p['by'], array('term', 'title', 'person'), true) && $p['q'] === '') {
      throw new InvalidArgumentException('Enter something to search for');
    }
    if ($p['by'] === 'category' && $p['cat'] === '') {
      throw new InvalidArgumentException('Choose a category');
    }
    if ($p['medium'] !== '' && !in_array($p['medium'], self::MEDIUMS, true)) {
      throw new InvalidArgumentException('Unknown medium');
    }
    if ($p['by'] === 'medium' && $p['medium'] === '') {
      throw new InvalidArgumentException('Choose a medium');
    }
    if ($p['by'] === 'tag' && !in_array($p['tag'], self::TAGS, true)) {
      throw new InvalidArgumentException('Choose a tag');
    }
    if ($p['val'] !== '') {
      if (!in_array($p['val'], self::VALUE_TYPES, true)) {
        throw new InvalidArgumentException('Unknown value type');
      }
      // Only these calls know about value blocks; other results do not say
      if (!in_array($p['by'], array('term', 'title'), true)) {
        throw new InvalidArgumentException('The value filter only works with text or title search');
      }
    }
    foreach (array('cat', 'notcat', 'lang') as $list) {
      if ($p[$list] !== '' && !preg_match('/^[\p{L}\p{N} &,_-]+$/u', $p[$list])) {
        throw new InvalidArgumentException("Invalid $list");
      }
    }
    return $p;
  }
}
