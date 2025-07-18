---
title: "Additional Extensions"
weight: 30
---

notd includes several additional extensions that enhance your workflow with specialized tools and integrations. Each extension is designed to work seamlessly with the core system while providing focused functionality.

## Attachment Dashboard 📎

The Attachment Dashboard provides centralized file management for organizing and accessing all your note attachments.

### Features
- **Centralized View** - See all attachments across your entire knowledge base
- **File Type Filtering** - Filter by images, documents, spreadsheets, etc.
- **Search Capabilities** - Find attachments by filename or associated note content
- **Quick Preview** - Preview supported file types without leaving the dashboard
- **Bulk Operations** - Manage multiple files at once

### Usage Examples

```markdown
# Project Assets {category::project}

## Design Files
- Logo variations uploaded to dashboard
- Brand guidelines document attached
- Color palette reference images

## Documentation  
- Technical specifications PDF
- User manual drafts
- Meeting recordings

{attachments::managed}
{project::alpha}
```

Access the dashboard to:
- Organize files by project or category
- Find orphaned attachments
- Preview images and documents
- Clean up unused files

## Math Notepad 🧮

The Math Notepad extension provides mathematical notation support and calculation capabilities within your notes.

### Features
- **LaTeX Support** - Write mathematical expressions using LaTeX syntax
- **Live Rendering** - See equations rendered in real-time
- **Calculation Engine** - Perform calculations within notes
- **Formula Library** - Save and reuse common formulas
- **Export Options** - Export equations as images or LaTeX code

### Usage Examples

```markdown
# Physics Notes {subject::physics}

## Kinematic Equations

Using the Math Notepad for complex equations:

Velocity: $v = v_0 + at$
Position: $x = x_0 + v_0t + \frac{1}{2}at^2$
Acceleration: $v^2 = v_0^2 + 2a(x - x_0)$

## Calculations
- Initial velocity: 10 m/s
- Acceleration: 9.8 m/s²
- Time: 5 seconds

{course::intro-physics}
{formulas::kinematics}
```

Perfect for:
- Academic note-taking
- Engineering calculations
- Scientific documentation
- Mathematical modeling

## Pomodoro Timer ⏲️

The Pomodoro Timer extension helps manage your time using the Pomodoro Technique directly within your note-taking workflow.

### Features
- **25-minute Focus Sessions** - Standard Pomodoro intervals
- **Break Reminders** - Automatic 5-minute and 15-minute breaks
- **Session Tracking** - Log completed pomodoros per task/page
- **Progress Visualization** - See productivity patterns over time
- **Task Integration** - Link pomodoros to specific notes or tasks

### Usage Examples

```markdown
# Deep Work Session {type::focus}

## Today's Focus: API Documentation

TODO Write endpoint documentation {estimate::3-pomodoros}
TODO Create code examples {estimate::2-pomodoros}  
TODO Review and edit {estimate::1-pomodoro}

## Pomodoro Log
- 09:00-09:25: Endpoint docs (completed)
- 09:30-09:55: Code examples (completed)
- 10:00-10:25: Review session (in progress)

{productivity-method::pomodoro}
{focus-level::high}
```

Track productivity with properties:
```markdown
{pomodoros-completed::5}
{focus-rating::excellent}
{session-date::2024-07-18}
```

## RSS Handler 📰

The RSS Handler extension integrates RSS feeds into your knowledge management workflow.

### Features
- **Feed Management** - Subscribe to and organize RSS feeds
- **Content Import** - Import interesting articles directly into notes
- **Automatic Categorization** - Tag imported content based on source
- **Reading Lists** - Create curated reading lists from feed content
- **Archive Integration** - Save important articles to your knowledge base

### Usage Examples

```markdown
# Reading Queue {type::content-curation}

## Tech News Sources
- Hacker News feed
- Dev.to articles  
- GitHub trending
- Stack Overflow blog

## Today's Interesting Articles
TODO Review: "New JavaScript Features in 2024" {source::dev.to} {priority::high}
TODO Read: "API Design Best Practices" {source::medium} {category::learning}

## Archived Articles
- [[Article: Database Optimization Techniques]]
- [[Article: Frontend Performance Tips]]

{content-type::rss}
{curation-date::2024-07-18}
```

Workflow integration:
```markdown
{reading-status::queued}
{source-feed::tech-blogs}
{relevance::high}
{follow-up::required}
```

## Mail Handler 📧

The Mail Handler extension enables email integration for capturing and organizing email-related notes.

### Features
- **Email Import** - Import emails as notes or attachments
- **Thread Management** - Organize email conversations
- **Contact Integration** - Link emails to people and projects
- **Action Items** - Extract tasks from email content
- **Follow-up Tracking** - Track email responses and follow-ups

### Usage Examples

```markdown
# Client Communication Log {type::communication}

## Email Thread: Project Alpha Kickoff

### Initial Email (2024-07-15)
From: client@company.com
Subject: Project Alpha Requirements

Key points:
- Budget approved: $50k
- Timeline: 3 months
- Primary contact: Jane Smith

### Follow-up Tasks
TODO Send project timeline {assigned::pm} {due::2024-07-20}
TODO Schedule kickoff meeting {type::meeting} {participants::team,client}

{client::company-abc}
{project::alpha}
{communication-type::email}
```

Email-to-note workflow:
```markdown
{email-imported::2024-07-18}
{sender::john@client.com}
{original-subject::Budget Approval}
{action-required::true}
```

## Zen Mode 🧘

The Zen Mode extension provides a distraction-free writing environment for focused work sessions.

### Features
- **Minimal Interface** - Clean, distraction-free environment
- **Full-screen Writing** - Immersive writing experience
- **Focus Tools** - Hide sidebars, menus, and notifications
- **Writing Metrics** - Track words written and time spent
- **Gentle Reminders** - Subtle break reminders without interruption

### Usage Examples

```markdown
# Writing Session: Project Proposal {type::writing}

## Session Goals
- Complete executive summary (500 words)
- Draft technical approach (1000 words)
- Outline budget section (300 words)

## Writing Metrics
- Start time: 09:00
- Target: 1800 words
- Current: 1245 words
- Focus level: High

{writing-session::zen-mode}
{word-target::1800}
{distraction-level::minimal}
```

Perfect for:
- Long-form writing
- Documentation creation
- Creative writing
- Focused research sessions

## Extension Integration Tips

### Cross-Extension Workflows

Combine extensions for powerful workflows:

```markdown
# Project Documentation Sprint {type::project}

## Planning (Kanban Board)
TODO Outline documentation structure {board::docs-sprint}
DOING Research existing content {board::docs-sprint}

## Writing (Zen Mode + Pomodoro)
TODO Write API docs {pomodoros::4} {zen-mode::enabled}
TODO Create diagrams {extension::excalidraw}

## Review (Attachment Dashboard)
TODO Organize all project files {dashboard::review}
TODO Export final documentation {format::pdf}

{multi-extension::true}
{workflow::documentation}
```

### Property-Based Extension Coordination

Use properties to coordinate between extensions:

```markdown
{kanban-board::project-alpha}
{pomodoro-estimate::3}
{zen-mode-eligible::true}
{attachments-required::diagrams}
{math-content::equations}
```

This allows extensions to work together intelligently based on your content and workflow needs.