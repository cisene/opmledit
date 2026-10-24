<?php

/**
 * Settings that get tweaked: limits and cache times, read from data/config.json
 * on every request and edited on the admin page. Secrets, the site URL and
 * data_dir stay in config.php.
 *
 * Names, defaults, ranges and descriptions come from
 * specifications/config.schema.json; see specifications/config.md.
 */
final class Config {

  private const SCHEMA = __DIR__ . '/../../specifications/config.schema.json';

  /**
   * @param array $config from config.php
   * @return array $config with every setting, from data/config.json or the default
   * @throws InvalidArgumentException when data/config.json is broken, naming the problem
   */
  public static function load(array $config): array {
    $config['data_dir'] = rtrim($config['data_dir'] ?? (__DIR__ . '/../../data'), '/');
    try {
      return self::nest(self::resolve(self::flatten(self::read($config['data_dir'])))) + $config;
    } catch (InvalidArgumentException $e) {
      throw new InvalidArgumentException('data/config.json: ' . $e->getMessage());
    }
  }

  /**
   * Every setting for the admin page, nested ones with dotted keys.
   *
   * @return array<int, array{key: string, description: string, default: int, min: int, max: int, value: ?int}>
   */
  public static function describe(string $dataDir): array {
    $stored = self::flatten(self::read($dataDir));
    $list = array();
    foreach (self::definitions() as $key => $def) {
      $list[] = array('key' => $key) + $def + array('value' => $stored[$key] ?? null);
    }
    return $list;
  }

  /**
   * Validates and stores settings given with dotted keys; null or missing means the default.
   *
   * @param array<string, ?int> $values
   * @throws InvalidArgumentException naming the first invalid setting
   * @throws RuntimeException when the file cannot be written
   */
  public static function save(string $dataDir, array $values): void {
    $values = array_filter($values, fn($v) => $v !== null);
    self::resolve($values);
    $file = $dataDir . '/config.json';
    $tmp = $file . '.' . bin2hex(random_bytes(4));
    $json = json_encode((object)self::nest($values), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents($tmp, $json) === false || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
      @unlink($tmp);
      throw new RuntimeException('Could not write data/config.json');
    }
  }

  /**
   * The key of the setting an InvalidArgumentException from save() is about.
   */
  public static function keyOf(InvalidArgumentException $e): ?string {
    return preg_match('/^Setting (\S+)/', $e->getMessage(), $m) ? $m[1] : null;
  }

  /**
   * The settings as stored: only the keys that are set, not the defaults.
   */
  private static function read(string $dataDir): array {
    $file = $dataDir . '/config.json';
    if (!is_file($file)) {
      return array();
    }
    $settings = json_decode((string)file_get_contents($file), true);
    if (!is_array($settings) || array_is_list($settings) && $settings) {
      throw new InvalidArgumentException('not a JSON object');
    }
    return $settings;
  }

  /**
   * @param array<string, mixed> $values dotted keys
   * @return array<string, int> every setting, dotted keys
   */
  private static function resolve(array $values): array {
    $defs = self::definitions();
    $unknown = array_diff_key($values, $defs);
    if ($unknown) {
      $key = (string)array_key_first($unknown);
      $group = preg_grep('/^' . preg_quote($key, '/') . '\./', array_keys($defs));
      throw new InvalidArgumentException("Setting $key " . ($group ? 'must be an object' : 'does not exist'));
    }
    $resolved = array();
    foreach ($defs as $key => $def) {
      $value = $values[$key] ?? $def['default'];
      if (!is_int($value) || $value < $def['min'] || $value > $def['max']) {
        throw new InvalidArgumentException("Setting $key must be a whole number from {$def['min']} to {$def['max']}");
      }
      $resolved[$key] = $value;
    }
    return $resolved;
  }

  /**
   * @return array<string, array{description: string, default: int, min: int, max: int}> by dotted key
   */
  private static function definitions(?array $properties = null, string $prefix = ''): array {
    if ($properties === null) {
      $schema = json_decode((string)@file_get_contents(self::SCHEMA), true);
      if (!is_array($schema['properties'] ?? null)) {
        throw new RuntimeException('specifications/config.schema.json is missing or broken');
      }
      $properties = $schema['properties'];
    }
    $defs = array();
    foreach ($properties as $name => $prop) {
      if (($prop['type'] ?? '') === 'object') {
        $defs += self::definitions($prop['properties'], "$prefix$name.");
      } else {
        $defs["$prefix$name"] = array(
          'description' => (string)$prop['description'],
          'default'     => (int)$prop['default'],
          'min'         => (int)$prop['minimum'],
          'max'         => (int)$prop['maximum'],
        );
      }
    }
    return $defs;
  }

  /**
   * {"cache_seconds": {"search": 1}} to {"cache_seconds.search": 1}. A nested
   * value that is not an object is kept under its own key so resolve() names it.
   */
  private static function flatten(array $settings, string $prefix = ''): array {
    $flat = array();
    foreach ($settings as $name => $value) {
      if (is_array($value) && ($value === array() || !array_is_list($value))) {
        $flat += self::flatten($value, "$prefix$name.");
      } else {
        $flat["$prefix$name"] = $value;
      }
    }
    return $flat;
  }

  private static function nest(array $flat): array {
    $nested = array();
    foreach ($flat as $key => $value) {
      $ref = &$nested;
      foreach (explode('.', $key) as $part) {
        $ref = &$ref[$part];
      }
      $ref = $value;
      unset($ref);
    }
    return $nested;
  }
}
