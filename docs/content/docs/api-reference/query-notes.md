---
title: "Query Notes"
weight: 90
---

Runs a SQL query that selects note ids and returns the matching notes. This is what `SQL{...}` blocks in notes use.

The query runs on a **read-only** connection. Unless `ADVANCED_QUERY_MODE` is enabled in the configuration, it must also:

*   start with one of these forms:
    *   `SELECT id FROM Notes WHERE ...`
    *   `SELECT [DISTINCT] N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE ...`
    *   `SELECT id FROM Notes WHERE id IN (SELECT note_id FROM Properties WHERE ...`
*   contain no semicolon and none of the words `INSERT`, `UPDATE`, `DELETE`, `DROP`, `TRUNCATE`, `ALTER`, `EXEC`, `CREATE`, `ATTACH`, `DETACH`;
*   only refer to these columns when using a table prefix: `id`, `content`, `page_id`, `parent_note_id`, `created_at`, `updated_at`, `sort_key`, `version`, `collapsed`, `active` (Notes) and `note_id`, `name`, `value`, `weight` (Properties).

The first selected column must be the note id. Do not add `LIMIT`; use `page` and `per_page`.

### `POST /api/v1/query_notes.php`
*   **Description**: Fetches notes that match a specific, secure SQL query pattern.
*   **Request**: `application/json`
    ```json
    {
      "sql_query": "SELECT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = 'status' AND P.value = 'DONE'",
      "page": 1,
      "per_page": 10
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": "01a1c6b0-ebb2-7c92-bee9-9974b6bcb8f2",
                "content": "This is an updated task.\n{status::::DONE}\n{priority::High}",
                "properties": { "...": "..." },
                "...": "..."
            }
        ],
        "pagination": { "current_page": 1, "...": "..." }
    }
    ```
