---
title: "Change feed"
weight: 40
description: "GET /api/v1/changes: what changed since a client last looked."
---

Every change gets a number, `seq`, that only goes up; the `seq` in an operation's response is the number of its last change. A client keeps the last number it has seen and asks `GET /api/v1/changes?since=N` for what came after. This is how other devices and other tabs stay current: they poll the feed, by default every 10 seconds.

The response is `{"changes": [...], "next": N, "more": false}`:

- `changes` lists at most one entry per node, its latest change in this batch, oldest first: `{"seq", "op", "id", "clock", "at", "node"}`. `node` is the node as it is now, or `null` if it has been deleted (and then `op` is `delete`, whatever the change was).
- `next` is the number to send as `since` next time.
- `more` is `true` when there may be more right away; ask again with `since=next`. `limit` (default 500, at most 2,000) sets the size of a batch.

## Example

```request
POST /api/v1/ops
{"ops": [
  {"op": "create", "kind": "page", "id": "01900000-0000-7000-8000-0000000000a1", "title": "Inbox"},
  {"op": "create", "id": "01900000-0000-7000-8000-000000000001", "parent": "01900000-0000-7000-8000-0000000000a1", "text": "draft"}
]}
```

```response
200
{"seq": 2}
```

Another device edits the block twice and deletes nothing; a client that has seen up to 2 gets one entry with the final text:

```request
POST /api/v1/ops
{"ops": [
  {"op": "edit", "id": "01900000-0000-7000-8000-000000000001", "text": "second draft"},
  {"op": "edit", "id": "01900000-0000-7000-8000-000000000001", "text": "final"}
]}
```

```response
200
{"seq": 4}
```

```request
GET /api/v1/changes?since=2
```

```response
200
{"changes": [{"seq": 4, "op": "edit", "id": "01900000-0000-7000-8000-000000000001", "clock": "…", "at": "…",
              "node": {"text": "final"}}],
 "next": 4, "more": false}
```

```request
GET /api/v1/changes?since=4
```

```response
200
{"changes": [], "next": 4, "more": false}
```
