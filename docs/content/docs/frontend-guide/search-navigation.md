---
title: "Search & Navigation"
weight: 40
---

notd provides powerful search capabilities that help you find information quickly across all your notes, pages, and attachments. The search system integrates with all frontend features including encryption, properties, and backlinks.

## Global Search

### Search Interface

The global search is accessible from the sidebar and provides:
- **Real-time results** - Search as you type with debounced queries
- **Content highlighting** - Search terms are highlighted in results
- **Context snippets** - Preview content around matching terms
- **Property filtering** - Filter by page and note properties
- **Mixed results** - Pages and notes shown together with clear distinction

### Search Syntax

#### Basic Search
```
project management
```
Finds content containing both "project" and "management".

#### Property Search
```
priority::high
```
Finds notes/pages with the `priority` property set to `high`.

#### Combined Search
```
API documentation priority::urgent
```
Finds content about "API documentation" with urgent priority.

#### Task Search
```
TODO review
```
Finds all TODO tasks containing "review".

### Search Results

Results display:
- **Page/Note title** - With direct links
- **Content snippet** - Context around matching terms
- **Properties** - Relevant properties shown with results
- **Page context** - Which page contains the matching note
- **Timestamp** - When the content was last updated

### Encrypted Content Search

When searching encrypted pages:
- **Password prompt** - You'll be asked for the page password
- **Decrypted results** - Content is decrypted for display if password is correct
- **Fallback display** - Shows `[ENCRYPTED CONTENT]` if decryption fails
- **Security** - Passwords are never stored, only used for active session

## Navigation Features

### Sidebar Navigation

The sidebar provides multiple navigation methods:

#### Recent Pages
- **Automatic tracking** - Recently viewed pages appear automatically
- **Quick access** - Click to jump to any recent page
- **Smart ordering** - Most recent first, with intelligent grouping

#### Favorites
- **Quick access** - All favorited pages shown prominently
- **Categories** - Favorites can be grouped by category
- **One-click access** - Direct navigation to important pages

#### Calendar Widget
- **Date navigation** - Click any date to jump to that journal page
- **Visual indicators** - Days with content are highlighted
- **Quick date jumps** - Navigate months and years easily

### Page Navigation

#### Page Links
Create navigable connections between pages:
```markdown
See also [[Related Topic]] and [[Project Documentation]]
```

#### Hierarchical Pages
Use forward slashes to create page hierarchies:
```markdown
[[Projects/Alpha/Sprint 1]]
[[Projects/Alpha/Sprint 2]]
[[Projects/Beta/Planning]]
```

#### Breadcrumb Navigation
Create breadcrumb-style navigation:
```markdown
# Current Location
[[Home]] → [[Projects]] → [[Project Alpha]] → [[Current Sprint]]
```

## Advanced Search Features

### SQL-Based Search

Create custom search queries using SQL:

```sql
SQL{
  SELECT 
    P.name as page,
    N.content,
    GROUP_CONCAT(Prop.name || '=' || Prop.value) as properties
  FROM Notes N
  JOIN Pages P ON N.page_id = P.id
  LEFT JOIN Properties Prop ON N.id = Prop.note_id
  WHERE N.content LIKE '%API%'
  GROUP BY N.id
  ORDER BY N.updated_at DESC
  LIMIT 10
}
```

This creates a custom search for API-related content with full property details.

### Property-Based Filtering

Search by any property combination:

```sql
SQL{
  -- Find high-priority work tasks assigned to specific team members
  SELECT 
    N.content,
    P.name as page_name,
    Prop1.value as priority,
    Prop2.value as assigned_to
  FROM Notes N
  JOIN Pages P ON N.page_id = P.id
  JOIN Properties Prop1 ON N.id = Prop1.note_id AND Prop1.name = 'priority'
  JOIN Properties Prop2 ON N.id = Prop2.note_id AND Prop2.name = 'assigned'
  WHERE Prop1.value = 'high' 
    AND Prop2.value IN ('john', 'jane', 'bob')
    AND N.content LIKE 'TODO%'
  ORDER BY N.created_at DESC
}
```

### Backlink Discovery

Find connection patterns:

```sql
SQL{
  -- Pages that link to the most other pages (hub pages)
  SELECT 
    P.name,
    COUNT(DISTINCT Prop.value) as links_to_count
  FROM Pages P
  JOIN Properties Prop ON P.id = Prop.page_id
  WHERE Prop.name = 'links_to_page'
  GROUP BY P.id
  ORDER BY links_to_count DESC
  LIMIT 10
}
```

## Search Workflows

### Daily Search Patterns

#### Morning Review
```sql
SQL{
  -- Tasks created or updated yesterday
  SELECT N.content, P.name
  FROM Notes N 
  JOIN Pages P ON N.page_id = P.id
  WHERE N.content LIKE 'TODO%'
    AND DATE(N.updated_at) = DATE('now', '-1 day')
  ORDER BY N.updated_at DESC
}
```

#### Weekly Planning
```sql
SQL{
  -- All active projects with recent activity
  SELECT 
    P.name,
    COUNT(N.id) as note_count,
    MAX(N.updated_at) as last_activity
  FROM Pages P
  LEFT JOIN Notes N ON P.id = N.page_id
  JOIN Properties Prop ON P.id = Prop.page_id
  WHERE Prop.name = 'type' AND Prop.value = 'project'
    AND P.updated_at > DATE('now', '-7 days')
  GROUP BY P.id
  ORDER BY last_activity DESC
}
```

### Project Discovery

#### Finding Related Work
```sql
SQL{
  -- Notes that mention similar topics to current page
  SELECT 
    N.content,
    P.name as page_name,
    COUNT(*) as relevance_score
  FROM Notes N
  JOIN Pages P ON N.page_id = P.id
  WHERE N.content LIKE '%authentication%'
     OR N.content LIKE '%security%'
     OR N.content LIKE '%login%'
  GROUP BY N.id
  ORDER BY relevance_score DESC, N.updated_at DESC
}
```

#### Team Coordination
```sql
SQL{
  -- All tasks assigned to team members by project
  SELECT 
    Prop1.value as assigned_to,
    Prop2.value as project,
    COUNT(*) as task_count,
    SUM(CASE WHEN N.content LIKE 'DONE%' THEN 1 ELSE 0 END) as completed
  FROM Notes N
  JOIN Properties Prop1 ON N.id = Prop1.note_id AND Prop1.name = 'assigned'
  LEFT JOIN Properties Prop2 ON N.id = Prop2.note_id AND Prop2.name = 'project'
  WHERE N.content REGEXP '^(TODO|DOING|DONE)'
  GROUP BY Prop1.value, Prop2.value
  ORDER BY assigned_to, project
}
```

## Search Optimization Tips

### Content Organization

1. **Consistent Tagging** - Use consistent property names and values
2. **Descriptive Titles** - Make page and note titles searchable
3. **Strategic Keywords** - Include searchable terms in content
4. **Property Standards** - Establish team-wide property conventions

### Search Strategies

1. **Start Broad** - Begin with general terms, then narrow down
2. **Use Properties** - Leverage the property system for precise filtering
3. **Combine Methods** - Mix text search with property filtering
4. **Save Searches** - Create reusable SQL queries for common searches

### Performance Considerations

1. **Index Awareness** - Understand that search is optimized for content and properties
2. **Result Limits** - Use LIMIT in SQL queries for better performance
3. **Caching** - Recent search results are cached for faster repeat searches
4. **Incremental Refinement** - Refine searches incrementally rather than complex initial queries

## Keyboard Shortcuts

Common navigation shortcuts:
- `Ctrl/Cmd + K` - Focus global search
- `Ctrl/Cmd + Click` - Open page link in new tab  
- `Escape` - Close search results
- `Arrow Keys` - Navigate search results
- `Enter` - Open selected result

These search and navigation features make notd a powerful tool for knowledge management and information discovery.