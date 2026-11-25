---
title: "Search"
weight: 25
description: "GET /api/v1/search: full-text search over titles and text."
---

`GET /api/v1/search?q=...` searches the titles and text of all nodes. Optional `limit` (default 50, at most 200) and `offset` page through the results.

| Typed | Finds |
| --- | --- |
| `kitchen tiles` | Notes with words starting with `kitchen` and `tiles`, in any order. |
| `"kitchen tiles"` | The exact phrase. |
| `tiles -bathroom` | `tiles`, but not `bathroom`. |

Case does not matter, in any language. Diacritics do: `ålesund` does not find `Alesund`. Title matches rank above text matches, then the best match by BM25.

Each result is `{"node", "pageTitle", "snippet"}`. `snippet` is a short piece of the text around the match, **already HTML-escaped**, with matches wrapped in `<mark>`, so a client can insert it as HTML.

## Examples

```request
POST /api/v1/ops
{"ops": [
  {"op": "create", "kind": "page", "id": "01900000-0000-7000-8000-0000000000a1", "title": "Kitchen renovation"},
  {"op": "create", "parent": "01900000-0000-7000-8000-0000000000a1", "text": "Choose tiles for the kitchen floor"},
  {"op": "create", "parent": "01900000-0000-7000-8000-0000000000a1", "text": "Bathroom tiles are <b>cheaper</b>"}
]}
```

```response
200
{"seq": 3}
```

```request
GET /api/v1/search?q=tiles%20-kitch
```

```response
200
[{"node": {"text": "Bathroom tiles are <b>cheaper</b>"}, "pageTitle": "Kitchen renovation",
  "snippet": "Bathroom <mark>tiles</mark> are &lt;b&gt;cheaper&lt;/b&gt;"}]
```

```request
GET /api/v1/search?q=%22kitchen%20floor%22
```

```response
200
[{"node": {"text": "Choose tiles for the kitchen floor"}, "snippet": "Choose tiles for the <mark>kitchen floor</mark>"}]
```

```request
GET /api/v1/search?q=kitchen&limit=1
```

```response
200
[{"node": {"title": "Kitchen renovation"}, "snippet": "<mark>Kitchen</mark> renovation"}]
```
