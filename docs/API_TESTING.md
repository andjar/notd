# API Testing Guide

This guide provides examples for testing the Notd Outliner API endpoints using various tools and methods.

## Testing Tools

### 1. cURL
Command-line tool for making HTTP requests.

### 2. Postman
GUI tool for API testing with request/response visualization.

### 3. JavaScript/Fetch
For testing from web browsers or Node.js.

### 4. Python/requests
For automated testing and scripting.

## Basic Setup

### Base URL
```
http://localhost/api/v1/
```

### Headers
```bash
Content-Type: application/json
```

## Health Check

### Test API Availability
```bash
curl http://localhost/api/v1/ping
```

**Expected Response:**
```json
{
  "status": "pong",
  "timestamp": "2024-01-15T10:30:00Z"
}
```

## Page Management Tests

### 1. Get Recent Pages
```bash
curl "http://localhost/api/v1/recent_pages"
```

**Expected Response:**
```json
{
  "status": "success",
  "data": {
    "recent_pages": [
      {
        "id": 1,
        "name": "Most Recent Page",
        "updated_at": "2024-01-15 10:30:00"
      }
    ]
  }
}
```

### 2. Create a Page
```bash
curl -X POST http://localhost/api/v1/pages \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Project",
    "content": "{type::project} {status::active}"
  }'
```

### 2. Get Page by Name
```bash
curl "http://localhost/api/v1/pages?name=Test%20Project"
```

### 3. List All Pages
```bash
curl "http://localhost/api/v1/pages?page=1&per_page=10"
```

### 4. Update Page
```bash
curl -X PUT http://localhost/api/v1/pages \
  -H "Content-Type: application/json" \
  -d '{
    "id": 1,
    "name": "Updated Test Project",
    "content": "{type::project} {status::completed}"
  }'
```

## Note Management Tests

### 1. Append Notes to Page
```bash
curl -X POST http://localhost/api/v1/append_to_page \
  -H "Content-Type: application/json" \
  -d '{
    "page_name": "Test Project",
    "notes": [
      {
        "content": "Project overview {priority::high}",
        "order_index": 1
      },
      {
        "content": "TODO Research competitors",
        "order_index": 2
      },
      {
        "content": "DONE Setup development environment",
        "order_index": 3
      }
    ]
  }'
```

### 2. Get Notes by Page
```bash
curl "http://localhost/api/v1/notes?page_id=1&include_internal=true&include_parent_properties=true"
```

### 3. Batch Operations
```bash
curl -X POST http://localhost/api/v1/notes \
  -H "Content-Type: application/json" \
  -d '{
    "action": "batch",
    "operations": [
      {
        "type": "create",
        "payload": {
          "page_id": 1,
          "content": "New note with {tags::important}",
          "order_index": 4
        }
      },
      {
        "type": "create",
        "payload": {
          "page_id": 1,
          "content": "Another note",
          "order_index": 5
        }
      }
    ]
  }'
```

### 4. Update Note
```bash
curl -X POST http://localhost/api/v1/notes \
  -H "Content-Type: application/json" \
  -d '{
    "action": "batch",
    "operations": [
      {
        "type": "update",
        "payload": {
          "id": 1,
          "content": "Updated project overview {priority::critical}",
          "order_index": 1
        }
      }
    ]
  }'
```

### 5. Delete Note
```bash
curl -X POST http://localhost/api/v1/notes \
  -H "Content-Type: application/json" \
  -d '{
    "action": "batch",
    "operations": [
      {
        "type": "delete",
        "payload": {
          "id": 5
        }
      }
    ]
  }'
```

## Property Tests

### 1. Get Note Properties
```bash
curl "http://localhost/api/v1/properties?entity_type=note&entity_id=1&include_hidden=true"
```

### 2. Get Page Properties
```bash
curl "http://localhost/api/v1/properties?entity_type=page&entity_id=1&include_hidden=true"
```

## Search Tests

### 1. Full-text Search
```bash
curl "http://localhost/api/v1/search?q=project&page=1&per_page=10"
```

### 2. Task Search
```bash
# All tasks
curl "http://localhost/api/v1/search?tasks=ALL&page=1&per_page=10"

# TODO tasks only
curl "http://localhost/api/v1/search?tasks=TODO&page=1&per_page=10"

# DONE tasks only
curl "http://localhost/api/v1/search?tasks=DONE&page=1&per_page=10"
```

### 3. Backlinks Search
```bash
curl "http://localhost/api/v1/search?backlinks_for_page_name=Test%20Project&page=1&per_page=10"
```

### 4. Favorites Search
```bash
curl "http://localhost/api/v1/search?favorites=true&page=1&per_page=10"
```

## Custom Query Tests

### 1. Simple Query
```bash
curl -X POST http://localhost/api/v1/query_notes \
  -H "Content-Type: application/json" \
  -d '{
    "sql_query": "SELECT id FROM Notes WHERE content LIKE \'%important%\'",
    "page": 1,
    "per_page": 10,
    "include_properties": true
  }'
```

### 2. Property-based Query
```bash
curl -X POST http://localhost/api/v1/query_notes \
  -H "Content-Type: application/json" \
  -d '{
    "sql_query": "SELECT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = \'priority\' AND P.value = \'high\'",
    "page": 1,
    "per_page": 10,
    "include_properties": true
  }'
```

## Attachment Tests

### 1. Upload Attachment
```bash
curl -X POST http://localhost/api/v1/attachments \
  -F "note_id=1" \
  -F "attachmentFile=@/path/to/your/file.txt"
```

### 2. List Attachments
```bash
# For specific note
curl "http://localhost/api/v1/attachments?note_id=1"

# All attachments
curl "http://localhost/api/v1/attachments?page=1&per_page=10&sort_by=created_at&sort_order=desc"
```

### 3. Delete Attachment
```bash
curl -X POST http://localhost/api/v1/attachments \
  -H "Content-Type: application/json" \
  -d '{
    "action": "delete",
    "attachment_id": 1,
    "note_id": 1
  }'
```

## Template Tests

### 1. List Templates
```bash
curl "http://localhost/api/v1/templates?type=note"
```

### 2. Create Template
```bash
curl -X POST http://localhost/api/v1/templates \
  -H "Content-Type: application/json" \
  -d '{
    "type": "note",
    "name": "Meeting Template",
    "content": "Meeting: {{title}}\nDate: {{date}}\nAttendees: {{attendees}}\n\nAgenda:\n- \n\nAction Items:\n- "
  }'
```

### 3. Update Template
```bash
curl -X POST http://localhost/api/v1/templates \
  -H "Content-Type: application/json" \
  -d '{
    "_method": "PUT",
    "type": "note",
    "current_name": "Meeting Template",
    "new_name": "Updated Meeting Template",
    "content": "Updated template content"
  }'
```

### 4. Delete Template
```bash
curl -X POST http://localhost/api/v1/templates \
  -H "Content-Type: application/json" \
  -d '{
    "_method": "DELETE",
    "type": "note",
    "name": "Meeting Template"
  }'
```

## Extension Tests

### 1. List Extensions
```bash
curl "http://localhost/api/v1/extensions"
```

## Webhook Tests

### 1. Create Webhook
```bash
curl -X POST http://localhost/api/v1/webhooks \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://webhook.site/your-unique-url",
    "entity_type": "note",
    "property_names": ["status", "priority"],
    "event_types": ["property_change", "entity_create"]
  }'
```

### 2. List Webhooks
```bash
curl "http://localhost/api/v1/webhooks"
```

### 3. Test Webhook
```bash
curl -X POST "http://localhost/api/v1/webhooks/1/test"
```

### 4. Get Webhook History
```bash
curl "http://localhost/api/v1/webhooks/1/history?page=1&per_page=10"
```

## JavaScript Testing Examples

### Using Fetch API

```javascript
// Test health check
async function testHealthCheck() {
  try {
    const response = await fetch('/api/v1/ping');
    const data = await response.json();
    console.log('Health check:', data);
  } catch (error) {
    console.error('Health check failed:', error);
  }
}

// Create a page
async function createPage() {
  try {
    const response = await fetch('/api/v1/pages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: 'Test Page',
        content: '{type::test}'
      })
    });
    const data = await response.json();
    console.log('Page created:', data);
  } catch (error) {
    console.error('Create page failed:', error);
  }
}

// Batch operations
async function batchOperations() {
  try {
    const response = await fetch('/api/v1/notes', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'batch',
        operations: [
          {
            type: 'create',
            payload: {
              page_id: 1,
              content: 'Test note {priority::high}',
              order_index: 1
            }
          }
        ]
      })
    });
    const data = await response.json();
    console.log('Batch operations:', data);
  } catch (error) {
    console.error('Batch operations failed:', error);
  }
}
```

## Python Testing Examples

### Using requests library

```python
import requests
import json

BASE_URL = 'http://localhost/api/v1'

def test_health_check():
    """Test the health check endpoint"""
    response = requests.get(f'{BASE_URL}/ping')
    print('Health check:', response.json())
    return response.status_code == 200

def create_page():
    """Create a test page"""
    data = {
        'name': 'Python Test Page',
        'content': '{type::test} {language::python}'
    }
    response = requests.post(f'{BASE_URL}/pages', json=data)
    print('Create page:', response.json())
    return response.status_code == 201

def batch_operations():
    """Test batch operations"""
    data = {
        'action': 'batch',
        'operations': [
            {
                'type': 'create',
                'payload': {
                    'page_id': 1,
                    'content': 'Python test note {priority::high}',
                    'order_index': 1
                }
            }
        ]
    }
    response = requests.post(f'{BASE_URL}/notes', json=data)
    print('Batch operations:', response.json())
    return response.status_code == 200

def search_notes():
    """Test search functionality"""
    params = {
        'q': 'python',
        'page': 1,
        'per_page': 10
    }
    response = requests.get(f'{BASE_URL}/search', params=params)
    print('Search results:', response.json())
    return response.status_code == 200

if __name__ == '__main__':
    # Run tests
    print('Testing API endpoints...')
    
    if test_health_check():
        print('✓ Health check passed')
    else:
        print('✗ Health check failed')
    
    if create_page():
        print('✓ Create page passed')
    else:
        print('✗ Create page failed')
    
    if batch_operations():
        print('✓ Batch operations passed')
    else:
        print('✗ Batch operations failed')
    
    if search_notes():
        print('✓ Search passed')
    else:
        print('✗ Search failed')
```

## Postman Collection

You can import this collection into Postman for easier testing:

```json
{
  "info": {
    "name": "Notd Outliner API",
    "description": "API testing collection for Notd Outliner",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "variable": [
    {
      "key": "base_url",
      "value": "http://localhost/api/v1"
    }
  ],
  "item": [
    {
      "name": "Health Check",
      "request": {
        "method": "GET",
        "url": "{{base_url}}/ping"
      }
    },
    {
      "name": "Create Page",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/pages",
        "header": [
          {
            "key": "Content-Type",
            "value": "application/json"
          }
        ],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"name\": \"Test Page\",\n  \"content\": \"{type::test}\"\n}"
        }
      }
    },
    {
      "name": "Batch Operations",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/notes",
        "header": [
          {
            "key": "Content-Type",
            "value": "application/json"
          }
        ],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"action\": \"batch\",\n  \"operations\": [\n    {\n      \"type\": \"create\",\n      \"payload\": {\n        \"page_id\": 1,\n        \"content\": \"Test note\",\n        \"order_index\": 1\n      }\n    }\n  ]\n}"
        }
      }
    }
  ]
}
```

## Error Testing

### Test Invalid Requests

```bash
# Test invalid JSON
curl -X POST http://localhost/api/v1/pages \
  -H "Content-Type: application/json" \
  -d '{invalid json}'

# Test missing required fields
curl -X POST http://localhost/api/v1/pages \
  -H "Content-Type: application/json" \
  -d '{}'

# Test invalid method
curl -X PATCH http://localhost/api/v1/pages

# Test non-existent resource
curl "http://localhost/api/v1/notes?id=999999"
```

## Performance Testing

### Load Testing with Apache Bench

```bash
# Test health check endpoint
ab -n 100 -c 10 http://localhost/api/v1/ping

# Test search endpoint
ab -n 50 -c 5 "http://localhost/api/v1/search?q=test&page=1&per_page=10"
```

### Concurrent Batch Operations

```bash
# Test multiple concurrent batch operations
for i in {1..10}; do
  curl -X POST http://localhost/api/v1/notes \
    -H "Content-Type: application/json" \
    -d "{
      \"action\": \"batch\",
      \"operations\": [
        {
          \"type\": \"create\",
          \"payload\": {
            \"page_id\": 1,
            \"content\": \"Concurrent note $i\",
            \"order_index\": $i
          }
        }
      ]
    }" &
done
wait
```

## Validation Checklist

When testing the API, verify these aspects:

- [ ] All endpoints return proper HTTP status codes
- [ ] JSON responses are valid and well-formed
- [ ] Error responses include meaningful messages
- [ ] Pagination works correctly
- [ ] Properties are extracted from content correctly
- [ ] Search functionality returns relevant results
- [ ] Batch operations handle dependencies correctly
- [ ] File uploads work with supported formats
- [ ] Templates process placeholders correctly
- [ ] Webhooks trigger and deliver payloads
- [ ] Database locking is handled gracefully
- [ ] Performance is acceptable under load

## Troubleshooting

### Common Issues

1. **CORS Errors**: Ensure your server allows cross-origin requests if testing from a browser
2. **File Upload Failures**: Check file size limits and supported formats
3. **Database Locks**: The API includes retry logic, but monitor for persistent issues
4. **Property Parsing**: Verify content uses correct syntax: `{key::value}`
5. **Search Issues**: Ensure search terms are properly URL-encoded

### Debug Mode

Enable detailed logging by checking server logs for:
- SQL queries
- Property processing
- Error stack traces
- Performance metrics 