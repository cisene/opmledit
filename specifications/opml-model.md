# OPML model

How OPML is read, represented and written.

OPML was created by **Dave Winer**, the father of OPML, at UserLand Software
in 2000 as the native format of the Radio UserLand outliner. He wrote and
still maintains the specifications at opml.org: OPML 1.0
(opml.org/spec1.opml) and OPML 2.0 (opml.org/spec2.opml). These are the
references for this document.

## Goal

Any version of OPML can be imported, and exported as 2.0, 1.1 or 1.0.

A valid uploaded file exported again without edits, in its own version, must
contain the same information: the same elements, attributes, attribute values, text
and namespaces, in the same order within the body. Formatting may differ.

## Versions

| Version | Specification | Differences |
|---------|---------------|-------------|
| Before 1.0 | None published. Files from early UserLand tools (Radio, Frontier), often with no `version` attribute | Root element `<outlineDocument>` instead of `<opml>` (stated in the 1.0 specification). Otherwise read like 1.0 |
| 1.0 | opml.org/spec1.opml (September 2000) | Head: `title`, `dateCreated`, `dateModified`, `ownerName`, `ownerEmail`, `expansionState`, `vertScrollState`, `window*`. Outline: `text`, `type`, `isComment`, `isBreakpoint`, plus any other attributes |
| 1.1 | None published. Common in feed reader and podcast app exports | Same as 1.0 |
| 2.0 | opml.org/spec2.opml (2006) | Adds head `ownerId` and `docs`; outline `created` and `category`; defines types `rss`, `link`, `include`; `text` required; namespaces allowed |

2.0 is a superset of the older versions, so one reader handles all of them.
The model below keeps everything in the file whatever its version. Converting
happens only on export.

## Parsing

Parsing is done on the server with PHP `DOMDocument`.

- Network access and entity substitution are disabled (`LIBXML_NONET`, no
  `LIBXML_NOENT`). A `DOCTYPE` is removed before parsing and never used.
- Everything is stored as UTF-8.
- Limits from config apply: file size, number of nodes, nesting depth (see
  [security.md](security.md)).

Import accepts broken files and makes them valid. Each fix adds a warning to
the import result, with a count, e.g. "14 unescaped `&` fixed". Only input that
contains no `outline` and no `opml` element at all (an HTML page, an
image, an empty file) is rejected with `422 invalid_opml`.

### 1. Strict parse

The file is parsed as well formed XML first. If that works, only the
structure fixes in step 4 apply, so a valid file round-trips unchanged.

### 2. Encoding

If the strict parse fails, or the text is not valid in its declared encoding:

1. Encoding comes from the BOM, else the XML declaration, else UTF-8.
2. If the bytes are not valid in that encoding, try UTF-8, then
   Windows-1252. Windows-1252 accepts any byte, so decoding always succeeds.
3. Double-encoded UTF-8 (mojibake such as `CafÃ©` for `Café`) is fixed per
   attribute value and text: if encoding the value as Windows-1252 gives
   valid UTF-8 that differs from it, that result is used.
4. Characters not allowed in XML 1.0 (control characters) are removed.

The XML declaration is rewritten to `encoding="UTF-8"`.

### 3. Text fixes and recovering parse

Before the second parse:

- `&` that does not start a valid entity or character reference becomes `&amp;`.
- HTML named entities (`&nbsp;`, `&eacute;`, …) become their characters,
  since OPML has no DTD to define them.
- `<` inside attribute values becomes `&lt;`.
- An attribute repeated on one element: the first one is kept.

Then the file is parsed with libxml's recover mode, which closes unclosed
elements and resolves mismatched end tags. The tree it returns is used
as-is.

### 4. Structure

Applied to every import:

- Root `<outlineDocument>` (before 1.0): renamed to `<opml>`.
- No `<opml>` root: one is created around the content.
- No `<head>` or `<body>`: created empty. Outlines outside `<body>` are moved
  into it, and head elements outside `<head>` into the head, in document order.
- Element and attribute names of known OPML items are matched
  case-insensitively and written in their proper form (`XMLURL`, `xmlurl` →
  `xmlUrl`; `<Outline>` → `<outline>`).
- An `outline` without `text` gets it from `title`, else `xmlUrl`, else
  `url`, else `"Untitled"`.
- A namespace prefix used without a declaration is declared as
  `urn:opmledit:undeclared:<prefix>`, so the name is kept.
- No `version` attribute: set to `2.0` if the file uses anything 2.0 added
  (`ownerId`, `docs`, `created`, `category`, namespaces), else `1.0`.

### Root `<opml>`

| Source | Stored as |
|--------|-----------|
| `version` attribute | `opml.version`, the string as written. Set on import when missing (see [Structure](#4-structure)) |
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

- Every `outline` node must have a `text` attribute (filled in on import, see
  [Structure](#4-structure)); the editor flags empty `text`.
- `type="rss"` outlines should have `xmlUrl`; missing `xmlUrl` is a warning,
  not an error.
- Attribute and element names must be valid XML names; a prefix must be
  declared in `opml.namespaces` (prefix `xml` excepted).
- Attribute values must not contain characters that are invalid in XML 1.0.

Import reports the version it found. A missing version is filled in (see
[Structure](#4-structure)). An unknown version (not 1.0, 1.1 or 2.0) gives a
warning, but the file is still read and the version kept.

## Serialization (export)

The export chooses a target version: **as stored** (default), `2.0`, `1.1`
or `1.0`.

| Target | `version` attribute | Left out |
|--------|---------------------|----------|
| As stored | `opml.version` | Nothing |
| 2.0 | `2.0` | Elements without a namespace that 2.0 does not define (unknown head elements, non-`outline` body elements) |
| 1.1, 1.0 | `1.1` / `1.0` | Head `ownerId`, `docs` and all `headExtra`; namespace declarations, and every namespaced element and attribute; non-`outline` body elements |

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

## Schemas

Published schemas, found October 2026:

| Version | Schema | License | Usable as is |
|---------|--------|---------|--------------|
| Before 1.0 | None found | — | — |
| 1.0 | DTD by Wayne Steele, posted to xml-dev on 18 September 2000 (xml.coverpages.org/opmlSteeleDTD.html), linked from the 1.0 specification | None stated | No. Allows only `text`, `type`, `isComment`, `isBreakpoint` on outlines |
| 2.0 | DLESE Program Center (UCAR), 2006: `opml.xsd` + `contents.xsd`, dlese.org/Metadata/opml/2.0/ (only on web.archive.org now) | GPL 2.0 or later | No. Declares a target namespace (`http://www.dlese.org/Metadata/opml`), which OPML files do not use |
| 2.0 | Eclipse Buckminster, `opml-2.0.xsd` (github.com/eclipse-archived/buckminster) | EPL 1.0 | No. Rejects any attribute it does not list, and `version` other than 1.0 / 2.0 |

None of them accepts what the OPML specifications allow: 1.0 permits any
attribute on an outline, and 2.0 adds namespaced elements and attributes.
That is what podcast files use (e.g. `podcast:` and `itunes:` attributes).

opmledit therefore has its own schemas, written from the specifications
with these as reference, in `specifications/xsd/`:

- `opml-1.0.xsd`, also used for 1.1 (same rules, `version` 1.0 or 1.1);
- `opml-2.0.xsd`.

Both allow any extra attribute on `outline` (`xs:anyAttribute
processContents="lax"`). The 2.0 schema also allows elements from other
namespaces in `head` and `outline`. They are used to:

- check every export to 2.0, 1.1 or 1.0 against that version's schema, in
  tests and before the file is sent. A failure is a bug in opmledit, logged
  and answered `500`. An "as stored" export is not checked: it gives back
  what was imported;
- report on import whether the original file was valid for its version
  (an import warning, never a rejection).

`DOMDocument::schemaValidate()` does the checking; no extra library is
needed.

## Edits that affect `expansionState`

`expansionState` refers to outline line numbers, which change when the body is
reordered. The editor keeps the value as text and shows a hint that it may no
longer be accurate after reordering. It is not recomputed.
