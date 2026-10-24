# Security

## Access model

Users sign in with email address and password ([auth.md](auth.md)). Each user can
only reach documents in their own folder; there is no sharing.

- The folder is named by a key derived from email address and password (Argon2id with
  a server-secret salt). The password and any password hash are not stored.
- The only personal data stored are the email addresses on the admin's access
  list ([admin.md](admin.md#access-list)). Nothing records which folder
  belongs to which address.
- Separation is structural: document paths are always
  `users/<session user key>/documents/<id>.json`. There is no code path that
  takes a user key from the request.
- Document ids are still random (128 bits), so ids in URLs and logs reveal
  nothing, but they are not what protects a document.
- Session cookie is `HttpOnly`, `Secure`, `SameSite=Lax`; session id
  regenerated at sign-in. CSRF token and `Origin` check on every state
  changing request ([api.md](api.md#conventions)).
- Sign-in, account creation and key derivation are rate limited
  ([auth.md](auth.md#rate-limits)).
- Only email addresses the admin has allowed can create accounts and sign
  in ([admin.md](admin.md)). Email addresses are not verified.
- Passwords are only in the request body over HTTPS; they are never logged.
  Request logging must exclude bodies of `register`, `login`,
  `change_password`, `change_email`, `delete_account`, and the admin
  `login`.
- Pages and API send `Referrer-Policy: no-referrer` and
  `X-Robots-Tag: noindex`. The API sets its headers in PHP. The pages are
  static, so the web server sets theirs (`src/.htaccess` for Apache).

### Known limitation

There is no password reset. This follows from not storing anything that
identifies the user, and is stated at account creation.

## File system

- `data/` is outside the web root, plus `.htaccess` with `Require all denied`
  as a second line of defence.
- File paths are only ever built from an id that has passed the id pattern;
  no user input is otherwise used in a path.
- Atomic writes and locking, see [storage.md](storage.md#writing).
- Upload temp files are read from `$_FILES[...]['tmp_name']` after
  `is_uploaded_file()` and never moved into `data/` as is; only the parsed
  JSON is stored.

## Fetching URLs

Import from URL and a feed's episodes ([episodes.md](episodes.md)) make the
server fetch a URL a user typed. To keep it from being used to reach the
server's own network or other internal services (`src/lib/UrlFetcher.php`):

- Only `http` and `https`, ports 80 and 443, no user name or password in
  the URL, max 2048 characters.
- The host name is resolved first. If any address is loopback, private,
  link-local, multicast or otherwise reserved (IPv4 and IPv6, including
  IPv4-mapped IPv6), the fetch is refused. The connection then goes to
  the address that was checked (curl `CURLOPT_RESOLVE`), so DNS cannot
  change it in between.
- At most 3 redirects, each one checked the same way.
- Download stops at `max_upload_bytes` for import, `max_feed_bytes` for
  episodes (counted after decompression), and after `fetch_timeout` seconds
  in total.
- No cookies and no credentials are sent. The user agent is the
  configured one.
- The response's `Content-Type` is ignored, except its charset for feeds;
  the content goes through the same parsing and repair as an uploaded file,
  or the feed repair ([episodes.md](episodes.md#repair)).
- Rate limit: `max_url_imports_per_hour` per user.

## XML parsing

- No external entities, no network (`LIBXML_NONET`), no entity substitution.
- `DOCTYPE` removed before parsing, which also rules out entity expansion
  attacks. This applies to the recovering parse of broken files too.
- Size, node count and depth limits applied (see [api.md](api.md#limits)).

## Output

- The browser renders all document content with `textContent` / form field
  values, never `innerHTML`.
- Content-Security-Policy on the pages (set by the web server):
  `default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'`.
  Artwork comes from arbitrary hosts, hence `img-src https:`.
- Export sets `Content-Disposition: attachment` and a sanitized file name.

## Abuse limits

- Upload and document size limits.
- Per-user limit on stored documents (config `max_documents_per_user`,
  default 200) and per-IP rate limits on account creation.
- Signed out use stores nothing on the server; its stateless endpoints are
  rate limited per IP ([auth.md](auth.md#rate-limits)).
- PodcastIndex calls go through the server; the API secret never reaches the
  browser.

## Configuration

`config.php` holds credentials, `user_key_secret`, hostnames and paths and is
not in git. Losing `user_key_secret` makes every account unreachable: back it
up separately from `data/`.
`config.sample.php` documents every key.
Limits and cache times are not secret and live in `data/config.json`,
which the admin edits ([config.md](config.md)).
