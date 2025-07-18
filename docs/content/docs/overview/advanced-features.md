---
title: "Advanced Features"
weight: 40
---

notd includes several advanced features that leverage both the backend API and frontend capabilities to create powerful workflows and automation.

## Frontend-Driven Features

### Dynamic SQL Queries

The frontend can execute SQL queries embedded directly in notes, providing powerful data analysis and reporting capabilities:

```text
SQL{SELECT id FROM Notes WHERE content LIKE '%important%'}
```

This creates dynamic content that updates automatically when underlying data changes.

#### Query Examples

**Project Dashboard:**
```sql
SQL{
  SELECT 
    P.name as project,
    COUNT(CASE WHEN N.content LIKE 'TODO%' THEN 1 END) as pending_tasks,
    COUNT(CASE WHEN N.content LIKE 'DONE%' THEN 1 END) as completed_tasks,
    MAX(P.updated_at) as last_activity
  FROM Pages P
  LEFT JOIN Notes N ON P.id = N.page_id
  JOIN Properties Prop ON P.id = Prop.page_id AND Prop.name = 'type' AND Prop.value = 'project'
  GROUP BY P.id
  ORDER BY last_activity DESC
}
```

**Team Workload Analysis:**
```sql
SQL{
  SELECT 
    Prop.value as team_member,
    COUNT(*) as total_tasks,
    COUNT(CASE WHEN N.content LIKE 'DONE%' THEN 1 END) as completed,
    ROUND(COUNT(CASE WHEN N.content LIKE 'DONE%' THEN 1 END) * 100.0 / COUNT(*), 1) as completion_rate
  FROM Notes N
  JOIN Properties Prop ON N.id = Prop.note_id AND Prop.name = 'assigned'
  WHERE N.content REGEXP '^(TODO|DOING|DONE)'
  GROUP BY Prop.value
  ORDER BY completion_rate DESC
}
```

### Content Transclusion

Embed content from other notes or pages for modular documentation:

```text
{{transclude:Note Title}}
```

This enables:
- **Reusable Components** - Create once, use everywhere
- **Dynamic References** - Content updates automatically when source changes
- **Modular Documentation** - Build complex documents from smaller parts
- **Consistent Information** - Single source of truth for shared content

#### Transclusion Examples

**Company Information Block:**
```markdown
# Company Details {type::reference}

**Company:** Acme Corporation
**Founded:** 2010
**Employees:** 150
**Website:** https://acme.com

{category::company-info}
{type::transclusion-source}
```

**Meeting Template with Transclusion:**
```markdown
# Weekly Team Meeting - {{date}}

## Company Updates
{{transclude:Company Details}}

## Agenda
- Project status updates
- Resource allocation
- Upcoming deadlines

## Action Items
[Items added during meeting]
```

### Client-Side Encryption

Protect sensitive content with AES-256 encryption:

```text
ENC{sensitive information here}
```

#### Encryption Features
- **Client-Side Security** - Content encrypted before transmission
- **Password-Protected** - Each encrypted page requires a password
- **Search Privacy** - Encrypted content excluded from global search
- **Selective Encryption** - Choose what to encrypt within each page

#### Encryption Workflow
1. **Set Page Property:** `{encrypted::true}`
2. **Enter Password:** Prompted when viewing encrypted pages
3. **Mark Content:** Use `ENC{...}` syntax for sensitive content
4. **Automatic Processing:** Content encrypted/decrypted transparently

### Property System

The property system enables powerful organization and automation:

```markdown
{property::value}        # Public property (weight 2)
{property:::value}       # Internal property (weight 3)
{property::::value}      # System log property (weight 4)
```

#### Advanced Property Patterns

**Task Management:**
```markdown
TODO Implement feature {
  priority::high
  assigned::dev-team
  sprint::3
  estimate::8h
  dependencies::database-migration
  epic::user-authentication
}
```

**Content Classification:**
```markdown
# Meeting Notes {
  type::meeting
  date::2024-07-18
  participants::team-lead,product-manager,designer
  project::alpha
  status::draft
  action-items::3
  follow-up::required
}
```

**Automation Triggers:**
```markdown
{auto-notify::team-lead}
{auto-archive::30-days}
{auto-backup::daily}
{workflow-stage::review}
```

## Backend API Features

### Page Links and Backlinks

Page links using `[[Page Name]]` syntax automatically create backlink relationships:

```markdown
See also [[Related Topic]] and [[Project Documentation]]
```

This creates bidirectional relationships tracked in the Properties table.

### Task Status Management

The API supports comprehensive task status tracking:

- `TODO` - Task to be done
- `DOING` - Task in progress
- `DONE` - Completed task
- `SOMEDAY` - Future task
- `WAITING` - Task waiting on something
- `CANCELLED` - Cancelled task
- `NLR` - No longer required

### Batch Operations

Batch operations allow multiple actions in a single request:

1. **Delete operations** - Processed first
2. **Create operations** - Processed second  
3. **Update operations** - Processed last

This ensures proper dependency handling and data consistency.

### Hierarchical Organization

Support for hierarchical page structures:
- Use forward slashes in page names: `Projects/Alpha/Sprint-1`
- Automatic parent-child relationships
- Breadcrumb navigation support
- Nested property inheritance

## Integration Patterns

### Frontend-Backend Coordination

**Real-time Updates:**
- Frontend changes immediately reflected in UI
- Background API calls sync data
- Optimistic updates with error recovery
- Conflict resolution for concurrent edits

**Search Integration:**
- Frontend search with backend indexing
- Property-based filtering
- Full-text search with highlighting
- Encrypted content handling

**Extension Architecture:**
- Extensions interact with core API
- Property-based extension configuration
- Event-driven extension communication
- Shared state management

### Workflow Automation

**Template-Driven Workflows:**
```markdown
# Project Template {type::template}

## Project Setup
TODO Define requirements {stage::planning} {auto-assign::product}
TODO Create initial backlog {stage::planning} {auto-assign::product}
TODO Set up development environment {stage::setup} {auto-assign::dev-lead}

## Development Phase
TODO Implement core features {stage::development} {auto-assign::dev-team}
TODO Write documentation {stage::development} {auto-assign::tech-writer}
TODO Create tests {stage::development} {auto-assign::qa-team}

## Launch Phase
TODO User acceptance testing {stage::testing} {auto-assign::qa-team}
TODO Deploy to production {stage::deployment} {auto-assign::devops}
TODO Monitor metrics {stage::monitoring} {auto-assign::dev-lead}

{workflow::project-lifecycle}
{auto-create-pages::true}
```

**Property-Based Automation:**
```markdown
{auto-notify::stakeholders}
{auto-deadline::sprint-end}
{auto-priority::inherit-from-epic}
{auto-board::project-kanban}
```

## Error Handling and Recovery

### Database Locking
- Automatic retry logic for locked databases
- `503` status codes with retry headers
- Graceful degradation during high load
- Transaction isolation for data consistency

### Network Resilience
- Offline-first architecture
- Local caching of recent data
- Automatic sync when connection restored
- Conflict resolution strategies

### Data Validation
- Schema validation on API endpoints
- Frontend input validation
- Property value constraints
- Content length limits

## Performance Optimization

### Pagination
Most list endpoints support pagination:
- `page` - Page number (default: 1)
- `per_page` - Items per page (default: 20, max: 100)

### Caching Strategies
- **Frontend Caching** - Recent pages and search results
- **API Response Caching** - Expensive query results
- **Static Asset Caching** - CSS, JS, and image files
- **Database Query Optimization** - Indexed searches and joins

### Lazy Loading
- **Content Loading** - Load notes on demand
- **Image Loading** - Load attachments when visible
- **Extension Loading** - Load extensions when accessed
- **Search Results** - Progressive result loading

These advanced features make notd a powerful platform for knowledge management, project coordination, and team collaboration.
