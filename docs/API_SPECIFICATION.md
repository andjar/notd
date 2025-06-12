Of course. Here is the complete, revised `API_SPECIFICATION.md` file with detailed examples for all endpoints, adhering to the GET/POST-only constraint. It has been updated for consistency, clarity, and completeness based on the provided PHP files.

---

# API Specification (v1)

This document provides a detailed specification for the API endpoints, revised to operate exclusively with GET and POST methods, and incorporating best practices for consistency, pagination, and versioning.

**Key Design Principles:**
*   **API Versioning:** All endpoints are prefixed with `/api/v1/`.
*   **GET/POST Only:** Operations typically using PUT or DELETE are handled via POST requests, using an `_method` parameter in the request body (for form-like requests) or as a top-level key in JSON payloads.
*   **Idempotent GETs:** GET requests are strictly for data retrieval and have no side effects.
*   **Universal Pagination:** All list-returning endpoints support `page` and `per_page` parameters and include a consistent pagination object in the response.
*   **Response Standardization:**
    *   Unified success/error reporting using `status: "success"` or `status: "error"`.
    *   Successful responses wrap their payload in a `data` key.
*   **Consistent Property Structure:** Property objects within responses are consistently structured as ` "property_name": [{"value": "...", "internal": 0/1}]`.

## Table of Contents

- [General Concepts](#general-concepts)
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
  - [Property Definitions (`/api/v1/property_definitions.php`)](#property-definitions-apiv1property_definitionsphp)
  - [Query Notes (`/api/v1/query_notes.php`)](#query-notes-apiv1query_notesphp)
  - [Search (`/api/v1/search.php`)](#search-apiv1searchphp)
  - [Templates (`/api/v1/templates.php`)](#templates-apiv1templatesphp)
  - [Webhooks (`/api/v1/webhooks.php`)](#webhooks-apiv1webhooksphp)

## General Concepts

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
    "total_pages": 8,
    "has_next_page": true,
    "has_prev_page": true
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
            "note_id": "42",
            "name": "my-document.pdf",
            "path": "2023/10/uniqueid_my-document.pdf",
            "type": "application/pdf",
            "created_at": "2023-10-27 10:30:00",
            "size": null
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
*   **Example (List All, Paginated)**: `GET /api/v1/attachments.php?page=1&per_page=10`
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
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
            ],
            "pagination": {
                "total_items": 1,
                "per_page": 10,
                "current_page": 1,
                "total_pages": 1
            }
        }
    }
    ```

### Notes (`/api/v1/notes.php`)
Manages notes, their content, and their hierarchy.

#### **`POST /api/v1/notes.php` (Batch Operations)**
*   **Description**: Create, update, and delete multiple notes in a single atomic request. This is the primary way to modify notes.
*   **Request**: `application/json`
    ```json
    {
      "action": "batch",
      "operations": [
        {
          "type": "create",
          "payload": {
            "client_temp_id": "temp-note-1",
            "page_id": 1,
            "content": "This is the parent note."
          }
        },
        {
          "type": "create",
          "payload": {
            "client_temp_id": "temp-note-2",
            "page_id": 1,
            "content": "This is a child note.",
            "parent_note_id": "temp-note-1"
          }
        },
        {
          "type": "update",
          "payload": {
            "id": 55,
            "content": "Updated content for an existing note."
          }
        },
        {
          "type": "delete",
          "payload": {
            "id": 56
          }
        }
      ]
    }
    ```
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
                    "note": { "id": 101, "content": "This is the parent note.", "...": "..." },
                    "client_temp_id": "temp-note-1"
                },
                {
                    "type": "create",
                    "status": "success",
                    "note": { "id": 102, "parent_note_id": 101, "content": "This is a child note.", "...": "..." },
                    "client_temp_id": "temp-note-2"
                },
                {
                    "type": "update",
                    "status": "success",
                    "note": { "id": 55, "content": "Updated content for an existing note.", "...": "..." }
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

#### **`GET /api/v1/notes.php?id={id}`**
*   **Description**: Retrieves a single note by its ID.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 1,
            "page_id": 10,
            "content": "This is a sample note.\n{status::TODO}",
            "parent_note_id": null,
            "order_index": 0,
            "collapsed": 0,
            "has_attachments": 1,
            "created_at": "2023-10-27 10:30:00",
            "updated_at": "2023-10-27 10:31:00",
            "internal": 0,
            "properties": {
                "status": [{"value": "TODO", "internal": 0}]
            }
        }
    }
    ```

#### **`GET /api/v1/notes.php?page_id={id}`**
*   **Description**: Retrieves all notes for a specific page, ordered by their `order_index`.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 1,
                "page_id": 10,
                "content": "First note on the page.",
                "properties": {},
                "...": "..."
            },
            {
                "id": 2,
                "page_id": 10,
                "content": "Second note on the page.",
                "properties": {},
                "...": "..."
            }
        ]
    }
    ```

### Pages (`/api/v1/pages.php`)
Manages pages, which are containers for notes.

#### **`POST /api/v1/pages.php` (Create)**
*   **Description**: Creates a new page. If a page with the given name exists, it returns the existing page.
*   **Request**: `application/json`
    ```json
    {
        "name": "New Project Page",
        "alias": "proj-new"
    }
    ```
*   **Response (201 Created or 200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 25,
            "name": "New Project Page",
            "alias": "proj-new",
            "updated_at": "2023-10-27 10:35:00",
            "created_at": "2023-10-27 10:35:00",
            "active": 1,
            "properties": {}
        }
    }
    ```

#### **`POST /api/v1/pages.php` (Update)**
*   **Description**: Updates a page's name or alias.
*   **Request**: `application/json`
    ```json
    {
        "_method": "PUT",
        "id": 25,
        "name": "Updated Project Page"
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 25,
            "name": "Updated Project Page",
            "alias": "proj-new",
            "...": "..."
        }
    }
    ```

#### **`POST /api/v1/pages.php` (Delete)**
*   **Description**: Deletes a page and all its associated notes and properties via database cascade.
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

#### **`GET /api/v1/pages.php?name={name}`**
*   **Description**: Retrieves a page by its name. If the page doesn't exist, it is created and returned.
*   **Response (200 OK or 201 Created)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 26,
            "name": "2023-10-27",
            "alias": null,
            "properties": {
                "type": [{"value": "journal", "internal": 0}]
            },
            "...": "..."
        }
    }
    ```

#### **`GET /api/v1/pages.php` (List All)**
*   **Description**: Retrieves a paginated list of all pages.
*   **Example**: `GET /api/v1/pages.php?page=1&per_page=10&exclude_journal=1`
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "data": [
                {
                    "id": 2,
                    "name": "Project Alpha",
                    "...": "..."
                }
            ],
            "pagination": {
                "current_page": 1,
                "per_page": 10,
                "total_pages": 1,
                "total_items": 1,
                "...": "..."
            }
        }
    }
    ```

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
          "content": "Discussion Topic 1: Budget",
          "order_index": 0
        },
        {
          "content": "Action Item: Follow up with finance.",
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
                "...": "..."
            },
            "appended_notes": [
                {
                    "id": 103,
                    "page_id": 27,
                    "content": "Discussion Topic 1: Budget",
                    "...": "..."
                },
                {
                    "id": 104,
                    "page_id": 27,
                    "parent_note_id": 103,
                    "content": "Action Item: Follow up with finance.",
                    "...": "..."
                }
            ]
        }
    }
    ```

### Properties (`/api/v1/properties.php`)
Manages metadata properties for notes and pages.

#### **`POST /api/v1/properties.php` (Set)**
*   **Description**: Creates or updates a property for a given entity.
*   **Request**: `application/json`
    ```json
    {
        "entity_type": "note",
        "entity_id": 42,
        "name": "priority",
        "value": "High"
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "property": {
                "name": "priority",
                "value": "High",
                "internal": 0
            }
        }
    }
    ```

#### **`POST /api/v1/properties.php` (Delete)**
*   **Description**: Deletes all values for a given property name from an entity.
*   **Request**: `application/json`
    ```json
    {
        "action": "delete",
        "entity_type": "note",
        "entity_id": 42,
        "name": "priority"
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": null
    }
    ```

#### **`GET /api/v1/properties.php`**
*   **Description**: Retrieves all properties for a given entity.
*   **Example**: `GET /api/v1/properties.php?entity_type=note&entity_id=42&include_internal=1`
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "priority": [{"value": "High", "internal": 0}],
            "status": [{"value": "TODO", "internal": 0}],
            "_last_processed": [{"value": "2023-10-27 10:40:00", "internal": 1}]
        }
    }
    ```

### Property Definitions (`/api/v1/property_definitions.php`)
Manages the schema and behavior of properties.

#### **`POST /api/v1/property_definitions.php` (Create/Update)**
*   **Description**: Defines or updates a property's behavior (e.g., making it internal).
*   **Request**: `application/json`
    ```json
    {
        "name": "secret_key",
        "internal": 1,
        "description": "A secret key for integration, should not be displayed.",
        "auto_apply": 1
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Property definition saved and applied to 0 existing properties"
        }
    }
    ```

#### **`GET /api/v1/property_definitions.php`**
*   **Description**: Retrieves a list of all defined properties.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 1,
                "name": "internal",
                "internal": 1,
                "description": "Properties that control note/page visibility",
                "auto_apply": 1,
                "...": "..."
            }
        ]
    }
    ```

### Query Notes (`/api/v1/query_notes.php`)
Executes safe, predefined SQL queries to find notes.

#### **`POST /api/v1/query_notes.php`**
*   **Description**: Fetches notes that match a specific, secure SQL query pattern.
*   **Request**: `application/json`
    ```json
    {
      "sql_query": "SELECT DISTINCT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = 'status' AND P.value = 'TODO'",
      "include_properties": true,
      "page": 1,
      "per_page": 5
    }
    ```
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "data": [
                {
                    "id": 42,
                    "content": "This is a task that needs to be done. {status::TODO}",
                    "properties": {
                        "status": [{"value": "TODO", "internal": 0}]
                    },
                    "...": "..."
                }
            ],
            "pagination": {
                "current_page": 1,
                "per_page": 5,
                "total_count": 1,
                "total_pages": 1
            }
        }
    }
    ```

### Search (`/api/v1/search.php`)
Provides full-text, backlink, and task search capabilities.

#### **`GET /api/v1/search.php?q={term}`**
*   **Description**: Performs a full-text search across note content and page names.
*   **Response (200 OK)**:
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
                    "content_snippet": "... with the search <mark>term</mark> highlighted ..."
                }
            ],
            "pagination": { "total": 1, "page": 1, "per_page": 20, "total_pages": 1 }
        }
    }
    ```

#### **`GET /api/v1/search.php?backlinks_for_page_name={name}`**
*   **Description**: Finds all notes that link to the specified page name.
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "results": [
                {
                    "note_id": 15,
                    "content": "See details in [[Project Alpha]] for more info.",
                    "page_id": 12,
                    "source_page_name": "Meeting Summary",
                    "content_snippet": "See details in <mark>[[Project Alpha]]</mark> for more info."
                }
            ],
            "pagination": { "total": 1, "page": 1, "per_page": 20, "total_pages": 1 }
        }
    }
    ```

#### **`GET /api/v1/search.php?tasks={status}`**
*   **Description**: Finds all notes with a task status of `todo` or `done`.
*   **Example**: `GET /api/v1/search.php?tasks=todo`
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "results": [
                {
                    "note_id": 42,
                    "content": "TODO Finalize the report",
                    "page_id": 10,
                    "page_name": "Project Alpha",
                    "property_name": "status",
                    "property_value": "TODO",
                    "content_snippet": "<mark>TODO</mark> Finalize the report",
                    "properties": {
                        "status": [{"value": "TODO", "internal": 0}]
                    }
                }
            ],
            "pagination": { "total": 1, "page": 1, "per_page": 20, "total_pages": 1 }
        }
    }
    ```

### Templates (`/api/v1/templates.php`)
Manages reusable templates for creating notes and pages.

#### **`POST /api/v1/templates.php` (Create)**
*   **Description**: Creates a new template.
*   **Request**: `application/json`
    ```json
    {
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

### Webhooks (`/api/v1/webhooks.php`)
Manages webhook subscriptions for event notifications. To maintain consistency, all modification actions are done via `POST` with a URL query parameter `action`.

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

#### **`GET /api/v1/webhooks.php`**
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