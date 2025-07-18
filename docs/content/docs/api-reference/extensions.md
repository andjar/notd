---
title: "Extensions"
weight: 20
---

Retrieves details about currently active extensions.

### `GET /api/v1/extensions.php`
*   **Description**: Lists active extensions defined in the configuration.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "extensions": [
                {
                    "name": "calendar",
                    "featherIcon": "calendar"
                },
                {
                    "name": "tasks",
                    "featherIcon": "check-square"
                }
            ]
        }
    }
    ```
