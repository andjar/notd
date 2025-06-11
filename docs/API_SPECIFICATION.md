# API Specification (v1)

This document provides a detailed specification for the API endpoints, revised to operate exclusively with GET and POST methods, and incorporating best practices for consistency, pagination, and versioning.

**Key Changes from Previous Version:**
*   **API Versioning:** All endpoints are now prefixed with `/v1/`.
*   **GET/POST Only:** Operations typically using PUT, DELETE, or PATCH are now handled via POST requests, generally using an `action` parameter in the request body or query string.
*   **No GET Side-Effects:** GET requests are strictly for data retrieval.
*   **Universal Pagination:** All list-returning endpoints now support `page` and `per_page` parameters and include a consistent pagination object in the response.
*   **Response Standardization:**
    *   Unified success/error reporting using `status: "success"` or `status: "error"`.
    *   Consistent structure for property objects.
*   **HTTP Status Codes:** More semantic use of status codes (e.g., `201 Created`).

## Table of Contents

- [Attachments API (`api/v1/attachments.php`)](#attachments-api)
- [Notes API (`api/v1/notes.php`)](#notes-api)
- [Pages API (`api/v1/pages.php`)](#pages-api)
- [Properties API (`api/v1/properties.php`)](#properties-api)
- [Property Definitions API (`api/v1/property_definitions.php`)](#property-definitions-api)
- [Search API (`api/v1/search.php`)](#search-api)
- [Templates API (`api/v1/templates.php`)](#templates-api)
- [Query Notes API (`api/v1/query_notes.php`)](#query-notes-api)
- [Utility Scripts](#utility-scripts)
- [Webhooks API (`api/v1/webhooks.php`)](#webhooks-api)

## Attachments API (`api/v1/attachments.php`)

### Main Objective

The Attachments API is responsible for managing file attachments associated with notes. It allows users to upload, download, and delete attachments.

### Supported HTTP Methods & Endpoints

- **POST `api/v1/attachments.php`**: Uploads a new attachment OR deletes an existing attachment.
- **GET `api/v1/attachments.php`**: Retrieves an attachment or a list of attachments.

### Request Parameters

#### POST `api/v1/attachments.php` (Upload Attachment)

- **Headers**:
    - `Content-Type: multipart/form-data`
- **Body**:
    - `action` (optional, string): If not present or different from "delete", implies "upload".
    - `note_id` (required): The ID of the note to which the attachment should be associated.
    - `file` (required): The file to be uploaded. (Sent as `attachmentFile` in the form data).

#### POST `api/v1/attachments.php` (Delete Attachment)

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `"delete"`.
    - `id` (required, integer): The ID of the attachment to delete.
    ```json
    {
        "action": "delete",
        "id": 123
    }
    ```

#### GET `api/v1/attachments.php`

This endpoint has two modes of operation:

1.  **Retrieve JSON metadata for a specific attachment by its ID**:
    -   **URL Parameters**:
        -   `id` (required): The ID of the attachment for which to retrieve metadata.
    -   *Note: This mode is triggered if an `id` parameter is provided (and `note_id` is not, to differentiate from listing attachments for a note).*
    -   The actual file content should be accessed via the `url` provided in the response. The `disposition` parameter is not used by this metadata endpoint.

2.  **List attachments (with filtering and pagination)**:
    -   **URL Parameters**:
        -   `note_id` (optional): If provided, lists attachments only for this specific note. If `id` is also provided, `note_id` takes precedence for listing.
        -   `page` (optional, integer): The page number for pagination. Defaults to `1`.
        -   `per_page` (optional, integer): The number of attachments to return per page. Defaults to `10`, max `100`.
        -   `sort_by` (optional, string): Field to sort by. Allowed: `id`, `name`, `path`, `type`, `size`, `created_at`. Defaults to `created_at`.
        -   `sort_order` (optional, string): `asc` or `desc`. Defaults to `desc`.
        -   `filter_by_name` (optional, string): Filters by name (case-insensitive, partial match).
        -   `filter_by_type` (optional, string): Filters by exact MIME type.
    -   *Note: This mode is triggered if no `id` parameter is provided.*

### Response Structure

#### Success Responses

- **POST `api/v1/attachments.php` (Upload)**:
    *Status Code: 201 Created*
    *Headers: `Location: /api/v1/attachments.php?id={new_attachment_id}`*
    ```json
    {
        "status": "success",
        "message": "File uploaded successfully.",
        "data": {
            "id": 123,
            "note_id": 456,
            "filename": "example.jpg",
            "filesize": 102400,
            "filetype": "image/jpeg",
            "storage_key": "attachments/note_456/example.jpg", // Renamed from s3_key for generality
            "url": "http://localhost/api/v1/attachments.php?id=123", // This URL used to point to the API for file serving. Now it's a direct link.
            "created_at": "YYYY-MM-DD HH:MM:SS"
        }
    }
    ```
- **GET `api/v1/attachments.php?id={attachment_id}` (Retrieve JSON metadata for a specific attachment)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": {
            "id": 123,
            "note_id": 456,
            "name": "example.jpg",
            "path": "2023/10/unique_example.jpg", // Relative path from the 'uploads' directory
            "type": "image/jpeg",
            "size": 102400, // in bytes
            "created_at": "YYYY-MM-DD HH:MM:SS",
            "url": "http://localhost/uploads/2023/10/unique_example.jpg" // Full URL to access/download the actual file
        }
    }
    ```
    *Note: To download the actual file, the client should use the `url` provided in this response.*

- **GET `api/v1/attachments.php` (List attachments, e.g., by `note_id`)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 1,
                "note_id": 5, // Included if filtering by note_id or always
                "name": "document.pdf",
                "path": "2023/11/another_document.pdf", // Relative path
                "type": "application/pdf",
                "size": 123456, // in bytes
                "created_at": "YYYY-MM-DD HH:MM:SS",
                "url": "http://localhost/uploads/2023/11/another_document.pdf" // Full URL
            }
            // ... more attachments
        ],
        "pagination": {
            "total_items": 100,
            "per_page": 10,
            "current_page": 1,
            "total_pages": 10
        }
    }
    ```
- **POST `api/v1/attachments.php` (Delete)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Attachment deleted successfully.",
        "data": {
            "deleted_attachment_id": 123
        }
    }
    ```

#### Error Responses (General Structure)
    *Status Code: 4xx or 5xx*
    ```json
    {
        "status": "error",
        "message": "Error message describing the issue.",
        "details": { /* Optional: specific field errors */ }
    }
    ```
    *Specific error messages (e.g., "Attachment not found", "File upload failed", "Note not found", "Invalid input") will be in the `message` field.*

---

## Notes API (`api/v1/notes.php`)

### Main Objective

Manages notes, content, and properties. Supports create, retrieve, update, delete.

### Supported HTTP Methods & Endpoints

- **GET `api/v1/notes.php`**: Retrieves a list of notes or a single note.
- **POST `api/v1/notes.php`**: Creates a new note, updates an existing note, or deletes a note.

### Request Parameters

#### GET `api/v1/notes.php`

- **URL Parameters**:
    - `id` (optional, integer): ID of the note to retrieve.
    - `page_id` (optional, integer): ID of the page whose notes are to be retrieved. If specified, returns notes and page details.
    - `include_internal` (optional, boolean): If `true`, internal properties included. Defaults to `false`.
    - `page` (optional, integer): Page number for pagination (if not retrieving by `id`). Defaults to `1`.
    - `per_page` (optional, integer): Items per page (if not retrieving by `id`). Defaults to `10`.
    - `sort_by` (optional, string): Field to sort by (e.g., `created_at`, `updated_at`, `order_index`). Default `order_index`.
    - `sort_order` (optional, string): `asc` or `desc`. Default `asc` for `order_index`.

#### POST `api/v1/notes.php` (Create Note)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (optional, string): If not present or different from "update"/"delete", implies "create".
    - `page_id` (required, integer): Page ID for the note.
    - `content` (optional, string): Note content. Properties can be embedded.
    - `parent_note_id` (optional, integer|null): Parent note ID.
    - `order_index` (optional, integer): Display order.
    - `collapsed` (optional, integer): `0` or `1`.
    - `properties_explicit` (optional, object): Explicit properties to set (skips content parsing for properties).

#### POST `api/v1/notes.php` (Update Note)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `"update"`.
    - `id` (required, integer): ID of the note to update.
    - `content` (optional, string): New content.
    - `page_id` (optional, integer): Move note to a different page.
    - `parent_note_id` (optional, integer|null): Change parent.
    - `order_index` (optional, integer): Change order.
    - `collapsed` (optional, integer): Change collapsed state.
    - `properties_explicit` (optional, object): Explicit properties to set/update.

#### POST `api/v1/notes.php` (Delete Note)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `"delete"`.
    - `id` (required, integer): ID of the note to delete.

### Response Structure (Property objects are always `{"value": "...", "internal": 0/1}` for consistency)

#### Success Responses

- **GET `api/v1/notes.php?id={note_id}` (Single Note)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": {
            "id": 1,
            "page_id": 10,
            "content": "This is a sample note.\ncategory:: personal\ntags:: #important #urgent",
            "parent_note_id": null,
            "order_index": 0,
            "collapsed": 0,
            "has_attachments": 0, // Consider if this should be a count or a boolean
            "created_at": "YYYY-MM-DD HH:MM:SS",
            "updated_at": "YYYY-MM-DD HH:MM:SS",
            "internal": 0, // Is this "internal" flag on the note itself necessary if properties handle it?
            "properties": {
                "category": [{"value": "personal", "internal": 0}],
                "tags": [
                    {"value": "#important", "internal": 0},
                    {"value": "#urgent", "internal": 0}
                ]
            }
        }
    }
    ```
- **GET `api/v1/notes.php?page_id={page_id}` (Notes by Page)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": {
            "page": {
                "id": 10,
                "title": "My Awesome Page",
                // ... other page fields ...
                "properties": {
                    "page_status": [{"value": "draft", "internal": 0}]
                }
            },
            "notes": [ /* array of note objects for this page, structured as single note */ ],
            "pagination": { /* pagination for notes list */ }
        }
    }
    ```
- **GET `api/v1/notes.php` (List All Notes)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": [ /* array of note objects */ ],
        "pagination": { /* pagination object */ }
    }
    ```
- **POST `api/v1/notes.php` (Create Note)**:
    *Status Code: 201 Created*
    *Headers: `Location: /api/v1/notes.php?id={new_note_id}`*
    ```json
    {
        "status": "success",
        "message": "Note created successfully.",
        "data": { /* newly created note object */ }
    }
    ```
- **POST `api/v1/notes.php` (Update Note)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Note updated successfully.",
        "data": { /* updated note object */ }
    }
    ```
- **POST `api/v1/notes.php` (Delete Note)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Note deleted successfully.",
        "data": {
            "deleted_note_id": 1
        }
    }
    ```

#### Error Responses
    *General structure as defined in Attachments API.*
    *Specific messages: "Note not found", "Page not found", "Invalid input for X".*

### POST `api/v1/notes.php` (Batch Operations)

Allows multiple create, update, or delete operations on notes within a single request. Operations are processed sequentially, and the server may wrap them in a transaction for atomicity.

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `"batch"`.
    - `operations` (required, array): An array of operation objects. Each object must contain:
        - `type` (required, string): The type of operation: `"create"`, `"update"`, or `"delete"`.
        - `payload` (required, object): The parameters for the specific operation type.
            - **For `type: "create"`**:
                - `page_id` (required, integer): Page ID for the new note.
                - `content` (optional, string): Note content.
                - `parent_note_id` (optional, integer|null): Parent note ID.
                - `order_index` (optional, integer): Display order.
                - `collapsed` (optional, integer): `0` or `1`.
                - `properties_explicit` (optional, object): Explicit properties.
                - `client_temp_id` (optional, string): A client-generated temporary ID. If provided, this ID can be used to reference this new note in subsequent `update` or `delete` operations within the same batch request. The response will map this `client_temp_id` to the actual `id` assigned upon creation.
            - **For `type: "update"`**:
                - `id` (required, integer|string): ID of the note to update. This can be an existing note's integer ID or a `client_temp_id` of a note created in a previous step of this batch.
                - `content` (optional, string): New content.
                - `page_id` (optional, integer): Move note.
                - `parent_note_id` (optional, integer|null): Change parent.
                - `order_index` (optional, integer): Change order.
                - `collapsed` (optional, integer): Change collapsed state.
                - `properties_explicit` (optional, object): Update properties.
            - **For `type: "delete"`**:
                - `id` (required, integer|string): ID of the note to delete. Can be an existing note's integer ID or a `client_temp_id`.

#### Success Response (Batch Operations)

*Status Code: 200 OK* (or 207 Multi-Status if operations have mixed results, but 200 OK with detailed results array is also common)
```json
{
    "status": "success", // Indicates the batch request itself was accepted and processed. Individual operations might have failed.
    "message": "Batch operations processed.",
    "results": [
        {
            "type": "create",
            "status": "success",
            "client_temp_id": "client-temp-123", // Included if provided in the request
            "data": { /* newly created note object, including its actual 'id' */ }
        },
        {
            "type": "update",
            "status": "success",
            "data": { /* updated note object */ }
        },
        {
            "type": "delete",
            "status": "success",
            "data": {
                "deleted_note_id": 456
            }
        },
        {
            "type": "create",
            "status": "error",
            "client_temp_id": "client-temp-456",
            "error": "Invalid page_id.",
            "details": { "payload": { /* original payload for this operation */ } }
        }
    ]
}
```
- **`client_temp_id` Handling**: If a `client_temp_id` is provided for a "create" operation, the corresponding result object in the `results` array will include this `client_temp_id` along with the actual `id` of the created note. This allows the client to map its temporary ID to the permanent server-assigned ID. Subsequent operations in the *same batch request* can then use this `client_temp_id` in the `id` field for "update" or "delete" operations, and the server will resolve it to the newly created note's ID.

#### Error Responses (Batch Operations)

- **Invalid Batch Structure**: If the overall batch request is malformed (e.g., missing `action: "batch"` or `operations` array).
    *Status Code: 400 Bad Request*
    ```json
    {
        "status": "error",
        "message": "Invalid batch request structure. 'action' must be 'batch' and 'operations' array is required.",
        "details": {}
    }
    ```
- **Transaction Failure**: If the batch operations are designed to be atomic and a failure in one operation causes a rollback of all operations.
    *Status Code: 500 Internal Server Error* (or 409 Conflict if appropriate)
    ```json
    {
        "status": "error",
        "message": "Batch transaction failed. All operations have been rolled back.",
        "details": {
            "failed_operation_index": 2, // Optional: index of the operation that caused the failure
            "reason": "Specific error from the failing operation"
        }
    }
    ```
- Individual operation errors are reported within the `results` array as shown in the success response example.

---

## Pages API (`api/v1/pages.php`)

### Main Objective

Manages pages, which act as containers for notes.

### Supported HTTP Methods & Endpoints

- **GET `api/v1/pages.php`**: Retrieves a list of pages or a single page.
- **POST `api/v1/pages.php`**: Creates a new page, updates an existing page, or deletes a page.

### Request Parameters

#### GET `api/v1/pages.php`

- **URL Parameters**:
    - `id` (optional, integer): ID of the page.
    - `name` (optional, string): Name of the page.
    - `exclude_journal` (optional, `1`): If set, excludes journal pages from list view.
    - `follow_aliases` (optional, `0` or `1`): Default `1` (retrieve the aliased page).
    - `include_details` (optional, `1`): If `1`, notes within the page are returned.
        - `notes_page` (optional, integer, requires `include_details=1`): Page number for notes list. Defaults to `1`.
        - `notes_per_page` (optional, integer, requires `include_details=1`): Number of notes per page. Defaults to `20`. Set to `0` or `"all"` to retrieve all notes for the page (no pagination for notes).
    - `include_internal` (optional, boolean): If `true`, internal properties included. Defaults to `false`.
    - `page` (optional, integer): Page number for page list (if not getting by `id` or `name`). Defaults to `1`.
    - `per_page` (optional, integer): Items per page for page list. Defaults to `10`.
    - `sort_by` (optional, string): Field to sort by (e.g., `name`, `updated_at`). Default `name`.
    - `sort_order` (optional, string): `asc` or `desc`. Default `asc`.

#### POST `api/v1/pages.php` (Create Page)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (optional, string): If not present or different from "update"/"delete", implies "create".
    - `name` (required, string): Name of the page. If exists, returns existing (unless `action` implies update/delete). Journal pages (YYYY-MM-DD) are auto-created if they don't exist.
    - `alias` (optional, string): Alias for the page.
    - `properties_explicit` (optional, object): Explicit properties to set for the page.

#### POST `api/v1/pages.php` (Update Page)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `"update"`.
    - `id` (required, integer): ID of the page to update.
    - `name` (optional, string): New name for the page. Must be unique.
    - `alias` (optional, string|null): New alias.
    - `properties_explicit` (optional, object): Explicit properties to set/update.
    *At least one of `name`, `alias`, or `properties_explicit` must be provided for an update.*

#### POST `api/v1/pages.php` (Delete Page)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `"delete"`.
    - `id` (required, integer): ID of the page to delete.

### Response Structure (Property objects are always `{"value": "...", "internal": 0/1}` for consistency)

#### Success Responses

- **GET `api/v1/pages.php?id={id}` or `?name={name}` (Single Page)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": {
            "id": 1,
            "name": "My Page",
            "alias": "my-alias",
            "updated_at": "YYYY-MM-DD HH:MM:SS",
            "properties": {
                "type": [{"value": "project", "internal": 0}]
            },
            // If include_details=1, notes_data object would be here:
            "notes_data": { 
                "notes": [ 
                    { /* note object 1, with its own 'properties' object */ },
                    { /* note object 2, with its own 'properties' object */ }
                ],
                "pagination": { // This is pagination for the notes list
                    "total_items": 50,
                    "per_page": 20,
                    "current_page": 1,
                    "total_pages": 3
                } // This pagination object will be null if notes_per_page was 0/'all' or if not applicable.
            }
        }
    }
    ```
- **GET `api/v1/pages.php` (List All Pages)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": [ /* array of page objects (structure as single page, without notes unless include_details=1) */ ],
        "pagination": { /* pagination object */ }
    }
    ```
- **POST `api/v1/pages.php` (Create Page)**:
    *Status Code: 201 Created (if new) or 200 OK (if existing page by name is returned)*
    *Headers (if 201): `Location: /api/v1/pages.php?id={new_page_id}`*
    ```json
    {
        "status": "success",
        "message": "Page created/retrieved successfully.", // Adjust message based on action
        "data": { /* page object */ }
    }
    ```
- **POST `api/v1/pages.php` (Update Page)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Page updated successfully.",
        "data": { /* updated page object */ }
    }
    ```
- **POST `api/v1/pages.php` (Delete Page)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Page deleted successfully.",
        "data": {
            "deleted_page_id": 1
        }
    }
    ```

#### Error Responses
    *General structure. Specific messages: "Page not found", "Page name already exists" (on update if name conflict), "Invalid input".*

---

## Properties API (`api/v1/properties.php`)

### Main Objective

Manages properties associated with notes or pages.

### Supported HTTP Methods & Endpoints

- **GET `api/v1/properties.php`**: Retrieves properties for an entity.
- **POST `api/v1/properties.php`**: Creates, updates, or deletes a property for an entity.

### Request Parameters

#### GET `api/v1/properties.php`

- **URL Parameters**:
    - `entity_type` (required, string): `note` or `page`.
    - `entity_id` (required, integer): ID of the entity.
    - `include_internal` (optional, boolean): Defaults to `false`.

#### POST `api/v1/properties.php` (Create/Update Property)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (optional, string): If not present or different from "delete", implies "set" (create/update).
    - `entity_type` (required, string): `note` or `page`.
    - `entity_id` (required, integer): ID of the entity.
    - `name` (required, string): Property name.
    - `value` (required, any): Property value.
    - `internal` (optional, integer `0` or `1`): Explicitly set internal status.

#### POST `api/v1/properties.php` (Delete Property)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `"delete"`.
    - `entity_type` (required, string): `note` or `page`.
    - `entity_id` (required, integer): ID of the entity.
    - `name` (required, string): Name of the property to delete.
    - `value` (optional, any): If provided, deletes a specific value from a multi-value property. If omitted, deletes all values for the property name.

### Response Structure (Property objects are always `{"value": "...", "internal": 0/1}` for consistency)

#### Success Responses

- **GET `api/v1/properties.php`**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": {
            "property_name_1": [{"value": "value1", "internal": 0}],
            "property_name_2": [
                {"value": "value2a", "internal": 0},
                {"value": "value2b", "internal": 0}
            ]
            // "internal_prop": [{"value": "secret", "internal": 1}] // if include_internal=true
        }
    }
    ```
- **POST `api/v1/properties.php` (Create/Update)**:
    *Status Code: 200 OK (or 201 Created if considered a new sub-resource, but 200 OK is fine for property set)*
    ```json
    {
        "status": "success",
        "message": "Property set successfully.",
        "data": {
            "name": "color",
            "values": [{"value": "blue", "internal": 0}] // Return all current values for the property
        }
    }
    ```
- **POST `api/v1/properties.php` (Delete)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Property deleted/value removed successfully.",
        "data": { // Optionally return the remaining state of the property or null
            "name": "property_name",
            "values": [/* remaining values, or empty if all deleted */]
        }
    }
    ```

#### Error Responses
    *General structure. Specific messages: "Entity not found", "Invalid property format", "Property not found for deletion".*

---

## Property Definitions API (`api/v1/property_definitions.php`)

### Main Objective

Manages standard property definitions.

### Supported HTTP Methods & Endpoints

- **GET `api/v1/property_definitions.php`**: Retrieves all property definitions.
- **POST `api/v1/property_definitions.php`**: Creates/updates a definition, applies definitions, or deletes a definition.

### Request Parameters

#### GET `api/v1/property_definitions.php`

- **URL Parameters**:
    - `page` (optional, integer): Page number. Defaults to `1`.
    - `per_page` (optional, integer): Items per page. Defaults to `10`.

#### POST `api/v1/property_definitions.php` (Create/Update Definition)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (optional, string): If not "apply_definition", "apply_all", or "delete", implies "set" (create/update).
    - `name` (required, string): Property name being defined.
    - `internal` (optional, integer `0` or `1`): Default `0`.
    - `description` (optional, string): Description.
    - `auto_apply` (optional, integer `0` or `1`): Default `1`.

#### POST `api/v1/property_definitions.php` (Apply Single Definition)

- **JSON Payload**:
    - `action` (required, string): Must be `"apply_definition"`.
    - `name` (required, string): Name of the definition to apply.

#### POST `api/v1/property_definitions.php` (Apply All Definitions)

- **JSON Payload**:
    - `action` (required, string): Must be `"apply_all"`.

#### POST `api/v1/property_definitions.php` (Delete Definition)

- **JSON Payload**:
    - `action` (required, string): Must be `"delete"`.
    - `id` (required, integer): ID of the definition to delete.

### Response Structure

#### Success Responses

- **GET `api/v1/property_definitions.php` (List Definitions)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 1,
                "name": "status",
                "internal": 0,
                "description": "Tracks status.",
                "auto_apply": 1,
                "created_at": "YYYY-MM-DD HH:MM:SS",
                "updated_at": "YYYY-MM-DD HH:MM:SS"
            }
        ],
        "pagination": { /* pagination object */ }
    }
    ```
- **POST (Create/Update Definition)**:
    *Status Code: 200 OK (or 201 if new and `Location` header)*
    ```json
    {
        "status": "success",
        "message": "Property definition saved.", // Add "and applied..." if auto_apply was true and successful
        "data": { /* property definition object */ }
    }
    ```
- **POST (Apply Single/All Definitions, Delete Definition)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Action completed successfully." // e.g., "Applied definition...", "Definitions applied...", "Definition deleted."
        // "data": { /* Optional: details like count of affected items */ }
    }
    ```

#### Error Responses
    *General structure. Specific messages: "Property name required", "Definition ID required".*

---

## Search API (`api/v1/search.php`)

### Main Objective

Provides search functionalities across notes and pages.

### Supported HTTP Methods & Endpoints

- **GET `api/v1/search.php`**: Performs a search.

### Request Parameters

#### GET `api/v1/search.php`

- **URL Parameters (mutually exclusive search modes)**:
    - `q` (optional, string): Full-text search term.
    - `backlinks_for_page_name` (optional, string): Page name for backlink search.
    - `tasks` (optional, string): Task status (`todo` or `done`).
- **Pagination Parameters (apply to all search modes)**:
    - `page` (optional, integer): Page number. Defaults to `1`.
    - `per_page` (optional, integer): Items per page. Defaults to `10`.

### Response Structure

#### Success Responses (Example for Full-Text Search)

- **GET `api/v1/search.php?q={term}`**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": [
            {
                "note_id": 123,
                "content": "Full content...",
                "page_id": 10,
                "page_name": "Containing Page",
                "content_snippet": "... <mark>term</mark> ..."
                // "properties": { ... } // Consider adding if useful for search results
            }
            // ... more results
        ],
        "pagination": { /* pagination object */ }
    }
    ```
    *Similar structure for `backlinks` and `tasks` search, with relevant fields and pagination.*

#### Error Responses
    *General structure. Specific messages: "Missing search parameter", "Invalid task status".*

---

## Templates API (`api/v1/templates.php`)

### Main Objective

Manages templates for notes and pages.

### Supported HTTP Methods & Endpoints

- **GET `api/v1/templates.php`**: Retrieves a list of templates.
- **POST `api/v1/templates.php`**: Creates, updates, or deletes a template.

### Request Parameters

#### GET `api/v1/templates.php`

- **URL Parameters**:
    - `type` (required, string): `note` or `page`.
    - `page` (optional, integer): Page number. Defaults to `1`.
    - `per_page` (optional, integer): Items per page. Defaults to `10`.

#### POST `api/v1/templates.php` (Create Template)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (optional, string): If not "update" or "delete", implies "create".
    - `type` (required, string): `note` or `page`.
    - `name` (required, string): Template name (no extension).
    - `content` (required, string): Template content.

#### POST `api/v1/templates.php` (Update Template)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `"update"`.
    - `type` (required, string): `note` or `page`.
    - `current_name` (required, string): Current name of the template to update.
    - `new_name` (optional, string): New name for the template.
    - `content` (optional, string): New content for the template.
    *At least one of `new_name` or `content` must be provided for update.*

#### POST `api/v1/templates.php` (Delete Template)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `"delete"`.
    - `type` (required, string): `note` or `page`.
    - `name` (required, string): Name of the template to delete.

### Response Structure

#### Success Responses

- **GET `api/v1/templates.php?type={type}`**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": [
            {
                "name": "template_name_1",
                "type": "note", // Added for clarity
                "content": "Content of template 1..."
            }
        ],
        "pagination": { /* pagination object */ }
    }
    ```
- **POST (Create Template)**:
    *Status Code: 201 Created*
    *Headers: `Location: /api/v1/templates.php?type={type}&name={new_template_name}` (or similar unique identifier)*
    ```json
    {
        "status": "success",
        "message": "Template created successfully.",
        "data": { /* created template object */ }
    }
    ```
- **POST (Update Template)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Template updated successfully.",
        "data": { /* updated template object */ }
    }
    ```
- **POST (Delete Template)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Template deleted successfully.",
        "data": { "deleted_template_name": "name", "type": "type" }
    }
    ```

#### Error Responses
    *General structure. Specific messages: "Invalid template type", "Template name and content required", "Failed to create/update/delete template".*

---

## Query Notes API (`api/v1/query_notes.php`)

### Main Objective

Allows fetching notes based on custom SQL queries (heavily restricted).

### Supported HTTP Methods & Endpoints

- **POST `api/v1/query_notes.php`**: Executes a custom SQL query.

### Request Parameters

#### POST `api/v1/query_notes.php`

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `sql_query` (required, string): SQL query conforming to allowed patterns.
    - `include_properties` (optional, boolean): Defaults to `false`. If `true`, fetches and embeds properties for each note.
    - `page` (optional, integer): Page number for query results. Defaults to `1`.
    - `per_page` (optional, integer): Items per page for query results. Defaults to `10`.

### Response Structure

#### Success Responses

- **POST `api/v1/query_notes.php`**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": [
            { // Note object structure similar to GET /v1/notes.php?id={id}
                "id": 123,
                "page_id": 10,
                "content": "Content...",
                // ... other note fields ...
                "properties": { /* if include_properties=true */ }
            }
        ],
        "pagination": { /* pagination object for query results */ }
    }
    ```

#### Error Responses
    *General structure. Specific messages: "Missing sql_query parameter", "Invalid SQL query", "Database error".*

---

## Utility Scripts

*(This section remains largely conceptual as these are not direct API endpoints. Their descriptions are fine.)*
- `api/data_manager.php`
- `api/db_connect.php`
- `api/pattern_processor.php`
- `api/property_auto_internal.php`
- `api/property_parser.php`
- `api/property_trigger_service.php`
- `api/response_utils.php`
- `api/validator_utils.php`

---

## Webhooks API (`api/v1/webhooks.php`)

### Main Objective

Manages webhook subscriptions for real-time event notifications.

### Supported HTTP Methods & Endpoints

- **GET `api/v1/webhooks.php`**: Lists webhooks or gets details of a specific webhook or its history.
- **POST `api/v1/webhooks.php`**: Creates, updates, deletes, tests, or verifies a webhook.

### Request Parameters

#### GET `api/v1/webhooks.php` (List or Get Specific)

- **URL Parameters**:
    - `id` (optional, integer): If provided, gets details of a specific webhook.
    - `action` (optional, string): If `id` is provided, can be `"history"` to get event delivery history.
        - If `action=history`:
            - `page` (optional, integer): Page for history. Defaults to `1`.
            - `limit` (optional, integer): Items per page for history. Defaults to `20`. (Renamed from `per_page` if `limit` is more conventional here).
    - If no `id` or `action`:
        - `page` (optional, integer): Page for webhook list. Defaults to `1`.
        - `per_page` (optional, integer): Items per page for list. Defaults to `10`.

#### POST `api/v1/webhooks.php` (All Actions)

- **Headers**: `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): One of `"create"`, `"update"`, `"delete"`, `"test"`, `"verify"`.
    - **For `create`**:
        - `url` (required, string): Webhook URL.
        - `entity_type` (required, string): e.g., "note", "page".
        - `property_name` (required, string): Property to monitor (or `*` for any). Consider allowing an array of property names.
        - `event_types` (optional, array of string): e.g., `["property_change", "entity_created"]`. Defaults to `["property_change"]`.
        - `active` (optional, boolean): Defaults to `true`.
    - **For `update`**:
        - `id` (required, integer): Webhook ID to update.
        - `url` (optional, string): New URL.
        - `entity_type` (optional, string): New entity type.
        - `property_name` (optional, string): New property name.
        - `event_types` (optional, array of string): New event types.
        - `active` (optional, boolean): New active status.
    - **For `delete`**:
        - `id` (required, integer): Webhook ID to delete.
    - **For `test`**:
        - `id` (required, integer): Webhook ID to test.
    - **For `verify`**:
        - `id` (required, integer): Webhook ID to verify.

### Response Structure (Success)

- **POST `action="create"`**:
    *Status Code: 201 Created*
    *Headers: `Location: /api/v1/webhooks.php?id={new_webhook_id}`*
    ```json
    {
        "status": "success",
        "message": "Webhook created successfully.",
        "data": { /* webhook object, including secret */ }
    }
    ```
- **GET (List webhooks)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": [ /* array of webhook objects (secret not included) */ ],
        "pagination": { /* pagination object */ }
    }
    ```
- **GET `?id={id}` (Specific webhook)**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": { /* webhook object (secret not included) */ }
    }
    ```
- **POST `action="update"`**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Webhook updated successfully.",
        "data": { /* updated webhook object (secret not included) */ }
    }
    ```
- **POST `action="delete"`**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Webhook deleted successfully.",
        "data": { "deleted_webhook_id": 123 }
    }
    ```
- **POST `action="test"` / `action="verify"`**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "message": "Test event sent." // or "Verification initiated/completed."
        // "data": { /* optional details */ }
    }
    ```
- **GET `?id={id}&action=history`**:
    *Status Code: 200 OK*
    ```json
    {
        "status": "success",
        "data": { /* webhook history events */ },
        "pagination": { /* pagination for history */ }
    }
    ```

### Event Triggers and Payload Structure / Verifying Signatures
*(These sections from your original document are generally good and don't need major changes other than ensuring consistency with the `status: "success"` key if example API calls are shown).*

### Error Responses
    *General structure. Specific messages: "Webhook not found", "Invalid input", "Verification failed".*

---