---
title: "Ping"
weight: 10
---

A simple utility endpoint to check if the API is running.

### `GET /api/v1/ping.php`
*   **Description**: Checks API health.
*   **Response (200 OK)**:
    ```json
    {
      "status": "pong",
      "timestamp": "2023-10-27T10:30:00+00:00"
    }
    ```
