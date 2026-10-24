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
| [admin.md](admin.md) | Which email addresses may use the service |

## Scope

Users sign in with email address and password; each user sees only their own
documents. Once signed in, there are two ways in, and the same operations
after that:

1. **Upload** an existing OPML file of any version (before 1.0, 1.0, 1.1, 2.0),
   also broken ones (wrong encoding, unclosed elements, no version): they are
   repaired into valid OPML. Valid files are parsed with all information preserved (see
   [opml-model.md](opml-model.md)).
2. **New** document from scratch.

Operations on a document:

- Edit every `<head>` element.
- Edit the `<body>`: reorder outlines, nest and un-nest them, add and delete
  outlines, open any outline and edit all of its attributes.
- Add podcast feeds found through PodcastIndex, and check existing feeds.
- **Save** to the server, and come back later (weeks) to continue.
- **Export** as an OPML file download, in the stored version or converted to
  OPML 2.0, 1.1 or 1.0.
- Delete the document.

Documents not touched for 90 days (configurable) are purged.

A small admin page ([admin.md](admin.md)) lists the email addresses that may
use the service. Everyone else is refused.

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
| D9 | All hostnames, URLs, paths and limits live in `config.php` | They may change; nothing host specific in code |
| D10 | The application never sends mail | Not needed: the email address is only an identifier. No mail configuration |
| D11 | Only email addresses the admin has allowed can use the service; not verified | An open service gets misused; users are known to the admin personally |

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

1. Should XML comments in the uploaded file be preserved? (Currently: no, see
   [opml-model.md](opml-model.md#not-preserved).)
2. Should export also offer a version from before 1.0? There is no
   published specification; for now such files are imported as 1.0 (or 2.0
   if they use 2.0 features).
3. Should "Export" also offer a flat OPML (folders removed) for podcast apps that
   do not handle nesting?
4. Should a read-only share link be offered, so a document can be shown to
   someone without an account?
5. Retention: 90 days from last touch — is a "keep longer" option wanted?
