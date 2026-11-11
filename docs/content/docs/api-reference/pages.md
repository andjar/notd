---
title: "Pages"
weight: 50
---

A page has a unique name (compared case-insensitively) and its own `content`, a piece of text whose `{key::value}` properties become the page's properties.

Update and delete are sent as POST with `_method` in the body, so they work on servers that only allow GET and POST.

### Read

| Request | Returns |
|---|---|
| `GET pages.php?name=Name` | The page with that name. It is created if it does not exist (date names get `{type::journal}`). |
| `GET pages.php?id=25` | One page, or 404. |
| `GET pages.php` | A list, most recently changed first: `?page`, `?per_page` (max 100), `?exclude_journal=1`. The response has `data` and `pagination`. |
| `GET pages.php?names=1` | The names of all pages, as an array of strings (`&exclude_journal=1` leaves out date pages). Used for link suggestions. |
| `GET pages.php?month=2026-11` | The days of that month that have a journal page: `[{"date": "2026-11-10", "page_name": "2026-11-10"}]`. Used by the calendar. |
| `GET pages.php?date=2026-11-10` | Pages with a `{date::2026-11-10}` property. |
| `GET child_pages.php?namespace=Projects` | The pages directly below `Projects` (`Projects/Alpha`, not `Projects/Alpha/Notes`). |
| `GET export.php?page=Name` | The page as Markdown (`text/markdown`), in Logseq's layout. `&download=1` sends it as a file; `?page_id=25` works too. |

A page looks like this:

```json
{
  "id": 25,
  "name": "Projects/Alpha",
  "content": "{type::project}\n{lead:::Jane}",
  "created_at": "2026-11-10 10:35:00",
  "updated_at": "2026-11-10 10:40:00",
  "properties": {
    "type": [{"value": "project", "internal": false}],
    "lead": [{"value": "Jane", "internal": true}]
  }
}
```

### Create

```json
POST pages.php
{"name": "New Project", "content": "{type::project}"}
```

Answers 201 with the page. Leading and trailing spaces and doubled slashes are removed from the name; an existing name answers 409.

### Update

```json
POST pages.php
{"_method": "PUT", "id": 25, "name": "Projects/Beta", "content": "{type::project}"}
```

Both `name` and `content` are optional. Changing `content` re-indexes the page's properties (removed properties disappear). Changing `name` renames the page in one transaction:

- every `[[Old Name]]` link in notes and page content becomes `[[New Name]]`;
- child pages follow: `Projects/Alpha/Notes` becomes `Projects/Beta/Notes`;
- a name already used by another page answers 409 and changes nothing.

### Delete

```json
POST pages.php
{"_method": "DELETE", "id": 25}
```

Deletes the page, its notes and their attachments. Answers `{"deleted_page_id": 25}`.
