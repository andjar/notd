---
title: "API Overview"
weight: 10
---

The Notd Outliner is a powerful note-taking and knowledge management system that combines a robust PHP-based API with a rich, interactive frontend. While this section focuses on the API, notd's strength lies in the seamless integration between backend data management and frontend user experience.

## Frontend Highlights

Before diving into the API, explore notd's powerful frontend features:

- **📝 Rich Text Editing** - Markdown-based with live preview and syntax highlighting
- **🔍 SQL Queries** - Execute dynamic queries directly in your notes for data-driven content
- **🔗 Transclusion** - Embed content from other notes for modular documentation
- **🔐 Encryption** - Client-side AES-256 encryption for sensitive content
- **📅 Journal System** - Date-based pages for daily workflows and organization
- **✅ Task Management** - Built-in TODO system with multiple states and visual indicators
- **🔄 Backlinking** - Discover connections between your notes automatically
- **⭐ Favorites** - Quick access to important pages and content
- **🧩 Extensions** - 8 powerful extensions including Kanban boards, Excalidraw, and more
- **🔎 Advanced Search** - Fast, comprehensive search with property filtering and encryption support

[→ Explore the Frontend Guide](/docs/frontend-guide/) for complete documentation of user-facing features.

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
