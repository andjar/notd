---
title: "Search"
weight: 80
---

Provides powerful search capabilities. It can search by term, find backlinks, list tasks, or list favorited pages.

**General Parameters for search types returning notes (e.g., `q`, `backlinks_for_page_name`, `tasks`):**
*   `include_parent_properties` (optional, boolean): Defaults to `false`. If set to `true`, each note item in the `results` array will include a `parent_properties` field. This field contains an object with aggregated properties from all direct and indirect parent notes. The structure mirrors the main `properties` field. For search types that do not return notes (e.g., `favorites`), this parameter has no effect.

### `GET /api/v1/search.php?q={term}`
*   **Description**: Performs a full-text search across note content. Also supports pagination parameters (`page`, `per_page`).
*   **Example URL**: `/api/v1/search.php?q=meeting&include_parent_properties=true`
*   **Response (200 OK) when `include_parent_properties=true`**:
    ```json
    {
        "status": "success",
        "data": {
            "results": [
                {
                    "note_id": 42,
                    "content": "The full content of the note mentioning the search term.",
                    "page_id": 10,
                    "page_name": "Project Alpha",
                    "content_snippet": "... with the search <mark>term</mark> highlighted ...",
                    "properties": { "status": [{"value": "TODO"}] },
                    "parent_properties": { "project_code": [{"value": "Alpha"}] }
                }
            ],
            "pagination": { "total_items": 1, "current_page": 1, "per_page": 20, "total_pages": 1 }
        }
    }
    ```
*   If `include_parent_properties` is `false` (default), the `parent_properties` field will be an empty object (`{}`) or `null`.

The Search endpoint also supports other modes:

### `GET /api/v1/search.php?backlinks_for_page_name={page_name}`
*   **Description**: Finds all notes that link to the specified `page_name`. Supports pagination and `include_parent_properties`.
*   **(Response structure similar to `q` search but `content_snippet` will highlight the link)**

### `GET /api/v1/search.php?tasks={status}`
*   **Description**: Finds all notes with a `{status::TODO}` or `{status::DONE}` etc. property. `status` can be `TODO`, `DONE`, ..., or `ALL`. Supports pagination and `include_parent_properties`.
*   **(Response structure similar to `q` search but `content_snippet` will highlight the status property)**

### `GET /api/v1/search.php?agenda={YYYY-MM-DD}`
*   **Description**: Notes with `{scheduled::...}` or `{deadline::...}` on that day, as on a journal page's "Scheduled and due" list. Add `&overdue=1` to also get open tasks dated earlier. Tasks that are `DONE` or `CANCELLED` are left out; notes that are not tasks are returned for their own day only. Supports pagination.
*   **Result fields** (besides `note_id`, `content`, `page_id`, `page_name`, `properties`): `status` (the task keyword, or `null`), `scheduled` and `deadline` (the property values, or `null`), `date` (the earliest of the two), `overdue`.
*   **Order**: overdue first, then by date, deadlines before scheduled notes on the same day.
*   **Example**: `GET /api/v1/search.php?agenda=2026-11-16&overdue=1`

### `GET /api/v1/search.php?favorites=true`
*   **Description**: Finds all pages that have a `{favorite::true}` property. Supports pagination. (`include_parent_properties` is not applicable here as it returns pages, not notes).
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "results": [
                {
                    "page_id": 15,
                    "page_name": "My Favorite Project Page"
                    // Other page fields might be present
                }
            ],
            "pagination": { "total_items": 1, "current_page": 1, "per_page": 20, "total_pages": 1 }
        }
    }
    ```
