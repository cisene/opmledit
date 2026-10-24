# Episodes

When a feed is opened in the editor, its episodes are fetched from the feed
URL on demand, read as tolerantly as OPML is, and kept in the feed's cache
entry. Searching episodes then works on that local copy.

## Flow

`GET api.php?action=episodes&url=<feed url>[&refresh=1]` ([api.md](api.md#episodes))

1. If the feed's cache entry has content younger than
   `cache_seconds.episodes` ([config.md](config.md#cache_secondsepisodes)),
   it is returned as is. `refresh=1` (the **Refresh** button) skips this step.
2. Otherwise the feed is fetched, following [security.md](security.md#fetching-urls),
   with `If-None-Match` and `If-Modified-Since` from the cached copy. A `304`
   keeps the cached episodes and only renews their time.
3. A `200` is repaired ([Repair](#repair)), parsed ([Feed formats](#feed-formats)),
   stored and returned.
4. If the fetch or the parse fails and there is a cached copy, of any age,
   that copy is returned with `stale: true` and the reason in `error`. Only
   without a cached copy is the request answered with an error.

The feed URL is used as given. Redirects are followed (at most 3), and the
address the content came from is returned as `fetchedFrom`, so the editor
can point out a moved feed. The cache stays under the URL that was asked for.

## Repair

Transport and feeds are often broken. Each fix is listed in `repairs`, in
words for the user (e.g. `"Converted from ISO-8859-1"`). A valid UTF-8 feed
has no repairs. In order:

1. **Compression**: gzip and deflate are accepted and undone by the
   transport. The size limit `max_feed_bytes` counts the decompressed bytes.
2. **UTF-16**: detected from the byte order mark or from `<\0?\0` style
   starts, and converted to UTF-8.
3. **Junk around the XML**: a UTF-8 byte order mark, whitespace, PHP
   warnings, an HTML error page footer. Everything before the XML
   declaration or the root element (`rss`, `feed`, `rdf:RDF`) is removed,
   and so is everything after the root's end tag. Input with no such root
   is not a feed (`422 invalid_feed`).
4. **Encoding**: taken from the XML declaration, else the HTTP
   `Content-Type` charset, else UTF-8.
   - A declared ISO-8859-1 is read as Windows-1252, its superset, as
     browsers do.
   - If the declared encoding is not UTF-8 but the bytes are valid UTF-8
     with non-ASCII characters, they are taken as UTF-8 (a common mislabel).
   - Otherwise the text is converted from the declared encoding. An
     encoding PHP does not know is read as UTF-8.
   - Any byte that is still not valid UTF-8 is read as Windows-1252, one by
     one. This also repairs feeds that mix the two.
   - The declaration is rewritten to `encoding="UTF-8"`.
5. **Characters not allowed in XML** (control characters) are removed.
6. **`DOCTYPE`** is removed and never used (no entities, no network).
7. **Strict parse.** If it succeeds, the repair is done.
8. Otherwise, outside CDATA sections:
   - HTML named entities (`&nbsp;`, `&eacute;`) become characters, and
     unknown ones become text (`&amp;bogus;`).
   - A bare `&` becomes `&amp;`.
   - A text-only element left open (`<language>en<copyright>`) is closed
     before the next tag. This only applies to elements that never contain
     markup, such as `language`, `pubDate`, `guid`, `link` and
     `itunes:duration`, and never to `title` or `description`.

   Then the feed is parsed in libxml recover mode, which closes the
   remaining unclosed elements. The first parser error is listed in
   `repairs`.
9. **Mojibake**: each text value and attribute value that is UTF-8 read as
   Windows-1252 and encoded again (`CafÃ©`) is turned back (`Café`).

## Feed formats

| Format | Root | Items |
|--------|------|-------|
| RSS 2.0 (nearly all podcasts) | `rss` | `channel/item` |
| RSS 1.0 | `rdf:RDF` | `item` beside `channel` |
| Atom | `feed` | `entry` |

The first `max_episodes` items are read, in feed order (usually newest
first). `episodeCount` is the number of items in the feed.

## Namespaces

Elements are recognised by namespace URI, not by the prefix a feed happens
to use. Output uses these prefixes:

| Prefix | Namespace URI |
|--------|---------------|
| `podcast` | `https://podcastindex.org/namespace/1.0` (also `http://`, and the old GitHub URL) |
| `itunes` | `http://www.itunes.com/dtds/podcast-1.0.dtd` |
| `googleplay` | `http://www.google.com/schemas/play-podcasts/1.0` |
| `media` | `http://search.yahoo.com/mrss/` |
| `content` | `http://purl.org/rss/1.0/modules/content/` |
| `dc`, `dcterms` | `http://purl.org/dc/elements/1.1/`, `http://purl.org/dc/terms/` |
| `atom` | `http://www.w3.org/2005/Atom` (in RSS feeds) |
| `sy` | `http://purl.org/rss/1.0/modules/syndication/` |
| `psc` | `http://podlove.org/simple-chapters` |
| `spotify` | `http://www.spotify.com/ns/rss` |
| `rawvoice` | `http://www.rawvoice.com/rawvoiceRssModule/` |
| `georss` | `http://www.georss.org/georss` |

Elements of any other namespace keep the feed's own prefix. A prefix used
without a declaration (kept by the recovering parse) is taken as written,
so `itunes:duration` is found even when `xmlns:itunes` is missing.

### Extensions

Every namespaced child element of the channel and of each item is kept in
`extensions`, keyed by `prefix:name`, in document order:

```json
"podcast:value": [
  {
    "attributes": { "type": "lightning", "method": "keysend" },
    "children": {
      "podcast:valueRecipient": [
        { "attributes": { "name": "Host", "address": "02ab…", "split": "90" } },
        { "attributes": { "name": "App", "address": "03cd…", "split": "10", "fee": "true" } }
      ]
    }
  }
]
```

- A node has `attributes`, plus `children` when it has child elements,
  else `text` (at most 1000 characters). Empty parts are left out.
- This keeps the whole [Podcasting 2.0 namespace](https://podcastindex.org/namespace/1.0)
  without code for each tag, also tags added later. Examples:
  - on the channel: `podcast:guid`, `podcast:locked`, `podcast:funding`,
    `podcast:value`, `podcast:person`, `podcast:location`,
    `podcast:medium`, `podcast:podroll`, `podcast:liveItem`,
    `podcast:trailer`, `podcast:license`, `podcast:txt`,
    `podcast:updateFrequency`;
  - on an item: `podcast:transcript`, `podcast:chapters`,
    `podcast:soundbite`, `podcast:person`, `podcast:season`,
    `podcast:episode`, `podcast:alternateEnclosure` (with `podcast:source`
    and `podcast:integrity`), `podcast:value` (with
    `podcast:valueTimeSplit`), `podcast:socialInteract`,
    `podcast:location`, `podcast:license`, `podcast:images`.
- Not kept, because they repeat the description and can be large:
  `content:encoded` and `itunes:summary`.
- Core RSS elements without a namespace, and Atom elements in an Atom feed,
  are not extensions. The ones that matter are read into the fields below.

## Channel

As read from the feed itself. PodcastIndex's view of the feed is separate,
under [lookup](api.md#lookup).

| Field | From |
|-------|------|
| `title` | `title` |
| `link` | `link`; Atom `link rel="alternate"` |
| `description` | `description`, else `itunes:summary`; Atom `subtitle`. Plain text, at most 1000 characters |
| `language` | `language`; Atom `xml:lang` |
| `author` | `itunes:author`, else `author/name`, else `managingEditor` |
| `image` | `itunes:image@href`, else `podcast:image@href`, else `image/url`; Atom `logo` or `icon` |
| `explicit` | `itunes:explicit` (`true`, `yes`, `explicit` / `false`, `no`, `clean`) |
| `podcastGuid` | `podcast:guid` |
| `medium` | `podcast:medium` |
| `newFeedUrl` | `itunes:new-feed-url` |
| `extensions` | see [Extensions](#extensions) |

Fields without a value are left out.

## Episode

One object per item, `src/lib/Episode.php`. Fields without a value are
left out.

| Field | Type | From |
|-------|------|------|
| `id` | string | `guid`, else the enclosure URL, else `link`, else a hash of title, date and position. Stable across fetches |
| `title` | string | `title`, else `itunes:title`; may be empty |
| `guid` | string | `guid`; Atom `id` |
| `link` | URL | `link`; Atom `link rel="alternate"` |
| `published` | Unix seconds | `pubDate`, else `dc:date`; Atom `published`, else `updated`. Read leniently (RFC 822, ISO 8601, misspelt day names) |
| `description` | string | `description`, else `itunes:summary`, else `content:encoded`; Atom `summary`, else `content`. HTML removed, entities decoded, at most 1000 characters |
| `enclosureUrl`, `enclosureType`, `enclosureLength` | URL, string, int | `enclosure`; Atom `link rel="enclosure"`. Spaces in the URL become `%20` |
| `duration` | seconds | `itunes:duration`: `3723`, `62:03`, `1:02:03` |
| `season` | int | `podcast:season`, else `itunes:season` |
| `episode` | int | `podcast:episode`, else `itunes:episode` |
| `episodeType` | string | `itunes:episodeType` (`full`, `trailer`, `bonus`) |
| `explicit` | bool | `itunes:explicit` |
| `image` | URL | `itunes:image@href`, else `podcast:image@href`, else `media:thumbnail@url` |
| `extensions` | object | see [Extensions](#extensions) |

URLs that are not `http` or `https` are dropped.

## Cache

The content is part of the feed's cache entry, next to its PodcastIndex
record ([storage.md](storage.md#cache)):

```json
{
  "url": "https://feeds.example.org/show.xml",
  "podcastindex": { "at": 1793000000, "record": { … } },
  "content": {
    "at": 1793000000,
    "fetchedFrom": "https://feeds.example.org/show.xml",
    "etag": "W/\"5f3a…\"",
    "lastModified": "Sat, 24 Oct 2026 10:00:00 GMT",
    "repairs": [],
    "channel": { … },
    "episodes": [ <Episode>, … ],
    "episodeCount": 209
  }
}
```

The episodes of every feed that has been opened are in the cache, so
looking through them needs no network. The editor filters the episodes it
has by title and description without a new request.

## Editor

See [editor.md](editor.md#episodes).
