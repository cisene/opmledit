# Settings

opmledit has two configuration files:

| File | Holds | Changed |
|------|-------|---------|
| `src/config.php` | Secrets, site URL, `data_dir` | By hand, when setting up a server |
| `data/config.json` | Limits and cache times, described here | On the [admin page](admin.md#settings), or by hand |

Secrets are kept out of `data/` so that a copy of `data/` alone holds no
secret ([auth.md](auth.md#user-key)).

## `data/config.json`

The format is defined by [config.schema.json](config.schema.json)
(normative). The server reads names, defaults, ranges and descriptions from
that file at run time, so the schema, the checks and the admin page cannot
disagree. Adding a setting means adding it to the schema and to this
document.

```json
{
  "podcastindex_max_results": 1000,
  "cache_seconds": { "search": 14400, "feeds": 3600, "categories": 86400 }
}
```

- A JSON object. Every key is optional: a missing key, or a missing file,
  means the default. The admin page stores only the keys that are set.
- All values are whole numbers within the ranges below.
- The file is read on every request, so a change applies at once.
- An unknown key or a value out of range makes the API answer
  `500 config` with a message naming the key, e.g.
  `data/config.json: Setting max_nodes must be a whole number from 1 to 200000`.
  A typo never silently falls back to a default.
- Written atomically (temp file and rename), mode `0600`.

## Parameters

### PodcastIndex

#### `podcastindex_max_results`

| Default | Range | Unit |
|---------|-------|------|
| 1000 | 1 – 1000 | feeds |

Most feeds one search returns. It is also the `max` asked from PodcastIndex,
which never returns more than 1000. The server's own filters (language,
category, dead feeds) run on what PodcastIndex returned, so a filtered result
can be smaller. When the limit is reached, the page says the result was cut
off. Tag search pages through PodcastIndex until it has this many feeds
([api.md](api.md#podcastindex)).

Lower it if searches are slow or the result lists are too long to work with.

### Cache

`cache_seconds` is an object with one value per kind of PodcastIndex answer:
how many seconds a cached answer is reused before PodcastIndex is asked
again. `0` turns caching off for that kind. Range for each: 0 – 2 592 000
(30 days). See [storage.md](storage.md#cache) for the file layout.

#### `cache_seconds.search`

| Default | Range | Unit |
|---------|-------|------|
| 14400 (4 hours) | 0 – 2592000 | seconds |

Answers to searches in every mode, including each page of a tag search. A
search repeated within this time, with any filters, costs no PodcastIndex
call. New feeds in PodcastIndex show up in searches after at most this long.

#### `cache_seconds.feeds`

| Default | Range | Unit |
|---------|-------|------|
| 3600 (1 hour) | 0 – 2592000 | seconds |

Single feed lookups by podcast guid, feed URL or PodcastIndex id, including
the answer "not known". *Check all feeds* uses the same entries. A feed's
dead or moved status can be this old when checked.

#### `cache_seconds.categories`

| Default | Range | Unit |
|---------|-------|------|
| 86400 (1 day) | 0 – 2592000 | seconds |

The PodcastIndex category list for the category filters. It rarely changes.

### Import

#### `max_upload_bytes`

| Default | Range | Unit |
|---------|-------|------|
| 5242880 (5 MB) | 1024 – 52428800 (50 MB) | bytes |

Largest OPML file accepted for import, as an upload or as a download for
import from URL. A download is counted after decompression and stops when
it goes over ([security.md](security.md#fetching-urls)). Uploads over the
limit are refused with `413`. PHP's own `upload_max_filesize` and
`post_max_size` must be at least this large.

#### `fetch_timeout`

| Default | Range | Unit |
|---------|-------|------|
| 15 | 1 – 120 | seconds |

Longest time an import from URL may take, connecting and downloading
together, including redirects.

#### `max_url_imports_per_hour`

| Default | Range | Unit |
|---------|-------|------|
| 30 | 1 – 1000 | imports per hour |

Imports from URL per signed-in user per hour. Over the limit: `429`.
Signed out, the per-IP limits in [auth.md](auth.md#rate-limits) apply.

### Documents

#### `max_document_bytes`

| Default | Range | Unit |
|---------|-------|------|
| 5242880 (5 MB) | 1024 – 52428800 (50 MB) | bytes |

Largest document, measured as the save request body and as the stored JSON.
Over the limit: `413`.

#### `max_nodes`

| Default | Range | Unit |
|---------|-------|------|
| 20000 | 1 – 200000 | outline nodes |

Most outline elements in a document body, on import and on save.

#### `max_depth`

| Default | Range | Unit |
|---------|-------|------|
| 32 | 2 – 128 | levels |

Deepest nesting of outline elements, on import and on save.

#### `max_documents_per_user`

| Default | Range | Unit |
|---------|-------|------|
| 200 | 1 – 10000 | documents |

Most documents in one user's library. Creating, importing or moving a
browser document into a full library is refused. Lowering it removes
nothing; users over the limit just cannot add more.

#### `retention_days`

| Default | Range | Unit |
|---------|-------|------|
| 90 | 1 – 3650 | days |

A library document's `expires` is set to now plus this many days when it is
created, opened, saved or exported; the purge removes documents past
`expires` ([storage.md](storage.md#lifecycle)). A change applies to each
document the next time its `expires` is set. Documents in the browser
(signed out) never expire.
