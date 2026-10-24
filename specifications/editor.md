# Editor

A single static page, `src/index.html`, with vanilla JavaScript (`src/js/`)
and CSS (`src/css/`). It talks to the server only through `api.php`
([api.md](api.md)); no PHP is involved in the page itself.

## Pages / states

| URL | Shows |
|-----|-------|
| `/` | Sign-in screen when signed out, else start screen |
| `/?doc=<id>` | Editor for that document (sign-in screen first if signed out, then back to the document) |
| `/?account` | Account settings |

On load the page calls `me` to know whether a session exists.

### Sign-in screen

- Email address and password, **Sign in**. Wrong credentials show "Wrong email or
  password" and nothing more.
- **Create account** switches the form to email address, password, repeat password,
  and a notice that must be ticked before continuing:

  > There is no password reset. If you forget your password, your documents
  > cannot be recovered and are deleted after 90 days without use. Export
  > anything important.

- The email address is only an identifier; the form says no mail will be sent.
- `403 not_allowed` shows "This email address does not have access".
- Uses `autocomplete="username"` / `"current-password"` / `"new-password"`
  so password managers work.

### Start screen

- **New document** — `create`, then go to `/?doc=<id>`.
- **Upload OPML** — file picker and drop zone; `import`, then go to the editor
  and show any import warnings.
- **My documents** — from `list`: title, outline count, last modified, expiry
  date (highlighted when within 14 days). Open, export or delete from the list.
- A note that documents are deleted 90 days after last use (value from
  `user.retentionDays`).
- Header: signed-in email address, links to **Account** and **Sign out**.

### Account settings

- Change password, change email address (both ask for the current password, and
  repeat the no-reset warning).
- Delete account, with password and a confirmation listing how many
  documents will be deleted.

### Editor

Three areas:

```
+-------------------------------------------------------------+
| Title · saved 2 min ago · expires 2027-01-28                |
| [Save] [Export] [Delete] [Close]          you@example.org ▾ |
+---------------------------+---------------------------------+
| Head                      | Outline details                 |
|  (form)                   |  (form for selected node)       |
| Body                      |                                 |
|  tree of outlines         | PodcastIndex                    |
|                           |  search / check                 |
+---------------------------+---------------------------------+
```

On narrow screens the areas stack: head, body, details.

## Head

A form with one field per OPML 2.0 head element (see
[opml-model.md](opml-model.md#head)).

- A field left empty removes the element; a checkbox "keep empty element"
  covers the rare empty-but-present case.
- `dateCreated` and `dateModified` get a "now" button. `dateModified` is
  shown as "set automatically on save".
- `ownerEmail`, `ownerId`, `docs` validated as email/URL with a warning, not
  a block (values are kept verbatim).
- `headExtra` elements are listed read-only with a remove button.
- Root `version`, namespaces and extra root attributes are shown under an
  "Advanced" disclosure; namespaces can be added and removed (removal blocked
  while a prefix is in use).

## Body tree

Each node is one row: expand/collapse toggle, `text` (or element name for
non-outline nodes), a type badge (`rss`, `link`, `include`, folder when it has
children), and a status marker from feed checks.

Operations on the selected node:

| Operation | Mouse | Keyboard |
|-----------|-------|----------|
| Select | click | ↑ / ↓ |
| Expand / collapse | toggle | → / ← |
| Move up / down among siblings | drag, or buttons | Alt+↑ / Alt+↓ |
| Indent (become child of previous sibling) | drag, or button | Tab |
| Outdent (become next sibling of parent) | drag, or button | Shift+Tab |
| Add sibling after | button | Enter |
| Add child | button | Ctrl+Enter |
| Duplicate | button | Ctrl+D |
| Delete (with children) | button | Delete, with confirm when it has children |

- Drag and drop supports dropping before, after or into a node.
- Undo / redo of tree and attribute edits within the session (Ctrl+Z /
  Ctrl+Shift+Z). Not persisted.
- A filter box narrows the tree to nodes whose `text`, `title` or `xmlUrl`
  contains the term.

## Outline details

Opening a node shows all its attributes.

- Known OPML attributes first, as labelled fields: `text`, `type`, `title`,
  `xmlUrl`, `htmlUrl`, `url`, `description`, `language`, `version`,
  `created`, `category`, `isComment`, `isBreakpoint`.
- Then all other attributes, as name/value rows. Rows can be added, renamed
  and removed. Namespaced names need a declared prefix.
- `type` is a combo box: `rss`, `link`, `include`, or free text.
- For `rss` outlines with an `xmlUrl`: **Check** against PodcastIndex (see
  below) and **Fill from PodcastIndex**, which offers to set empty `title`,
  `htmlUrl`, `description`, `language` attributes from the lookup.
- Non-outline nodes show element name, attributes and text.

## PodcastIndex

- **Search** by term; each result can be added as a new `rss` outline after
  the selected node (or at the end of the body). Feeds already in the
  document (same `xmlUrl`) are marked "already added".
- **Check feeds**: for the selected subtree or the whole document, look up each
  `xmlUrl` one at a time and mark:
  - OK, with last update date
  - Not in PodcastIndex
  - Dead
  - Moved — with a button to replace `xmlUrl` with the new URL
- Results are shown in the tree and details; they are not stored.

## Saving

- Explicit **Save** (button, Ctrl+S). The title bar shows "unsaved changes"
  while dirty; leaving the page with unsaved changes asks for confirmation.
- No autosave to the server. A draft of unsaved changes is kept in
  `localStorage` per document and offered for restore when reopening, so a
  closed tab or crash does not lose work. Drafts are cleared on sign out, so
  the next person using the same browser does not see them.
- If the session has expired (401), the editor shows the sign-in form in a
  dialog, keeps all local changes, and retries the save after sign-in.
- On conflict (409) the editor offers: reload the server version (discarding
  local changes), or overwrite.
- Validation errors from the server are shown next to the affected node.

## Export

**Export** offers the target version: as stored (showing which version
that is, or "no version"), OPML 2.0, 1.1 or 1.0. For 1.x it first lists what
will be left out (see
[opml-model.md](opml-model.md#serialization-export)). Then it saves if
there are unsaved changes, and downloads `api.php?action=export&version=…`.

The document list's export uses the stored version.

## Delete

**Delete** asks for confirmation, deletes on the server, removes the entry
from the local draft store and returns to the start screen.
