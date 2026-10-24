<?php

/**
 * One item of a feed, as read from the feed itself.
 * See specifications/episodes.md, "Episode".
 */
final readonly class Episode implements JsonSerializable {

  /**
   * @param array<string, array[]> $extensions namespaced elements, e.g. "podcast:transcript" => [node, …],
   *        each node ['attributes' => [...], 'text' => '…', 'children' => [name => [node, …]]], empty parts left out
   */
  public function __construct(
    public string $id,
    public string $title,
    public ?string $guid = null,
    public ?string $link = null,
    public ?DateTimeImmutable $published = null,
    public ?string $description = null,
    public ?string $enclosureUrl = null,
    public ?string $enclosureType = null,
    public ?int $enclosureLength = null,
    public ?int $duration = null,
    public ?int $season = null,
    public ?int $episode = null,
    public ?string $episodeType = null,
    public ?bool $explicit = null,
    public ?string $image = null,
    public array $extensions = array(),
  ) {
    if ($id === '') {
      throw new InvalidArgumentException('Episode id is required');
    }
  }

  /**
   * From the cache, the inverse of jsonSerialize().
   */
  public static function fromArray(array $data): self {
    $data['published'] = isset($data['published']) ? new DateTimeImmutable('@' . (int)$data['published']) : null;
    return new self(...array_intersect_key($data, get_class_vars(self::class)));
  }

  public function jsonSerialize(): array {
    $data = get_object_vars($this);
    // Unix seconds, like Feed
    $data['published'] = $this->published?->getTimestamp();
    return array_filter($data, fn($v) => $v !== null && $v !== array());
  }
}
