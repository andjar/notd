---
title: "Frontend Quick Start"
weight: 5
---

Get up and running with notd's powerful frontend features in minutes. This guide covers the essential features you need to know to be productive immediately.

## First Steps

### 1. Understanding the Interface

![notd main layout](images/frontpage.png)

When you first open notd, you'll see:
- **Sidebar** (left) - Navigation, search, and quick access
- **Main area** (center) - Your notes and content
- **Today's date** - notd starts with today's journal page

### 2. Create Your First Note

![notd main layout](images/my_first_note.png)

Click in the main area and start typing:

```markdown
# My First Note

Welcome to notd! This is my first note.

TODO Learn notd features {priority::high}
DOING Explore the interface
```

NOTE: Each task must be a separate note.

### 3. Try Essential Features

**Create a page link:**
```markdown
This connects to [[My Project Page]]
```

**Add properties:**
```markdown
{category::personal}
{favorite::true}
```

**Create a task:**
```markdown
TODO Set up my workspace {due::today}
```

## Essential Features Tour

### Page Linking and Navigation

**Create connections between ideas:**
```markdown
# Project Alpha

Related pages:
- [[Meeting Notes]]
- [[Technical Requirements]]
- [[Team Members]]

{project::alpha}
{status::active}
```

**Use backlinks to discover connections:**
- Any page that links to the current page shows up in backlinks
- Find unexpected relationships in your notes
- Navigate between related concepts quickly

Backlinks are visible in the right sidebar and their context can be shown with `ctrl + b`

### Task Management

**Use different task states:**
```markdown
TODO Plan project kickoff {priority::high} {assigned::me}
DOING Write project proposal {estimate::2h}
DONE Set up project repository {completed::2024-07-18}
WAITING Client feedback on proposal {contact::john}
SOMEDAY Add advanced features {category::enhancement}
```

### Search and Discovery

**Use global search (`Ctrl+K` or `shift + space`):**
- Type anything to find it across all your notes
- Search by content: "project planning"
- Search by properties: "priority::high"
- Search by task status: "TODO"

**Quick navigation:**
- **Recent pages** - See your recently viewed content
- **Favorites** - Mark important pages with `{favorite::true}`
- **Calendar** - Navigate to any date's journal page

### Properties for Organization

**Common property patterns:**
```markdown
{type::meeting}           # Categorize content
{priority::urgent}        # Set importance
{assigned::team-member}   # Assign responsibility
{project::alpha}          # Group by project
{status::in-progress}     # Track status
{due::2024-07-25}         # Set deadlines
```

**Create property-based queries:**
```markdown
SQL{
  SELECT N.id FROM Notes N
  JOIN Properties P ON N.id = P.note_id
  WHERE P.name = 'status' AND P.value = 'TODO'
}
```

## Power User Features [DOCUMENTATION NOT UPDATED YET]

### SQL Queries in Notes

Create dynamic content with SQL:

```markdown
# My Task Dashboard

## High Priority Tasks
SQL{
  SELECT N.content, P.name as page 
  FROM Notes N 
  JOIN Pages P ON N.page_id = P.id 
  JOIN Properties Prop ON N.id = Prop.note_id 
  WHERE Prop.name = 'priority' AND Prop.value = 'high'
  AND N.content LIKE 'TODO%'
}

## Project Progress
SQL{
  SELECT 
    Prop.value as project,
    COUNT(CASE WHEN N.content LIKE 'TODO%' THEN 1 END) as pending,
    COUNT(CASE WHEN N.content LIKE 'DONE%' THEN 1 END) as completed
  FROM Notes N
  JOIN Properties Prop ON N.id = Prop.note_id AND Prop.name = 'project'
  WHERE N.content REGEXP '^(TODO|DONE)'
  GROUP BY Prop.value
}
```

### Transclusion for Reusable Content

Create reusable content blocks:

```markdown
# Team Contact Information {type::reference}

**Project Manager:** Jane Smith (jane@company.com)
**Tech Lead:** John Doe (john@company.com)  
**Designer:** Bob Wilson (bob@company.com)

{category::contacts}
{type::transclusion-source}
```

To transclude the note (and its children) elsewhere, right click on the bullet an choose "Copy transclusion link". Transclusions are inserted as

```markdown
!{{note_id}}
```

### Encryption for Sensitive Content

Protect sensitive information. Click the gear icon besides the page title, and select Encrypt. The notes will be encrypted client-side and are NOT recoverable if you lose the key.

## Daily Workflow Examples [DOCUMENTATION NOT UPDATED YET]

### Morning Planning
```markdown
# 2024-07-18

## Today's Priorities
TODO Review pull requests {priority::high} {estimate::1h}
TODO Team standup at 9am {type::meeting}
TODO Complete project proposal {due::today} {priority::urgent}

## Notes
Started early today, feeling productive.

{mood::good}
{focus-level::high}
```

### Project Organization
```markdown
# Project Alpha Hub {favorite::true}

## Quick Links
- [[Project Alpha/Requirements]]
- [[Project Alpha/Timeline]]
- [[Project Alpha/Team]]

## Current Sprint
TODO Implement user authentication {sprint::3} {assigned::backend-team}
DOING Design login UI {sprint::3} {assigned::design-team}
DONE Set up CI/CD pipeline {sprint::3} {completed::2024-07-15}

## Resources
- [[API Documentation]]
- [[Design System]]
- [[Testing Guidelines]]

{type::project}
{status::active}
{team::full-stack}
```

### Meeting Notes
```markdown
# Weekly Team Meeting - 2024-07-18

## Attendees
- [[John Smith]] (Tech Lead)
- [[Jane Doe]] (Product Manager)
- [[Bob Wilson]] (Designer)

## Decisions Made
- Approved new feature request
- Timeline adjusted for Q4 launch
- See [[Technical Architecture]] for implementation details

## Action Items
TODO Update project timeline {assigned::jane} {due::friday}
TODO Review security requirements {assigned::john} {priority::high}
TODO Create UI mockups {assigned::bob} {due::next-week}

## Next Meeting
[[Weekly Team Meeting - 2024-07-25]]

{type::meeting}
{project::alpha}
{follow-up::required}
```

## Quick Tips

### Productivity Tips
1. **Start with today's date** - Use journal pages for daily planning
2. **Use favorites** - Mark important pages for quick access
3. **Consistent properties** - Develop standard property names
4. **Link liberally** - Connect related ideas with page links
5. **Search everything** - Use Ctrl+K to find anything quickly

### Organization Tips
1. **Hierarchical pages** - Use `Project/Subproject/Details` naming
2. **Property conventions** - Establish team standards for properties
3. **Template pages** - Create reusable page templates
4. **Regular reviews** - Use SQL queries for progress tracking
5. **Backlink exploration** - Discover unexpected connections

### Workflow Tips
1. **Morning routine** - Start each day with today's journal page
2. **Weekly reviews** - Use SQL queries to review completed work
3. **Project hubs** - Create central pages for each major project
4. **Meeting templates** - Standardize meeting note formats
5. **Archive old content** - Use properties to mark completed projects

## Next Steps

Once you're comfortable with these basics:

1. **Explore Extensions** - Try the Kanban board, Excalidraw editor, and other extensions
2. **Advanced Features** - Learn about encryption, advanced SQL queries, and automation
3. **Customization** - Configure themes, shortcuts, and interface preferences
4. **Team Collaboration** - Set up shared conventions and workflows

notd grows with you - start simple and gradually adopt more advanced features as your needs evolve.