---
title: "Extensions"
weight: 25
---

notd features a powerful extension system that adds specialized functionality to your note-taking workflow. Each extension is designed to handle specific use cases while integrating seamlessly with the core system.

## Available Extensions

notd includes 8 built-in extensions:

### **Kanban Board** 📋
Visual task management with customizable boards, filters, and drag-and-drop functionality.

### **Excalidraw Editor** ✏️  
Integrated drawing and diagramming tool for visual thinking and sketching.

### **Attachment Dashboard** 📎
Centralized file management for organizing and accessing your attachments.

### **Math Notepad** 🧮
Mathematical notation support and calculation capabilities within your notes.

### **Pomodoro Timer** ⏲️
Built-in time management tool following the Pomodoro Technique.

### **RSS Handler** 📰
RSS feed integration for staying updated with external content sources.

### **Mail Handler** 📧
Email integration for capturing and organizing email-related notes.

### **Zen Mode** 🧘
Distraction-free writing environment for focused work sessions.

## Extension Architecture

Extensions in notd:
- **Self-contained** - Each extension has its own directory with all necessary files
- **Configurable** - JSON-based configuration for customization
- **Integrated** - Seamless integration with the core notd interface
- **Responsive** - Work across different screen sizes and devices

## Accessing Extensions

Extensions can be accessed through:
- **Extension menu** in the main interface
- **Direct URLs** to extension pages
- **Keyboard shortcuts** (where supported)
- **Context menus** for relevant content

## Extension Configuration

Most extensions support configuration through `config.json` files in their respective directories:

```
extensions/
├── kanban_board/
│   ├── config.json
│   ├── index.php
│   └── ...
├── excalidraw_editor/
│   ├── config.json  
│   ├── index.php
│   └── ...
```

## Integration with Core Features

Extensions integrate with notd's core features:

### **Properties Integration**
Extensions can read and write page/note properties:
```markdown
{board::project-alpha}  # Kanban board assignment
{timer::pomodoro}       # Pomodoro session tracking
{attachment::diagram}   # File type classification
```

### **Search Integration**
Extension content is searchable through the global search system.

### **Backlink Support**
Extensions can create and reference page links, contributing to the backlink system.

### **Template Support**
Extensions can use and create templates for consistent workflows.

## Getting Started with Extensions

1. **Explore the extensions** - Click through each one to understand capabilities
2. **Configure as needed** - Customize settings through config files
3. **Integrate with workflow** - Use properties and links to connect with your notes
4. **Create templates** - Build reusable patterns that incorporate extensions

Navigate through this section to learn about each extension in detail.