---
title: "Templates"
weight: 100
---

Templates are plain Markdown files in `assets/template/note/` and `assets/template/page/`. They are text only: nothing in them is executed.

### List

```text
GET templates.php?type=note&tz=Europe/Oslo
```

```json
{"status": "success", "data": [{"name": "meeting", "content": "## Meeting 2026-11-11\n- Attendees: "}]}
```

Placeholders are filled in when listed, in the time zone given by `tz` (the server's otherwise): `{{date}}`, `{{time}}`, `{{datetime}}`, `{{year}}`, `{{month}}`, `{{day}}`, `{{weekday}}`, `{{timestamp}}`, `{{random}}`, `{{uuid}}`. Date placeholders take a PHP date format: `{{date:d.m.Y}}`.

### Create, update, delete

```json
POST templates.php  {"type": "note", "name": "daily-review", "content": "## Review\n- "}
POST templates.php  {"_method": "PUT", "type": "note", "current_name": "daily-review", "new_name": "review", "content": "..."}
POST templates.php  {"_method": "DELETE", "type": "note", "name": "review"}
```

Names may contain letters, digits, `-` and `_`. Creating a name that exists answers 409.
