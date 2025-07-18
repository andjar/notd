---
title: "API Reference"
weight: 20
---

This document provides a detailed specification for the API endpoints, revised to operate exclusively with GET and POST methods, and incorporating best practices for consistency, pagination, and versioning.

**Key Design Principles:**
*   **API Versioning:** All endpoints are prefixed with `/api/v1/`.
*   **GET/POST Only:** Write operations (create, update, delete) are handled via POST requests, using an `_method` override key in the JSON payload.
*   **Content as Single Source of Truth:** Properties for notes and pages are derived directly from their respective `content` fields. The `Properties` database table serves as a queryable index of this content.
*   **Universal Pagination:** All list-returning endpoints support `page` and `per_page` and include a consistent pagination object.
*   **Response Standardization:**
    *   Unified success/error reporting using `status: "success"` or `status: "error"`.
    *   Successful responses wrap their payload in a `data` key.

## General Concepts

### Content-Driven Properties

The `Properties` table is no longer directly managed. Instead, it is an index populated by parsing the `content` of a Note or Page. This is the new core of the system.

#### Syntax

Properties are defined within content using a key, a variable number of colons (`:`), and a value, optionally enclosed in curly braces `{}`. The number of colons determines the property's `weight`.

*   `key::value` or `{key::value}` -> **Weight 2** (Default, public property)
*   `key:::value` or `{key:::value}` -> **Weight 3** (Internal property)
*   `key::::value` or `{key::::value}` -> **Weight 4** (System property, e.g., for logging)

#### Special Properties

The system automatically adds certain special properties with specific weights to track different types of content:

*   **SQL Properties** (Weight 3): Added when SQL code blocks are detected in the content. Used to track and manage SQL queries.
*   **Task Properties** (Weight 4): Added for task management, tracking task states like TODO, DOING, DONE, etc.
*   **Done At Properties** (Weight 3): Automatically added when a task is marked as done, recording the completion timestamp.
*   **Transclusion Properties** (Weight 3): Added when content from other notes is embedded (transcluded) into the current note.
*   **Link Properties** (Weight 3): Added when the note contains links to other pages or notes.
*   **URL Properties** (Weight 3): Added when the note contains urls.

These special properties are managed by the system and should not be manually modified. They are used for internal tracking and functionality.

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
