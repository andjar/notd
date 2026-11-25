---
title: "Reading"
weight: 15
description: "Pages, nodes, backlinks, page names and journal days."
---

All reads are `GET`. Lists of blocks are flat and in **outline order**: depth first, siblings by sort key, so a client can rebuild the tree from `parent` in one pass.

| Endpoint | Returns |
| --- | --- |
| `page?title=...` or `page?id=...` | `{"page", "via", "blocks"}`: the page and all its blocks. A title is matched without regard to case, then against aliases (`"via": "alias"`). A title that is a date (`2026-11-25`) is a journal page and is created if it does not exist. |
| `node?id=...` | `{"node", "descendants"}`: any node and everything under it. |
| `backlinks?id=...` | For a page: blocks on other pages that link to it, tag it or embed it, also through its aliases. For a block: blocks that reference or embed it. Grouped by page: `[{"page": {"id", "title"}, "blocks": [...]}]`. |
| `pages` | Every page and document: `id`, `kind`, `title`, `updated`, `aliases`, ordered by title. |
| `journal-days?month=YYYY-MM` | The dates in that month whose journal page has some text. |
| `journal-days?before=YYYY-MM-DD&limit=10` | The latest dates before that day whose journal page has some text, newest first (at most 100). |

## Examples

A page with an alias, and a journal entry that links to it through the alias:

```request
POST /api/v1/ops
{"ops": [
  {"op": "create", "kind": "page", "id": "01900000-0000-7000-8000-0000000000a1", "title": "New York City", "text": "{alias:: NYC}"},
  {"op": "create", "id": "01900000-0000-7000-8000-000000000001", "parent": "01900000-0000-7000-8000-0000000000a1", "text": "Boroughs", "sort": "a0"},
  {"op": "create", "id": "01900000-0000-7000-8000-000000000002", "parent": "01900000-0000-7000-8000-000000000001", "text": "Brooklyn", "sort": "a0"},
  {"op": "create", "kind": "page", "id": "01900000-0000-7000-8000-0000000000b1", "title": "2026-11-20"},
  {"op": "create", "id": "01900000-0000-7000-8000-000000000003", "parent": "01900000-0000-7000-8000-0000000000b1", "text": "Flew to [[nyc]]"}
]}
```

```response
200
{"seq": 5}
```

```request
GET /api/v1/page?title=new%20york%20city
```

```response
200
{"page": {"id": "01900000-0000-7000-8000-0000000000a1", "kind": "page", "title": "New York City", "text": "{alias:: NYC}", "parent": null},
 "via": null,
 "blocks": [
   {"id": "01900000-0000-7000-8000-000000000001", "parent": "01900000-0000-7000-8000-0000000000a1", "text": "Boroughs"},
   {"id": "01900000-0000-7000-8000-000000000002", "parent": "01900000-0000-7000-8000-000000000001", "text": "Brooklyn"}
 ]}
```

```request
GET /api/v1/page?title=NYC
```

```response
200
{"page": {"title": "New York City"}, "via": "alias", "blocks": ["…", "…"]}
```

```request
GET /api/v1/backlinks?id=01900000-0000-7000-8000-0000000000a1
```

```response
200
[{"page": {"id": "01900000-0000-7000-8000-0000000000b1", "title": "2026-11-20"},
  "blocks": [{"id": "01900000-0000-7000-8000-000000000003", "text": "Flew to [[nyc]]"}]}]
```

```request
GET /api/v1/node?id=01900000-0000-7000-8000-000000000001
```

```response
200
{"node": {"text": "Boroughs"}, "descendants": [{"text": "Brooklyn"}]}
```

```request
GET /api/v1/pages
```

```response
200
[{"title": "2026-11-20", "aliases": []}, {"title": "New York City", "aliases": ["NYC"]}]
```

```request
GET /api/v1/journal-days?month=2026-11
```

```response
200
["2026-11-20"]
```

```request
GET /api/v1/journal-days?before=2026-11-25&limit=5
```

```response
200
["2026-11-20"]
```

Visiting a day that has no journal yet creates it:

```request
GET /api/v1/page?title=2026-11-21
```

```response
200
{"page": {"title": "2026-11-21", "kind": "page"}, "blocks": []}
```

Any other unknown title is not found:

```request
GET /api/v1/page?title=Atlantis
```

```response
404
{"error": {"code": "not_found", "message": "…"}}
```
