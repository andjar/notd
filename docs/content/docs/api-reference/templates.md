---
title: "Templates"
weight: 100
---

Manages reusable templates for creating notes and pages.

### `POST /api/v1/templates.php` (Create)
*   **Description**: Creates a new template.
*   **Request**: `application/json`
    ```json
    {
        "_method": "POST",
        "type": "note",
        "name": "daily-review",
        "content": "## Daily Review\n\n### What went well?\n\n- \n\n### What could be improved?\n\n- "
    }
    ```
*   **Response (201 Created)**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Template created successfully"
        }
    }
    ```

### `GET /api/v1/templates.php?type=note`
*   **Description**: Retrieves all available templates of a given type.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "name": "daily-review",
                "content": "## Daily Review\n\n### What went well?\n\n- \n\n### What could be improved?\n\n- "
            }
        ]
    }
    ```
