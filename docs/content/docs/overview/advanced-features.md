---
title: "Advanced Features"
weight: 40
---

### Property System

Properties are automatically parsed from content using these patterns:

```text
{key::value}           # Public property (weight 2)
{key:::value}          # Internal property (weight 3)
{key::::value}         # System log property (weight 4)
```

### Content Patterns

The API recognizes several content patterns:

#### Page Links
```text
[[Page Name]]          # Creates a link to another page
```

#### Task Status
```text
TODO Research topic     # Creates a TODO task
DONE Complete task      # Creates a DONE task
```

#### SQL Queries
```text
SQL{SELECT id FROM Notes WHERE content LIKE '%important%'}
```
The frontend can be configured to execute SQL queries embedded in notes. This is a powerful feature that allows for dynamic content generation.

#### Transclusion
```text
{{transclude:Note Title}}
```
The frontend supports transclusion, which allows you to embed the content of one note into another.

#### Encryption
```text
ENC{...}
```
The frontend supports client-side encryption of notes. The content is encrypted using AES-256 and can only be decrypted with the correct password.

#### External URLs
```text
https://example.com     # Automatically detected and indexed
```

### Batch Operations

Batch operations allow you to perform multiple actions in a single request, which is more efficient than individual requests. The operations are processed in this order:

1. Delete operations
2. Create operations
3. Update operations

This ensures that dependencies are handled correctly.

### Pagination

Most list endpoints support pagination with these parameters:

- `page` - Page number (default: 1)
- `per_page` - Items per page (default: 20, max: 100)

### Error Handling

The API uses standard HTTP status codes:

- `200` - Success
- `201` - Created
- `400` - Bad Request
- `404` - Not Found
- `405` - Method Not Allowed
- `409` - Conflict
- `500` - Internal Server Error
- `503` - Service Unavailable

### Database Locking

The API includes retry logic for database locking issues, especially with batch operations. If you encounter a `503` status code, the request will be retried automatically.
