# Storage

## Location

Documents are stored as JSON files in a data directory configured in
`config.php`:

```php
'data_dir'       => __DIR__ . '/../data',   // outside the web root
'retention_days' => 90,
```

## Layout

```
data/
  users/
    <user_key>/                64 hex chars, see auth.md
      user.json                account bookkeeping
      documents/
        <id>.json              one file per document
  sessions/                    PHP session files
  ratelimit/                   rate limit counters
  admin/
    access.json                allowed and denied email addresses, see admin.md
  .last-purge                  timestamp of last purge run
  .htaccess                    "Require all denied" (defence in depth)
```

Each user's documents live only under their own folder. The folder name is
the user key derived from email address and password ([auth.md](auth.md#user-key));
no email address is stored in a user folder.

### `user.json`

```json
{ "schemaVersion": 1, "keyVersion": 1, "created": "2026-10-24T12:00:00Z", "lastLogin": "2026-10-30T08:15:00Z" }
```

- The web server's document root is `src/`; `data/` is a sibling and is not
  reachable over HTTP. The `.htaccess` file guards against a misconfiguration
  that puts the repository root under the web root.
- `data/` is listed in `.gitignore`.
- The directory is created on first use with mode `0700`, files `0600`, owned
  by the PHP process user.

## Document file

One JSON object per file, UTF-8, validated by
[document.schema.json](document.schema.json).

```json
{
  "schemaVersion": 1,
  "id": "q3Xv9kTqR2mW8bZ1aYc0Lg",
  "revision": 4,
  "created": "2026-10-24T12:00:00Z",
  "modified": "2026-10-30T08:15:42Z",
  "expires": "2027-01-28T08:15:42Z",
  "origin": { "kind": "import", "filename": "podcasts.opml" },
  "opml": {
    "version": "2.0",
    "namespaces": {},
    "attributes": {},
    "head": {
      "title": "My podcasts",
      "dateCreated": "Sat, 24 Oct 2026 12:00:00 GMT",
      "dateModified": "Fri, 30 Oct 2026 08:15:42 GMT",
      "ownerName": "Example Owner"
    },
    "headExtra": [],
    "body": [
      {
        "id": "n1",
        "element": "outline",
        "attributes": { "text": "News" },
        "children": [
          {
            "id": "n2",
            "element": "outline",
            "attributes": {
              "text": "Podcasting 2.0",
              "type": "rss",
              "xmlUrl": "https://feeds.example.org/pc20.xml"
            },
            "children": []
          }
        ]
      }
    ]
  }
}
```

### Fields

| Field | Type | Meaning |
|-------|------|---------|
| `schemaVersion` | integer | Format version, currently `1`. Bumped on incompatible change; older files are migrated on read. |
| `id` | string | Document id, 22 characters base64url (128 random bits). Equals the file name. Unique within the user's folder. |
| `revision` | integer | Starts at 1, incremented on every save. Used for conflict detection. |
| `created` | RFC 3339 UTC | When the document was created in opmledit (not the OPML `dateCreated`). |
| `modified` | RFC 3339 UTC | Last successful save. |
| `expires` | RFC 3339 UTC | When the document will be purged unless touched. |
| `origin.kind` | `"new"` \| `"import"` | How the document started. |
| `origin.filename` | string \| null | Original upload file name, used to suggest the export name. |
| `opml` | object | The OPML content, see [opml-model.md](opml-model.md). |

`created`, `modified` and `expires` are opmledit bookkeeping and are never
written into the exported OPML. The OPML's own dates live in `opml.head`.

## Lifecycle

| Event | `revision` | `modified` | `expires` |
|-------|------------|------------|-----------|
| Create (new or import) | 1 | now | now + retention |
| Open (GET) | — | — | now + retention, written only if it moves by more than 1 day |
| Save | +1 | now | now + retention |
| Export | — | — | now + retention (same 1 day rule) |
| Delete | file removed | | |

The 1 day rule avoids a disk write on every page load.

## Purging

Purging walks every user folder and deletes:

- documents whose `expires` is in the past;
- user folders with no documents left and `lastLogin` older than the
  retention period (an account nobody signs in to, e.g. after a forgotten
  password);
- expired session files and rate limit counters.

Purge runs:

1. From cron, recommended daily: `php bin/purge.php`
   (`bin/` is outside the web root).
2. Opportunistically: an API request checks `data/.last-purge`; if older than
   24 hours, it updates the marker and runs the purge after sending its
   response. This keeps hosts without cron working.

Purge reads `expires` from each file. A file that cannot be parsed is left in
place and logged, never deleted, so a bug cannot wipe data.

## Writing

- Writes go to a temporary file in the same directory, then `rename()` over the
  target, so a reader never sees a partial file.
- Save holds an exclusive `flock()` on `<id>.json.lock` across read, revision
  check and write.
- Account level changes (change password/email address, delete account) and purge of a
  user folder hold an exclusive lock on `data/users/<user_key>.lock`; document
  writes hold it shared.
- JSON is written with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
  JSON_UNESCAPED_UNICODE` for readability.

## Validation

The server validates a document against the rules of
[document.schema.json](document.schema.json) and
[opml-model.md](opml-model.md#validation-rules) before every write. The PHP
implementation is hand written; the schema file is the reference and is used
in tests.
