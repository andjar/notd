---
title: "Quick Start Examples"
weight: 30
---

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
