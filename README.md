# opmledit
OPML Edit - PodcastIndex.org as backend

## Requirements

- PHP 8.2 or later with the extensions curl, mbstring, dom (libxml) and
  json.
- A web server with HTTPS. On Apache: `AllowOverride` for `src/.htaccess`
  and `mod_headers`. Behind a reverse proxy, `mod_remoteip`, so rate limits
  see the real client address (`specifications/auth.md`).

## Setup

1. Deploy the whole repository and point the web server's document root for
   the site at `src/`. The `specifications/` folder must stay next to
   `src/`: the server reads `specifications/config.schema.json`.
2. Copy `src/config.sample.php` to `src/config.php` and fill in the site URL
   and your PodcastIndex API key and secret. Hostnames and URLs live only in
   `config.php`, not in the code.
3. `data/` (next to `src/`, outside the web root, created on first use) must
   be writable by the PHP user. It holds the cache, rate limit counters and
   settings.
4. Limits and cache times are changed on the admin page, stored in
   `data/config.json` (optional; see `specifications/config.md`). Changes
   apply at once.

Pages (`index.html`, CSS, JavaScript) are static; PHP is only the backend
(`api.php`, `lib/`). On Apache, `src/.htaccess` sets the security headers;
other web servers need the same headers, see `specifications/security.md`.

Local development: `php -S localhost:8000 -t src`
