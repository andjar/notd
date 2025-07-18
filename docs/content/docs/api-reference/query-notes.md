---
title: "Query Notes"
weight: 90
---

Executes safe, predefined SQL queries against the indexed `Properties` table to find notes. This becomes even more powerful for historical queries.

### `POST /api/v1/query_notes.php`
*   **Description**: Fetches notes that match a specific, secure SQL query pattern.
*   **Request**: `application/json`
    ```json
    {
      "sql_query": "SELECT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = 'status' AND P.value = 'DONE' AND P.created_at > '2023-10-27'",
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
                "id": 42,
                "content": "This is an updated task.\n{status::::DONE}\n{priority::High}",
                "properties": { "...": "..." },
                "...": "..."
            }
        ],
        "pagination": { "current_page": 1, "...": "..." }
    }
    ```
