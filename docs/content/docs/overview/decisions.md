---
title: "Decisions"
weight: 30
description: "Why notd 4 is built the way it is. New decisions are added at the end."
---

Each entry says what was decided, and why. A decision that is later changed is not deleted; a new entry replaces it and says so.

## 1. A clean rewrite, on the `next` branch (2026-11-25)

notd 3 grew around many tables and features that the core had to know about. notd 4 starts again around one table of nodes and a small core. It lives on `next` so that notd 3 stays usable on `main` until notd 4 is good enough for daily use. Notes move over through the Markdown import; there are no migration scripts.

## 2. PHP, SQLite and plain JavaScript, no build step (2026-11-25)

These are the parts most likely to still run, unchanged, in twenty years. No Composer or npm packages at runtime; third-party browser libraries are committed as prebuilt files, each with a script to rebuild it.

## 3. One server instead of peer-to-peer sync (2026-11-25)

notd has one user. A server every device can reach, with a change feed, is far simpler than merging replicas, and conflicts are rare enough that keeping both versions is the right answer.

## 4. The text is the source of truth (2026-11-25)

Fields (`{key::value}`), links and tags are written in the note and parsed into indexes. The indexes can always be rebuilt, the Markdown export loses nothing, and nothing depends on hidden metadata.

## 5. Links and backlinks are core; everything else extra is a plugin (2026-11-25)

Linking is what makes an outliner a notebook, so it stays in the core. Tasks, kanban, SQL, citations, git and the rest are plugins on the public API, to avoid the feature creep that made Logseq large. CI enforces a line budget on the core.

## 6. Plugin behaviour is code in files, not a rule language (2026-11-25)

Reacting to changes ("tag movies without a rating") is done by small PHP action files. A rule language written in notes was considered and rejected as more to learn and debug than a few lines of PHP.

## 7. lit-html instead of Alpine.js (2026-11-25)

Alpine is built for sprinkling behaviour on server-rendered HTML, keeps logic in attribute strings and cost about 150 ms of DOM observation on a 3,000-note page. lit-html is 3 KB compressed, plain JavaScript template literals, with no virtual DOM. Mithril and Preact with htm were considered; their component models are not needed. Plugins are not bound to it.

## 8. Version history through Markdown export and git (2026-11-25)

A deterministic export committed by the git plugin gives history in a format every tool can read, without building versioning into the database.

## 9. One milestone, and frameworks only on their second use (2026-11-25)

The first plan tried to build every framework (exports, templates, layout from notes) before using notd 4. The cut: reach daily use after about 40 small pull requests (phases 0 to 3), then build each framework when a second real use asks for it. Accepted gaps: native mobile apps, live collaboration, whiteboards, flashcards, PDF annotation, AI. If daily use is not reached in about three months of part-time work, the plan is reconsidered, including adopting SiYuan or TriliumNext instead.

## 10. Plain HTTP and JSON, GET and POST only (2026-11-25)

The protocol is `/api/v1/<name>`, GET for reads and POST with a JSON body for writes, errors as `{"error": {"code", "message"}}`. Minimal web servers such as phpdesktop handle only GET and POST, and plain JSON over HTTP is the most likely to still be understood by tools in twenty years.

## 11. Pages addressed in the fragment, and a strict Content-Security-Policy (2026-11-25)

The browser app addresses pages as `#/page/<title>`. Unlike paths, fragments never reach the server, so notd runs from any folder of any web server (Apache, phpdesktop, `php -S`) without rewrite rules, and every relative URL keeps working. The app page allows scripts only from notd itself (`script-src 'self'`), and has no inline script ([decision 14](#14-no-import-map-relative-imports-and-lf-line-endings-2026-11-25)).

## 12. Import is a tool, not part of the core (2026-11-25)

Reading a Logseq graph or a notd 3 export is done once, from the command line, so the importer lives in `tools/lib/import.php` and does not count towards the core's size budget. It still goes through the operations pipeline, so imported notes are indexed exactly like typed ones. This kept the core within 2,500 lines of PHP once actions and jobs were added.

## 13. Outside effects run as jobs after the change (2026-11-25)

Actions may run programs, but only through a job queue written in the same transaction as the change and run after the answer, with a time limit, a log, retries and keys that coalesce repeated jobs. A program never runs inside a change, never through a shell, and never for a change that did not happen.

## 14. No import map: relative imports and LF line endings (2026-11-25)

The first version had an inline import map, so modules could import `lit-html` by name, and the page's CSP allowed it by its hash, computed by the server from the file. On a Windows checkout with CRLF line endings the server hashed the bytes on disk, while the browser hashes the script after its parser has turned CRLF into LF. The hashes never matched, the browser refused the import map, and every module that imported `lit-html` by name failed, so notd did not start. An import map in a file of its own (`<script type="importmap" src>`) would need no hash, but browsers do not load one.

So there is no import map and no inline script. Every module, core, vendored or plugin, imports by relative path to a `.js` file (lit-html through `client/lib/html.js`), and the CSP is plain `script-src 'self'`. `.gitattributes` checks text files out with LF line endings everywhere, so notd serves the same bytes on every system. A Node test rejects bare names, `.mjs` files and inline scripts, and a browser test runs the app under its real CSP from a copy with CRLF line endings. The cost: a plugin imports lit-html as `../../client/lib/html.js` rather than as `lit-html`.

## 15. The look: a quiet page with serif titles (2026-11-27)

The first interface worked but looked like a default: capitalised labels over every panel, lines between everything, a full-width bar and a status line for one word, text glyphs for icons, browser checkboxes, and titles, boxes and notes that each started at a different place. The aim is elegant, inviting and clean, without the flat look of Material or Apple's glass.

So the note text keeps the system's sans-serif, which reads well on every platform, while titles and headings use Newsreader, a serif drawn for screens. It is vendored (OFL) so it looks the same everywhere. The colours are warm greys with an ink-blue accent, in light and dark, and every text colour reaches 4.5:1 contrast. Small labels are in sentence case: all-caps text is harder to read, and it shouts. Space and a tinted sidebar separate things before a line does. Titles, the agenda, linked references and the history all line up with the note text, with bullets and toggles hanging to the left. Icons are drawn (SVG), checkboxes are styled, and focus shows as an accent ring. The sidebar runs the full height of the window, and the save status moved into the bar above the page. Journal pages keep their `YYYY-MM-DD` titles but are shown as dates in the reader's language.
