# opmledit
OPML Edit - PodcastIndex.org as backend

## Setup

1. Point the web server's document root for the site at `src/`.
2. Copy `src/config.sample.php` to `src/config.php` and fill in the site URL
   and your PodcastIndex API key and secret. Hostnames and URLs live only in
   `config.php`, not in the code.
3. Requires PHP 8.2+ with the curl extension.

Pages (`index.html`, CSS, JavaScript) are static; PHP is only the backend
(`api.php`, `lib/`). On Apache, `src/.htaccess` sets the security headers;
other web servers need the same headers, see `specifications/security.md`.

Local development: `php -S localhost:8000 -t src`
