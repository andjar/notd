---

# API Specification (v1)

This document provides a detailed specification for the API endpoints, revised to operate exclusively with GET and POST methods, and incorporating best practices for consistency, pagination, and versioning.

**Key Design Principles:**
*   **API Versioning:** All endpoints are prefixed with `/api/v1/`.
*   **GET/POST Only:** Write operations (create, update, delete) are handled via POST requests, using an `_method` override key in the JSON payload.
*   **Content as Single Source of Truth:** Properties for notes and pages are derived directly from their respective `content` fields. The `Properties` database table serves as a queryable index of this content.
*   **Universal Pagination:** All list-returning endpoints support `page` and `per_page` and include a consistent pagination object.
*   **Response Standardization:**
    *   Unified success/error reporting using `status: "success"` or `status: "error"`.
    *   Successful responses wrap their payload in a `data` key.

## Table of Contents

- [General Concepts](#general-concepts)
  - [Content-Driven Properties](#content-driven-properties)
  - [Pagination](#pagination)
  - [Error Responses](#error-responses)
- [Endpoints](#endpoints)
  - [Ping (`/api/v1/ping.php`)](#ping-apiv1pingphp)
  - [Extensions (`/api/v1/extensions.php`)](#extensions-apiv1extensionsphp)
  - [Attachments (`/api/v1/attachments.php`)](#attachments-apiv1attachmentsphp)
  - [Notes (`/api/v1/notes.php`)](#notes-apiv1notesphp)
  - [Pages (`/api/v1/pages.php`)](#pages-apiv1pagesphp)
  - [Append to Page (`/api/v1/append_to_page.php`)](#append-to-page-apiv1append_to_pagephp)
  - [Properties (`/api/v1/properties.php`)](#properties-apiv1propertiesphp)
  - [Search (`/api/v1/search.php`)](#search-apiv1searchphp)
  - [Query Notes (`/api/v1/query_notes.php`)](#query-notes-apiv1query_notesphp)
  - [Templates (`/api/v1/templates.php`)](#templates-apiv1templatesphp)
  - [Webhooks (`/api/v1/webhooks.php`)](#webhooks-apiv1webhooksphp)

---

## General Concepts

### Content-Driven Properties

The `Properties` table is no longer directly managed. Instead, it is an index populated by parsing the `content` of a Note or Page. This is the new core of the system.

#### Syntax

Properties are defined within content using a key, a variable number of colons (`:`), and a value, optionally enclosed in curly braces `{}`. The number of colons determines the property's `weight`.

*   `key::value` or `{key::value}` -> **Weight 2** (Default, public property)
*   `key:::value` or `{key:::value}` -> **Weight 3** (Internal property)
*   `key::::value` or `{key::::value}` -> **Weight 4** (System property, e.g., for logging)

#### Configuration (`config.php`)

The behavior of properties based on their weight is defined in `config.php` and interpreted by the **frontend**. The API's role is simply to parse and return the properties with their weight.

**Example `config.php` structure:**
```php
define('PROPERTY_WEIGHTS', [
    2 => [
        'label' => 'Public',
        'update_behavior' => 'replace', // 'replace' or 'append'
        'visible_in_view_mode' => true,
        'visible_in_edit_mode' => true
    ],
    3 => [
        'label' => 'Internal',
        'update_behavior' => 'replace',
        'visible_in_view_mode' => false,
        'visible_in_edit_mode' => true
    ],
    4 => [
        'label' => 'System Log',
        'update_behavior' => 'append',
        'visible_in_view_mode' => false,
        'visible_in_edit_mode' => false
    ]
]);
```

#### Backend Logic

When a Note or Page is created or updated via the API:
1.  The backend receives the new `content`.
2.  It parses all property strings from the content.
3.  For each parsed property, it intelligently updates the `Properties` table:
    *   It compares the parsed properties with what's currently in the `Properties` table for that entity.
    *   If a property's weight is configured with `update_behavior: 'replace'`, it finds and updates the existing row for that property name or inserts a new one if it doesn't exist.
    *   If a property's weight is configured with `update_behavior: 'append'`, it **always inserts a new row**, creating a history of values.
    *   Properties that existed in the table but are no longer in the content are deleted (unless their weight config specifies they should be preserved).
4.  This logic ensures timestamps (`created_at`, `updated_at`) in the `Properties` table are meaningful.

### Pagination

Endpoints that return a list of items support pagination via URL parameters.

*   `page` (optional, integer): The page number for pagination. Defaults to `1`.
*   `per_page` (optional, integer): The number of items to return per page. Defaults to `20`, max `100`.

The response for a paginated list will include a `pagination` object:

```json
"pagination": {
    "total_items": 150,
    "per_page": 20,
    "current_page": 2,
    "total_pages": 8
}
```

### Error Responses

Errors are returned with an appropriate `4xx` or `5xx` status code and a consistent JSON structure.

```json
{
    "status": "error",
    "message": "A human-readable error message.",
    "details": {
        "field_name": "Specific error for this field."
    }
}
```

---

## Endpoints

### Ping (`/api/v1/ping.php`)
A simple utility endpoint to check if the API is running.

#### **`GET /api/v1/ping.php`**
*   **Description**: Checks API health.
*   **Response (200 OK)**:
    ```json
    {
      "status": "pong",
      "timestamp": "2023-10-27T10:30:00+00:00"
    }
    ```

### Extensions (`/api/v1/extensions.php`)
Retrieves details about currently active extensions.

#### **`GET /api/v1/extensions.php`**
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

### Attachments (`/api/v1/attachments.php`)

Manages file attachments for notes.

#### **`POST /api/v1/attachments.php` (Upload)**
*   **Description**: Uploads a new attachment for a note.
*   **Request**: `multipart/form-data`
    - `note_id` (form field): `42`
    - `attachmentFile` (file field): `(binary data of my-document.pdf)`
*   **Response (201 Created)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 101,
            "note_id": 42,
            "name": "my-document.pdf",
            "path": "2023/10/uniqueid_my-document.pdf",
            "type": "application/pdf",
            "size": 123456,
            "created_at": "2023-10-27 10:30:00"
        }
    }
    ```

#### **`POST /api/v1/attachments.php` (Delete)**
*   **Description**: Deletes an existing attachment.
*   **Request**: `application/json`
    ```json
    {
      "_method": "DELETE",
      "id": 101
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "deleted_attachment_id": 101
        }
    }
    ```

#### **`GET /api/v1/attachments.php`**
*   **Description**: Retrieves a list of attachments, either for a specific note or for all notes with pagination and filtering.
*   **Example (By Note ID)**: `GET /api/v1/attachments.php?note_id=42`
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 101,
                "name": "my-document.pdf",
                "path": "2023/10/uniqueid_my-document.pdf",
                "type": "application/pdf",
                "size": 123456,
                "created_at": "2023-10-27 10:30:00",
                "url": "http://localhost/uploads/2023/10/uniqueid_my-document.pdf"
            }
        ]
    }
    ```
---

### Notes (`/api/v1/notes.php`)

Manages notes and their content. All property modifications are now done by updating the note's `content` field.

#### **`POST /api/v1/notes.php` (Batch Operations)**
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

#### **`GET /api/v1/notes.php?id={id}`**
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

#### **`GET /api/v1/notes.php?page_id={id}`**
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

---

### Pages (`/api/v1/pages.php`)

Manages pages, which now also have a `content` field to drive their properties.

#### **`POST /api/v1/pages.php` (Create)**
*   **Description**: Creates a new page.
*   **Request**: `application/json`
    ```json
    {
      "name": "New Project Page",
      "content": "{type::Project}\n{lead:::John Doe}"
    }
    ```
*   **Response (201 Created)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 25,
            "name": "New Project Page",
            "content": "{type::Project}\n{lead:::John Doe}",
            "created_at": "2023-10-27 10:35:00",
            "updated_at": "2023-10-27 10:35:00",
            "properties": {
                "type": [{"value": "Project", "weight": 2, "created_at": "2023-10-27 10:35:00"}],
                "lead": [{"value": "John Doe", "weight": 3, "created_at": "2023-10-27 10:35:00"}]
            }
        }
    }
    ```

#### **`POST /api/v1/pages.php` (Update)**
*   **Description**: Updates a page's name or content.
*   **Request**: `application/json`
    ```json
    {
        "_method": "PUT",
        "id": 25,
        "content": "{type::Project}\n{lead:::Jane Doe}\n{status::Active}"
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 25,
            "name": "New Project Page",
            "content": "{type::Project}\n{lead:::Jane Doe}\n{status::Active}",
            "...": "...",
            "properties": { "..." : "..." }
        }
    }
    ```
    
#### **`POST /api/v1/pages.php` (Delete)**
*   **Description**: Deletes a page and all its associated notes.
*   **Request**: `application/json`
    ```json
    {
        "_method": "DELETE",
        "id": 25
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "deleted_page_id": 25
        }
    }
    ```

#### **`GET /api/v1/pages.php?name={page_name}`**
*   **Description**: Retrieves a page by its exact name. If the page does not exist, it will be created with null content.
*   **Request example**: `GET /api/v1/pages.php?name=My%20New%20Page`
*   **Response example for an existing page (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 26,
            "name": "My New Page",
            "content": "Some existing content.",
            "created_at": "2023-10-28 10:00:00",
            "updated_at": "2023-10-28 10:05:00",
            "properties": {}
        }
    }
    ```
*   **Response example for a newly created page (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 27,
            "name": "My New Page",
            "content": null,
            "created_at": "2023-10-28 10:10:00",
            "updated_at": "2023-10-28 10:10:00",
            "properties": {}
        }
    }
    ```

#### **`GET /api/v1/pages.php?id={id}`**
*   **Description**: Retrieves a single page by its ID.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 25,
            "name": "New Project Page",
            "content": "{type::Project}\n{lead:::Jane Doe}\n{status::Active}",
            "created_at": "2023-10-27 10:35:00",
            "updated_at": "2023-10-27 10:40:00",
            "properties": {
                "type": [{"value": "Project", "weight": 2, "created_at": "2023-10-27 10:35:00"}],
                "lead": [{"value": "Jane Doe", "weight": 3, "created_at": "2023-10-27 10:40:00"}],
                "status": [{"value": "Active", "weight": 2, "created_at": "2023-10-27 10:40:00"}]
            }
        }
    }
    ```

#### **`GET /api/v1/pages.php?date={YYYY-MM-DD}`**
*   **Description**: Retrieves a list of pages that have a property 'date' matching the specified date. The date must be in YYYY-MM-DD format.
*   **Request example**: `GET /api/v1/pages.php?date=2023-11-15`
*   **Response example (200 OK)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 30,
                "name": "Meeting Notes 2023-11-15",
                "content": "Discussed project milestones.\n{date::2023-11-15}",
                "alias": null,
                "updated_at": "2023-11-15 14:00:00"
            },
            {
                "id": 31,
                "name": "Daily Log 2023-11-15",
                "content": "Logged daily activities.\n{date::2023-11-15}",
                "alias": "today-log",
                "updated_at": "2023-11-15 16:30:00"
            }
        ]
    }
    ```
*   If no pages match the date, an empty array will be returned in the `data` field.
    ```json
    {
        "status": "success",
        "data": []
    }
    ```

#### **`GET /api/v1/pages.php` (List)**
*   **Description**: Retrieves a paginated list of pages.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 25,
                "name": "New Project Page",
                "content": "...",
                "properties": { "...": "..." }
            }
        ],
        "pagination": { "total_items": 1, "...": "..." }
    }
    ```

---

### Append to Page (`/api/v1/append_to_page.php`)
A utility endpoint to quickly add notes to a page, creating the page if it doesn't exist.

#### **`POST /api/v1/append_to_page.php`**
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

---

### Properties (`/api/v1/properties.php`)

This endpoint is now **read-only**. It provides a way to query the `Properties` table index directly, which can be useful for advanced filtering or finding all entities with a specific property value, without needing to perform a full-text search.

#### **`GET /api/v1/properties.php`**
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

---

### Search (`/api/v1/search.php`)

Provides powerful search capabilities. It can search by term, find backlinks, list tasks, or list favorited pages.

**General Parameters for search types returning notes (e.g., `q`, `backlinks_for_page_name`, `tasks`):**
*   `include_parent_properties` (optional, boolean): Defaults to `false`. If set to `true`, each note item in the `results` array will include a `parent_properties` field. This field contains an object with aggregated properties from all direct and indirect parent notes. The structure mirrors the main `properties` field. For search types that do not return notes (e.g., `favorites`), this parameter has no effect.

#### **`GET /api/v1/search.php?q={term}`**
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

#### **`GET /api/v1/search.php?backlinks_for_page_name={page_name}`**
*   **Description**: Finds all notes that link to the specified `page_name`. Supports pagination and `include_parent_properties`.
*   **(Response structure similar to `q` search but `content_snippet` will highlight the link)**

#### **`GET /api/v1/search.php?tasks={status}`**
*   **Description**: Finds all notes with a `{status::TODO}` or `{status::DONE}` property. `status` can be `TODO` or `DONE`. Supports pagination and `include_parent_properties`.
*   **(Response structure similar to `q` search but `content_snippet` will highlight the status property)**

#### **`GET /api/v1/search.php?favorites=true`**
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

---

### Query Notes (`/api/v1/query_notes.php`)

Executes safe, predefined SQL queries against the indexed `Properties` table to find notes. This becomes even more powerful for historical queries.

#### **`POST /api/v1/query_notes.php`**
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

---

### Templates (`/api/v1/templates.php`)

Manages reusable templates for creating notes and pages.

#### **`POST /api/v1/templates.php` (Create)**
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

#### **`GET /api/v1/templates.php?type=note`**
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

---

### Webhooks (`/api/v1/webhooks.php`)
Manages webhook subscriptions for event notifications.

#### **`POST /api/v1/webhooks.php` (Create)**
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

#### **`POST /api/v1/webhooks.php?action=test&id=1`**
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

#### **`GET /api/v1/webhooks.php?action=history&id=1`**
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

#### **`GET /api/v1/webhooks.php` (List)**
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