# Authentication and users

Sign in exists to keep each user's documents separate. A user is identified by
the combination of email address and password. The account folder holds no
email address, no password and no password hash.

Only email addresses the admin has allowed can use the service
([admin.md](admin.md#access-list)).

## User key

The **user key** is derived from email address and password and names the user's
folder (see [storage.md](storage.md#layout)):

```
email_n  = lowercase(trim(email))
salt     = first 16 bytes of HMAC-SHA256(user_key_secret, "opmledit-salt:" + email_n)
derived  = Argon2id(password, salt, opslimit = 3, memlimit = 64 MiB, 32 bytes)
user_key = hex(derived)                                   // 64 hex characters
```

- `user_key_secret` is a long random value in `config.php` (32+ bytes),
  generated once at install.
- The salt is deterministic (needed to find the folder again) but depends on a
  server secret, so precomputed tables cannot be used and a copy of `data/`
  alone cannot be attacked without `config.php`.
- Argon2id makes each guess cost about 0.15 s and 64 MiB, which slows down
  offline guessing if both `data/` and `config.php` leak.
- The password is never stored or logged. Only the user key is kept, in the
  session and as the folder name.
- The user never sees the user key; it is not in any URL, page or API
  response.

### Fixed parameters

`user_key_secret`, the salt prefix, and the Argon2id parameters must never
change. If any of them changes, every user key changes and all users see
empty accounts. A future change needs a migration on sign in (derive the old
key, rename the folder to the new key); the algorithm version is
`key_version` 1.

## Consequences (shown to users at account creation)

- **A forgotten password cannot be reset.** No password is stored and no mail
  is sent, so there is no recovery. The documents in that account become unreachable and are purged
  after the retention period. Users should export anything important.
- The email address receives no mail and is not verified. It must be the
  address the admin allowed. Normalization is lowercasing and trimming,
  nothing more (`a.b@x` and `ab@x` are different).
- The same email address with a different password is a different account.

## Flows

### Create account

`POST api.php?action=register` with `{ "email", "password" }`. The editor asks
for the password twice; the server only receives it once.

1. Validate: the email address is well formed (max 254 characters), password at
   least 10 characters (max 1024).
2. The address must be allowed ([admin.md](admin.md#access-list)), else
   `403 not_allowed`. This is checked before the key is derived.
3. Derive the user key. If the folder exists, sign in to it (same result as
   signing in; no information leaks, since only someone with the password can
   reach this case).
4. Otherwise create the folder with `user.json` and sign in.

### Sign in

`POST api.php?action=login` with `{ "email", "password" }`.

1. The address must be allowed, else `403 not_allowed`.
2. Derive the user key.
3. If the folder exists: regenerate the session id, store the user key, update
   `user.json` `lastLogin`, respond `200`.
4. Otherwise respond `401` "Wrong email or password". No folder is created, so
   a typo does not silently open an empty account.

### Change password

Signed in. `POST api.php?action=change_password` with
`{ "currentPassword", "newPassword" }`. The email address is taken from the session.

1. Derive the key from the current password; it must equal the session's user
   key, else `401`.
2. Derive the new key. If its folder exists, `409` (choose another password).
3. Take the user lock, `rename()` the folder to the new key, update the
   session.

### Change email address

Same as change password, with `{ "currentPassword", "newEmail" }`. The new
address must be allowed, else `403 not_allowed`.

### Sign out

`POST api.php?action=logout` destroys the session and clears the cookie.

### Delete account

`POST api.php?action=delete_account` with `{ "password" }`. Verifies as in
change password, then removes the user folder and all documents, and signs
out.

## Sessions

- PHP native sessions, files in `data/sessions/`, purged with the rest of the
  data.
- Session holds: `userKey`, `email` (display, and needed to re-derive the key
  for password changes), `csrfToken`, `created`.
- Cookie: name from config (default `opmledit`), `HttpOnly`, `Secure`,
  `SameSite=Lax`, `Path=/`, host-only (no `Domain`).
- Lifetime 30 days (`session_days`), sliding.

## Authorization

- Every document and PodcastIndex endpoint requires a session, else `401` and
  the page shows the sign-in screen.
- Every signed-in request checks that the session's email address is still
  allowed. If it is not, the session is destroyed and the answer is `403
  not_allowed`.
- Document paths are built from the session's user key and the document id.
  A document id that belongs to another user is not found (`404`).

## Rate limits

| Limit | Default | Counted per |
|-------|---------|-------------|
| Failed sign-ins | 10 per 15 minutes | IP |
| Failed sign-ins | 5 per 15 minutes | email address (hashed with HMAC, not stored in clear) |
| Account creations | 5 per hour | IP |

Over the limit: `429`. Counters are small files in `data/ratelimit/`, removed
after their window. Every login request costs a key derivation, so the limits
also protect the server's CPU and memory.

## Configuration

```php
'user_key_secret' => '',          // random, 32+ bytes, NEVER change
'session_cookie'  => 'opmledit',
'session_days'    => 30,
```

`admin_password_hash` is described in [admin.md](admin.md#admin-sign-in).
