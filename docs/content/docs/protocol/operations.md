---
title: "Operations"
weight: 10
description: "POST /api/v1/ops: the one way notes change."
---

Every change to notes, whoever makes it, is an operation sent to `POST /api/v1/ops`. Operations come in a batch, `{"ops": [...]}`, of at most 1,000. The batch is applied in one transaction: if any operation fails, none of them happen, and the error says which one failed (`"Operation 2: ..."`).

## Nodes

Operations create and change **nodes**. The protocol shows a node as:

| Key | Meaning |
| --- | --- |
| `id` | A lower-case UUIDv7. Clients make ids themselves, so they can refer to a node before the server has seen it. |
| `kind` | `page`, `block`, `doc` or `file`. |
| `parent` | The parent page or block of a block; `null` for pages. |
| `page` | The page a block belongs to; a page's own id. |
| `sort` | The fractional sort key that orders a block among its siblings. |
| `title` | Pages, documents and files only. Unique among pages without regard to case. |
| `text` | The text, Markdown with notd's syntax. |
| `collapsed` | Whether the block's children are hidden. |
| `clock` | When it last changed, as a hybrid logical clock (`"0001732540000000-0000"`): milliseconds and a counter, a string that sorts in time order. |
| `created`, `updated` | Milliseconds since 1970. |

## The operations

| `op` | Keys | Does |
| --- | --- | --- |
| `create` | `kind` (`page` or `doc`, default `block`), `id`, and `title` for pages or `parent`, `text`, `sort` for blocks; optional `collapsed` | Creates a node. Without `sort`, a block goes after its last sibling. |
| `edit` | `id`, and `text` and/or `title` | Changes the text, or renames a page. |
| `move` | `id`, `parent`, optional `sort` | Moves a block, with its children, under another block or page. |
| `delete` | `id` | Deletes the node and everything under it. |
| `collapse` | `id`, `collapsed` | Hides or shows a block's children. |

Any operation may carry an `opId`, a string the client chooses (at most 100 characters). An `opId` that was applied before is skipped and reported as `"duplicate"`, so a client that lost a response can simply send the batch again.

The response lists a result per operation, and `seq`, the number of the last entry in the change feed after the batch.

## Examples

Create a page and two blocks under it. The second block has a sort key that puts it first:

```request
POST /api/v1/ops
{"ops": [
  {"op": "create", "kind": "page", "id": "01900000-0000-7000-8000-000000000001", "title": "Østfold"},
  {"op": "create", "id": "01900000-0000-7000-8000-000000000002", "parent": "01900000-0000-7000-8000-000000000001", "text": "Fredrikstad", "opId": "op-1"},
  {"op": "create", "id": "01900000-0000-7000-8000-000000000003", "parent": "01900000-0000-7000-8000-000000000001", "text": "Halden", "sort": "Zz"}
]}
```

```response
200
{"results": [
  {"opId": null, "status": "applied", "id": "01900000-0000-7000-8000-000000000001"},
  {"opId": "op-1", "status": "applied", "id": "01900000-0000-7000-8000-000000000002"},
  {"opId": null, "status": "applied", "id": "01900000-0000-7000-8000-000000000003"}
], "seq": 3}
```

Sending an operation with the same `opId` again changes nothing:

```request
POST /api/v1/ops
{"ops": [{"op": "create", "id": "01900000-0000-7000-8000-000000000002", "parent": "01900000-0000-7000-8000-000000000001", "text": "Fredrikstad", "opId": "op-1"}]}
```

```response
200
{"results": [{"opId": "op-1", "status": "duplicate"}], "seq": 3}
```

A page title is unique without regard to case, so this batch fails, and its first operation does not happen either:

```request
POST /api/v1/ops
{"ops": [
  {"op": "edit", "id": "01900000-0000-7000-8000-000000000002", "text": "Fredrikstad (changed)"},
  {"op": "create", "kind": "page", "title": "ØSTFOLD"}
]}
```

```response
409
{"error": {"code": "title_taken", "message": "Operation 1: A page titled \"Østfold\" already exists."}}
```

Edit, move, collapse and delete:

```request
POST /api/v1/ops
{"ops": [
  {"op": "edit", "id": "01900000-0000-7000-8000-000000000002", "text": "Fredrikstad, the old town"},
  {"op": "move", "id": "01900000-0000-7000-8000-000000000003", "parent": "01900000-0000-7000-8000-000000000002"},
  {"op": "collapse", "id": "01900000-0000-7000-8000-000000000002", "collapsed": true},
  {"op": "delete", "id": "01900000-0000-7000-8000-000000000003"}
]}
```

```response
200
{"results": ["…", "…", "…", "…"], "seq": 7}
```

A block cannot move into its own children:

```request
POST /api/v1/ops
{"ops": [
  {"op": "create", "id": "01900000-0000-7000-8000-000000000004", "parent": "01900000-0000-7000-8000-000000000002", "text": "child"},
  {"op": "move", "id": "01900000-0000-7000-8000-000000000002", "parent": "01900000-0000-7000-8000-000000000004"}
]}
```

```response
409
{"error": {"code": "cycle", "message": "…"}}
```

## Errors

| Code | Status | Meaning |
| --- | --- | --- |
| `bad_request` | 400 | A key is missing or has the wrong type, or the batch is too large. |
| `bad_op` | 400 | Unknown operation. |
| `bad_id` | 400 | An id that is not a lower-case UUID. |
| `bad_title` | 400 | An empty title, one longer than 255 characters, or one with control characters or square brackets. |
| `bad_sort` | 400 | Not a valid sort key. |
| `bad_parent` | 400 | The parent cannot have blocks (a document or a file). |
| `not_found` | 404 | No node with that id. |
| `title_taken` | 409 | Another page has that title. |
| `id_taken` | 409 | A node with that id exists. |
| `cycle` | 409 | The move would put a block inside itself. |
