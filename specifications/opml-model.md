# OPML model

How OPML is read, represented and written. References: OPML 1.0
(opml.org/spec1.opml) and OPML 2.0 (opml.org/spec2.opml).

## Goal

Any version of OPML can be imported, and exported as 2.0, 1.1 or 1.0.

An uploaded file exported again without edits, in its own version, must contain
the same information: the same elements, attributes, attribute values, text
and namespaces, in the same order within the body. Formatting may differ.

## Versions

| Version | Specification | Differences |
|---------|---------------|-------------|
| Before 1.0 | None published. Files from early UserLand tools (Radio, Frontier), often with no `version` attribute | Read like 1.0 |
| 1.0 | opml.org/spec1.opml (2000) | Head: `title`, `dateCreated`, `dateModified`, `ownerName`, `ownerEmail`, `expansionState`, `vertScrollState`, `window*`. Outline: `text`, `type`, `isComment`, `isBreakpoint`, plus any other attributes |
| 1.1 | None published. Common in feed reader and podcast app exports | Same as 1.0 |
| 2.0 | opml.org/spec2.opml (2006) | Adds head `ownerId` and `docs`; outline `created` and `category`; defines types `rss`, `link`, `include`; `text` required; namespaces allowed |

2.0 is a superset of the older versions, so one reader handles all of them.
The model below keeps everything in the file whatever its version. Converting
happens only on export.

## Parsing

Parsing is done on the server with PHP `DOMDocument`.

- Network access and entity substitution are disabled (`LIBXML_NONET`, no
  `LIBXML_NOENT`). Documents with a `DOCTYPE` are rejected.
- The root element must be `opml`. Any other root is rejected.
- Input must be well formed XML. Encoding is taken from the XML declaration,
  default UTF-8. Everything is stored as UTF-8.
- Limits from config apply: file size, number of nodes, nesting depth (see
  [security.md](security.md)).

### Root `<opml>`

| Source | Stored as |
|--------|-----------|
| `version` attribute | `opml.version`, the string as written. `""` when the attribute is missing (typical of files from before 1.0) |
| `xmlns:prefix="uri"` declarations, anywhere in the file | `opml.namespaces` (`prefix → uri`) |
| Any other root attribute | `opml.attributes` |

Namespace declarations found on inner elements are collected into
`opml.namespaces` and written on the root on export. If the same prefix is
bound to two different URIs, the file is rejected.

### `<head>`

The OPML 2.0 head elements are stored as named string fields in `opml.head`:

| Element | Meaning |
|---------|---------|
| `title` | Title of the document |
| `dateCreated` | RFC 822 date-time the document was created |
| `dateModified` | RFC 822 date-time the document was last modified |
| `ownerName` | Owner's name |
| `ownerEmail` | Owner's email address |
| `ownerId` | URL of a page with contact information for the owner |
| `docs` | URL of the format documentation |
| `expansionState` | Comma separated line numbers of expanded outlines |
| `vertScrollState` | Line number at top of window |
| `windowTop`, `windowLeft`, `windowBottom`, `windowRight` | Window position in pixels |

- Values are stored verbatim as strings (trimmed of surrounding whitespace).
  They are not reformatted, so an unusual date stays as written.
- A missing element is absent from `opml.head`. An empty element is stored as
  `""` and exported as an empty element.
- Any other head element (unknown, namespaced, or a repeated known element
  after its first occurrence) is stored as a node in `opml.headExtra`, in
  document order.

### `<body>`

The body is stored as an ordered tree of nodes in `opml.body`. Every element
in the body becomes a node:

```json
{
  "id": "n7",
  "element": "outline",
  "attributes": { "text": "Podcasting 2.0", "type": "rss", "xmlUrl": "https://…" },
  "children": []
}
```

- `element` is the qualified name as written (`outline`, or e.g. `foo:bar`).
- `attributes` keeps every attribute, in source order, values verbatim. This
  includes OPML 2.0 attributes (`text`, `type`, `isComment`, `isBreakpoint`,
  `created`, `category`, `xmlUrl`, `htmlUrl`, `title`, `description`,
  `language`, `version`, `url`) and any others, namespaced ones as
  `prefix:name`.
- `text` holds non-whitespace text content of a node, if any. `<outline>`
  normally has none.
- `id` is assigned by opmledit, unique within the document, stable across
  saves. It is used by the editor and API only and is never exported.

## Not preserved

- XML comments and processing instructions (other than the XML declaration).
- Whitespace, indentation, attribute quoting style, entity vs. literal
  character choice, and `CDATA` sections (content is kept, the wrapping is
  not).
- The order of elements in `<head>` (exported in the table order above,
  then `headExtra`).

## New documents

A new document starts as:

- `version` `"2.0"`, no namespaces, no extra attributes.
- `head.title` `"Untitled"`, `head.dateCreated` set to now (RFC 822).
- Empty body.

## Validation rules

Enforced on import and on every save.

- Every `outline` node must have a `text` attribute. On import, an outline
  without `text` gets `text` copied from `title`, else `""`; the editor flags
  empty `text`.
- `type="rss"` outlines should have `xmlUrl`; missing `xmlUrl` is a warning,
  not an error.
- Attribute and element names must be valid XML names; a prefix must be
  declared in `opml.namespaces` (prefix `xml` excepted).
- Attribute values must not contain characters that are invalid in XML 1.0.

Import reports the version it found. A missing or unknown version (not 1.0,
1.1 or 2.0) gives a warning, but the file is still read.

## Serialization (export)

The export chooses a target version: **as stored** (default), `2.0`, `1.1`
or `1.0`.

| Target | `version` attribute | Left out |
|--------|---------------------|----------|
| As stored | `opml.version`; no attribute if it is `""` | Nothing |
| 2.0 | `2.0` | Nothing |
| 1.1, 1.0 | `1.1` / `1.0` | Head `ownerId` and `docs`; namespace declarations, and every namespaced element and attribute |

`created`, `category` and types such as `include` are kept for 1.x, as plain
attributes (1.0 allows any attribute on an outline). The stored document is
not changed by an export. Before downloading, the editor lists what a 1.x
export will leave out, using the document it has loaded.

Steps:

1. XML declaration `<?xml version="1.0" encoding="UTF-8"?>`.
2. `<opml version="…">` (see above) with `xmlns:*` declarations from
   `opml.namespaces`, then `opml.attributes`.
3. `<head>`: known fields present in `opml.head` in table order, then
   `headExtra` nodes.
4. `<body>`: nodes in order, recursively. Nodes without children and text are
   written as empty elements (`<outline … />`).
5. Indented with two spaces.

`head.dateModified` is set to the save time on each save (RFC 822, UTC), so the
export reflects the last edit. If the user edits it by hand, the hand value is
kept for that save only.

## Edits that affect `expansionState`

`expansionState` refers to outline line numbers, which change when the body is
reordered. The editor keeps the value as text and shows a hint that it may no
longer be accurate after reordering. It is not recomputed.
