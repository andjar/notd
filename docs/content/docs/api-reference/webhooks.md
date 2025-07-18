---
title: "Webhooks"
weight: 110
---

Manages webhook subscriptions for event notifications.

### `POST /api/v1/webhooks.php` (Create)
*   **Description**: Registers a new webhook.
*   **Request**: `application/json`
    ```json
    {
      "url": "https://example.com/webhook-receiver",
      "entity_type": "note",
      "property_names": ["status", "priority"],
      "event_types": ["property_change", "entity_created"]
    }
    ```
*   **Response (201 Created)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 1,
            "url": "https://example.com/webhook-receiver",
            "entity_type": "note",
            "property_names": ["status", "priority"],
            "event_types": ["property_change", "entity_created"],
            "secret": "whsec_... (only shown on creation)",
            "...": "..."
        }
    }
    ```

### `POST /api/v1/webhooks.php?action=test&id=1`
*   **Description**: Sends a test event to the specified webhook.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Test event sent. Check history for result."
        }
    }
    ```

### `GET /api/v1/webhooks.php?action=history&id=1`
*   **Description**: Retrieves the delivery history for a webhook.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "pagination": { "total": 1, "page": 1, "limit": 20 },
            "history": [
                {
                    "id": 1,
                    "webhook_id": 1,
                    "event_type": "test",
                    "payload": "{\"event\":\"test\",...}",
                    "response_code": 200,
                    "response_body": "{\"status\":\"ok\"}",
                    "success": 1,
                    "created_at": "2023-10-27 11:00:00"
                }
            ]
        }
    }
    ```

### `GET /api/v1/webhooks.php` (List)
*   **Description**: Lists all configured webhooks.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 1,
                "url": "https://example.com/webhook-receiver",
                "entity_type": "note",
                "property_names": ["status", "priority"],
                "event_types": ["property_change", "entity_created"],
                "active": 1,
                "verified": 1,
                "last_triggered": "2023-10-27 11:00:00"
            }
        ]
    }
    ```
