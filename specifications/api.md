# HTTP API

All endpoints are served by `src/api.php` with an `action` query parameter
(no URL rewriting needed). Responses are JSON (`application/json; charset=utf-8`)
except export.

## Conventions

- All endpoints except `register` and `login` require a signed-in session
  ([auth.md](auth.md)); documents are always read from and written to the
  session user's folder.
- State changing requests (`POST`, `PUT`, `DELETE`) must send the session's
  CSRF token in an `X-CSRF-Token` header, and an `Origin` header matching
  `site_url` when the browser sends one. Otherwise `403`.
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

No body. Creates an empty document (see [opml-model.md](opml-model.md#new-documents)).

`201` → `{ "document": <document> }`

### Import — `POST api.php?action=import`

`multipart/form-data` with field `file` (the OPML file).

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
  `origin.filename` if any, else derived from `head.title`, else the id.

Exports the stored document; the editor saves first if there are unsaved
changes.

### Delete — `DELETE api.php?action=delete&id=<id>`

`204`. Removes the file immediately.

## PodcastIndex

Existing endpoints, kept. The client uses them to add feeds as outlines and to
check outlines that have an `xmlUrl`.

| Request | Response |
|---------|----------|
| `GET api.php?action=search&q=<term>` | `{ "feeds": [ <Feed> ] }` |
| `GET api.php?action=lookup&url=<feed url>` | `{ "feed": <Feed> \| null }` |
| `GET api.php?action=lookup&guid=<podcast guid>` | `{ "feed": <Feed> \| null }` |

Lookup by `guid` uses PodcastIndex `podcasts/byguid`. It finds the feed even
after its URL has changed.

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

From `config.php`, with defaults:

| Key | Default | Applies to |
|-----|---------|-----------|
| `max_upload_bytes` | 5 MB | Import file |
| `max_document_bytes` | 5 MB | Save request body / stored JSON |
| `max_nodes` | 20 000 | Body nodes |
| `max_depth` | 32 | Nesting depth |
| `max_documents_per_user` | 200 | `create` and `import` |
