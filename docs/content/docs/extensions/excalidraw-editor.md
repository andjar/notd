---
title: "Excalidraw Editor"
weight: 20
---

The Excalidraw Editor extension integrates the powerful [Excalidraw](https://excalidraw.com/) visual whiteboard directly into notd, enabling you to create hand-drawn diagrams, sketches, and visual notes that are automatically saved as note attachments.

## Features

- **Visual Drawing** - Full-featured drawing canvas with shapes, text, and freehand drawing
- **Seamless Integration** - Launch directly from note context menus
- **Automatic Saving** - Drawings are saved as PNG attachments to specific notes
- **Offline Support** - Works completely offline with phpdesktop
- **Modern Interface** - React-based Excalidraw component with Alpine.js controls

## Getting Started

### Opening the Editor

1. **From Note Context Menu** - Right-click any note and select "Open in Excalidraw"
2. **Direct Access** - Navigate to the extension through the extensions menu
3. **URL Parameters** - Access with specific note ID: `excalidraw.html?note_id=123`

### Creating Drawings

The Excalidraw editor provides:
- **Shapes** - Rectangles, circles, arrows, lines
- **Text** - Add labels and annotations
- **Freehand** - Draw naturally with pen/mouse
- **Colors** - Multiple color options for organization
- **Layers** - Arrange elements with bring-to-front/send-to-back

### Saving Drawings

1. Create your diagram in the Excalidraw interface
2. Click **Save as Attachment** when ready
3. The drawing is automatically exported as PNG
4. File is uploaded and attached to the associated note
5. Return to your note to see the attachment

## Drawing Workflow Examples

### Mind Mapping

Use Excalidraw for visual mind maps:

```markdown
# Project Planning {type::project}

Initial brainstorming session - see attached mind map for full breakdown.

## Key Areas
- User Experience Design
- Backend Architecture  
- Marketing Strategy
- Timeline & Milestones
```

*[Attach Excalidraw mind map showing detailed connections between areas]*

### System Architecture

Document technical systems visually:

```markdown
# API Architecture Documentation {category::technical}

## System Overview
The attached diagram shows the complete API architecture including:
- Microservices breakdown
- Database relationships
- External integrations
- Data flow patterns

{priority::high}
{type::documentation}
```

*[Attach detailed architecture diagram created in Excalidraw]*

### Process Flows

Visualize workflows and processes:

```markdown
# User Onboarding Process {type::process}

## Current Flow
The attached flowchart details our current onboarding process:

1. User Registration
2. Email Verification  
3. Profile Setup
4. Welcome Tutorial
5. First Project Creation

## Pain Points Identified
- Step 3 has high dropout rate
- Tutorial is too long
- Need better progress indicators

{category::improvement}
{assigned::ux-team}
```

*[Attach process flow diagram with decision points and bottlenecks highlighted]*

## Integration with Notes

### Referencing Diagrams

Link to diagrams from multiple notes:

```markdown
# Meeting Notes - Architecture Review

## Decisions Made
- Microservices approach approved
- See [[API Architecture Documentation]] for detailed diagrams
- Database schema finalized

## Action Items  
TODO Update architecture diagrams {assigned::tech-lead}
TODO Share diagrams with stakeholders {due::friday}
```

### Version Control

Track diagram versions through note updates:

```markdown
# System Design Evolution {category::documentation}

## Version History
- v1.0 - Initial concept (2024-07-01)
- v1.1 - Added authentication flow (2024-07-10) 
- v1.2 - Microservices breakdown (2024-07-18) ← Current

## Current Version
The attached diagram represents v1.2 of our system design.

## Planned Changes
- Add caching layer
- Include monitoring systems
- Detail deployment pipeline

{version::1.2}
{last-updated::2024-07-18}
```

### Collaborative Diagrams

Use properties to track collaborative drawing sessions:

```markdown
# Whiteboard Session - Product Planning

## Session Details
- Date: 2024-07-18
- Participants: Product Team, UX Team, Engineering
- Duration: 2 hours

## Outputs
Three diagrams were created during the session:
1. User journey map
2. Feature prioritization matrix  
3. Technical dependency graph

{session-type::collaborative}
{participants::product,ux,engineering}
{follow-up::required}
```

## Technical Details

### File Integration

Excalidraw drawings integrate with notd's attachment system:
- **Format** - Saved as PNG images for universal compatibility
- **Storage** - Stored in the same location as other note attachments
- **Linking** - Automatically linked to the originating note
- **Search** - Attachment metadata is searchable

### Browser Compatibility

The extension works across modern browsers:
- **Chrome/Chromium** - Full support including offline mode
- **Firefox** - Full support with excellent performance
- **Safari** - Compatible with minor rendering differences
- **phpdesktop** - Optimized for desktop app usage

### Performance

- **React-based** - Uses React for the Excalidraw component
- **CDN Delivery** - Excalidraw loaded from reliable CDN
- **Local Storage** - Temporary drawings stored locally until saved
- **Export Optimization** - PNG export optimized for file size

## Configuration

### Extension Settings

Configure the extension via `config.json`:

```json
{
  "featherIcon": "edit-3",
  "defaultCanvas": {
    "width": 1200,
    "height": 800
  },
  "exportSettings": {
    "format": "png",
    "quality": 0.9,
    "scale": 2
  }
}
```

### Canvas Presets

Create preset templates for common diagram types:

```json
{
  "templates": [
    {
      "name": "mind-map",
      "elements": [
        {"type": "ellipse", "x": 600, "y": 400, "text": "Central Topic"}
      ]
    },
    {
      "name": "flowchart", 
      "elements": [
        {"type": "rectangle", "x": 100, "y": 100, "text": "Start"}
      ]
    }
  ]
}
```

## Best Practices

### Organization

- **Consistent Naming** - Use clear, descriptive file names
- **Property Tagging** - Tag notes with diagram types for easy filtering
- **Regular Updates** - Keep diagrams current with system changes

### Collaboration

- **Share Context** - Always include explanatory text with diagrams
- **Version Notes** - Document what changed between versions
- **Review Process** - Include diagram reviews in your workflow

### Workflow Integration

```markdown
# Diagram Review Process {type::process}

## Creation Phase
TODO Create initial diagram in Excalidraw {assigned::creator}
TODO Add explanatory context in note {assigned::creator}

## Review Phase  
TODO Technical review {assigned::tech-lead} {type::review}
TODO Stakeholder approval {assigned::product} {type::approval}

## Finalization
TODO Update version number {assigned::creator}
TODO Share with team {type::communication}
DONE Archive previous version {completed::auto}

{workflow::diagram-review}
```

This ensures diagrams go through proper review and version control processes.