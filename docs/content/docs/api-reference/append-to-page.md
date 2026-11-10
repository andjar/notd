---
title: "Append to Page"
weight: 60
---

The simplest way for a script to add notes: no ids, no ordering.

### `POST /api/v1/append_to_page.php`

Appends notes at the end of a page. The page is created if it does not exist (a page named like a date, `YYYY-MM-DD`, becomes a journal page).

```bash
curl -X POST http://localhost:8000/api/v1/append_to_page.php \
  -H 'Content-Type: application/json' \
  -d '{
        "page_name": "My Project",
        "notes": [
          { "content": "Project overview {priority::high}",
            "children": [ { "content": "TODO Research competitors" } ] },
          { "content": "Second note" }
        ]
      }'
```

| Field | Meaning |
|---|---|
| `page_name` | Required. Name of the page. |
| `notes` | Required. Either a string (one note) or a list of `{ "content": "...", "children": [...] }` objects. `children` nest to any depth. |

**Response (200)**

```json
{
    "status": "success",
    "data": {
        "message": "Page created and notes appended successfully.",
        "page": { "id": 87, "name": "My Project", "...": "..." },
        "appended_notes": [
            { "id": "01a1c6b0-ebb1-72da-82de-1d78e583e241", "parent_note_id": null, "sort_key": "a0",
              "content": "Project overview {priority::high}", "...": "..." }
        ]
    }
}
```

`appended_notes` is a flat list of every note that was created, in the shape described under [Notes]({{< relref "notes.md" >}}).
