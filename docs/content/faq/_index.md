+++
draft= false
title = "FAQ"
description = "Frequently Asked Questions"
+++

## What is the Notd Outliner API?
The Notd Outliner API is a RESTful API for a PHP-based outliner application designed to run offline using phpdesktop. It provides endpoints for managing pages, notes, properties, attachments, and more in a hierarchical structure.

## Is authentication required to use the API?
No, the API is designed for offline use and does not require authentication.

## What is the base URL for the API?
All API endpoints are served from `/api/v1/` relative to your application root.

## How are properties managed in the API?
Properties are not managed directly. Instead, they are automatically extracted from the content of notes and pages using specific patterns. For example, `{key::value}` creates a public property.

## What are the supported task states?
The API supports the following task states: `TODO`, `DOING`, `DONE`, `SOMEDAY`, `WAITING`, `CANCELLED`, and `NLR`.

## How can I perform multiple operations in a single request?
The API supports batch operations for creating, updating, and deleting notes. This is more efficient than making individual requests. You can use the `/api/v1/notes` endpoint with the `action` parameter set to `batch`.

## How does pagination work?
Most list endpoints support pagination using the `page` and `per_page` query parameters. The default is `page=1` and `per_page=20`.

## How should I handle errors?
The API uses standard HTTP status codes to indicate the success or failure of a request. Error responses include a JSON body with a `status` of `error` and a descriptive `message`.

## What are the file upload limits?
The maximum file size for attachments is 10MB. Supported formats include JPEG, PNG, GIF, WebP, PDF, TXT, Markdown, CSV, and JSON.