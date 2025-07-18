---
title: "Core Concepts"
weight: 20
---

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
