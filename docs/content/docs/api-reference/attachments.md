---
title: "Attachments"
weight: 30
---

Manages file attachments for notes.

### `POST /api/v1/attachments.php` (Upload)
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

### `POST /api/v1/attachments.php` (Delete)
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

### `GET /api/v1/attachments.php`
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
