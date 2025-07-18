---
title: "Properties"
weight: 70
---

This endpoint is now **read-only**. It provides a way to query the `Properties` table index directly, which can be useful for advanced filtering or finding all entities with a specific property value, without needing to perform a full-text search.

### `GET /api/v1/properties.php`
*   **Description**: Retrieves all indexed properties for a specific entity.
*   **Example Call**: `GET /api/v1/properties.php?entity_type=note&entity_id=42`
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "status": [
                { "id": 201, "value": "TODO", "weight": 2, "created_at": "2023-10-27 10:30:00" },
                { "id": 205, "value": "DONE", "weight": 4, "created_at": "2023-10-27 10:31:00" }
            ],
            "priority": [
                { "id": 202, "value": "High", "weight": 2, "created_at": "2023-10-27 10:30:00" }
            ]
        }
    }
    ```
