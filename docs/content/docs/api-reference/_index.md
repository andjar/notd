---
title: "API Reference"
weight: 20
---

Everything the notd frontend does goes through this HTTP API, so scripts and extensions have the same abilities as the UI.

*   **Base URL:** `/api/v1/` below the folder notd is served from. Endpoints are plain PHP files (`/api/v1/pages.php`); on Apache the `.php` suffix can be omitted.
*   **No authentication.** notd is made for a single user on their own machine. Put it behind HTTP authentication if other machines can reach it.
*   **GET and POST only.** Updates and deletes are POST requests with `"_method": "PUT"` or `"_method": "DELETE"` in the JSON body, because some embedded web servers (phpdesktop) only pass GET and POST.
*   **One response envelope.** Success: `{"status": "success", "data": ...}`. Failure: `{"status": "error", "message": "..."}` with a `4xx` or `5xx` status code.
*   **Content is the source of truth.** Properties are parsed from the `content` of notes and pages. The `Properties` table is an index of that content and is never written directly.

| Task | Endpoint |
|---|---|
| Add notes from a script | [`append_to_page.php`]({{< relref "append-to-page.md" >}}) |
| Create, edit, move, delete notes | [`ops.php`]({{< relref "ops.md" >}}) |
| Read notes | [`notes.php`]({{< relref "notes.md" >}}) |
| Pages and namespaces | [`pages.php`, `child_pages.php`]({{< relref "pages.md" >}}) |
| Full-text search, backlinks, tasks, favorites | [`search.php`]({{< relref "search.md" >}}) |
| Find notes with SQL | [`query_notes.php`]({{< relref "query-notes.md" >}}) |
| Files | [`attachments.php`]({{< relref "attachments.md" >}}) |
| Templates | [`templates.php`]({{< relref "templates.md" >}}) |
| Notifications to other systems | [`webhooks.php`]({{< relref "webhooks.md" >}}) |

## General Concepts

### Content-Driven Properties

The `Properties` table is an index populated by parsing the `content` of a note or page.

#### Syntax

Properties are defined within content using a key, a variable number of colons (`:`), and a value, optionally enclosed in curly braces `{}`. The number of colons determines the property's `weight`.

*   `key::value` or `{key::value}` -> **Weight 2** (Default, public property)
*   `key:::value` or `{key:::value}` -> **Weight 3** (Internal property)
*   `key::::value` or `{key::::value}` -> **Weight 4** (System property, e.g., for logging)

#### Special Properties

The system automatically adds certain special properties with specific weights to track different types of content:

*   **SQL Properties** (Weight 3): Added when SQL code blocks are detected in the content. Used to track and manage SQL queries.
*   **Task Properties** (Weight 4): Added for task management, tracking task states like TODO, DOING, DONE, etc.
*   **Done At Properties** (Weight 3): Automatically added when a task is marked as done, recording the completion timestamp.
*   **Transclusion Properties** (Weight 3): Added when content from other notes is embedded (transcluded) into the current note.
*   **Link Properties** (Weight 3): Added when the note contains links to other pages or notes.
*   **URL Properties** (Weight 3): Added when the note contains urls.

These special properties are managed by the system and should not be manually modified. They are used for internal tracking and functionality.

#### Configuration

The behavior of properties based on their weight is defined by `PROPERTY_WEIGHTS` (defaults in `config.php`, overrides in `config.local.php`) and interpreted by the **frontend**. The API's role is simply to parse and return the properties with their weight.

**Default:**
```php
define('PROPERTY_WEIGHTS', [
    2 => [
        'label' => 'Public',
        'update_behavior' => 'replace', // 'replace' or 'append'
        'visible_in_view_mode' => true,
        'visible_in_edit_mode' => true
    ],
    3 => [
        'label' => 'Internal',
        'update_behavior' => 'replace',
        'visible_in_view_mode' => false,
        'visible_in_edit_mode' => true
    ],
    4 => [
        'label' => 'System Log',
        'update_behavior' => 'append',
        'visible_in_view_mode' => false,
        'visible_in_edit_mode' => false
    ]
]);
```

#### Backend Logic

When a note or page is created or updated:
1.  The backend receives the new `content`.
2.  It parses all property strings from the content.
3.  For each parsed property, it intelligently updates the `Properties` table:
    *   It compares the parsed properties with what's currently in the `Properties` table for that entity.
    *   If a property's weight is configured with `update_behavior: 'replace'`, it finds and updates the existing row for that property name or inserts a new one if it doesn't exist.
    *   If a property's weight is configured with `update_behavior: 'append'`, it **always inserts a new row**, creating a history of values.
    *   Properties that existed in the table but are no longer in the content are deleted (unless their weight config specifies they should be preserved).
4.  This logic ensures timestamps (`created_at`, `updated_at`) in the `Properties` table are meaningful.

### Pagination

Endpoints that return a list of items support pagination via URL parameters.

*   `page` (optional, integer): The page number for pagination. Defaults to `1`.
*   `per_page` (optional, integer): The number of items to return per page. Defaults to `20`, max `100`.

The response for a paginated list will include a `pagination` object:

```json
"pagination": {
    "total_items": 150,
    "per_page": 20,
    "current_page": 2,
    "total_pages": 8
}
```

### Error Responses

Errors are returned with a `4xx` or `5xx` status code:

```json
{
    "status": "error",
    "message": "A human-readable error message."
}
```

Some validation errors add a `details` object. Unexpected server errors only include file names and stack traces when `NOTD_DEBUG` is enabled; they are always written to the PHP error log.
