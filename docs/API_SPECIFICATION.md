# API Specification

This document provides a detailed specification for the API endpoints.

## Table of Contents

- [Attachments API (`api/attachments.php`)](#attachments-api)
- [Notes API (`api/notes.php`)](#notes-api)
- [Pages API (`api/pages.php`)](#pages-api)
- [Properties API (`api/properties.php`)](#properties-api)
- [Property Definitions API (`api/property_definitions.php`)](#property-definitions-api)
- [Internal Properties API (`api/internal_properties.php`)](#internal-properties-api)
- [Search API (`api/search.php`)](#search-api)
- [Templates API (`api/templates.php`)](#templates-api)
- [Query Notes API (`api/query_notes.php`)](#query-notes-api)
- [Utility Scripts](#utility-scripts)

## Attachments API

### Main Objective

The Attachments API is responsible for managing file attachments associated with notes. It allows users to upload, download, and delete attachments.

### Supported HTTP Methods & Endpoints

- **POST `api/attachments.php`**: Uploads a new attachment.
- **GET `api/attachments.php`**: Retrieves an attachment or a list of attachments.
- **DELETE `api/attachments.php`**: Deletes an attachment.

### Request Parameters

#### POST `api/attachments.php`

- **Headers**:
    - `Content-Type: multipart/form-data`
- **Body**:
    - `note_id` (required): The ID of the note to which the attachment should be associated.
    - `file` (required): The file to be uploaded. (Sent as `attachmentFile` in the form data).

#### GET `api/attachments.php`

This endpoint has two modes of operation:

1.  **Retrieve a specific attachment by its ID**:
    -   **URL Parameters**:
        -   `id` (required): The ID of the attachment to retrieve.
        -   `action=download` (optional): If specified, forces the browser to download the file.
        -   `action=view` (optional): If specified, attempts to display the file in the browser (default).
    -   *Note: This mode is triggered if an `id` parameter is provided.*

2.  **List all attachments (with filtering and pagination)**:
    -   **URL Parameters**:
        -   `note_id` (optional): If provided, lists attachments only for this specific note.
        -   `page` (optional, integer): The page number for pagination. Defaults to `1`.
        -   `per_page` (optional, integer): The number of attachments to return per page. Defaults to `10`, max `100`.
        -   `sort_by` (optional, string): The field to sort attachments by. Allowed values: `id`, `name`, `path`, `type`, `size`, `created_at`. Defaults to `created_at`.
        -   `sort_order` (optional, string): The order of sorting. Allowed values: `asc`, `desc`. Defaults to `desc`.
        -   `filter_by_name` (optional, string): Filters attachments by name (case-insensitive, partial match).
        -   `filter_by_type` (optional, string): Filters attachments by exact MIME type (e.g., `image/jpeg`, `application/pdf`).
    -   *Note: This mode is triggered if no `id` parameter is provided. If `note_id` is provided without other listing parameters, it will list all attachments for that note.*


#### DELETE `api/attachments.php`

- **URL Parameters**:
    - `id` (required): The ID of the attachment to delete.

### Response Structure

#### Success Responses

- **POST `api/attachments.php`**:
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
            "s3_key": "attachments/note_456/example.jpg",
            "created_at": "YYYY-MM-DD HH:MM:SS"
        }
    }
    ```
- **GET `api/attachments.php` (Retrieve specific attachment - action=view or no action specified)**:
    - The raw file content is returned with the appropriate `Content-Type` header.
- **GET `api/attachments.php` (Retrieve specific attachment - action=download)**:
    - The raw file content is returned with `Content-Disposition: attachment; filename="example.jpg"` and the appropriate `Content-Type` header.
- **GET `api/attachments.php` (List all attachments)**:
    ```json
    {
        "status": "success",
        "data": {
            "data": [
                {
                    "id": 1,
                    "name": "document.pdf",
                    "path": "2023/10/uniqueid_document.pdf",
                    "type": "application/pdf",
                    "size": 123456, // in bytes
                    "created_at": "YYYY-MM-DD HH:MM:SS",
                    "url": "http://example.com/uploads/2023/10/uniqueid_document.pdf"
                },
                {
                    "id": 2,
                    "name": "image.png",
                    "path": "2023/11/anotherid_image.png",
                    "type": "image/png",
                    "size": 78900, // in bytes
                    "created_at": "YYYY-MM-DD HH:MM:SS",
                    "url": "http://example.com/uploads/2023/11/anotherid_image.png"
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
    }
    ```
   *Note: If listing attachments for a specific `note_id` (using the `note_id` parameter without the main `id` parameter), the response structure is an array of attachment objects directly under `data`, without the nested `data` and `pagination` keys, e.g.:*
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 3,
                "note_id": 5, // Will be present
                "name": "note_specific.txt",
                "path": "2023/12/notespec_text.txt",
                "type": "text/plain",
                "size": 1234,
                "created_at": "YYYY-MM-DD HH:MM:SS",
                "url": "http://example.com/uploads/2023/12/notespec_text.txt"
            }
            // ... other attachments for this note
        ]
    }
    ```
- **DELETE `api/attachments.php`**:
    ```json
    {
        "status": "success",
        "data": { // Modified to reflect actual response
            "deleted_attachment_id": 123
        }
    }
    ```

#### Error Responses

- **General Error (e.g., missing parameters, server error)**:
    ```json
    {
        "status": "error",
        "message": "Error message describing the issue."
        // "details": { ... } // Optional for specific field errors
    }
    ```
- **Attachment Not Found (GET specific attachment / DELETE)**:
    ```json
    {
        "status": "error",
        "message": "Attachment not found" // Corrected message
    }
    ```
- **Upload Failed (POST)**:
    ```json
    {
        "status": "error",
        "message": "File upload failed. Details about the failure." // Or specific messages like "File type not allowed", "File exceeds maximum size limit"
    }
    ```
- **Note Not Found (POST)**:
     ```json
    {
        "status": "error",
        "message": "Note not found" // Corrected message
    }
    ```
- **Invalid Input (e.g., for pagination parameters)**:
    ```json
    {
        "status": "error",
        "message": "Invalid input for X.", // X would be the parameter name
        "details": { /* specific errors */ }
    }
    ```


---

## Notes API

### Main Objective

The Notes API is responsible for managing notes, including their content and associated properties. It supports creating, retrieving, updating, and deleting notes.

### Supported HTTP Methods & Endpoints

- **GET `api/notes.php`**: Retrieves a list of notes or a single note.
    - `?id={note_id}`: Retrieves a specific note by its ID.
    - `?page_id={page_id}`: Retrieves all notes associated with a specific page, along with the page details and properties.
    - No parameters: Retrieves all notes (consider pagination for large datasets).
    - `?include_internal=true`: Optionally includes internal properties in the response.
- **POST `api/notes.php`**: Creates a new note.
- **PUT `api/notes.php?id={note_id}`**: Updates an existing note. Can also be called with `id` in the JSON payload if using `_method: "PUT"` override.
- **DELETE `api/notes.php?id={note_id}`**: Deletes a note. Can also be called with `id` in the JSON payload if using `_method: "DELETE"` override.

### Request Parameters

#### GET `api/notes.php`

- **URL Parameters**:
    - `id` (optional): The ID of the note to retrieve.
    - `page_id` (optional): The ID of the page whose notes are to be retrieved.
    - `include_internal` (optional, boolean): If `true`, internal properties are included in the response. Defaults to `false`.

#### POST `api/notes.php`

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `page_id` (required, integer): The ID of the page to which the note belongs.
    - `content` (optional, string): The content of the note. Properties can be embedded in the content (e.g., `key:: value`) and will be automatically parsed and saved.

#### PUT `api/notes.php?id={note_id}`

- **Headers**:
    - `Content-Type: application/json`
- **URL Parameters**:
    - `id` (required, integer): The ID of the note to update.
- **JSON Payload**:
    - `id` (optional, integer): The ID of the note to update (can be used if using `_method: "PUT"` override instead of URL parameter).
    - `content` (optional, string): The new content for the note. If provided, existing non-internal properties will be re-parsed from this content unless `properties_explicit` is also provided.
    - `parent_note_id` (optional, integer|null): The ID of the parent note, or `null` for a top-level note within its page.
    - `order_index` (optional, integer): The display order of the note among its siblings.
    - `collapsed` (optional, integer): `0` for expanded, `1` for collapsed.
    - `properties_explicit` (optional, object): An object where keys are property names and values are the property values (or an array of values for multi-value properties). If provided, these properties will be saved, and content parsing for properties will be skipped. Existing non-internal properties are cleared before saving these.
    - `_method` (optional, string): Set to `PUT` to override POST method (for environments like phpdesktop that might not support PUT directly).

#### DELETE `api/notes.php?id={note_id}`

- **Headers**:
    - `Content-Type: application/json` (if sending a body for method override)
- **URL Parameters**:
    - `id` (required, integer): The ID of the note to delete.
- **JSON Payload (for method override)**:
    - `id` (optional, integer): The ID of the note to delete (can be used if using `_method: "DELETE"` override).
    - `_method` (optional, string): Set to `DELETE` to override POST method.

### Response Structure

#### Success Responses

- **GET `api/notes.php?id={note_id}` (Single Note)**:
    ```json
    {
        "success": true,
        "data": {
            "id": 1,
            "page_id": 10,
            "content": "This is a sample note.\ncategory:: personal\ntags:: #important #urgent",
            "parent_note_id": null,
            "order_index": 0,
            "collapsed": 0,
            "has_attachments": 0,
            "created_at": "YYYY-MM-DD HH:MM:SS",
            "updated_at": "YYYY-MM-DD HH:MM:SS",
            "internal": 0,
            "properties": {
                "category": "personal", // Single value property
                "tags": [ // Multi-value property if multiple instances or if include_internal=true
                    {"value": "#important", "internal": 0},
                    {"value": "#urgent", "internal": 0}
                ]
                // ... other properties ...
            }
        }
    }
    ```
    *Note: If `include_internal=false` (default), properties are simplified: single-value properties are direct key-value pairs. If `include_internal=true`, or for multi-value properties, each property value is an object `{"value": "...", "internal": 0/1}`.*

- **GET `api/notes.php?page_id={page_id}` (Notes by Page)**:
    ```json
    {
        "success": true,
        "data": {
            "page": {
                "id": 10,
                "title": "My Awesome Page",
                "created_at": "YYYY-MM-DD HH:MM:SS",
                "updated_at": "YYYY-MM-DD HH:MM:SS",
                "properties": {
                    "page_status": "draft",
                    "priority": {"value": "high", "internal": 0}
                    // ... other page properties ...
                }
            },
            "notes": [
                {
                    "id": 1,
                    "page_id": 10,
                    "content": "This is note 1.",
                    "has_attachments": 1,
                    // ... other note fields ...
                    "properties": {
                        // ... note 1 properties ...
                    }
                },
                {
                    "id": 2,
                    "page_id": 10,
                    "content": "This is note 2.",
                    "has_attachments": 0,
                    // ... other note fields ...
                    "properties": {
                        // ... note 2 properties ...
                    }
                }
            ]
        }
    }
    ```

- **GET `api/notes.php` (All Notes - structure of each note similar to single note response)**:
    ```json
    {
        "success": true,
        "data": [
            // ... array of note objects ...
        ]
    }
    ```

- **POST `api/notes.php` (Create Note)**:
    ```json
    {
        "success": true,
        "data": {
            "id": 2,
            "page_id": 10,
            "content": "Newly created note.\nstatus:: new",
            "has_attachments": 0,
            // ... other fields (parent_note_id, order_index default to null/0 or as set) ...
            "properties": {
                "status": "new"
            }
        }
    }
    ```
    *(Response status code: 201 Created)*

- **PUT `api/notes.php?id={note_id}` (Update Note)**:
    ```json
    {
        "success": true,
        "data": {
            "id": 1,
            "page_id": 10,
            "content": "Updated note content.\nstatus:: updated",
            "has_attachments": 0,
            // ... other updated fields ...
            "properties": {
                "status": "updated"
                // ... other current properties ...
            }
        }
    }
    ```

- **DELETE `api/notes.php?id={note_id}` (Delete Note)**:
    ```json
    {
        "success": true,
        "data": {
            "deleted_note_id": 1
        }
    }
    ```

#### Error Responses

- **General Error (e.g., missing parameters, server error, invalid JSON)**:
    ```json
    {
        "success": false,
        "error": "Error message describing the issue."
        // "details": "More specific error message if available" (e.g. for POST/PUT failures)
    }
    ```
    *(Response status code: 400 Bad Request, 500 Internal Server Error, etc.)*

- **Note Not Found (GET/PUT/DELETE)**:
    ```json
    {
        "success": false,
        "error": "Note not found" // or "Note not found or is internal" if include_internal=false
    }
    ```
    *(Response status code: 404 Not Found)*

- **Page Not Found (POST when creating a note with invalid page_id, or GET ?page_id={id})**:
    ```json
    {
        "success": false,
        "error": "Page not found" // or "A valid page_id is required."
    }
    ```
    *(Response status code: 404 Not Found or 400 Bad Request)*

- **Method Not Allowed**:
    ```json
    {
        "success": false,
        "error": "Method not allowed"
    }
    ```
    *(Response status code: 405 Method Not Allowed)*

---

## Query Notes API

### Main Objective

The Query Notes API allows for fetching notes based on custom SQL queries. This endpoint is designed for advanced use cases where complex filtering is required, such as querying notes based on specific property values, combinations of properties, or other criteria not directly supported by simpler API endpoints. For security, the SQL queries are heavily restricted to specific patterns and keywords.

### Supported HTTP Methods & Endpoints

- **POST `api/query_notes.php`**: Executes a custom SQL query to retrieve notes.

### Request Parameters

#### POST `api/query_notes.php`

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `sql_query` (required, string): The SQL query to execute. The query must conform to one of the allowed patterns:
        1.  `SELECT id FROM Notes WHERE [conditions]`
        2.  `SELECT [DISTINCT] N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE [conditions]`
        3.  `SELECT id FROM Notes WHERE id IN (SELECT note_id FROM Properties WHERE [conditions])`
        The query must only use `SELECT` statements and is restricted to `Notes`, `Properties`, and `Pages` tables and their allowed columns. Forbidden keywords (like `UPDATE`, `DELETE`, `DROP`, etc.) and SQL comments (`--`, `/* */`) are not allowed. Semicolons are only permitted at the very end of the query.

### Response Structure

#### Success Responses

- **POST `api/query_notes.php`**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 123,
                "page_id": 10,
                "content": "Content of note 123...",
                "parent_note_id": null,
                "order_index": 0,
                "collapsed": 0,
                "created_at": "YYYY-MM-DD HH:MM:SS",
                "updated_at": "YYYY-MM-DD HH:MM:SS",
                "internal": 0,
                "active": 1
                // Note: Properties are NOT automatically included here.
                // The query returns note objects. Client needs to fetch properties separately if needed.
            },
            {
                "id": 456,
                // ... other fields for note 456
            }
            // ... more notes matching the query
        ]
    }
    ```
    *(If no notes match the query, `data` will be an empty array `[]`.)*

#### Error Responses

- **Missing `sql_query` Parameter**:
    ```json
    {
        "status": "error",
        "message": "Missing sql_query parameter."
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Invalid SQL Query (Pattern Mismatch, Forbidden Keywords, Unauthorized Tables/Columns, Invalid Semicolon Use)**:
    ```json
    {
        "status": "error",
        "message": "Query must be one of the allowed patterns..." // or other specific validation error
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Database Error (During Query Execution)**:
    ```json
    {
        "status": "error",
        "message": "A database error occurred."
    }
    ```
    *(Response status code: 500 Internal Server Error)*

- **Unexpected Error**:
    ```json
    {
        "status": "error",
        "message": "An unexpected error occurred."
    }
    ```
    *(Response status code: 500 Internal Server Error)*

---

## Utility Scripts

This section briefly describes various utility scripts found in the `api/` directory. These scripts are not typically called directly as API endpoints but provide essential backend functions, helper classes, or services used by the main API handlers.

-   **`api/data_manager.php`**:
    -   **Role**: Provides a `DataManager` class that centralizes data retrieval operations, especially for fetching pages, notes, and their properties with consistent formatting. It helps abstract database interactions for common data shapes like getting a page with all its notes and properties.
    -   **Used By**: `pages.php`, `properties.php`, `search.php`.

-   **`api/db_connect.php`**:
    -   **Role**: Contains the function `get_db_connection()` which is responsible for establishing and returning a PDO database connection instance using settings from `config.php`. It ensures a single point of database connection setup.
    -   **Used By**: Almost all API endpoint files that interact with the database.

-   **`api/pattern_processor.php`**:
    -   **Role**: Implements the `PatternProcessor` class. This class is responsible for parsing note content to find and extract properties (e.g., `key:: value`), tags (`#tag`), and links (`[[Page Name]]`). It also handles the synchronization of these parsed properties with the `Properties` table in the database.
    -   **Used By**: `notes.php` (when creating/updating notes with content).

-   **`api/property_auto_internal.php`**:
    -   **Role**: Contains the `determinePropertyInternalStatus()` function. This function checks against `PropertyDefinitions` to decide if a property being saved should be marked as `internal`. It centralizes the logic for how a property's internal status is automatically set.
    -   **Used By**: `notes.php`, `properties.php`.

-   **`api/property_parser.php`**:
    -   **Role**: Provides the `syncNotePropertiesFromContent()` function (and potentially other related parsing utilities). This function orchestrates the use of `PatternProcessor` to parse properties from note content and then saves them to the database, ensuring that properties derived from content are correctly stored.
    -   **Used By**: `notes.php`.

-   **`api/property_trigger_service.php`**:
    -   **Role**: Implements the `PropertyTriggerService` class. This service is responsible for handling actions that should occur when specific properties are set or changed. For example, if a `date_due:: YYYY-MM-DD` property is set, it might trigger the creation of a calendar event. It manages a registry of triggers and dispatches them accordingly.
    -   **Used By**: `properties.php`, `internal_properties.php`, `notes.php` (indirectly via property updates).

-   **`api/response_utils.php`**:
    -   **Role**: Provides the `ApiResponse` class with static methods like `success()` and `error()`. This utility standardizes the JSON response format across all APIs, ensuring consistent structure for success and error messages, status codes, and data payloads.
    -   **Used By**: Most API endpoint files to send JSON responses.

-   **`api/validator_utils.php`**:
    -   **Role**: Provides the `Validator` class with static methods for validating input data (e.g., `validate()`, `sanitizeString()`, type checks like `isPositiveInteger`, `isValidEntityType`). It helps ensure that data received by API endpoints is clean and meets expected formats before processing.
    -   **Used By**: `pages.php`, `properties.php`.

---

## Templates API

### Main Objective

The Templates API manages templates for notes and pages. It allows listing available templates, retrieving their content, creating new templates, and deleting existing ones. Templates are stored as files on the server.

### Supported HTTP Methods & Endpoints

- **GET `api/templates.php`**: Retrieves a list of available templates and their content for a specified type.
    - `?type={template_type}` (required): `note` or `page`.
- **POST `api/templates.php`**: Creates a new template.
- **DELETE `api/templates.php`**: Deletes an existing template.
    - `?name={template_name}` (required): The name of the template to delete.
    - `?type={template_type}` (required): The type of the template (`note` or `page`).

### Request Parameters

#### GET `api/templates.php`

- **URL Parameters**:
    - `type` (required, string): The type of templates to retrieve, either `note` or `page`.

#### POST `api/templates.php`

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `type` (required, string): The type of template to create, either `note` or `page`.
    - `name` (required, string): The name for the new template (e.g., `meeting_minutes`, `project_plan`). The `.php` or `.json` extension should be omitted as it's handled by the backend.
    - `content` (required, string): The content of the template.

#### DELETE `api/templates.php`

- **URL Parameters**:
    - `name` (required, string): The name of the template to delete (omitting extension).
    - `type` (required, string): The type of template to delete, either `note` or `page`.

### Response Structure

#### Success Responses

- **GET `api/templates.php?type={template_type}`**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "name": "template_name_1", // e.g., "meeting_note"
                "content": "Content of template 1..."
            },
            {
                "name": "template_name_2", // e.g., "bookmark"
                "content": "Content of template 2..."
            }
            // ... more templates
        ]
    }
    ```

- **POST `api/templates.php` (Create Template)**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Template created successfully"
        }
    }
    ```

- **DELETE `api/templates.php?name={template_name}&type={template_type}` (Delete Template)**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Template deleted successfully"
        }
    }
    ```

#### Error Responses

- **Invalid Template Type (GET, POST, DELETE)**:
    ```json
    {
        "status": "error",
        "message": "Invalid template type"
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Template Name and Content Required (POST)**:
    ```json
    {
        "status": "error",
        "message": "Template name and content are required"
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Template Name and Type Required (DELETE)**:
    ```json
    {
        "status": "error",
        "message": "Template name and type are required"
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Failed to Create/Delete Template (Server-side issue)**:
    ```json
    {
        "status": "error",
        "message": "Failed to create template" // or "Failed to delete template"
    }
    ```
    *(Response status code: 500 Internal Server Error)*
    ```json
    {
        "status": "error",
        "message": "An error occurred processing template template_name: <specific error from TemplateProcessor>",
        "details": "Check server logs for more information"
    }
    ```
    *(Response status code: 500, when a specific template fails to load during GET)*


- **Method Not Allowed**:
    ```json
    {
        "status": "error",
        "message": "Method not allowed"
    }
    ```
    *(Response status code: 405 Method Not Allowed)*

---

## Search API

### Main Objective

The Search API provides various search functionalities across notes and pages. It supports full-text search in note content and page names, finding backlinks to a specific page, and searching for tasks (notes with a 'status' property of 'TODO' or 'DONE').

### Supported HTTP Methods & Endpoints

- **GET `api/search.php`**: Performs a search based on the provided query parameters.
    - `?q={search_term}`: Performs a full-text search across note content and page names.
    - `?backlinks_for_page_name={page_name}`: Finds all notes that link to the specified page name (via `[[page_name]]` syntax, which creates a `links_to_page` property).
    - `?tasks={status}`: Finds notes that are tasks, where `status` can be `todo` or `done`.

### Request Parameters

#### GET `api/search.php`

- **URL Parameters (mutually exclusive)**:
    - `q` (optional, string): The search term for full-text search. Special FTS5 characters are sanitized.
    - `backlinks_for_page_name` (optional, string): The name of the page for which to find backlinks.
    - `tasks` (optional, string): The status of tasks to search for. Must be `todo` or `done`. (Maps to `status:: TODO` or `status:: DONE` properties).

### Response Structure

#### Success Responses

- **GET `api/search.php?q={search_term}` (Full-Text Search)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "note_id": 123,
                "content": "Full content of the note where the term was found...",
                "page_id": 10,
                "page_name": "Containing Page Name",
                "content_snippet": "...context <mark>search_term</mark> context..." // HTML marked snippet if FTS5 is used
            }
            // ... more results (up to 100)
        ]
    }
    ```

- **GET `api/search.php?backlinks_for_page_name={page_name}` (Backlinks Search)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "note_id": 456,
                "content": "Content of the note containing the link [[target_page_name]]...",
                "page_id": 20,
                "source_page_name": "Page With The Link", // The page containing the note that links
                "content_snippet": "...context [[<mark>target_page_name</mark>]] context..."
            }
            // ... more results
        ]
    }
    ```

- **GET `api/search.php?tasks={status}` (Tasks Search)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "note_id": 789,
                "content": "This is a task note with status:: TODO",
                "page_id": 30,
                "page_name": "Tasks Page",
                // "property_name": "status", // May be included depending on implementation details
                // "property_value": "TODO",  // May be included
                "content_snippet": "...context <mark>TODO</mark> context...",
                "properties": { // All properties of the note
                    "status": "TODO",
                    "priority": "high"
                    // ... other properties for this note
                }
            }
            // ... more results
        ]
    }
    ```

#### Error Responses

- **Missing Search Parameter**:
    ```json
    {
        "status": "error",
        "message": "Missing search parameter. Use q, backlinks_for_page_name, or tasks"
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Invalid Task Status (for `?tasks=...`)**:
    ```json
    {
        "status": "error",
        "message": "Invalid task status. Use \"todo\" or \"done\""
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Search Failed (General Server Error)**:
    ```json
    {
        "status": "error",
        "message": "Search failed: <specific PDO error message>"
    }
    ```
    *(Response status code: 500 Internal Server Error)*

- **Method Not Allowed**:
    ```json
    {
        "status": "error",
        "message": "Method Not Allowed"
    }
    ```
    *(Response status code: 405 Method Not Allowed)*

---

## Internal Properties API

### Main Objective

The Internal Properties API is a specialized endpoint for managing the `internal` status of an *existing* property. It allows fetching the current internal status or explicitly setting it (e.g., marking a property as internal or not). This is different from the main Properties API which might determine `internal` status automatically based on definitions during property creation/update.

### Supported HTTP Methods & Endpoints

- **GET `api/internal_properties.php`**: Retrieves the `internal` status of a specific property.
    - `?entity_type={type}` (required): `note` or `page`.
    - `?entity_id={id}` (required): The ID of the note or page.
    - `?name={property_name}` (required): The name of the property.
- **POST `api/internal_properties.php`**: Updates the `internal` status of an existing property.

### Request Parameters

#### GET `api/internal_properties.php`

- **URL Parameters**:
    - `entity_type` (required, string): `note` or `page`.
    - `entity_id` (required, integer): The ID of the note or page.
    - `name` (required, string): The name of the property whose internal status is to be fetched.

#### POST `api/internal_properties.php`

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `entity_type` (required, string): `note` or `page`.
    - `entity_id` (required, integer): The ID of the note or page.
    - `name` (required, string): The name of the property whose internal status is to be updated.
    - `internal` (required, integer `0` or `1`): The new internal status for the property. `1` for internal, `0` for not internal.

### Response Structure

#### Success Responses

- **GET `api/internal_properties.php`**:
    ```json
    {
        "status": "success",
        "data": {
            "name": "property_name",
            "internal": 1 // or 0
        }
    }
    ```

- **POST `api/internal_properties.php`**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Property internal status updated."
        }
    }
    ```

#### Error Responses

- **Missing Parameters (GET or POST)**:
    ```json
    {
        "status": "error",
        "message": "Missing required GET parameters: entity_type, entity_id, name"
        // or "Missing required POST parameters: entity_type, entity_id, name, internal"
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Invalid Internal Flag Value (POST)**:
    ```json
    {
        "status": "error",
        "message": "Invalid internal flag value. Must be 0 or 1."
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Property Not Found (GET or POST for a non-existent property)**:
    ```json
    {
        "status": "error",
        "message": "Property not found"
        // or "Property not found. Cannot set internal status for a non-existent property." for POST
    }
    ```
    *(Response status code: 404 Not Found)*

- **Failed to Update (POST, server-side issue during update)**:
    ```json
    {
        "status": "error",
        "message": "Failed to update property internal status"
    }
    ```
    *(Response status code: 500 Internal Server Error)*

- **Invalid JSON (POST)**:
    ```json
    {
        "status": "error",
        "message": "Invalid JSON"
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Method Not Allowed**:
    ```json
    {
        "status": "error",
        "message": "Method not allowed"
    }
    ```
    *(Response status code: 405 Method Not Allowed)*

---

## Property Definitions API

### Main Objective

The Property Definitions API allows administrators to define standard properties, specifying their name, whether they are `internal`, a description, and whether changes to the definition should `auto_apply` to existing properties. This helps maintain consistency and control over how properties are handled throughout the system.

### Supported HTTP Methods & Endpoints

- **GET `api/property_definitions.php`**: Retrieves all property definitions.
    - `?apply_all=true`: (Special GET action) Applies all `auto_apply=1` definitions to existing properties.
- **POST `api/property_definitions.php`**: Creates or updates a property definition. Can also be used for specific actions like applying a single definition or deleting a definition.

### Request Parameters

#### GET `api/property_definitions.php`

- **URL Parameters**:
    - `apply_all` (optional, boolean): If `true`, triggers the application of all auto-apply enabled property definitions to all existing properties in the database.

#### POST `api/property_definitions.php` (for Create/Update Definition)

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `name` (required, string): The name of the property being defined (e.g., `status`, `priority`).
    - `internal` (optional, integer `0` or `1`): Defines if the property is internal. Defaults to `0` (not internal).
    - `description` (optional, string): A description of the property and its purpose.
    - `auto_apply` (optional, integer `0` or `1`): If `1`, this definition (specifically its `internal` status) will be applied to all existing properties with this name. Defaults to `1`.

#### POST `api/property_definitions.php` (for Applying a Single Definition)

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `apply_definition`.
    - `name` (required, string): The name of the property definition to apply to existing properties.

#### POST `api/property_definitions.php` (for Deleting a Definition)

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be `delete`.
    - `id` (required, integer): The ID of the property definition to delete.

### Response Structure

#### Success Responses

- **GET `api/property_definitions.php` (List Definitions)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 1,
                "name": "status",
                "internal": 0,
                "description": "Tracks the status of a task or item.",
                "auto_apply": 1,
                "created_at": "YYYY-MM-DD HH:MM:SS",
                "updated_at": "YYYY-MM-DD HH:MM:SS"
            },
            {
                "id": 2,
                "name": "encrypted",
                "internal": 1,
                "description": "Indicates if a note's content is encrypted.",
                "auto_apply": 1,
                "created_at": "YYYY-MM-DD HH:MM:SS",
                "updated_at": "YYYY-MM-DD HH:MM:SS"
            }
            // ... other definitions
        ]
    }
    ```

- **GET `api/property_definitions.php?apply_all=true`**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Applied property definitions to X existing properties"
        }
    }
    ```

- **POST `api/property_definitions.php` (Create/Update Definition)**:
    ```json
    {
        "status": "success",
        "data": {
            // Message indicates if saved and/or applied
            "message": "Property definition saved and applied to Y existing properties"
            // or "Property definition saved (not applied to existing properties)"
        }
    }
    ```
    *(The API uses `INSERT OR REPLACE`, so it acts as create or update based on the `name`.)*

- **POST `api/property_definitions.php` (Apply Single Definition)**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Applied definition for 'propertyName' to Z existing properties"
        }
    }
    ```

- **POST `api/property_definitions.php` (Delete Definition)**:
    ```json
    {
        "status": "success",
        "data": {
            "message": "Property definition deleted"
        }
    }
    ```

#### Error Responses

- **General Error (e.g., invalid JSON, missing parameters, server error)**:
    ```json
    {
        "status": "error",
        "message": "Error message describing the issue."
        // "details": { ... } // Optional specific errors
    }
    ```
    *(Response status code: 400 Bad Request, 500 Internal Server Error, etc.)*

- **Property Name Required (for create/update or apply_definition)**:
    ```json
    {
        "status": "error",
        "message": "Property name is required" // or "Property name required"
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Definition ID Required (for delete)**:
    ```json
    {
        "status": "error",
        "message": "Definition ID required"
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Method Not Allowed**:
    ```json
    {
        "status": "error",
        "message": "Method not allowed"
    }
    ```
    *(Response status code: 405 Method Not Allowed)*

---

## Properties API

### Main Objective

The Properties API is responsible for managing properties associated with notes or pages. It allows for the creation, retrieval, update (implicitly via creation), and deletion of individual properties. This API also handles property triggers and automatic determination of a property's `internal` status based on definitions.

### Supported HTTP Methods & Endpoints

- **GET `api/properties.php`**: Retrieves all properties for a specified entity (note or page).
    - `?entity_type={type}` (required): Specifies the type of entity, either `note` or `page`.
    - `?entity_id={id}` (required): The ID of the note or page.
    - `?include_internal=true`: Optionally includes internal properties in the response.
- **POST `api/properties.php`**: Creates or updates a property for a specified entity. If the property already exists, its value is replaced. Can also be used to delete a property if `action: "delete"` is specified in the payload.

### Request Parameters

#### GET `api/properties.php`

- **URL Parameters**:
    - `entity_type` (required, string): `note` or `page`.
    - `entity_id` (required, integer): The ID of the note or page.
    - `include_internal` (optional, boolean): If `true`, internal properties are included. Defaults to `false`.

#### POST `api/properties.php` (for Create/Update)

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `entity_type` (required, string): `note` or `page`.
    - `entity_id` (required, integer): The ID of the note or page.
    - `name` (required, string): The name of the property (e.g., `color`, `priority`, `tag::mytag`).
    - `value` (required, any): The value of the property. For `tag::` properties, the value will be normalized to match the tag name (e.g., if `name` is `tag::mytag`, `value` becomes `mytag`).
    - `internal` (optional, integer `0` or `1`): Explicitly sets the internal status of the property. If not provided, the status is determined automatically based on property definitions.

#### POST `api/properties.php` (for Delete)

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `action` (required, string): Must be set to `delete`.
    - `entity_type` (required, string): `note` or `page`.
    - `entity_id` (required, integer): The ID of the note or page.
    - `name` (required, string): The name of the property to delete.

### Response Structure

#### Success Responses

- **GET `api/properties.php`**:
    ```json
    {
        "status": "success",
        "data": { // Or an array if DataManager formats it as an array of key-value pairs
            "property_name_1": "value1", // Simplified if include_internal=false
            "property_name_2": [
                {"value": "value2a", "internal": 0},
                {"value": "value2b", "internal": 0}
            ],
            "internal_prop": {"value": "secret", "internal": 1} // If include_internal=true
            // ... other properties ...
        }
    }
    ```
    *Note: The exact structure of "data" (object vs. array of objects) and how multi-value vs. single-value properties are represented depends on the `DataManager` implementation and `include_internal` flag. The example shows a common representation.*

- **POST `api/properties.php` (Create/Update)**:
    ```json
    {
        "status": "success",
        "data": {
            "property": {
                "name": "color",
                "value": "blue",
                "internal": 0 // or 1, based on determination or explicit input
            }
        }
    }
    ```

- **POST `api/properties.php` (Delete)**:
    ```json
    {
        "status": "success",
        "data": null // Or a confirmation message, current implementation returns null data
    }
    ```
    *(Response status code: 200 OK)*

#### Error Responses

- **General Error (e.g., invalid JSON, missing parameters, server error)**:
    ```json
    {
        "status": "error",
        "message": "Error message describing the issue.",
        "details": { /* Optional: specific field errors for invalid input */ }
    }
    ```
    *(Response status code: 400 Bad Request, 500 Internal Server Error, etc.)*

- **Invalid Property Format (e.g., `tag::` without a name during POST)**:
    ```json
    {
        "status": "error",
        "message": "Invalid property format (e.g., tag:: without tag name)."
    }
    ```
    *(Response status code: 400 Bad Request)*

- **Entity Not Found (though not explicitly checked in this API, could be an indirect error if entity_id is invalid for triggers or other operations)**
    *(The current API version doesn't explicitly return a 404 if `entity_id` is invalid for GET, it would return empty properties. For POST, it might lead to issues if triggers expect a valid entity.)*

- **Method Not Allowed**:
    ```json
    {
        "status": "error",
        "message": "Method not allowed"
    }
    ```
    *(Response status code: 405 Method Not Allowed)*

---

## Pages API

### Main Objective

The Pages API is responsible for managing pages, which act as containers for notes. It handles the creation, retrieval, updating, and deletion of pages, including support for page aliases and automatic creation of journal pages.

### Supported HTTP Methods & Endpoints

- **GET `api/pages.php`**: Retrieves a list of pages or a single page.
    - `?id={page_id}`: Retrieves a specific page by its ID.
    - `?name={page_name}`: Retrieves a specific page by its name. If the name matches a date format (YYYY-MM-DD) and the page doesn't exist, it's automatically created as a journal page.
    - No parameters: Retrieves all pages.
    - `?exclude_journal=1`: Excludes journal pages (names matching YYYY-MM-DD or 'journal') from the list of all pages.
    - `?follow_aliases=0`: Disables alias resolution (default is `1`, meaning aliases are followed).
    - `?include_details=1`: Includes all notes and their properties for the fetched page(s).
    - `?include_internal=true`: Optionally includes internal properties for pages and their notes.
- **POST `api/pages.php`**: Creates a new page or retrieves an existing one if it matches the provided name.
- **PUT `api/pages.php?id={page_id}`**: Updates an existing page's name or alias.
- **DELETE `api/pages.php?id={page_id}`**: Deletes a page and all its associated notes and properties.

### Request Parameters

#### GET `api/pages.php`

- **URL Parameters**:
    - `id` (optional, integer): The ID of the page to retrieve.
    - `name` (optional, string): The name of the page to retrieve.
    - `exclude_journal` (optional, `1`): If set to `1`, journal pages are excluded.
    - `follow_aliases` (optional, `0` or `1`): If `0`, aliases are not followed. Defaults to `1` (follow aliases).
    - `include_details` (optional, `1`): If `1`, notes within the page(s) are also returned.
    - `include_internal` (optional, boolean): If `true`, internal properties for the page(s) and their notes are included. Defaults to `false`.

#### POST `api/pages.php`

- **Headers**:
    - `Content-Type: application/json`
- **JSON Payload**:
    - `name` (required, string): The name of the page to create. If a page with this name already exists, its details are returned.
    - `alias` (optional, string): An alias for the page.

#### PUT `api/pages.php?id={page_id}`

- **Headers**:
    - `Content-Type: application/json`
- **URL Parameters**:
    - `id` (required, integer): The ID of the page to update.
- **JSON Payload**:
    - `name` (optional, string): The new name for the page. Must be unique if provided.
    - `alias` (optional, string|null): The new alias for the page. Can be an empty string or null to remove an alias.
    - *At least one of `name` or `alias` must be provided.*

#### DELETE `api/pages.php?id={page_id}`

- **URL Parameters**:
    - `id` (required, integer): The ID of the page to delete.

### Response Structure

#### Success Responses

- **GET `api/pages.php?id={page_id}` or `?name={page_name}` (Single Page)**:
    ```json
    {
        "status": "success", // This wrapper might vary based on ApiResponse utility
        "data": {
            "id": 1,
            "name": "My Page",
            "alias": "my-alias", // or null
            "updated_at": "YYYY-MM-DD HH:MM:SS",
            "properties": { // Only included if page has properties
                "type": "project",
                "status": {"value": "active", "internal": 0}
            }
            // If include_details=1, notes array would be here:
            // "notes": [ { ...note_object... } ]
        }
    }
    ```
    *Note on properties: Similar to Notes API, structure depends on `include_internal`.*

- **GET `api/pages.php` (All Pages)**:
    ```json
    {
        "status": "success",
        "data": [
            {
                "id": 1,
                "name": "Page One",
                "alias": null,
                "updated_at": "YYYY-MM-DD HH:MM:SS"
                // If include_details=1, properties and notes would be here.
            },
            {
                "id": 2,
                "name": "Page Two",
                "alias": "p2",
                "updated_at": "YYYY-MM-DD HH:MM:SS"
            }
            // ... more pages
        ]
    }
    ```

- **GET `api/pages.php?id={page_id}&include_details=1` (Page with Details)**:
    ```json
    {
        "status": "success",
        "data": { // If page ID is fetched
            "page": { // Page details
                "id": 1,
                "name": "My Detailed Page",
                "alias": null,
                "updated_at": "YYYY-MM-DD HH:MM:SS",
                "properties": {
                    "category": "work"
                }
            },
            "notes": [ // Array of notes belonging to this page
                {
                    "id": 101,
                    "page_id": 1,
                    "content": "Note 1 on this page",
                    "properties": { /* ... */ }
                    // ... other note fields
                },
                {
                    "id": 102,
                    "page_id": 1,
                    "content": "Note 2 on this page",
                    "properties": { /* ... */ }
                }
            ]
        }
        // If multiple pages are fetched with include_details=1 (e.g., GET all pages with details)
        // "data": [ { "page": { ... }, "notes": [ ... ] }, ... ]
    }
    ```

- **POST `api/pages.php` (Create/Retrieve Page)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 3,
            "name": "Newly Created Page",
            "alias": "new-page-alias",
            "updated_at": "YYYY-MM-DD HH:MM:SS"
            // "properties": { "type": "journal" } // if it was a journal page
        }
    }
    ```
    *(If page already exists by name, returns existing page details. Status code might be 200 OK or 201 Created)*

- **PUT `api/pages.php?id={page_id}` (Update Page)**:
    ```json
    {
        "status": "success",
        "data": {
            "id": 1,
            "name": "Updated Page Name",
            "alias": "updated-alias",
            "updated_at": "YYYY-MM-DD HH:MM:SS"
        }
    }
    ```

- **DELETE `api/pages.php?id={page_id}` (Delete Page)**:
    ```json
    {
        "status": "success",
        "data": {
            "deleted_page_id": 1
        }
    }
    ```

#### Error Responses

- **General Error (e.g., invalid input, server error)**:
    ```json
    {
        "status": "error",
        "message": "Error message describing the issue.",
        "details": { /* Optional: specific field errors */ }
    }
    ```
    *(Response status code: 400 Bad Request, 500 Internal Server Error, etc.)*

- **Page Not Found (GET/PUT/DELETE for a non-existent ID, or GET for a non-existent name that isn't a journal pattern)**:
    ```json
    {
        "status": "error",
        "message": "Page not found"
    }
    ```
    *(Response status code: 404 Not Found)*

- **Page Name Already Exists (PUT, when trying to rename to an existing page name)**:
    ```json
    {
        "status": "error",
        "message": "Page name already exists"
    }
    ```
    *(Response status code: 409 Conflict)*

- **Method Not Allowed**:
    ```json
    {
        "status": "error",
        "message": "Method Not Allowed"
    }
    ```
    *(Response status code: 405 Method Not Allowed)*

---
