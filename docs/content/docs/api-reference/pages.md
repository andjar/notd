---
title: "Pages"
weight: 50
---

Manages pages, which now also have a `content` field to drive their properties.

### `POST /api/v1/pages.php` (Create)
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

### `POST /api/v1/pages.php` (Update)
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

### `POST /api/v1/pages.php` (Delete)
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

### `GET /api/v1/pages.php?name={page_name}`
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

### `GET /api/v1/pages.php?id={id}`
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

### `GET /api/v1/pages.php?date={YYYY-MM-DD}`
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

### `GET /api/v1/pages.php` (List)
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
