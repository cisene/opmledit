<?php

/**
 * Counts requests per client in fixed windows, one small file per client and
 * limit in data/ratelimit. The address is stored hashed, never in clear.
 * See specifications/auth.md, "Rate limits".
 */
final class RateLimiter {

  // One in this many calls removes counter files whose window has ended
  private const CLEANUP_ODDS = 100;

  public function __construct(private string $dir) {
    $this->dir = rtrim($dir, '/');
  }

  /**
   * Counts one request.
   *
   * @return int 0 when allowed, else seconds until the window ends (for Retry-After)
   */
  public function hit(string $scope, string $client, int $limit, int $window): int {
    if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true)) {
      return 0;   // like the cache: without a writable data directory, nothing is counted
    }
    if (random_int(1, self::CLEANUP_ODDS) === 1) {
      $this->cleanup($window);
    }

    $file = $this->dir . '/' . hash('sha256', $scope . '|' . $client) . '.json';
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
      return 0;
    }
    try {
      flock($fp, LOCK_EX);
      $now = time();
      $counter = json_decode((string)stream_get_contents($fp), true);
      if (!is_array($counter) || (int)($counter['start'] ?? 0) <= $now - $window) {
        $counter = array('start' => $now, 'count' => 0);
      }
      if ($counter['count'] >= $limit) {
        return max(1, $counter['start'] + $window - $now);
      }
      $counter['count']++;
      ftruncate($fp, 0);
      rewind($fp);
      fwrite($fp, json_encode($counter));
      return 0;
    } finally {
      flock($fp, LOCK_UN);
      fclose($fp);
      @chmod($file, 0600);
    }
  }

  /**
   * The client address to count by. IPv6 by /64, since one connection usually gets a whole /64.
   * Only REMOTE_ADDR is used; X-Forwarded-For can be set by anyone.
   */
  public static function client(string $address): string {
    $bin = @inet_pton($address);
    if ($bin !== false && strlen($bin) === 16) {
      return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return $address;
  }

  private function cleanup(int $window): void {
    foreach (glob($this->dir . '/*.json') ?: array() as $file) {
      if (@filemtime($file) < time() - $window) {
        @unlink($file);
      }
    }
  }
}
