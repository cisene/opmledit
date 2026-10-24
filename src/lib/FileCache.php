<?php

/**
 * JSON files under data/cache, one per entry, e.g. "feeds/guid/<guid>".
 * The cache is optional: when the directory cannot be written, nothing is cached.
 */
final class FileCache {

  public function __construct(private string $dir) {
    $this->dir = rtrim($dir, '/');
  }

  /**
   * @param ?int $maxAge seconds, or null for an entry of any age
   * @return ?array ['value' => …] when there is an entry younger than $maxAge seconds, else null
   */
  public function get(string $name, ?int $maxAge = null): ?array {
    $file = $this->file($name);
    if (!is_file($file) || ($maxAge !== null && filemtime($file) <= time() - $maxAge)) {
      return null;
    }
    $entry = json_decode((string)@file_get_contents($file), true);
    return is_array($entry) && array_key_exists('value', $entry) ? $entry : null;
  }

  public function set(string $name, mixed $value): void {
    $file = $this->file($name);
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
      return;
    }
    $tmp = $file . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode(array('value' => $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
      @chmod($tmp, 0600);
      @rename($tmp, $file);
    }
  }

  public function remember(string $name, int $maxAge, callable $fetch): mixed {
    $entry = $this->get($name, $maxAge);
    if ($entry !== null) {
      return $entry['value'];
    }
    $value = $fetch();
    $this->set($name, $value);
    return $value;
  }

  private function file(string $name): string {
    if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#', $name)) {
      throw new InvalidArgumentException('Invalid cache name');
    }
    return $this->dir . '/' . $name . '.json';
  }
}
