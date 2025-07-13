# Notd Outliner API Documentation

## Overview

The Notd Outliner API is a RESTful API for a PHP-based outliner application designed to run offline using phpdesktop. It provides endpoints for managing pages, notes, properties, attachments, and more in a hierarchical structure.

## Base URL

All API endpoints are served from `/api/v1/` relative to your application root.

## Authentication

This API does not require authentication as it's designed for offline use.

## Response Format

All API responses follow a consistent JSON format:

### Success Response
```json
{
  "status": "success",
  "data": {...}
}
```

### Error Response
```json
{
  "status": "error",
  "message": "Error description",
  "details": {...}
}
```

## Core Concepts

### Pages
Pages are top-level containers for notes. Each page has a unique name and can contain multiple notes.

### Notes
Notes are the core content blocks that belong to pages. Notes can be nested under other notes, creating a hierarchical structure.

### Properties
Properties are metadata extracted from content using pattern matching:

- `{key::value}` - Public property (weight 2)
- `{key:::value}` - Internal property (weight 3)
- `{key::::value}` - System log property (weight 4)

### Task States
The API supports task status tracking with these states:
- `TODO` - Task to be done
- `DOING` - Task in progress
- `DONE` - Completed task
- `SOMEDAY` - Future task
- `WAITING` - Task waiting on something
- `CANCELLED` - Cancelled task
- `NLR` - No longer required

## Quick Start Examples

### 1. Create a Page and Add Notes

```bash
# Create a page with notes
curl -X POST http://localhost/api/v1/append_to_page \
  -H "Content-Type: application/json" \
  -d '{
    "page_name": "My Project",
    "notes": [
      {
        "content": "Project overview {priority::high}",
        "order_index": 1
      },
      {
        "content": "TODO Research competitors",
        "order_index": 2
      }
    ]
  }'
```

### 2. Search for Tasks

```bash
# Find all TODO tasks
curl "http://localhost/api/v1/search?tasks=TODO&page=1&per_page=10"
```

### 3. Get Recent Pages

```bash
# Get the 7 most recently updated pages
curl "http://localhost/api/v1/recent_pages"
```

### 4. Full-text Search

```bash
# Search for notes containing "project"
curl "http://localhost/api/v1/search?q=project&page=1&per_page=10"
```

### 5. Batch Operations

```bash
# Create multiple notes in one request
curl -X POST http://localhost/api/v1/notes \
  -H "Content-Type: application/json" \
  -d '{
    "action": "batch",
    "operations": [
      {
        "type": "create",
        "payload": {
          "page_id": 1,
          "content": "First note {tags::important}",
          "order_index": 1
        }
      },
      {
        "type": "create",
        "payload": {
          "page_id": 1,
          "content": "Second note",
          "order_index": 2
        }
      }
    ]
  }'
```

## Detailed Endpoint Guide

### Pages

#### List Pages
```bash
GET /api/v1/pages?page=1&per_page=20&exclude_journal=true
```

#### Get Recent Pages
```bash
GET /api/v1/recent_pages
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "recent_pages": [
      {
        "id": 1,
        "name": "Most Recent Page",
        "updated_at": "2024-01-15 10:30:00"
      },
      {
        "id": 2,
        "name": "Second Most Recent",
        "updated_at": "2024-01-15 09:15:00"
      }
    ]
  }
}
```

**Description:** Returns the 7 most recently updated pages, ordered by `updated_at` timestamp in descending order. This endpoint is useful for displaying recent activity in the sidebar or navigation.

#### Get Specific Page
```bash
GET /api/v1/pages?name=My%20Page
```

#### Create Page
```bash
POST /api/v1/pages
{
  "name": "New Page",
  "content": "{type::project} {status::active}"
}
```

#### Update Page
```bash
PUT /api/v1/pages
{
  "id": 1,
  "name": "Updated Page Name",
  "content": "Updated content with {priority::high}"
}
```

### Notes

#### Get Notes by Page
```bash
GET /api/v1/notes?page_id=1&include_internal=true&include_parent_properties=true
```

#### Get Specific Note
```bash
GET /api/v1/notes?id=123&include_parent_properties=true
```

#### Batch Operations
```bash
POST /api/v1/notes
{
  "action": "batch",
  "operations": [
    {
      "type": "create",
      "payload": {
        "page_id": 1,
        "content": "New note",
        "parent_note_id": null,
        "order_index": 1,
        "collapsed": 0
      }
    },
    {
      "type": "update",
      "payload": {
        "id": 123,
        "content": "Updated content",
        "order_index": 2
      }
    },
    {
      "type": "delete",
      "payload": {
        "id": 456
      }
    }
  ]
}
```

### Properties

#### Get Properties
```bash
GET /api/v1/properties?entity_type=note&entity_id=123&include_hidden=true
```

Properties are automatically extracted from content and stored in the database. You cannot directly create or update properties - they are managed through content updates.

### Search

#### Full-text Search
```bash
GET /api/v1/search?q=search%20term&page=1&per_page=20&include_parent_properties=true
```

#### Task Search
```bash
# All tasks
GET /api/v1/search?tasks=ALL

# Specific task status
GET /api/v1/search?tasks=TODO
```

#### Backlinks
```bash
GET /api/v1/search?backlinks_for_page_name=Target%20Page
```

#### Favorites
```bash
GET /api/v1/search?favorites=true
```

### Attachments

#### Upload Attachment
```bash
POST /api/v1/attachments
Content-Type: multipart/form-data

note_id: 123
attachmentFile: [binary file data]
```

#### List Attachments
```bash
# For a specific note
GET /api/v1/attachments?note_id=123

# All attachments with pagination
GET /api/v1/attachments?page=1&per_page=10&sort_by=created_at&sort_order=desc
```

#### Delete Attachment
```bash
DELETE /api/v1/attachments
{
  "attachment_id": 456,
  "note_id": 123
}
```

### Templates

#### List Templates
```bash
GET /api/v1/templates?type=note
```

#### Create Template
```bash
POST /api/v1/templates
{
  "type": "note",
  "name": "Meeting Template",
  "content": "Meeting: {{title}}\nDate: {{date}}\nAttendees: {{attendees}}\n\nAgenda:\n- \n\nAction Items:\n- "
}
```

#### Update Template
```bash
POST /api/v1/templates
{
  "_method": "PUT",
  "type": "note",
  "current_name": "Meeting Template",
  "new_name": "Updated Meeting Template",
  "content": "Updated template content"
}
```

#### Delete Template
```bash
POST /api/v1/templates
{
  "_method": "DELETE",
  "type": "note",
  "name": "Meeting Template"
}
```

### Webhooks

#### Create Webhook
```bash
POST /api/v1/webhooks
{
  "url": "https://example.com/webhook",
  "entity_type": "note",
  "property_names": ["status", "priority"],
  "event_types": ["property_change", "entity_create"]
}
```

#### Test Webhook
```bash
POST /api/v1/webhooks/123/test
```

#### Get Webhook History
```bash
GET /api/v1/webhooks/123/history?page=1&per_page=20
```

## Advanced Features

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

## Best Practices

### 1. Use Batch Operations
For multiple note operations, use batch operations instead of individual requests:

```javascript
// Good
const batchResponse = await fetch('/api/v1/notes', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    action: 'batch',
    operations: [
      { type: 'create', payload: {...} },
      { type: 'update', payload: {...} }
    ]
  })
});

// Avoid
await fetch('/api/v1/notes', { method: 'POST', body: JSON.stringify({...}) });
await fetch('/api/v1/notes', { method: 'POST', body: JSON.stringify({...}) });
```

### 2. Include Parent Properties
When fetching notes, consider including parent properties for context:

```bash
GET /api/v1/notes?page_id=1&include_parent_properties=true
```

### 3. Use Properties for Metadata
Store metadata as properties in content rather than separate fields:

```text
Meeting Notes {date::2024-01-15} {attendees::John,Jane} {priority::high}
```

### 4. Leverage Search
Use the search endpoints for finding content rather than filtering client-side:

```bash
# Find all high priority items
GET /api/v1/search?q=priority::high

# Find all tasks for a specific person
GET /api/v1/search?q=assignee::john
```

### 5. Handle Errors Gracefully
Always check the response status and handle errors appropriately:

```javascript
const response = await fetch('/api/v1/notes');
const data = await response.json();

if (data.status === 'error') {
  console.error('API Error:', data.message);
  // Handle error appropriately
} else {
  // Process successful response
  console.log('Notes:', data.data);
}
```

## Rate Limiting

There are no explicit rate limits, but the API is designed for offline use. For optimal performance:

- Use batch operations for multiple changes
- Implement reasonable delays between requests
- Cache responses when appropriate

## File Upload Limits

- Maximum file size: 10MB
- Supported formats: JPEG, PNG, GIF, WebP, PDF, TXT, Markdown, CSV, JSON

## Troubleshooting

### Common Issues

1. **Database Locked Errors**
   - The API will retry automatically
   - If persistent, check for long-running transactions

2. **Property Not Found**
   - Properties are extracted from content automatically
   - Check that your content uses the correct syntax: `{key::value}`

3. **Search Not Working**
   - Ensure search terms are properly URL-encoded
   - Check that the content contains the search terms

4. **Template Processing Errors**
   - Verify template syntax
   - Check that placeholder names are valid

### Debug Mode

Enable debug logging by checking the server logs for detailed error information.

## Support

For issues and questions:

1. Check the server logs for detailed error messages
2. Verify your request format matches the API specification
3. Test with simple requests before complex operations
4. Review the OpenAPI specification for complete endpoint details

## Changelog

### Version 1.0.0
- Initial API release
- Full CRUD operations for pages and notes
- Property system with pattern matching
- Full-text search capabilities
- File attachment support
- Template system
- Webhook notifications
- Batch operations for efficiency 