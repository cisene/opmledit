<?php

class FetchException extends RuntimeException {}

/**
 * Fetches a URL a user gave without letting it reach the server's own network.
 * See specifications/security.md, "Fetching URLs".
 */
final class UrlFetcher {

  private const MAX_REDIRECTS = 3;
  private const MAX_URL_LENGTH = 2048;

  // Not public: IPv4 CIDR => prefix length
  private const BLOCKED_V4 = array(
    '0.0.0.0' => 8, '10.0.0.0' => 8, '100.64.0.0' => 10, '127.0.0.0' => 8, '169.254.0.0' => 16,
    '172.16.0.0' => 12, '192.0.0.0' => 24, '192.0.2.0' => 24, '192.168.0.0' => 16, '198.18.0.0' => 15,
    '198.51.100.0' => 24, '203.0.113.0' => 24, '224.0.0.0' => 4, '240.0.0.0' => 4,
  );

  public function __construct(
    private string $userAgent,
    private int $maxBytes,
    private int $timeout,
  ) {}

  /**
   * @param array<string, string> $headers extra request headers, e.g. If-None-Match
   * @return array{status: int, body: string, url: string, headers: array<string, string>}
   *         headers lowercased, the last value of each; url is where the content came from
   * @throws FetchException with a message for the user
   */
  public function fetch(string $url, array $headers = array()): array {
    $deadline = microtime(true) + $this->timeout;
    for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
      $res = $this->fetchOnce($url, $headers, $deadline);
      $location = $res['headers']['location'] ?? null;
      if ($res['status'] < 300 || $res['status'] >= 400 || $res['status'] === 304 || $location === null) {
        return $res + array('url' => $url);
      }
      $url = self::resolve($url, $location);
    }
    throw new FetchException('Too many redirects');
  }

  private function fetchOnce(string $url, array $headers, float $deadline): array {
    [$host, $port, $ip] = $this->check($url);
    $remaining = (int)ceil($deadline - microtime(true));
    if ($remaining <= 0) {
      throw new FetchException('Timed out');
    }

    $body = '';
    $responseHeaders = array();
    $tooLarge = false;
    $ch = curl_init($url);
    $lines = array('User-Agent: ' . $this->userAgent, 'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.5');
    foreach ($headers as $name => $value) {
      $lines[] = "$name: $value";
    }
    curl_setopt_array($ch, array(
      CURLOPT_RESOLVE         => array("$host:$port:" . (str_contains($ip, ':') ? "[$ip]" : $ip)),
      CURLOPT_FOLLOWLOCATION  => false,
      CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_HTTPHEADER      => $lines,
      CURLOPT_ENCODING        => '',   // accept gzip and deflate; the size limit counts decompressed bytes
      CURLOPT_CONNECTTIMEOUT  => min(10, $remaining),
      CURLOPT_TIMEOUT         => $remaining,
      CURLOPT_HEADERFUNCTION  => function ($ch, $line) use (&$responseHeaders) {
        if (preg_match('/^HTTP\//', $line)) {
          $responseHeaders = array();
        } elseif (str_contains($line, ':')) {
          [$name, $value] = explode(':', $line, 2);
          $responseHeaders[strtolower(trim($name))] = trim($value);
        }
        return strlen($line);
      },
      CURLOPT_WRITEFUNCTION   => function ($ch, $chunk) use (&$body, &$tooLarge) {
        if (strlen($body) + strlen($chunk) > $this->maxBytes) {
          $tooLarge = true;
          return 0;   // aborts the transfer
        }
        $body .= $chunk;
        return strlen($chunk);
      },
    ));
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);

    if ($tooLarge) {
      throw new FetchException('Larger than ' . round($this->maxBytes / 1048576, 1) . ' MB');
    }
    if ($ok === false) {
      throw new FetchException($errno === CURLE_OPERATION_TIMEDOUT ? 'Timed out' : "Could not fetch: $error");
    }
    return array('status' => $status, 'body' => $body, 'headers' => $responseHeaders);
  }

  /**
   * @return array{0: string, 1: int, 2: string} host, port and the checked address to connect to
   */
  private function check(string $url): array {
    if (strlen($url) > self::MAX_URL_LENGTH) {
      throw new FetchException('URL is too long');
    }
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower(trim($parts['host'] ?? '', '[]'));
    if (!in_array($scheme, array('http', 'https'), true) || $host === '') {
      throw new FetchException('Only http and https URLs can be fetched');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      throw new FetchException('URLs with a user name or password are not fetched');
    }
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, array(80, 443), true)) {
      throw new FetchException('Only ports 80 and 443 can be used');
    }

    $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : self::lookup($host);
    if (!$ips) {
      throw new FetchException("Host not found: $host");
    }
    foreach ($ips as $ip) {
      if (!self::isPublic($ip)) {
        throw new FetchException('The address of this host is not public');
      }
    }
    return array($host, $port, $ips[0]);
  }

  /** @return string[] */
  private static function lookup(string $host): array {
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    $ips = array();
    foreach ($records ?: array() as $record) {
      $ip = $record['ip'] ?? $record['ipv6'] ?? null;
      if ($ip !== null) {
        $ips[] = $ip;
      }
    }
    return $ips;
  }

  private static function isPublic(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      return false;
    }
    $bin = inet_pton($ip);
    if (strlen($bin) === 16) {
      // IPv4-mapped (::ffff:0:0/96) and NAT64 (64:ff9b::/96) carry an IPv4 address
      $prefix = substr($bin, 0, 12);
      if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
        return self::isPublic(inet_ntop(substr($bin, 12)));
      }
      $first = ord($bin[0]);
      // Unique local fc00::/7, link-local fe80::/10, multicast ff00::/8, documentation 2001:db8::/32
      return ($first & 0xfe) !== 0xfc && $first !== 0xff
        && !($first === 0xfe && (ord($bin[1]) & 0xc0) === 0x80)
        && substr($bin, 0, 4) !== "\x20\x01\x0d\xb8";
    }
    $long = ip2long($ip);
    foreach (self::BLOCKED_V4 as $net => $bits) {
      $mask = -1 << (32 - $bits);
      if (($long & $mask) === (ip2long($net) & $mask)) {
        return false;
      }
    }
    return true;
  }

  private static function resolve(string $base, string $location): string {
    if (preg_match('#^https?://#i', $location)) {
      return $location;
    }
    $b = parse_url($base);
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($location, '//')) {
      return $b['scheme'] . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
      return $origin . $location;
    }
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $origin . $dir . $location;
  }
}
