---
title: "Append to Page"
weight: 60
---

A utility endpoint to quickly add notes to a page, creating the page if it doesn't exist.

### `POST /api/v1/append_to_page.php`
*   **Description**: Appends one or more notes to a page, creating the page if necessary. Supports creating nested notes in one call.
*   **Request**: `application/json`
    ```json
    {
      "page_name": "Meeting Notes 2023-10-27",
      "notes": [
        {
          "client_temp_id": "topic-1",
          "content": "Discussion Topic 1: Budget {priority::High}",
          "order_index": 0
        },
        {
          "content": "Action Item: Follow up with finance. {status::TODO}",
          "parent_note_id": "topic-1",
          "order_index": 0
        }
      ]
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Page created and notes appended successfully.",
            "page": {
                "id": 27,
                "name": "Meeting Notes 2023-10-27",
                "content": null,
                "properties": {},
                "...": "..."
            },
            "appended_notes": [
                {
                    "id": 103,
                    "page_id": 27,
                    "content": "Discussion Topic 1: Budget {priority::High}",
                    "properties": { "priority": [{"value": "High", "weight": 2, "...":"..."}] },
                    "...": "..."
                },
                {
                    "id": 104,
                    "page_id": 27,
                    "parent_note_id": 103,
                    "content": "Action Item: Follow up with finance. {status::TODO}",
                    "properties": { "status": [{"value": "TODO", "weight": 2, "...":"..."}] },
                    "...": "..."
                }
            ]
        }
    }
    ```
