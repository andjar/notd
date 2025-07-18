---
title: "Kanban Board"
weight: 10
---

The Kanban Board extension provides visual task management with customizable boards, filters, and drag-and-drop functionality. It's perfect for organizing tasks across different projects, priorities, or workflows.

## Features

- **Visual Task Management** - Drag-and-drop interface for moving tasks between states
- **Multiple Boards** - Configure different boards for different contexts (work, personal, projects)
- **Smart Filters** - Show only tasks matching specific criteria
- **Real-time Updates** - Changes sync immediately with your notes
- **Property Integration** - Works seamlessly with notd's property system

## Board Configuration

The Kanban extension is configured through `/extensions/kanban_board/config.json`:

```json
{
  "featherIcon": "trello",
  "boards": [
    {
      "id": "all_tasks",
      "label": "All Tasks", 
      "filters": []
    },
    {
      "id": "work_tasks",
      "label": "Work Tasks",
      "filters": [
        {"name": "type", "value": "work"}
      ]
    }
  ]
}
```

### Board Properties

- **id** - Unique identifier for the board
- **label** - Display name shown in the board selector
- **filters** - Array of property filters to apply

### Filter Configuration

Filters restrict which tasks appear on each board:

```json
{
  "name": "priority",    // Property name to filter by
  "value": "high"        // Value that must match
}
```

## Task Columns

The Kanban board displays tasks in columns based on their status:

- **TODO** - Tasks to be done
- **DOING** - Tasks in progress
- **DONE** - Completed tasks
- **SOMEDAY** - Future tasks
- **WAITING** - Tasks waiting on dependencies
- **CANCELLED** - Cancelled tasks
- **NLR** - No longer required

## Using the Kanban Board

### Creating Tasks

Tasks are created as notes with status keywords:

```markdown
TODO Implement user authentication {type::work} {priority::high}
DOING Write documentation {type::work} 
DONE Fix login bug {type::work} {priority::urgent}
```

### Task Properties for Filtering

Use properties to organize tasks across boards:

```markdown
TODO Review project proposal {
  type::work
  priority::high
  category::review
  assigned::john
  estimate::2h
}
```

### Moving Tasks

- **Drag and drop** tasks between columns to change their status
- Changes are automatically saved to your notes
- Task content and properties are preserved

## Board Examples

### Project-Based Boards

```json
{
  "id": "project_alpha",
  "label": "Project Alpha",
  "filters": [
    {"name": "project", "value": "alpha"}
  ]
}
```

Use with tasks like:
```markdown
TODO Design user interface {project::alpha} {sprint::1}
DOING Implement backend API {project::alpha} {assigned::dev-team}
```

### Priority-Based Boards

```json
{
  "id": "urgent_tasks", 
  "label": "Urgent Tasks",
  "filters": [
    {"name": "priority", "value": "urgent"}
  ]
}
```

Perfect for focusing on high-priority work:
```markdown
TODO Fix production bug {priority::urgent} {severity::critical}
TODO Client presentation {priority::urgent} {due::today}
```

### Team-Based Boards

```json
{
  "id": "john_tasks",
  "label": "John's Tasks", 
  "filters": [
    {"name": "assigned", "value": "john"}
  ]
}
```

Track individual workloads:
```markdown
TODO Code review {assigned::john} {type::development}
DOING Feature implementation {assigned::john} {estimate::5h}
```

## Advanced Configuration

### Multiple Filters

Combine multiple filters for precise task selection:

```json
{
  "id": "urgent_work",
  "label": "Urgent Work Tasks",
  "filters": [
    {"name": "type", "value": "work"},
    {"name": "priority", "value": "urgent"}
  ]
}
```

### Custom Icons

Change the extension icon by modifying the `featherIcon` value:

```json
{
  "featherIcon": "check-square"  // or "list", "grid", etc.
}
```

## Integration Tips

### With Journal Pages

Use daily pages to plan tasks:

```markdown
# 2024-07-18

## Today's Tasks
TODO Morning standup {type::work} {board::daily}
TODO Review pull requests {type::work} {priority::high}
DOING Documentation update {type::work}

## Personal
TODO Grocery shopping {type::personal}
SOMEDAY Learn new framework {type::personal}
```

### With SQL Queries

Create dynamic task reports:

```sql
SQL{
  SELECT 
    SUBSTR(N.content, 1, 50) as task,
    CASE 
      WHEN N.content LIKE 'TODO%' THEN 'TODO'
      WHEN N.content LIKE 'DOING%' THEN 'DOING' 
      WHEN N.content LIKE 'DONE%' THEN 'DONE'
    END as status,
    Prop.value as priority
  FROM Notes N
  LEFT JOIN Properties Prop ON N.id = Prop.note_id AND Prop.name = 'priority'
  WHERE N.content REGEXP '^(TODO|DOING|DONE)'
  ORDER BY 
    CASE Prop.value 
      WHEN 'urgent' THEN 1
      WHEN 'high' THEN 2 
      WHEN 'medium' THEN 3
      ELSE 4
    END
}
```

### With Templates

Create task templates for recurring workflows:

```markdown
# Sprint Planning Template

## Sprint Goals
TODO Define user stories {type::work} {sprint::current}
TODO Estimate story points {type::work} {sprint::current}
TODO Assign tasks to team {type::work} {sprint::current}

## Development Tasks
TODO Set up development environment {type::work} {category::setup}
TODO Create database migrations {type::work} {category::backend}
TODO Implement API endpoints {type::work} {category::backend}

## Testing & Review
TODO Write unit tests {type::work} {category::testing}
TODO Code review {type::work} {category::review}
TODO User acceptance testing {type::work} {category::testing}
```

This template can be used to quickly set up new sprint boards with consistent task structure.