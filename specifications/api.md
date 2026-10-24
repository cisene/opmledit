# HTTP API

All endpoints are served by `src/api.php` with an `action` query parameter
(no URL rewriting needed). Responses are JSON (`application/json; charset=utf-8`)
except export.

## Conventions

- All endpoints except `register`, `login` and the
  [stateless endpoints](#stateless-endpoints) require a signed-in session
  ([auth.md](auth.md)); documents are always read from and written to the
  session user's folder.
- State changing requests (`POST`, `PUT`, `DELETE`) must send an `Origin`
  header matching `site_url` when the browser sends one. With a session they
  must also send the session's CSRF token in an `X-CSRF-Token` header.
  Otherwise `403`.
- Request bodies are JSON (`Content-Type: application/json`) except import.
- Document ids are validated against `^[A-Za-z0-9_-]{22}$` before any file
  access.
- Errors: HTTP status plus `{ "error": "<message>", "code": "<code>" }`.
- Responses carry `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

| Status | Code | When |
|--------|------|------|
| 400 | `bad_request` | Missing or invalid parameter |
| 401 | `unauthenticated` | Not signed in, or wrong email/password |
| 403 | `forbidden` | Missing/invalid CSRF token or foreign `Origin` |
| 403 | `not_allowed` | Email address not allowed by the admin ([admin.md](admin.md)) |
| 429 | `rate_limited` | Too many attempts, with `Retry-After` |
| 404 | `not_found` | No such document for this user (or purged) |
| 409 | `conflict` | Save with an outdated `revision` |
| 413 | `too_large` | Upload or document exceeds limits |
| 422 | `invalid_opml` / `invalid_document` | Unparseable OPML, or document fails validation; `details` lists problems |
| 500 | `config` / `storage` | Server side problem |
| 502 | `upstream` | PodcastIndex request failed |
| 502 | `fetch_failed` | Import from URL: the URL could not be fetched |

## Account

See [auth.md](auth.md#flows) for the rules behind each call.

| Request | Body | Success |
|---------|------|---------|
| `POST action=register` | `{ email, password }` | `201` → `{ "user": <user> }` |
| `POST action=login` | `{ email, password }` | `200` → `{ "user": <user> }` |
| `POST action=logout` | — | `204` |
| `GET action=me` | — | `200` → `{ "user": <user> }`, or `401` |
| `POST action=change_password` | `{ currentPassword, newPassword }` | `200` → `{ "user": <user> }` |
| `POST action=change_email` | `{ currentPassword, newEmail }` | `200` → `{ "user": <user> }` |
| `POST action=delete_account` | `{ password }` | `204` |

`<user>` is `{ "email": "…", "csrfToken": "…", "retentionDays": 90 }`. The
email address comes from the session; the user key is never returned. The client
keeps `csrfToken` in memory and sends it with every state changing request.

The admin page has its own API, see [admin.md](admin.md#admin-api).

## Documents

### List — `GET api.php?action=list`

`200` → `{ "documents": [ { "id", "title", "created", "modified", "expires", "outlineCount" } ] }`,
newest `modified` first. `title` is `opml.head.title`. Listing does not renew
`expires`.

### Create new — `POST api.php?action=create`

Without a body, creates an empty document (see
[opml-model.md](opml-model.md#new-documents)).

With a body `{ "opml": <opml>, "origin": <origin> }`, creates the document from
it. The body is validated like a save. This is how the browser's document is
moved into the library when signing in.

`201` → `{ "document": <document> }`

### Import — `POST api.php?action=import`

Either a file or a URL:

- `multipart/form-data` with field `file` (the OPML file), or
- JSON `{ "url": "https://…" }`: the server downloads the OPML from that URL
  (see [security.md](security.md#fetching-urls)) and imports it the same way.
  The URL is also stored as the document's source URL
  ([opml-model.md](opml-model.md#source-comment)), unless the file already
  has a source comment of its own.

A URL that cannot be fetched gives `502 fetch_failed` with the reason
("not found (404)", "timed out", "address not allowed", …).

`201` → `{ "document": <document>, "version": "1.1", "warnings": [ "…" ] }`

`version` is the OPML version found in the file, or the one inferred if it
had none. Warnings list every repair (see
[opml-model.md](opml-model.md#parsing)).

Warnings report things that were adjusted or dropped, e.g. comments removed,
encoding fixed, unclosed elements closed, missing `text` filled in.

### Open — `GET api.php?action=get&id=<id>`

`200` → `{ "document": <document> }`. Renews `expires` (see
[storage.md](storage.md#lifecycle)).

### Save — `PUT api.php?action=save&id=<id>`

Body: `{ "revision": <int>, "opml": <opml> }` — the revision the client
loaded, and the full `opml` object.

The server ignores any bookkeeping fields from the client, checks the revision,
validates, assigns ids to nodes without one, sets `head.dateModified`, and
writes.

- `200` → `{ "document": <document> }` with the new revision.
- `409` → `{ "error": …, "code": "conflict", "document": <current document> }`
  so the client can offer to reload or overwrite. Overwrite is a save with the
  current revision.

### Export — `GET api.php?action=export&id=<id>[&version=2.0|1.1|1.0]`

Without `version`, exports in the stored version. With `version`, converts as
described in [opml-model.md](opml-model.md#serialization-export). Returns the
OPML file:

- `Content-Type: text/x-opml; charset=utf-8`
- `Content-Disposition: attachment; filename="<name>.opml"`, where the name is
  `origin.filename` if any (for a URL import, the last part of the URL path),
  else derived from `head.title`, else the id.

Exports the stored document; the editor saves first if there are unsaved
changes.

### Delete — `DELETE api.php?action=delete&id=<id>`

`204`. Removes the file immediately.

## Stateless endpoints

These work with or without a session and store nothing on the server. Signed
out, the editor uses them for its one browser document. Without a session they
are rate limited per IP ([auth.md](auth.md#rate-limits)).

### Parse — `POST api.php?action=parse`

Same input as **Import** above (a file, or JSON `{ "url" }`), and the same
parsing and repair, but nothing is stored.

`200` → `{ "opml": <opml>, "origin": <origin>, "version": "1.1", "warnings": [ … ] }`

### New — `GET api.php?action=new`

`200` → `{ "opml": <opml> }`, an empty document as in
[opml-model.md](opml-model.md#new-documents).

### Render — `POST api.php?action=render`

Body `{ "opml": <opml>, "version": "2.0" | "1.1" | "1.0" | null, "filename": "…" | null }`.
Validates and returns the OPML file, exactly like **Export** above. The
browser saves it with a download link.

The PodcastIndex endpoints `search` and `lookup` below are stateless too.

## PodcastIndex

The client uses these to find feeds and add them as outlines, and to check
existing outlines. All calls go through the server, which holds the API key
(PodcastIndex API 1.0, reference: github.com/Podcastindex-org/docs-api).

### Search — `GET api.php?action=search&by=<mode>&…`

| `by` | Needs | PodcastIndex call | Finds |
|------|-------|-------------------|-------|
| `term` (default) | `q` | `search/byterm` with `similar` | Free text in feed title, author, owner |
| `title` | `q` | `search/bytitle` with `similar` | Feed title |
| `person` | `q` | `search/byperson` | Episodes mentioning a person (person tags, episode title and description, feed author and owner), reduced to their feeds, one per feed |
| `category` | `cat` | `podcasts/trending` with `cat`, `notcat`, `lang` | Trending feeds in the categories. PodcastIndex has no call that lists *all* feeds of a category |
| `medium` | `medium` | `podcasts/bymedium` | Feeds with that `podcast:medium` (`podcast`, `music`, `video`, `film`, `audiobook`, `newsletter`, `blog`) |
| `tag` | `tag` = `value` or `valueTimeSplit` | `podcasts/bytag` | Feeds using `podcast:value` or `podcast:valueTimeSplit`, the only tags PodcastIndex supports here |
| `recent` | — | `recent/feeds` with `cat`, `notcat`, `lang` | Most recently updated feeds |

**All results.** The search calls (`term`, `title`, `person`) and `category`,
`medium` and `recent` have no paging in PodcastIndex. They return up to `max`
results, at most 1000, so the server always asks for 1000. `tag` pages with
`start_at` / `nextStartAt`; the server follows the pages until there are no
more. In every mode the server stops at `podcastindex_max_results` (default
1000) and says so in `truncated`.

**Filters**, optional. PodcastIndex supports them only on some calls, so the
server applies them to the results itself, which works in every mode (except
`val`, see the table):

| Parameter | Keeps feeds |
|-----------|-------------|
| `cat` | in any of these categories (ids or names, comma separated) |
| `notcat` | in none of these categories |
| `lang` | with one of these languages (`en,sv`; `unknown` for none given), matching `en` also `en-US` |
| `medium` | with this medium |
| `clean` | not marked explicit |
| `val` | with a `podcast:value` block (`any`, or `lightning`, `hive`, `webmonetization`). Only for `term` and `title`: other results do not say whether a feed has one, so elsewhere it is refused with `400` |
| `alive` | not marked dead (on by default; `alive=0` includes dead feeds) |

Where PodcastIndex supports a filter on the call (`cat`, `notcat` and `lang` on
`category` and `recent`; `val` and `clean` on `term` and `title`), it is
also passed on, so the 1000 results are the ones that match.

`200` →
`{ "feeds": [ <Feed> ], "total": 1000, "truncated": true }`,
in PodcastIndex's order. `<Feed>` is `Feed::jsonSerialize()`
(`src/lib/Feed.php`).

### Categories — `GET api.php?action=categories`

`200` → `{ "categories": [ { "id": 9, "name": "Business" }, … ] }` from
`categories/list`, for the category filter. Cached on the server for a
day, like searches and feed lookups ([storage.md](storage.md#cache)).

### Lookup

| Request | Response |
|---------|----------|
| `GET api.php?action=lookup&url=<feed url>` | `{ "feed": <Feed> \| null }` |
| `GET api.php?action=lookup&guid=<podcast guid>` | `{ "feed": <Feed> \| null }` |
| `POST api.php?action=lookup_batch` with `{ "items": [ { "guid" } \| { "url" } ] }`, max 100 | `{ "feeds": [ <Feed> \| null ] }`, in the same order |

Lookup by `guid` uses PodcastIndex `podcasts/byguid`. It finds the feed even
after its URL has changed. `lookup_batch` sends all guids in one
`podcasts/batch/byguid` request and looks up the URLs one by one. The
editor's **Check feeds** uses it, 100 outlines per request.

When a PodcastIndex feed is added, the outline gets:

| Attribute | From |
|-----------|------|
| `type` | `"rss"` |
| `text`, `title` | `channel.title` |
| `xmlUrl` | `url` |
| `htmlUrl` | `channel.link` (if any) |
| `description` | `channel.description` (if any) |
| `language` | `channel.language` (if any) |
| `podcast:guid` | `podcastGuid` (if any) |
| `podcast:medium` | `channel.medium` (if any) |

The `podcast:` attributes follow
[opml-model.md](opml-model.md#podcasting-20-namespace).

## Limits

Settings in `data/config.json`, each described in [config.md](config.md). Defaults:

| Key | Default | Applies to |
|-----|---------|-----------|
| `max_upload_bytes` | 5 MB | Import file, or download for import from URL |
| `fetch_timeout` | 15 s | Import from URL, whole download |
| `max_url_imports_per_hour` | 30 | Import from URL, per user |
| `max_document_bytes` | 5 MB | Save request body / stored JSON |
| `max_nodes` | 20 000 | Body nodes |
| `max_depth` | 32 | Nesting depth |
| `max_documents_per_user` | 200 | `create` and `import` |
| `podcastindex_max_results` | 1000 | Results per PodcastIndex search |
