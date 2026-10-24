# opmledit — Specifications

opmledit is a simple web based editor for OPML files, with PodcastIndex.org as
a helper backend for finding and checking podcast feeds.

OPML was created by Dave Winer, the father of OPML; his specifications at
opml.org are the reference (see [opml-model.md](opml-model.md)).

## Documents

| Document | Covers |
|----------|--------|
| [opml-model.md](opml-model.md) | What is read from an OPML file, how it is preserved, how it is written back |
| [storage.md](storage.md) | JSON document format, the `data/` folder, lifecycle, expiry and purging |
| [document.schema.json](document.schema.json) | JSON Schema for stored documents (normative) |
| [xsd/opml-1.0.xsd](xsd/opml-1.0.xsd), [xsd/opml-2.0.xsd](xsd/opml-2.0.xsd) | XML Schemas exports are checked against (1.0 also covers 1.1), see [opml-model.md](opml-model.md#schemas) |
| [auth.md](auth.md) | Accounts, sign in, how users are kept apart |
| [api.md](api.md) | HTTP API between browser and server |
| [editor.md](editor.md) | Editor user interface and workflows |
| [security.md](security.md) | Access model, input handling, file system protection |
| [admin.md](admin.md) | Which email addresses may use the service, and the settings page |
| [config.md](config.md) | Every setting in `data/config.json`: meaning, default, range |
| [config.schema.json](config.schema.json) | JSON Schema for `data/config.json` (normative) |

## Code layout

PHP is the backend only. It never produces HTML; pages are static files.

```
src/                       web server document root
  index.html, admin.html   static pages
  css/, js/                stylesheets and vanilla JavaScript
  api.php, admin-api.php   PHP endpoints, JSON (and the OPML export file)
  lib/                     PHP classes
  config.php               secrets, site URL, data_dir (not in git)
specifications/            these documents; config.schema.json is read by the server
bin/                       PHP command line scripts (purge, admin password)
```

- HTML, CSS and JavaScript contain no PHP, and PHP contains no HTML, CSS or
  JavaScript.
- Anything the page needs from the server (signed-in user, retention days,
  CSRF token) comes from the API, e.g. `me`.
- Security headers for the static pages come from the web server; `src/.htaccess`
  sets them for Apache ([security.md](security.md#output)).

## Scope

Anyone can edit **one document without signing in**. It is kept in the
browser (IndexedDB), not on the server ([storage.md](storage.md#in-the-browser)).

Users who sign in with email address and password get a **library**: any number
of documents saved on the server, each user seeing only their own. When signing
in, the document from the browser can be moved into the library.

There are two ways to start a document, and the same operations after that:

1. **Import** an existing OPML file, uploaded or fetched from a URL, of any version (before 1.0, 1.0, 1.1, 2.0),
   also broken ones (wrong encoding, unclosed elements, no version): they are
   repaired into valid OPML. Valid files are parsed with all information preserved (see
   [opml-model.md](opml-model.md)).
2. **New** document from scratch.

Operations on a document:

- Edit every `<head>` element, and an optional source URL written as a
  comment between head and body, as a backlink to where the OPML came from.
- Edit the `<body>`: reorder outlines, nest and un-nest them, add and delete
  outlines, open any outline and edit all of its attributes.
- Add podcast feeds found through PodcastIndex, and check existing feeds.
- Enrich feed outlines with Podcasting 2.0 `podcast:` attributes
  (`podcast:guid`, `podcast:medium`, and any others by hand).
- **Save**: signed out, to the browser; signed in, to the server, to come
  back later (weeks) and continue.
- **Export** as an OPML file download, in the stored version or converted to
  OPML 2.0, 1.1 or 1.0.
- Delete the document.

Library documents not touched for 90 days (configurable) are purged.

A small admin page ([admin.md](admin.md)) lists the email addresses that may
have an account. Signed out use is open to everyone, with per-IP limits
([security.md](security.md#abuse-limits)).

## Out of scope

- Sending mail of any kind.
- Password reset. No password is stored and no mail is sent, see
  [auth.md](auth.md#consequences-shown-to-users-at-account-creation).
- Sharing documents between users.
- Collaboration / simultaneous editing beyond conflict detection.
- Fetching or parsing the RSS feeds themselves; feed information comes from
  PodcastIndex.

## Key decisions

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Documents stored as one JSON file each under a `data/` folder outside the web root | Simple, no database, easy to back up and inspect |
| D2 | Users sign in with email address + password; their folder is named by a key derived from both (Argon2id, server-secret salt). Neither is stored in the folder | Separates users; invisible to the user. Cost: no password reset |
| D3 | OPML is parsed and serialized on the server (PHP DOM) | One implementation of the format, validated before storage |
| D4 | Body stored as a generic element tree, not only `<outline>` | Unknown elements and attributes survive a round trip |
| D5 | Explicit Save; the whole document is sent on save | Documents are small; whole document replace is simple and robust |
| D6 | Optimistic locking with a `revision` number | Two open tabs cannot silently overwrite each other |
| D7 | Opening a document renews its expiry, saving also updates `modified` | "Touched" means used, not only changed |
| D8 | Purge runs from cron, with an opportunistic daily fallback | Works on hosts without cron access |
| D9 | Hostnames, URLs, paths and secrets live in `config.php`; limits and cache times in `data/config.json` | They may change; nothing host specific in code, and tweaking a limit needs no PHP edit |
| D10 | The application never sends mail | Not needed: the email address is only an identifier. No mail configuration |
| D11 | Only email addresses the admin has allowed can use the service; not verified | An open service gets misused; users are known to the admin personally |
| D12 | PHP only in `api.php`, `admin-api.php`, `lib/` and `bin/`; pages are static HTML, CSS and JS | Clear separation of backend and frontend; pages can be cached and edited without touching PHP |
| D13 | Signed out: one document in the browser's IndexedDB; server endpoints for parsing and export are stateless. Signing in offers to move it into the library | Lets anyone try the editor without an account, while nothing is stored on the server for them |

## Glossary

- **Document** — one stored OPML file being edited, identified by its id.
- **Node** — one element in the body tree. Most nodes are `<outline>`.
- **Outline** — a node whose element is `outline`.
- **Touched** — a document was opened or saved.
- **User key** — 64 hex characters derived from email address and password; the
  name of the user's folder. Never shown to the user.

## Relationship to the current code

The current prototype keeps a flat feed list in browser `localStorage`. These
specifications replace that model. `Channel`, `Feed` and `PodcastIndexApi`
remain and are used to fill in outline attributes from PodcastIndex.

## Open questions

1. Should XML comments in the uploaded file be preserved? (Currently: only
   the source comment between head and body, see
   [opml-model.md](opml-model.md#source-comment).)
2. Should export also offer a version from before 1.0? There is no
   published specification; for now such files are imported as 1.0 (or 2.0
   if they use 2.0 features).
3. Should "Export" also offer a flat OPML (folders removed) for podcast apps that
   do not handle nesting?
4. Should a read-only share link be offered, so a document can be shown to
   someone without an account?
5. Retention: 90 days from last touch — is a "keep longer" option wanted?
