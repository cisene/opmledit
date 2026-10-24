# Administration

Account holders are people the admin knows personally. The admin adds their
email addresses to an access list; nobody else can have an account. (Editing
a single document in the browser without an account is open to everyone,
see [README.md](README.md#scope).)

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

`src/admin.html` with `src/js/admin.js`, static like the editor: a password form, then a single
table of email addresses with state and note. Add an address, switch
between allowed and denied, edit the note, remove it.

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
