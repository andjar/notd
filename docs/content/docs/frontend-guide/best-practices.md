---
title: "Best Practices"
weight: 60
---

This guide covers best practices for using notd effectively, drawn from real-world usage patterns and team collaboration experiences.

## Content Organization

### Naming Conventions

**Use consistent, descriptive page names:**
```markdown
# Good Examples
Meeting Notes - Project Alpha - 2024-07-18
Project Alpha/Requirements
Team/John Smith/Performance Review 2024

# Avoid
mtg notes
proj requirements  
john stuff
```

**Establish team conventions:**
```markdown
# Project Pages
Project [Name]/Overview
Project [Name]/Requirements  
Project [Name]/Timeline
Project [Name]/Team

# Meeting Pages
Meeting - [Type] - [Date]
Meeting - Sprint Planning - 2024-07-18
Meeting - All Hands - 2024-07-18

# Personal Pages
Personal/[Category]/[Topic]
Personal/Learning/JavaScript Frameworks
Personal/Projects/Home Automation
```

### Hierarchical Structure

**Use forward slashes for logical grouping:**
```markdown
# Project Structure
Projects/Alpha/Overview
Projects/Alpha/Sprint-1/Planning
Projects/Alpha/Sprint-1/Retrospective
Projects/Beta/Overview
Projects/Beta/Architecture

# Department Structure  
Engineering/Documentation/API-Guide
Engineering/Documentation/Coding-Standards
Engineering/Meetings/Weekly-Standup
Marketing/Campaigns/Q4-Launch
```

**Benefits of hierarchy:**
- Logical grouping of related content
- Easier navigation and discovery
- Cleaner search results
- Better organization at scale

### Property Standards

**Establish consistent property vocabularies:**

```markdown
# Status Properties
{status::draft}          # For content in progress
{status::review}         # Ready for review
{status::final}          # Completed content
{status::archived}       # No longer active

# Priority Properties  
{priority::urgent}       # Immediate attention
{priority::high}         # Important, not urgent
{priority::medium}       # Normal priority
{priority::low}          # Nice to have

# Type Properties
{type::project}          # Project pages
{type::meeting}          # Meeting notes
{type::reference}        # Reference material
{type::template}         # Reusable templates
```

**Create property documentation:**
```markdown
# Team Property Standards {type::reference} {favorite::true}

## Required Properties
All project pages must include:
- `{type::project}`
- `{status::active|paused|completed}`
- `{owner::team-member-name}`

## Meeting Properties
All meeting notes should include:
- `{type::meeting}`
- `{date::YYYY-MM-DD}`
- `{participants::name1,name2,name3}`

## Task Properties
Use these for task organization:
- `{priority::urgent|high|medium|low}`
- `{assigned::team-member}`
- `{sprint::current|next|backlog}`
- `{estimate::1h|2h|4h|1d|3d}`
```

## Task Management

### Task Lifecycle

**Use consistent task progression:**
```markdown
# Standard Task Flow
TODO → DOING → DONE

# Extended Task Flow
TODO → DOING → DONE
     ↓
   WAITING (if blocked)
     ↓
   CANCELLED (if no longer needed)
   
# Future Tasks
SOMEDAY (for non-immediate tasks)
```

**Document task context:**
```markdown
TODO Implement user authentication {
  priority::high
  assigned::backend-team
  estimate::3d
  dependencies::database-setup
  sprint::current
  epic::user-management
  acceptance-criteria::see-requirements-doc
}
```

### Task Organization Patterns

**Daily task planning:**
```markdown
# 2024-07-18

## Must Do Today
TODO Complete project proposal {priority::urgent} {due::today}
TODO Client call at 2pm {type::meeting} {priority::high}

## Should Do Today  
TODO Review pull requests {priority::medium} {estimate::1h}
TODO Update project timeline {priority::medium}

## Could Do Today
TODO Research new tools {priority::low} {type::learning}
TODO Organize workspace {priority::low} {type::personal}

{daily-planning::true}
```

**Project task breakdown:**
```markdown
# Project Alpha Tasks

## Phase 1: Foundation
TODO Set up development environment {phase::1} {assigned::devops}
TODO Define API specification {phase::1} {assigned::backend-lead}
TODO Create design system {phase::1} {assigned::designer}

## Phase 2: Core Features
TODO Implement authentication {phase::2} {depends::api-spec}
TODO Build user dashboard {phase::2} {depends::design-system}
TODO Set up CI/CD pipeline {phase::2} {depends::dev-environment}

## Phase 3: Polish
TODO Performance optimization {phase::3} {depends::core-features}
TODO User testing {phase::3} {depends::dashboard}
TODO Documentation {phase::3} {depends::all-features}
```

## Search and Discovery

### Search Optimization

**Make content searchable:**
```markdown
# Good - Includes searchable keywords
Project Alpha Authentication Implementation
- Implementing OAuth2 authentication
- User login and registration features
- Security best practices for web apps

{keywords::authentication,oauth2,security,login}

# Poor - Limited searchability  
Auth stuff
- Some OAuth things
- User stuff
```

**Use descriptive content:**
```markdown
# Meeting Notes - API Design Review

## Key Decisions
- REST API approach approved over GraphQL
- Authentication will use JWT tokens
- Rate limiting: 1000 requests per hour per user
- API versioning strategy: URL path versioning

## Action Items
TODO Document API endpoints {assigned::tech-writer}
TODO Implement rate limiting {assigned::backend-team}

{meeting-type::technical-review}
{decisions-made::3}
{follow-up::required}
```

### Effective Search Strategies

**Use property-based searches:**
```sql
SQL{
  -- Find all high-priority tasks across projects
  SELECT N.content, P.name as page, Prop.value as project
  FROM Notes N
  JOIN Pages P ON N.page_id = P.id  
  LEFT JOIN Properties Prop ON N.id = Prop.note_id AND Prop.name = 'project'
  JOIN Properties Priority ON N.id = Priority.note_id AND Priority.name = 'priority'
  WHERE Priority.value = 'high' AND N.content LIKE 'TODO%'
  ORDER BY N.created_at DESC
}
```

**Create saved search pages:**
```markdown
# My Dashboard {favorite::true}

## Today's High Priority Tasks
SQL{
  SELECT N.content, P.name 
  FROM Notes N 
  JOIN Pages P ON N.page_id = P.id
  JOIN Properties Prop ON N.id = Prop.note_id
  WHERE Prop.name = 'priority' AND Prop.value = 'high'
  AND N.content LIKE 'TODO%'
  ORDER BY N.updated_at DESC
  LIMIT 10
}

## This Week's Meetings
SQL{
  SELECT P.name, Prop.value as date
  FROM Pages P
  JOIN Properties Prop ON P.id = Prop.page_id
  WHERE Prop.name = 'type' AND Prop.value = 'meeting'
  AND P.updated_at > DATE('now', '-7 days')
  ORDER BY Prop.value DESC
}
```

## Collaboration

### Team Workflows

**Establish shared conventions:**
```markdown
# Team Collaboration Guide {type::reference} {favorite::true}

## Page Naming
- Projects: `Project [Name]/[Section]`
- Meetings: `Meeting - [Type] - [Date]`
- Resources: `Resources/[Category]/[Topic]`

## Property Standards
- All pages need `{type::category}`
- Projects need `{owner::team-member}`
- Tasks need `{assigned::team-member}`

## Meeting Notes Template
Use [[Meeting Template]] for all team meetings

## Review Process
1. Mark drafts with `{status::draft}`
2. Request review with `{status::review}`
3. Finalize with `{status::final}`
```

**Create shared resources:**
```markdown
# Team Resources Hub {favorite::true}

## Templates
- [[Meeting Template]]
- [[Project Template]]  
- [[Retrospective Template]]

## Reference Materials
- [[Team Contacts]]
- [[Process Documentation]]
- [[Tool Documentation]]

## Current Projects
- [[Project Alpha]]
- [[Project Beta]]
- [[Infrastructure Upgrade]]

{type::hub}
{access::team-wide}
```

### Knowledge Sharing

**Document decisions:**
```markdown
# Architecture Decision: Database Choice

## Context
We need to choose a database for the new project.

## Options Considered
1. PostgreSQL - Relational, ACID compliance
2. MongoDB - Document store, flexible schema
3. Redis - In-memory, high performance

## Decision
PostgreSQL chosen for ACID compliance and team familiarity.

## Consequences
- Strong consistency guarantees
- Well-understood by team
- Requires schema planning

{type::decision}
{project::alpha}
{decision-date::2024-07-18}
{stakeholders::tech-team}
```

**Share learning:**
```markdown
# Learning: React Hooks Best Practices

## Key Takeaways
- Use useCallback for expensive computations
- Prefer useState for simple state
- Custom hooks for reusable logic

## Code Examples
[Include practical examples]

## Resources
- [[React Documentation]]
- [[Team Code Review Notes]]

{type::learning}
{technology::react}
{shared-with::frontend-team}
{date::2024-07-18}
```

## Data Management

### Backup and Archiving

**Use properties for lifecycle management:**
```markdown
{archive-date::2024-12-31}    # When to archive
{backup-priority::high}       # Backup importance
{retention::7-years}          # How long to keep
{access-level::team}          # Who can access
```

**Create archive workflows:**
```markdown
# Quarterly Archive Process

## Projects to Archive  
SQL{
  SELECT P.name, Prop.value as completion_date
  FROM Pages P
  JOIN Properties Prop ON P.id = Prop.page_id
  WHERE Prop.name = 'status' AND Prop.value = 'completed'
  AND Prop.updated_at < DATE('now', '-90 days')
}

## Archive Steps
TODO Review completed projects {assigned::project-manager}
TODO Update project status to archived {assigned::admin}
TODO Export project data {assigned::admin}
TODO Update team dashboards {assigned::team-leads}
```

### Data Quality

**Regular maintenance:**
```markdown
# Data Quality Checklist

## Weekly Tasks
TODO Check for orphaned pages {assigned::admin}
TODO Review property consistency {assigned::admin}  
TODO Update team member assignments {assigned::hr}

## Monthly Tasks
TODO Archive completed projects {assigned::project-manager}
TODO Review and clean favorites {assigned::all-team}
TODO Update templates {assigned::process-owner}

## Quarterly Tasks
TODO Full backup verification {assigned::admin}
TODO Property standard updates {assigned::team-leads}
TODO Template effectiveness review {assigned::process-owner}
```

## Performance and Scalability

### Content Organization at Scale

**Avoid deeply nested hierarchies:**
```markdown
# Good - Reasonable depth
Projects/Alpha/Sprint-1
Projects/Alpha/Sprint-2  

# Avoid - Too deep
Projects/Alpha/2024/Q3/July/Week-3/Day-Monday/Morning
```

**Use properties for filtering instead of deep nesting:**
```markdown
# Instead of: Projects/Work/Frontend/React/Components/Button
# Use: Projects/Button Component
{type::component}
{technology::react}
{category::frontend}
{context::work}
```

### Query Performance

**Optimize SQL queries:**
```sql
-- Good - Specific and limited
SQL{
  SELECT P.name, N.content 
  FROM Pages P 
  JOIN Notes N ON P.id = N.page_id 
  WHERE P.updated_at > DATE('now', '-7 days')
  LIMIT 20
}

-- Avoid - Unbounded queries
SQL{
  SELECT * FROM Pages P
  JOIN Notes N ON P.id = N.page_id
  -- No WHERE clause or LIMIT
}
```

**Use indexes effectively:**
```sql
-- Leverage indexed columns
SQL{
  SELECT * FROM Pages 
  WHERE name LIKE 'Project%'  -- Name is indexed
  ORDER BY updated_at DESC    -- Updated_at is indexed
  LIMIT 10
}
```

These best practices will help you maintain an organized, efficient, and collaborative knowledge base as your use of notd grows and evolves.