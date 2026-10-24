# opmledit
OPML Edit - PodcastIndex.org as backend

## Setup

1. Point the web server's document root for the site at `src/`.
2. Copy `src/config.sample.php` to `src/config.php` and fill in the site URL
   and your PodcastIndex API key and secret. Hostnames and URLs live only in
   `config.php`, not in the code.
3. Requires PHP 8.2+ with the curl extension.

Local development: `php -S localhost:8000 -t src`
