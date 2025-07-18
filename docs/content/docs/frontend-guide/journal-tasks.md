---
title: "Journal & Tasks"
weight: 20
---

## Journal-Based Workflow

notd is built around a journal-based system inspired by tools like LogSeq. This approach uses date-based pages as the foundation for daily note-taking and organization.

### Daily Journal Pages

By default, notd redirects to today's date page (e.g., `2024-07-18`). These journal pages:

- Are automatically created when first accessed
- Provide a consistent starting point for daily work
- Can contain any type of content: notes, tasks, links, etc.
- Form a chronological record of your thoughts and activities

### Date Navigation

Navigate between dates using:
- The calendar widget in the sidebar
- Direct URL editing (`page.php?page=2024-07-18`)
- Page links to specific dates: `[[2024-07-18]]`

## Task Management System

notd includes a comprehensive task management system with multiple states and visual indicators.

### Task States

Use these keywords at the beginning of a note to create tasks:

- `TODO` - Task to be done
- `DOING` - Task currently in progress  
- `DONE` - Completed task
- `SOMEDAY` - Future task (not immediate)
- `WAITING` - Task waiting on external dependency
- `CANCELLED` - Cancelled task
- `NLR` - No longer required

### Task Examples

```markdown
TODO Review project proposal
DOING Write documentation
DONE Update website content
SOMEDAY Learn new programming language
WAITING Client approval for design
CANCELLED Old feature request
NLR Outdated compliance requirement
```

### Task Properties

Enhance tasks with properties for better organization:

```markdown
TODO Research new tools {priority::high} {category::work} {due::2024-07-25}
DOING Write user guide {assigned::john} {estimate::4h}
DONE Fix login bug {priority::urgent} {completed::2024-07-18}
```

### Visual Task Indicators

Tasks are rendered with:
- **Color-coded keywords** - Each state has distinct styling
- **Checkboxes** - Visual indicators for task state
- **Context highlighting** - Important tasks stand out
- **Progress tracking** - Easy to see what's completed

## Task Workflow Integration

### Daily Planning

Use journal pages for daily task planning:

```markdown
# 2024-07-18

## Today's Goals
TODO Review quarterly reports {priority::high}
TODO Team meeting at 2pm {category::meeting}
TODO Update project timeline

## In Progress
DOING Code review for feature X
DOING Documentation update

## Completed
DONE Morning standup
DONE Email responses
```

### Project Organization

Create dedicated pages for projects with task breakdowns:

```markdown
# Project Alpha

## Sprint Planning
TODO Define user stories {sprint::1}
TODO Set up development environment {sprint::1}
DOING UI mockups {assigned::designer} {sprint::1}

## Backlog
SOMEDAY Mobile app version
SOMEDAY API documentation
```

### Task Tracking with Properties

Use properties for advanced task management:

```markdown
TODO Implement user authentication {
  priority::high
  estimate::8h
  assigned::dev-team
  sprint::2
  epic::user-management
}
```

## Recurring Tasks and Templates

### Template-Based Tasks

Create reusable task templates:

```markdown
# Weekly Review Template

TODO Review completed tasks
TODO Plan next week priorities  
TODO Update project status
TODO Team check-ins {category::meeting}
```

### Journal Templates

Set up templates for consistent daily structure:

```markdown
# Daily Journal Template

## Morning Review
TODO Check calendar
TODO Review priorities

## Work Tasks
[Tasks added throughout the day]

## Evening Reflection
- What went well?
- What could be improved?
- Tomorrow's priorities
```

## Task Search and Filtering

### Finding Tasks

Use search to find tasks across all pages:
- Search for task states: "TODO", "DOING", etc.
- Filter by properties: "priority::high"
- Find tasks by category: "category::work"

### SQL Queries for Task Management

Create dynamic task lists with SQL:

```sql
SQL{
  SELECT N.content, P.name as page_name, Prop.value as priority
  FROM Notes N 
  JOIN Pages P ON N.page_id = P.id 
  LEFT JOIN Properties Prop ON N.id = Prop.note_id AND Prop.name = 'priority'
  WHERE N.content LIKE 'TODO%' 
  ORDER BY 
    CASE Prop.value 
      WHEN 'urgent' THEN 1 
      WHEN 'high' THEN 2 
      WHEN 'medium' THEN 3 
      ELSE 4 
    END
}
```

This creates a prioritized task list across all your pages.