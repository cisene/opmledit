# Administration

Account holders are people the admin knows personally. The admin adds their
email addresses to an access list; nobody else can have an account. (Editing
a single document in the browser without an account is open to everyone,
see [README.md](README.md#scope).) The admin also sets the limits and cache
times ([Settings](#settings)).

## Access list

Each email address (normalized as in [auth.md](auth.md#user-key)) is:

| State | Effect |
|-------|--------|
| **allowed** | Can create an account and sign in |
| **denied** | Refused: `403 not_allowed` |
| **undefined** (not on the list) | Refused: `403 not_allowed` |

The check runs on register, on sign in and on every signed-in request, so
denying an address takes effect at once. Documents of a denied address are
not deleted; they expire like any other unused documents.

Stored in `data/admin/access.json`:

```json
{
  "anna@example.org": { "state": "allowed", "note": "Podcast club" },
  "bob@example.org":  { "state": "denied",  "note": "" }
}
```

The email addresses are stored in clear so the admin can read them.

Nothing checks that a person owns the email address they type. Anyone who
knows an allowed address can create their own account under it (with a
different password, so they cannot see the real owner's documents).
This is accepted, because the users are known to the admin.

## Admin sign in

```php
'admin_password_hash' => '',   // from: php bin/admin-password.php
```

- `bin/admin-password.php` asks for a password and prints the
  `password_hash(..., PASSWORD_ARGON2ID)` line for `config.php`.
- If the hash is empty, the admin API answers `404` to everything.
- The admin has its own session cookie (`<session_cookie>_admin`), with
  the same CSRF and `Origin` checks and sign-in rate limit as users
  ([auth.md](auth.md#rate-limits)).

## Admin page

`src/admin.html` with `src/js/admin.js`, static like the editor: a password
form, then two sections:

- **Email addresses**: a table of email addresses with state and note. Add
  an address, switch between allowed and denied, edit the note, remove it.
- **Settings**: see below.

## Settings

A form with one number field per setting in `data/config.json`
([config.md](config.md)), grouped as in that document.

- Each field shows the setting's name, its description, the allowed range
  and the default. The form is built from the `settings` answer, which
  comes from [config.schema.json](config.schema.json), so a new setting
  needs no change to the page.
- An empty field means the default. Only fields that are filled in are
  stored, so a later change of a default reaches every setting left empty.
- Save sends all fields at once. The server checks them against the same
  ranges as [config.schema.json](config.schema.json); on `400` the page
  marks the field named in the answer and nothing is stored.
- A change applies to the next request; nothing needs restarting.

## Admin API

`src/admin-api.php?action=…`, following the conventions in
[api.md](api.md#conventions).

| Request | Body | Success |
|---------|------|---------|
| `POST login` | `{ password }` | `200` → `{ "csrfToken" }` |
| `POST logout` | — | `204` |
| `GET list` | — | `200` → `{ "addresses": [ { "email", "state", "note" } ] }` |
| `POST set` | `{ email, state, note }` | `200` |
| `POST remove` | `{ email }` | `204` |
| `GET settings` | — | `200` → `{ "settings": [ … ] }`, see below |
| `POST settings` | `{ "values": { "<key>": <int or null>, … } }` | `200` → same as `GET settings`; `400` → `{ "error", "code": "bad_request", "key" }` |

`settings` lists every setting in the order of [config.md](config.md):

```json
{ "key": "cache_seconds.search", "description": "Seconds a search answer is reused …",
  "default": 14400, "min": 0, "max": 2592000, "value": null }
```

Nested settings use dotted keys (`cache_seconds.search`); `value` is the
stored value or `null` for the default. `POST settings` takes the same
dotted keys; a key left out or `null` is stored as unset. The server
writes `data/config.json` as nested JSON
([config.schema.json](config.schema.json)).
