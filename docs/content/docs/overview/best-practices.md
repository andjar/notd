---
title: "Best Practices"
weight: 50
---

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
