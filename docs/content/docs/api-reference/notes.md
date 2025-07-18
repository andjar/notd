---
title: "Notes"
weight: 40
---

Manages notes and their content. All property modifications are now done by updating the note's `content` field.

### `POST /api/v1/notes.php` (Batch Operations)
*   **Description**: Create, update, and delete multiple notes in a single atomic request. This is the **primary method** for all write operations on notes.
*   **Request**: `application/json`
    ```json
    {
      "action": "batch",
      "include_parent_properties": true, // Optional, boolean, defaults to false. If true, created/updated notes will include 'parent_properties'.
      "operations": [
        {
          "type": "create",
          "payload": {
            "client_temp_id": "temp-note-1",
            "page_id": 1,
            "parent_note_id": null, // or ID of parent note
            "content": "This is a new task.\n{status::TODO}\n{priority::High}"
          }
        },
        {
          "type": "update",
          "payload": {
            "id": 42, // ID of the note to update
            "content": "This is an updated task.\n{status::::DONE}\n{priority::High}"
          }
        },
        {
          "type": "delete",
          "payload": {
            "id": 56 // ID of the note to delete
          }
        }
      ]
    }
    ```
*   **Parameters**:
    *   `include_parent_properties` (optional, boolean): When `true`, each note object returned in the `results` for `create` and `update` operations will include a `parent_properties` field. This field contains an aggregated view of properties from all direct and indirect parent notes. Defaults to `false`.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Batch operations completed. Check individual results for status.",
            "results": [
                {
                    "type": "create",
                    "status": "success",
                    "note": {
                        "id": 101,
                        "content": "This is a new task...",
                        "properties": {"status": [{"value": "TODO"}], "priority": [{"value": "High"}]},
                        "parent_properties": {"project_code": [{"value": "Alpha"}]}, // Example if parent had {project_code::Alpha}
                        "...": "..."
                    },
                    "client_temp_id": "temp-note-1"
                },
                {
                    "type": "update",
                    "status": "success",
                    "note": {
                        "id": 42,
                        "content": "This is an updated task...",
                        "properties": {"status": [{"value": "DONE"}], "priority": [{"value": "High"}]},
                        "parent_properties": null, // Or {} if no parent properties, or if include_parent_properties was false
                        "...": "..."
                    }
                },
                {
                    "type": "delete",
                    "status": "success",
                    "deleted_note_id": 56
                }
            ]
        }
    }
    ```
    **Note on `parent_properties` in response:**
    *   The `parent_properties` field in the `note` object (for `create` and `update` results) will contain an object where keys are property names and values are arrays of property values inherited from parent notes. This structure mirrors the `properties` field.
    *   It will be `null` or an empty object (`{}`) if `include_parent_properties` is `false`, if the note has no parent, or if no inheritable properties exist on its parents.

### `GET /api/v1/notes.php?id={id}`
*   **Description**: Retrieves a single note, including its properties derived from its content.
    *   Accepts an optional boolean GET parameter `include_internal` (defaults to `false`). If `true`, internal properties (weight >= 3) are included. Otherwise, they are excluded.
    *   Accepts an optional boolean GET parameter `include_parent_properties` (defaults to `false`). If `true`, the `parent_properties` field will be populated.
*   **Response (200 OK) - Example when `include_internal=true` and `include_parent_properties=true`**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 42,
            "page_id": 10,
            "content": "This is an updated task.\n{status::::DONE}\n{priority::High}\n{internal_marker:::secret info}",
            "parent_note_id": 20, // Assuming parent note 20 exists
            "order_index": 0,
            "collapsed": 0,
            "created_at": "2023-10-27 10:30:00",
            "updated_at": "2023-10-27 10:32:00",
            "properties": {
                "status": [
                    { "value": "TODO", "weight": 2, "created_at": "2023-10-27 10:30:00" },
                    { "value": "DONE", "weight": 4, "created_at": "2023-10-27 10:31:00" }
                ],
                "priority": [
                    { "value": "High", "weight": 2, "created_at": "2023-10-27 10:30:00" }
                ],
                "internal_marker": [
                    { "value": "secret info", "weight": 3, "created_at": "2023-10-27 10:32:00"}
                ]
            },
            "parent_properties": { // Example, assuming parent note 20 has {project_code::Alpha}
                "project_code": [{"value": "Alpha", "weight": 2, "created_at": "..."}]
            }
        }
    }
    ```
*   If `GET /api/v1/notes.php?id=42` (i.e., `include_internal=false` and `include_parent_properties=false` by default) is called, `internal_marker` from `properties` and the entire `parent_properties` field would be excluded:
    ```json
    // Response (200 OK) - Example when include_internal=false (default)
    {
        "status": "success",
        "data": {
            "id": 42,
            "page_id": 10,
            "content": "This is an updated task.\n{status::::DONE}\n{priority::High}\n{internal_marker:::secret info}",
            "parent_note_id": null,
            "order_index": 0,
            "collapsed": 0,
            "created_at": "2023-10-27 10:30:00",
            "updated_at": "2023-10-27 10:32:00",
            "properties": {
                "status": [
                    { "value": "TODO", "weight": 2, "created_at": "2023-10-27 10:30:00" }
                ],
                "priority": [
                    { "value": "High", "weight": 2, "created_at": "2023-10-27 10:30:00" }
                ]
            }
        }
    }
    ```

### `GET /api/v1/notes.php?page_id={id}`
*   **Description**: Retrieves all notes for a specific page. Properties for each note are derived from its content. Accepts an optional boolean GET parameter `include_internal` (defaults to `false`). If `true`, internal properties (weight >= 3) for each note are included. Otherwise, they are excluded.
*   **Example response for `GET /api/v1/notes.php?page_id=10&include_internal=true`**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 42,
                "page_id": 10,
                "content": "First note with {secret:::shhh} and {public::data}",
                "parent_note_id": null,
                "order_index": 0,
                "collapsed": 0,
                "created_at": "2023-10-28 11:00:00",
                "updated_at": "2023-10-28 11:05:00",
                "properties": {
                    "secret": [{"value": "shhh", "weight": 3, "created_at": "2023-10-28 11:05:00"}],
                    "public": [{"value": "data", "weight": 2, "created_at": "2023-10-28 11:05:00"}]
                }
            },
            {
                "id": 43,
                "page_id": 10,
                "content": "Second note with {visible::info}",
                "parent_note_id": null,
                "order_index": 1,
                "collapsed": 0,
                "created_at": "2023-10-28 11:10:00",
                "updated_at": "2023-10-28 11:10:00",
                "properties": {
                    "visible": [{"value": "info", "weight": 2, "created_at": "2023-10-28 11:10:00"}]
                }
            }
        ]
    }
    ```
*   If `GET /api/v1/notes.php?page_id=10` or `GET /api/v1/notes.php?page_id=10&include_internal=false` is called, the `properties` for note `42` would only include `public`.
