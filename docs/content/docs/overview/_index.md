---
title: "API Overview"
weight: 10
---

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
