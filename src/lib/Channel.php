<?php

/**
 * The podcast itself, as described by the <channel> element of its feed.
 */
final readonly class Channel implements JsonSerializable {

  /**
   * @param array<int, string> $categories PodcastIndex category id => name
   */
  public function __construct(
    public string $title,
    public ?string $author = null,
    public ?string $ownerName = null,
    public ?string $description = null,
    public ?string $link = null,
    public ?string $image = null,
    public ?string $artwork = null,
    public ?string $language = null,
    public array $categories = array(),
    public bool $explicit = false,
    public ?string $medium = null,
  ) {
    foreach (array('link' => $link, 'image' => $image, 'artwork' => $artwork) as $name => $url) {
      if ($url !== null && !self::isHttpUrl($url)) {
        throw new InvalidArgumentException("Channel $name is not an http(s) URL: $url");
      }
    }
    foreach ($categories as $id => $category) {
      if (!is_int($id) || !is_string($category)) {
        throw new InvalidArgumentException('Channel categories must be int => string');
      }
    }
  }

  public static function fromPodcastIndex(array $data): self {
    return new self(
      title:       trim((string)($data['title'] ?? '')),
      author:      self::str($data['author'] ?? null),
      ownerName:   self::str($data['ownerName'] ?? null),
      description: self::str($data['description'] ?? null),
      link:        self::url($data['link'] ?? null),
      image:       self::url($data['image'] ?? null),
      artwork:     self::url($data['artwork'] ?? null),
      language:    self::str($data['language'] ?? null),
      categories:  array_map('strval', is_array($data['categories'] ?? null) ? $data['categories'] : array()),
      explicit:    (bool)($data['explicit'] ?? false),
      medium:      self::str($data['medium'] ?? null),
    );
  }

  /**
   * Best available artwork, preferring the full size image.
   */
  public function imageUrl(): ?string {
    return $this->artwork ?? $this->image;
  }

  public function jsonSerialize(): array {
    return get_object_vars($this);
  }

  private static function str(mixed $value): ?string {
    $value = trim((string)$value);
    return $value === '' ? null : $value;
  }

  // PodcastIndex passes through whatever the feed contains; drop junk rather than fail
  private static function url(mixed $value): ?string {
    $value = self::str($value);
    return ($value !== null && self::isHttpUrl($value)) ? $value : null;
  }

  private static function isHttpUrl(string $url): bool {
    return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1;
  }
}
