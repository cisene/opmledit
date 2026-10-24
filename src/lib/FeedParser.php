<?php

require_once __DIR__ . '/Episode.php';
require_once __DIR__ . '/XmlRepair.php';

/**
 * Reads the channel and episodes from an RSS 2.0, RSS 1.0 or Atom document.
 * Elements of other namespaces are kept generically under a well-known
 * prefix, so every Podcasting 2.0 tag is available, also ones added later.
 * See specifications/episodes.md.
 */
final class FeedParser {

  // Namespace URI (lowercase, no trailing slash) => the prefix used in output, whatever the feed uses
  public const NAMESPACES = array(
    'https://podcastindex.org/namespace/1.0'                               => 'podcast',
    'http://podcastindex.org/namespace/1.0'                                => 'podcast',
    'https://github.com/podcastindex-org/podcast-namespace/blob/main/docs/1.0.md' => 'podcast',
    'http://www.itunes.com/dtds/podcast-1.0.dtd'                          => 'itunes',
    'http://www.itunes.com/dtds/podcast-1.0.dtd/'                         => 'itunes',
    'http://www.google.com/schemas/play-podcasts/1.0'                     => 'googleplay',
    'http://search.yahoo.com/mrss'                                        => 'media',
    'http://purl.org/rss/1.0/modules/content'                             => 'content',
    'http://purl.org/dc/elements/1.1'                                     => 'dc',
    'http://purl.org/dc/terms'                                            => 'dcterms',
    'http://www.w3.org/2005/atom'                                         => 'atom',
    'http://purl.org/rss/1.0/modules/syndication'                         => 'sy',
    'http://podlove.org/simple-chapters'                                  => 'psc',
    'http://www.spotify.com/ns/rss'                                       => 'spotify',
    'http://www.rawvoice.com/rawvoicerssmodule'                           => 'rawvoice',
    'http://www.georss.org/georss'                                        => 'georss',
    'http://purl.org/rss/1.0'                                             => '',
    'http://www.w3.org/1999/02/22-rdf-syntax-ns#'                         => 'rdf',
  );

  private const DESCRIPTION_LENGTH = 1000;
  private const TEXT_LENGTH = 1000;
  // Repeated in every item and already read into description
  private const SKIP = array('content:encoded', 'itunes:summary');

  /**
   * @return array{channel: array, episodes: Episode[], episodeCount: int}
   *         episodeCount is the number of items in the feed, episodes at most $maxEpisodes of them
   * @throws InvalidArgumentException when the document is not a feed
   */
  public static function parse(DOMDocument $doc, int $maxEpisodes): array {
    $root = $doc->documentElement;
    $rootName = strtolower($root->localName);
    if (!in_array($rootName, array('rss', 'feed', 'rdf'), true)) {
      throw new InvalidArgumentException('This is not an RSS or Atom feed');
    }
    $atom = $rootName === 'feed';
    $channel = $atom ? $root : (self::child($root, 'channel') ?? $root);

    $items = array();
    foreach (($rootName === 'rdf' ? $root : $channel)->childNodes as $node) {
      if ($node instanceof DOMElement && self::name($node, $atom) === ($atom ? 'entry' : 'item')) {
        $items[] = $node;
      }
    }

    $episodes = array();
    foreach (array_slice($items, 0, $maxEpisodes) as $i => $item) {
      $episodes[] = $atom ? self::atomEntry($item, $i) : self::rssItem($item, $i);
    }
    return array('channel' => self::channel($channel, $atom), 'episodes' => $episodes, 'episodeCount' => count($items));
  }

  private static function channel(DOMElement $el, bool $atom): array {
    $image = self::attr($el, 'itunes:image', 'href') ?? self::attr($el, 'podcast:image', 'href')
      ?? self::text(self::child(self::child($el, 'image') ?? $el, 'url'))
      ?? ($atom ? self::text(self::child($el, 'logo')) ?? self::text(self::child($el, 'icon')) : null);
    $channel = array(
      'title'       => self::text(self::child($el, 'title')) ?? '',
      'link'        => self::url($atom ? self::atomLink($el, 'alternate') : self::text(self::child($el, 'link'))),
      'description' => self::plain(self::text(self::child($el, $atom ? 'subtitle' : 'description')) ?? self::text(self::child($el, 'itunes:summary'))),
      'language'    => self::text(self::child($el, 'language')) ?? ($el->getAttribute('xml:lang') ?: null),
      'author'      => self::text(self::child($el, 'itunes:author')) ?? self::text(self::child(self::child($el, 'author') ?? $el, 'name')) ?? self::text(self::child($el, 'managingEditor')),
      'image'       => self::url($image),
      'explicit'    => self::bool(self::text(self::child($el, 'itunes:explicit'))),
      'podcastGuid' => self::text(self::child($el, 'podcast:guid')),
      'medium'      => self::text(self::child($el, 'podcast:medium')),
      'newFeedUrl'  => self::url(self::text(self::child($el, 'itunes:new-feed-url'))),
      'extensions'  => self::extensions($el, $atom, array($atom ? 'entry' : 'item')),
    );
    return array_filter($channel, fn($v) => $v !== null && $v !== array());
  }

  private static function rssItem(DOMElement $el, int $index): Episode {
    $enclosure = self::child($el, 'enclosure');
    $guid = self::text(self::child($el, 'guid'));
    $title = self::text(self::child($el, 'title')) ?? self::text(self::child($el, 'itunes:title')) ?? '';
    $link = self::url(self::text(self::child($el, 'link')));
    $published = self::date(self::text(self::child($el, 'pubDate')) ?? self::text(self::child($el, 'dc:date')));
    $enclosureUrl = self::url($enclosure?->getAttribute('url'));
    $description = self::text(self::child($el, 'description')) ?? self::text(self::child($el, 'itunes:summary'))
      ?? self::text(self::child($el, 'content:encoded'));
    return self::episode($el, false, $index, $guid, $title, $link, $published, $description, $enclosureUrl,
      $enclosure?->getAttribute('type'), $enclosure?->getAttribute('length'));
  }

  private static function atomEntry(DOMElement $el, int $index): Episode {
    $guid = self::text(self::child($el, 'id'));
    $enclosure = null;
    foreach ($el->childNodes as $node) {
      if ($node instanceof DOMElement && self::name($node, true) === 'link' && $node->getAttribute('rel') === 'enclosure') {
        $enclosure = $node;
        break;
      }
    }
    $published = self::date(self::text(self::child($el, 'published')) ?? self::text(self::child($el, 'updated')));
    $description = self::text(self::child($el, 'summary')) ?? self::text(self::child($el, 'content'));
    return self::episode($el, true, $index, $guid, self::text(self::child($el, 'title')) ?? '',
      self::url(self::atomLink($el, 'alternate')), $published, $description,
      self::url($enclosure?->getAttribute('href')), $enclosure?->getAttribute('type'), $enclosure?->getAttribute('length'));
  }

  private static function episode(DOMElement $el, bool $atom, int $index, ?string $guid, string $title, ?string $link,
      ?DateTimeImmutable $published, ?string $description, ?string $enclosureUrl, ?string $type, ?string $length): Episode {
    $image = self::attr($el, 'itunes:image', 'href') ?? self::attr($el, 'podcast:image', 'href')
      ?? self::attr($el, 'media:thumbnail', 'url');
    return new Episode(
      // Stable across fetches, so the page can keep track of an episode
      id:              $guid ?? $enclosureUrl ?? $link ?? sha1($title . '|' . $published?->getTimestamp() . '|' . $index),
      title:           $title,
      guid:            $guid,
      link:            $link,
      published:       $published,
      description:     self::plain($description),
      enclosureUrl:    $enclosureUrl,
      enclosureType:   self::str($type),
      enclosureLength: self::int($length),
      duration:        self::duration(self::text(self::child($el, 'itunes:duration'))),
      season:          self::int(self::text(self::child($el, 'podcast:season')) ?? self::text(self::child($el, 'itunes:season'))),
      episode:         self::int(self::text(self::child($el, 'podcast:episode')) ?? self::text(self::child($el, 'itunes:episode'))),
      episodeType:     self::text(self::child($el, 'itunes:episodeType')),
      explicit:        self::bool(self::text(self::child($el, 'itunes:explicit'))),
      image:           self::url($image),
      extensions:      self::extensions($el, $atom),
    );
  }

  /**
   * Every namespaced child element, keyed by prefix:name, in document order.
   *
   * @param string[] $skip core names not to descend into (items of the channel)
   */
  private static function extensions(DOMElement $el, bool $atom, array $skip = array()): array {
    $out = array();
    foreach ($el->childNodes as $node) {
      if (!$node instanceof DOMElement) {
        continue;
      }
      $name = self::name($node, $atom);
      if (!str_contains($name, ':') || in_array($name, self::SKIP, true) || in_array($name, $skip, true)) {
        continue;
      }
      $out[$name][] = self::node($node, $atom);
    }
    return $out;
  }

  private static function node(DOMElement $el, bool $atom): array {
    $node = array();
    foreach ($el->attributes as $attr) {
      $node['attributes'][$attr->nodeName] = XmlRepair::fixMojibake(trim($attr->value));
    }
    $children = array();
    foreach ($el->childNodes as $child) {
      if ($child instanceof DOMElement) {
        $children[self::name($child, $atom)][] = self::node($child, $atom);
      }
    }
    if ($children) {
      $node['children'] = $children;
    } else {
      $text = self::text($el);
      if ($text !== null) {
        $node['text'] = mb_substr($text, 0, self::TEXT_LENGTH);
      }
    }
    return $node;
  }

  /**
   * prefix:local with the well-known prefix for the namespace; core RSS (and Atom in an
   * Atom feed) without a prefix. An undeclared prefix, kept by the recovering parser, is used as is.
   */
  private static function name(DOMElement $el, bool $atom): string {
    $uri = rtrim(strtolower((string)$el->namespaceURI), '/');
    if ($uri === '') {
      return $el->nodeName;
    }
    $prefix = self::NAMESPACES[$uri] ?? self::NAMESPACES[$uri . '/'] ?? $el->prefix;
    if ($prefix === 'atom' && $atom) {
      $prefix = '';
    }
    return $prefix === '' || $prefix === null ? $el->localName : "$prefix:{$el->localName}";
  }

  private static function child(?DOMElement $el, string $name): ?DOMElement {
    if ($el === null) {
      return null;
    }
    $atom = $el->ownerDocument->documentElement->localName === 'feed';
    foreach ($el->childNodes as $node) {
      if ($node instanceof DOMElement && strcasecmp(self::name($node, $atom), $name) === 0) {
        return $node;
      }
    }
    return null;
  }

  private static function attr(DOMElement $el, string $name, string $attribute): ?string {
    return self::str(self::child($el, $name)?->getAttribute($attribute));
  }

  private static function atomLink(DOMElement $el, string $rel): ?string {
    foreach ($el->childNodes as $node) {
      if ($node instanceof DOMElement && $node->localName === 'link' && ($node->getAttribute('rel') ?: 'alternate') === $rel) {
        return $node->getAttribute('href');
      }
    }
    return null;
  }

  private static function text(?DOMElement $el): ?string {
    return $el === null ? null : self::str(XmlRepair::fixMojibake($el->textContent));
  }

  private static function str(?string $value): ?string {
    $value = trim(preg_replace('/\s+/u', ' ', (string)$value));
    return $value === '' ? null : $value;
  }

  /**
   * HTML to plain text, shortened.
   */
  private static function plain(?string $html): ?string {
    if ($html === null) {
      return null;
    }
    $text = html_entity_decode(strip_tags(preg_replace('/<(br|p|div|li)\b/i', ' <$1', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = self::str($text);
    return $text === null || mb_strlen($text) <= self::DESCRIPTION_LENGTH ? $text : mb_substr($text, 0, self::DESCRIPTION_LENGTH - 1) . '…';
  }

  private static function url(?string $url): ?string {
    $url = str_replace(' ', '%20', trim((string)$url));
    return $url !== '' && preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
  }

  private static function int(?string $value): ?int {
    return $value !== null && preg_match('/^\d{1,12}$/', trim($value)) ? (int)$value : null;
  }

  private static function bool(?string $value): ?bool {
    return match (strtolower((string)$value)) {
      'yes', 'true', 'explicit' => true,
      'no', 'false', 'clean' => false,
      default => null,
    };
  }

  /**
   * RFC 822 as RSS wants, but also what feeds actually contain: ISO 8601,
   * long or misspelt day names, missing time zones.
   */
  private static function date(?string $value): ?DateTimeImmutable {
    if ($value === null) {
      return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
      $ts = strtotime(preg_replace('/^[A-Za-z]+,?\s*/', '', $value));
    }
    return $ts === false ? null : new DateTimeImmutable('@' . $ts);
  }

  /**
   * Seconds from "3723", "62:03", "1:02:03" or "3723.5".
   */
  private static function duration(?string $value): ?int {
    if ($value === null) {
      return null;
    }
    if (preg_match('/^\d+(\.\d+)?$/', $value)) {
      return (int)round((float)$value);
    }
    if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{1,2})(?:\.\d+)?$/', $value, $m)) {
      return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
    }
    return null;
  }
}
