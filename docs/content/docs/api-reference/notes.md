---
title: "Notes (read)"
weight: 40
---

`notes.php` is **read-only**. Every change to notes goes through [`ops.php`]({{< relref "ops.md" >}}).

A note looks like this:

```json
{
    "id": "01a1c6b0-ebb1-72da-82de-1d78e583e241",
    "page_id": 87,
    "parent_note_id": null,
    "sort_key": "a0",
    "content": "Project overview {priority::high}",
    "internal": 0,
    "collapsed": 0,
    "active": 1,
    "version": 1,
    "created_at": "2026-11-10 18:20:45",
    "updated_at": "2026-11-10 18:20:45",
    "properties": {
        "priority": [{ "value": "high", "internal": false }]
    },
    "has_attachments": false
}
```

| Field | Meaning |
|---|---|
| `id` | Opaque string, at most 64 characters of `A-Z a-z 0-9 _ -`. New notes get a UUIDv7; notes migrated from older databases keep ids such as `"123"`. |
| `parent_note_id` | Id of the parent note, or `null` for a top-level note. |
| `sort_key` | Position among siblings. Siblings are ordered by `(sort_key, id)` using plain byte comparison. See [Ordering]({{< relref "ops.md#ordering" >}}). |
| `version` | Incremented on every change to the note. |
| `properties` | Index of the properties found in `content`, grouped by name. `internal` is `true` for weight 3 and above. |

### `GET /api/v1/notes.php?page_id={id}`

All notes of a page as a flat list, ordered by `sort_key, id`. Build the tree from `parent_note_id`.

| Parameter | Default | Meaning |
|---|---|---|
| `include_internal` | `false` | Also return properties of weight 3 and above. |

### `GET /api/v1/notes.php?id={id}`

One note. Returns `404` if it does not exist.

| Parameter | Default | Meaning |
|---|---|---|
| `include_internal` | `false` | Also return properties of weight 3 and above. |
| `include_parent_properties` | `false` | Add `parent_properties`: the properties of all ancestors, in the same shape as `properties`. |
| `include_children` | `false` | Add `children`: the nested subtree of the note (up to 10 levels). |

### Any other method

Returns `405` with a message pointing to `ops.php`.
