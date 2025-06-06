# API Integration for email_handler.php

This document outlines the API endpoints and expected behaviors that the `services/email_handler.php` script relies upon to function correctly.

## 1. API Base URL Assumption

The `email_handler.php` script assumes a base URL for all API calls. This is currently hardcoded in the script (e.g., as `define('API_BASE_URL', 'http://localhost/api');`) but could be configured externally. All endpoint paths below are relative to this base URL.

## 2. Get or Create Page (for "Today's Page")

The email handler needs to find or create a daily page to store the email note.

*   **Endpoint:** `GET /pages.php`
*   **Query Parameter:** `name=<page_name>`
    *   `<page_name>` will be a date string in 'YYYY-MM-DD' format (e.g., `2023-10-28`).
*   **Expected Behavior:**
    *   The API should search for a page with the exact `name` provided.
    *   If a page with the given name exists, its details (including its `id`) are returned.
    *   If a page with the given name (specifically one matching the 'YYYY-MM-DD' format) does *not* exist, the API is expected to **automatically create** this page.
    *   When a daily page is auto-created due to a 'YYYY-MM-DD' name not being found, the API should also automatically add a property `type:: journal` to this newly created page.
*   **Success Response (JSON):**
    ```json
    {
      "success": true,
      "data": {
        "id": 123, // Example page_id_integer
        "name": "2023-10-28",
        "properties": {
            "type": "journal" // Present if newly created, or if already set
        }
        // ... other page fields that might be relevant
      }
    }
    ```
    *(Note: If the API returns an array of pages under `data` for a specific name query, the `email_handler.php` script is currently coded to use the `id` from the first element of that array, i.e., `data[0]['id']`.)*

*   **Error Response (JSON):**
    ```json
    {
      "success": false,
      "error": "Failed to retrieve or create page",
      "details": "Optional: more specific error information"
    }
    ```

## 3. Create Note

Once a page ID is obtained, the email handler will create a new note on that page.

*   **Endpoint:** `POST /notes.php`
*   **Request Body (JSON):**
    ```json
    {
      "page_id": 456, // Example page_id_integer
      "content": "## Email: Test Subject\n\n**From:** John Doe <john.doe@example.com>\n\n--- Email Body ---\nThis is the body of the email.\n\n---\ntag:: mail\nemail_from_name:: John Doe\nemail_from_address:: john.doe@example.com\nemail_subject:: Test Subject"
    }
    ```
    *   **`note_content_string` Details:**
        *   The `content` field contains the full text that will form the main body of the note.
        *   The `email_handler.php` script formats this string to include human-readable email information (subject, from, body) and appends property-like lines using the `key:: value` syntax (e.g., `tag:: mail`, `email_subject:: Test Subject`).
        *   The existing notes API (`POST /notes.php`) is expected to **parse these `key:: value` pairs** from the provided `content` string. These parsed pairs should then be stored as structured properties or metadata associated with the newly created note.

*   **Expected Behavior:**
    *   Create a new note record associated with the provided `page_id`.
    *   The API must scan the incoming `content` string for lines matching the `key:: value` pattern. Each such pair found should be extracted and saved as a distinct property of the note.

*   **Success Response (JSON):**
    ```json
    {
      "success": true,
      "data": {
        "id": 789, // Example new_note_id_integer
        "page_id": 456, // The page_id it was added to
        "content": "## Email: Test Subject\n\n**From:** John Doe <john.doe@example.com>\n\n--- Email Body ---\nThis is the body of the email.\n\n---\ntag:: mail\nemail_from_name:: John Doe\nemail_from_address:: john.doe@example.com\nemail_subject:: Test Subject",
        // ... other standard note fields
        "properties": { // This section shows the successfully parsed and stored properties
          "tag": "mail", // Or an array like ["mail"] if the system supports multi-value properties
          "email_from_name": "John Doe",
          "email_from_address": "john.doe@example.com",
          "email_subject": "Test Subject"
          // ... other properties extracted from the content string
        }
      }
    }
    ```

*   **Error Response (JSON):**
    ```json
    {
      "success": false,
      "error": "Failed to create note",
      "details": "Optional: more specific error information (e.g., 'Invalid page_id', 'Content too long')"
    }
    ```
