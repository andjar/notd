---
title: "Queries"
weight: 30
description: "GET /api/v1/query: the query language and its results."
---

`GET /api/v1/query?q=...` runs a query over all nodes. The same language is used inside notes as `{{query ...}}`: the browser app shows the results in place (see the [guide](../../guide/#queries)).

## The language

A query is a list of filters, all of which must hold, plus options.

| Filter | Matches nodes |
| --- | --- |
| `type:movie` | with the field `{type::movie}` (the value compared without regard to case) |
| `type!=movie` | without that value |
| `rating>=4`, `>`, `<`, `<=` | whose field compares so; numbers as numbers, anything else as text, so ISO dates work: `watched>2026-01-01` |
| `has:rating` | that have the field at all |
| `tag:scifi` | tagged `#scifi` (or with `{tags:: scifi}`) |
| `links:Page` | that link to or tag the page |
| `page:Title` | on that page (its blocks, not the page itself) |
| `kind:page`, `kind:block` | of that kind |
| `created:2026-11-25`, `updated>=2026-11-01` | changed on, after or before a date |
| `kitchen`, `"space opera"` | whose text contains the words (as prefixes) or the phrase, as in [search](../search/) |

A leading `-` negates a filter: `-tag:scifi`, `-has:rating`. `OR` joins the two filters on either side of it: `tag:horror OR type:book rating>4` means "(horror or book) and rating above 4". Values with spaces go in quotes: `page:"New York City"`.

| Option | Does |
| --- | --- |
| `sort:rating`, `sort:-rating` | Sorts by a field, ascending or (with `-`) descending; nodes without the field come last. Also `title`, `text`, `created`, `updated`. Can be repeated. Without it, the most recently changed come first. |
| `limit:20` | At most this many results (default 100, at most 1,000). |
| `group:type` | Groups the results by a field's value. |
| `view:...`, `columns:...` | Passed back untouched in `options`, for whoever shows the result. |

The words `sort`, `limit`, `group`, `view` and `columns` are therefore not usable as field names in a filter.

## Results

Without `group:`, the result is `{"nodes": [...], "options": {...}}`. Each node has the usual keys plus `pageTitle` and `fields`, an object of every field on it: `{"rating": ["4"]}`. With `group:`, it is `{"groups": [{"value": "movie", "nodes": [...]}, ...]}`, groups in order of their value and nodes without the field last (with `"value": null`).

A query that cannot be understood is refused with `400` and the code `bad_query`, with a message that says why.

## Examples

```request
POST /api/v1/ops
{"ops": [
  {"op": "create", "kind": "page", "id": "01900000-0000-7000-8000-0000000000a1", "title": "Films"},
  {"op": "create", "parent": "01900000-0000-7000-8000-0000000000a1", "text": "Dune {type::movie} {rating:: 4.5} #scifi"},
  {"op": "create", "parent": "01900000-0000-7000-8000-0000000000a1", "text": "Heat {type::movie} {rating:: 3}"},
  {"op": "create", "parent": "01900000-0000-7000-8000-0000000000a1", "text": "Solaris {type::book} {rating:: 5} #scifi"}
]}
```

```response
200
{"seq": 4}
```

```request
GET /api/v1/query?q=type:movie%20rating>=4
```

```response
200
{"nodes": [{"text": "Dune {type::movie} {rating:: 4.5} #scifi", "pageTitle": "Films",
            "fields": {"type": ["movie"], "rating": ["4.5"]}}], "options": {}}
```

```request
GET /api/v1/query?q=tag:scifi%20sort:-rating%20view:table
```

```response
200
{"nodes": [{"text": "Solaris {type::book} {rating:: 5} #scifi"}, {"text": "Dune {type::movie} {rating:: 4.5} #scifi"}],
 "options": {"view": "table"}}
```

```request
GET /api/v1/query?q=page:films%20group:type%20sort:rating
```

```response
200
{"groups": [{"value": "book", "nodes": ["…"]}, {"value": "movie", "nodes": [{"text": "Heat {type::movie} {rating:: 3}"}, "…"]}], "options": {}}
```

```request
GET /api/v1/query?q=type:movie%20OR
```

```response
400
{"error": {"code": "bad_query", "message": "OR needs a filter after it."}}
```
