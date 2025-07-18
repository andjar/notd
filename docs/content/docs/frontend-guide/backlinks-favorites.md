---
title: "Backlinks & Favorites"
weight: 30
---

## Backlinks System

Backlinks show you which pages reference the current page, helping you discover connections and relationships in your knowledge base.

### How Backlinks Work

When you create a page link using `[[Page Name]]`, notd automatically:
- Creates a clickable link to that page
- Adds the current page to the target page's backlinks
- Tracks these relationships in the Properties table

### Viewing Backlinks

Backlinks appear in several places:
- **Sidebar** - Shows recent backlinks to the current page
- **Backlinks Modal** - Full list accessible via the backlinks button
- **Context** - Shows which note on the source page contains the link

### Backlinks Examples

If your "Project Alpha" page contains:
```markdown
See also [[Team Meeting Notes]] and [[Budget Planning]]
```

Then both "Team Meeting Notes" and "Budget Planning" pages will show "Project Alpha" in their backlinks.

### Finding Connections

Backlinks help you:
- **Discover related content** - Find notes that reference similar topics
- **Navigate relationships** - Move between connected ideas quickly
- **Identify important pages** - Pages with many backlinks are often central topics
- **Track references** - See where you've mentioned specific concepts

### Backlinks in Search

Use SQL queries to explore backlink patterns:

```sql
SQL{
  SELECT P.name as source_page, 
         COUNT(*) as link_count,
         GROUP_CONCAT(Prop.value) as linked_pages
  FROM Properties Prop
  JOIN Pages P ON Prop.page_id = P.id  
  WHERE Prop.name = 'links_to_page'
  GROUP BY P.id
  ORDER BY link_count DESC
}
```

This shows which pages create the most links to other pages.

## Favorites System

The favorites system provides quick access to your most important pages using the `favorite` property.

### Adding Favorites

Mark any page as a favorite by adding the property:
```markdown
{favorite::true}
```

### Viewing Favorites

Favorites appear in:
- **Sidebar** - Quick access list under "Favorites"
- **Navigation** - Always visible for rapid access
- **Search results** - Favorites can be highlighted in search

### Managing Favorites

To remove from favorites, either:
- Delete the `{favorite::true}` property
- Change it to `{favorite::false}`

### Favorite Categories

Organize favorites with additional properties:
```markdown
{favorite::true}
{category::work}
{priority::daily}
```

Then query categorized favorites:
```sql
SQL{
  SELECT P.name, Prop2.value as category
  FROM Pages P
  JOIN Properties Prop1 ON P.id = Prop1.page_id AND Prop1.name = 'favorite' AND Prop1.value = 'true'
  LEFT JOIN Properties Prop2 ON P.id = Prop2.page_id AND Prop2.name = 'category'
  ORDER BY Prop2.value, P.name
}
```

## Navigation Patterns

### Hub Pages

Create hub pages that serve as navigation centers:

```markdown
# Work Hub {favorite::true}

## Active Projects
- [[Project Alpha]]
- [[Project Beta]]
- [[Client Onboarding]]

## Resources
- [[Team Contacts]]
- [[Meeting Templates]]
- [[Process Documentation]]

## Daily Pages
- [[Daily Standup Template]]
- [[Weekly Review Template]]
```

### Index Pages

Build index pages for specific topics:

```markdown
# Development Resources {favorite::true}

## Documentation
- [[API Documentation]]
- [[Setup Guides]]
- [[Troubleshooting]]

## Tools & Libraries
- [[Useful Libraries]]
- [[Development Tools]]
- [[Code Snippets]]
```

### Cross-Referencing

Use backlinks and favorites together:
```markdown
# Team Meeting Notes

## Attendees
[[John Smith]] [[Jane Doe]] [[Bob Wilson]]

## Projects Discussed
[[Project Alpha]] - Budget approved
[[Project Beta]] - Timeline updated  

## Action Items
TODO Follow up with [[Client ABC]] {assigned::john}
TODO Update [[Project Timeline]] {due::tomorrow}

{favorite::true}
{category::meetings}
{date::2024-07-18}
```

## Advanced Navigation

### Relationship Mapping

Create pages that map relationships:

```markdown
# Project Relationships {favorite::true}

## Client Connections
- [[Client ABC]] → [[Project Alpha]]
- [[Client XYZ]] → [[Project Beta]]

## Team Assignments  
- [[John Smith]] → [[Project Alpha]], [[Infrastructure]]
- [[Jane Doe]] → [[Project Beta]], [[Documentation]]

## Dependencies
- [[Project Alpha]] depends on [[Infrastructure Setup]]
- [[Project Beta]] requires [[API Documentation]]
```

### Smart Collections

Use SQL to create dynamic collections:

```sql
SQL{
  -- Pages that are both favorites and have recent activity
  SELECT P.name, P.updated_at
  FROM Pages P
  JOIN Properties Prop ON P.id = Prop.page_id 
  WHERE Prop.name = 'favorite' 
    AND Prop.value = 'true'
    AND P.updated_at > datetime('now', '-7 days')
  ORDER BY P.updated_at DESC
}
```

### Breadcrumb Navigation

Create breadcrumb-style navigation:

```markdown
# Current Location

[[Home]] → [[Projects]] → [[Project Alpha]] → [[Sprint 1]]

{category::project}
{parent::Project Alpha}
```

Then find all child pages:
```sql
SQL{
  SELECT name 
  FROM Pages 
  WHERE name LIKE 'Project Alpha/%'
  ORDER BY name
}
```