---
title: "Attachments"
weight: 30
---

Files attached to notes. Files are stored under `UPLOADS_DIR` in `year/month/` folders with a random prefix; the `url` field of each attachment is where the browser can fetch it.

Uploads are checked against a list of allowed types by extension: jpg, jpeg, png, gif, webp, pdf, txt, md, csv, json, excalidraw, docx, xlsx, pptx, odt, ods, odp, zip, mp3, m4a, ogg, wav, mp4 and webm. Anything else (HTML, SVG, scripts) is refused, because a browser could run code in it. The limit is 10 MB per file. Several files can be sent in one request (`attachmentFile[]`); the answer lists the stored ones under `attachments` and the refused ones under `failed`.

### `POST /api/v1/attachments.php` (Upload)
*   **Description**: Uploads a new attachment for a note.
*   **Request**: `multipart/form-data`
    - `note_id` (form field): `42`
    - `attachmentFile` (file field): `(binary data of my-document.pdf)`
*   **Response (200 OK)**:
    ```json
    {
        "status": "success",
        "data": {
            "attachments": [{
                "id": 101,
                "note_id": "0192f6a0-7c3e-7b1a-9f3e-2b1c4d5e6f70",
                "name": "my-document.pdf",
                "path": "2026/11/3f2a9c1b7d4e_my-document.pdf",
                "type": "application/pdf",
                "size": 123456,
                "created_at": "2026-11-10 10:30:00",
                "url": "/uploads/2026/11/3f2a9c1b7d4e_my-document.pdf"
            }],
            "failed": []
        }
    }
    ```
    If no file could be stored, the answer is an error (413 too large, 415 type not allowed, 404 unknown note) with the reasons in `details.failed`.

### `POST /api/v1/attachments.php` (Delete)
*   **Description**: Deletes an existing attachment.
*   **Request**: `application/json`
    ```json
    {
      "action": "delete",
      "attachment_id": 101
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
                "url": "/uploads/2023/10/uniqueid_my-document.pdf"
            }
        ]
    }
    ```
