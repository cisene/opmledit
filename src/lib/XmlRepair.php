<?php

/**
 * Turns broken XML as found on the web into a parsed document: wrong or mixed
 * encodings, junk around the XML, bad entities, unclosed elements.
 * Every fix is noted in $repairs for the user. See specifications/episodes.md, "Repair".
 */
final class XmlRepair {

  // Root elements of RSS 2.0, RSS 1.0 (RDF) and Atom
  private const ROOTS = 'rss|feed|rdf:RDF|RDF';

  // Elements that only ever hold plain text, so a missing end tag can be put back
  // before the next tag. Elements that may contain HTML are not in the list.
  private const TEXT_ONLY = 'language|pubDate|lastBuildDate|guid|link|ttl|generator|copyright|managingEditor|webMaster|'
    . 'itunes:duration|itunes:episode|itunes:season|itunes:episodeType|itunes:explicit|itunes:author|itunes:type|itunes:block|'
    . 'podcast:guid|podcast:medium|podcast:locked|podcast:season|podcast:episode';

  /**
   * @param ?string $charset from the HTTP Content-Type, used when the XML does not declare one
   * @param string[] $repairs fixes made, appended
   * @throws InvalidArgumentException when there is no feed in the bytes
   */
  public static function feedText(string $bytes, ?string $charset, array &$repairs): string {
    $text = self::fromUtf16($bytes, $repairs);
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
      $text = substr($text, 3);
    }
    $text = self::trimJunk($text, $repairs);
    $text = self::toUtf8($text, $charset, $repairs);

    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text, -1, $count);
    if ($count) {
      $repairs[] = "Removed $count characters not allowed in XML";
    }
    // Never used, and removing it rules out entity expansion
    return preg_replace('/<!DOCTYPE[^>\[]*(\[[^\]]*\])?\s*>/i', '', $text, 1);
  }

  /**
   * Parses strictly first; if that fails, fixes entities and parses in recover mode.
   *
   * @throws InvalidArgumentException when nothing usable could be parsed
   */
  public static function load(string $xml, array &$repairs): DOMDocument {
    $previous = libxml_use_internal_errors(true);
    try {
      $doc = new DOMDocument();
      if ($doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        return $doc;
      }
      $problem = libxml_get_errors()[0] ?? null;
      libxml_clear_errors();

      $xml = self::closeTextElements(self::fixEntities($xml, $repairs), $repairs);
      $doc = new DOMDocument();
      if (!@$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_RECOVER) || $doc->documentElement === null) {
        throw new InvalidArgumentException('The feed is not readable XML');
      }
      if ($problem !== null) {
        $repairs[] = 'Repaired broken XML (first problem on line ' . $problem->line . ': ' . trim($problem->message) . ')';
      }
      return $doc;
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
  }

  /**
   * Text that was UTF-8, then read as Windows-1252 and encoded again ("CafÃ©"): undone.
   */
  public static function fixMojibake(string $text): string {
    if (!preg_match('/[\xC2-\xC3\xC5\xCB\xE2][\x80-\xBF]/', $text)) {
      return $text;
    }
    $bytes = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    // Characters Windows-1252 cannot hold become "?", so a lost one shows in the count
    return $bytes !== $text && mb_check_encoding($bytes, 'UTF-8') && substr_count($bytes, '?') === substr_count($text, '?') ? $bytes : $text;
  }

  private static function fromUtf16(string $bytes, array &$repairs): string {
    $from = match (true) {
      str_starts_with($bytes, "\xFF\xFE"), str_starts_with($bytes, "<\0?\0"), str_starts_with($bytes, "<\0r\0") => 'UTF-16LE',
      str_starts_with($bytes, "\xFE\xFF"), str_starts_with($bytes, "\0<\0?"), str_starts_with($bytes, "\0<\0r") => 'UTF-16BE',
      default => null,
    };
    if ($from === null) {
      return $bytes;
    }
    $repairs[] = "Converted from $from";
    $text = mb_convert_encoding(preg_replace('/^(\xFF\xFE|\xFE\xFF)/', '', $bytes), 'UTF-8', $from);
    // The declaration still says UTF-16, which is no longer true
    return preg_replace('/(<\?xml[^>]*encoding=["\'])UTF-16(LE|BE)?(["\'])/i', '${1}UTF-8$3', $text, 1);
  }

  /**
   * Anything before the XML declaration or root element, and after the root's
   * end tag: whitespace, a stray BOM, PHP notices, an HTML error page footer.
   */
  private static function trimJunk(string $text, array &$repairs): string {
    if (!preg_match('/<\?xml\b|<(' . self::ROOTS . ')[\s>]/i', $text, $m, PREG_OFFSET_CAPTURE)) {
      throw new InvalidArgumentException('This is not an RSS or Atom feed');
    }
    $start = $m[0][1];
    if (trim(substr($text, 0, $start)) !== '') {
      $repairs[] = "Removed $start bytes before the XML";
    }
    $text = substr($text, $start);

    if (preg_match_all('/<\/(' . self::ROOTS . ')\s*>/i', $text, $m, PREG_OFFSET_CAPTURE)) {
      $last = end($m[0]);
      $end = $last[1] + strlen($last[0]);
      $tail = substr($text, $end);
      if (trim($tail) !== '') {
        $repairs[] = 'Removed ' . strlen($tail) . ' bytes after the XML';
      }
      $text = substr($text, 0, $end);
    }
    return $text;
  }

  /**
   * Encoding from the XML declaration, else the HTTP header, else UTF-8.
   * Bytes that are not valid UTF-8 afterwards are read as Windows-1252, one by one,
   * which also repairs files that mix the two.
   */
  private static function toUtf8(string $text, ?string $charset, array &$repairs): string {
    $declared = preg_match('/^<\?xml[^>]*encoding=["\']([A-Za-z0-9._:-]+)["\']/', $text, $m) ? $m[1] : null;
    $encoding = strtoupper($declared ?? $charset ?? 'UTF-8');

    if (!in_array($encoding, array('UTF-8', 'UTF8', 'US-ASCII', 'ASCII'), true)) {
      // Latin-1 labels are nearly always Windows-1252 in practice
      $from = in_array($encoding, array('ISO-8859-1', 'LATIN1', 'ISO_8859-1', 'L1'), true) ? 'Windows-1252' : $encoding;
      if (preg_match('/[\x80-\xFF]/', $text) && mb_check_encoding($text, 'UTF-8')) {
        $repairs[] = "Says $encoding but is UTF-8";
      } elseif (in_array(strtolower($from), array_map('strtolower', mb_list_encodings()), true)) {
        $text = mb_convert_encoding($text, 'UTF-8', $from);
        $repairs[] = "Converted from $encoding";
      } else {
        $repairs[] = "Unknown encoding $encoding, read as UTF-8";
      }
    }

    $count = 0;
    $text = preg_replace_callback(
      '/[\x00-\x7F]+|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|(.)/s',
      function ($m) use (&$count) {
        if (!isset($m[1])) {
          return $m[0];
        }
        $count++;
        return mb_convert_encoding($m[1], 'UTF-8', 'Windows-1252');
      },
      $text,
    );
    if ($count) {
      $repairs[] = "Read $count bytes that were not UTF-8 as Windows-1252";
    }

    if ($declared !== null && strtoupper($declared) !== 'UTF-8') {
      $text = preg_replace('/^(<\?xml[^>]*encoding=["\'])[A-Za-z0-9._:-]+/', '${1}UTF-8', $text, 1);
    }
    return $text;
  }

  /**
   * "<language>en<copyright>" becomes "<language>en</language><copyright>"; without it
   * the recovering parser nests the rest of the feed inside the unclosed element.
   */
  private static function closeTextElements(string $xml, array &$repairs): string {
    $xml = preg_replace_callback(
      '/<(' . self::TEXT_ONLY . ')(\s[^>]*[^\/])?>([^<]*)(?=<(?!\/\1\s*>|!\[CDATA\[))/i',
      fn($m) => $m[0] . '</' . $m[1] . '>',
      $xml, -1, $count,
    );
    if ($count) {
      $repairs[] = "Closed $count unclosed elements";
    }
    return $xml;
  }

  /**
   * Outside CDATA: HTML named entities become characters, a bare & becomes &amp;.
   */
  private static function fixEntities(string $xml, array &$repairs): string {
    $parts = preg_split('/(<!\[CDATA\[.*?\]\]>)/s', $xml, -1, PREG_SPLIT_DELIM_CAPTURE);
    $named = 0;
    $amps = 0;
    foreach ($parts as $i => $part) {
      if ($i % 2 === 1) {
        continue;
      }
      $part = preg_replace_callback('/&([A-Za-z][A-Za-z0-9]*);/', function ($m) use (&$named) {
        if (in_array($m[1], array('amp', 'lt', 'gt', 'quot', 'apos'), true)) {
          return $m[0];
        }
        $char = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($char === $m[0]) {
          return '&amp;' . $m[1] . ';';
        }
        $named++;
        return htmlspecialchars($char, ENT_XML1 | ENT_QUOTES, 'UTF-8');
      }, $part);
      $parts[$i] = preg_replace('/&(?!(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#x[0-9A-Fa-f]+);)/', '&amp;', $part, -1, $count);
      $amps += $count;
    }
    if ($named) {
      $repairs[] = "Replaced $named HTML entities";
    }
    if ($amps) {
      $repairs[] = "Escaped $amps bare & characters";
    }
    return implode('', $parts);
  }
}
