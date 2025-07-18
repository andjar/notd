---
title: "User Interface"
weight: 50
---

notd features a modern, responsive user interface built with Alpine.js and designed for efficient note-taking and knowledge management. This guide covers the interface components, interactions, and customization options.

## Interface Overview

### Main Layout

The notd interface consists of several key areas:

- **Sidebar** - Navigation, search, recent pages, favorites, and calendar
- **Main Content Area** - Note editing and viewing
- **Toolbar** - Action buttons and extension access
- **Status Bar** - Page information and connection status

### Responsive Design

notd adapts to different screen sizes:
- **Desktop** - Full sidebar with extended features
- **Tablet** - Collapsible sidebar, optimized touch targets
- **Mobile** - Hidden sidebar with hamburger menu access

## Sidebar Components

### Global Search
- **Search Input** - Type to search across all content
- **Real-time Results** - Results appear as you type
- **Result Categories** - Pages and notes clearly distinguished
- **Quick Actions** - Jump to results or create new pages

### Recent Pages
- **Automatic Tracking** - Recently viewed pages appear automatically
- **Visual Indicators** - Page types and modification dates
- **Quick Access** - One-click navigation to recent content

### Favorites
- **Starred Pages** - Pages marked with `{favorite::true}`
- **Category Grouping** - Organize favorites by category
- **Drag-and-Drop** - Reorder favorites (where supported)

### Calendar Widget
- **Visual Navigation** - Click dates to navigate to journal pages
- **Activity Indicators** - Dates with content are highlighted
- **Month/Year Navigation** - Navigate to any date quickly
- **Today Highlight** - Current date clearly marked

## Content Editor

### Markdown Editor
- **Live Preview** - See formatting as you type
- **Syntax Highlighting** - Code blocks and markdown syntax highlighted
- **Auto-completion** - Page links and properties auto-complete
- **Keyboard Shortcuts** - Standard markdown shortcuts supported

### Note Actions
Right-click any note for context menu:
- **Edit** - Modify note content
- **Delete** - Remove note (with confirmation)
- **Copy Link** - Copy internal link to note
- **Open in Extension** - Launch relevant extensions
- **Properties** - View/edit note properties

### Page Actions
Page-level actions in the toolbar:
- **New Note** - Add note to current page
- **Page Properties** - View/edit page metadata
- **Backlinks** - See which pages link here
- **Extensions** - Access relevant extensions
- **Export** - Download page content

## Interactive Elements

### Page Links
- **Auto-completion** - Type `[[` to see page suggestions
- **Visual Indicators** - Existing vs. new pages styled differently
- **Hover Preview** - Preview page content on hover (where supported)
- **Context Actions** - Right-click for link options

### Task Elements
- **Visual States** - Color-coded task status indicators
- **Checkboxes** - Click to toggle task completion
- **Progress Tracking** - Visual progress for task lists
- **Drag Reordering** - Reorder tasks within notes

### Property Editor
- **Inline Editing** - Edit properties directly in content
- **Property Suggestions** - Auto-complete common property names
- **Value Validation** - Validate property values where applicable
- **Bulk Operations** - Edit multiple properties at once

## Keyboard Shortcuts

### Global Shortcuts
- `Ctrl/Cmd + K` - Focus global search
- `Ctrl/Cmd + N` - Create new note
- `Ctrl/Cmd + S` - Save current changes
- `Ctrl/Cmd + /` - Toggle sidebar
- `Escape` - Close modals and panels

### Editor Shortcuts
- `Ctrl/Cmd + B` - Bold text
- `Ctrl/Cmd + I` - Italic text
- `Ctrl/Cmd + L` - Create page link
- `Tab` - Indent note (create sub-note)
- `Shift + Tab` - Outdent note
- `Ctrl/Cmd + Enter` - Create new note at same level

### Navigation Shortcuts
- `Ctrl/Cmd + 1-9` - Jump to recent pages
- `Ctrl/Cmd + G` - Go to today's journal page
- `Ctrl/Cmd + Shift + F` - Advanced search
- `Alt + Arrow Keys` - Navigate note hierarchy

## Themes and Customization

### Color Schemes
notd supports multiple color schemes:
- **Light Theme** - Clean, bright interface for day use
- **Dark Theme** - Eye-friendly dark mode for low-light environments
- **Auto Theme** - Follows system preferences
- **Custom Themes** - CSS customization support

### Typography
- **Font Selection** - Choose from system fonts or web fonts
- **Size Scaling** - Adjust text size for readability
- **Line Spacing** - Customize line height for comfort
- **Code Fonts** - Separate monospace font for code blocks

### Layout Options
- **Sidebar Width** - Adjustable sidebar size
- **Content Width** - Limit or expand content area
- **Density** - Compact or spacious interface modes
- **Animation** - Enable/disable interface animations

## Mobile Interface

### Touch Interactions
- **Tap to Edit** - Single tap to edit notes
- **Long Press** - Context menu access
- **Swipe Navigation** - Swipe between pages and sections
- **Pinch to Zoom** - Zoom content for readability

### Mobile-Specific Features
- **Hamburger Menu** - Access sidebar on mobile
- **Bottom Toolbar** - Quick actions at thumb reach
- **Voice Input** - Dictation support where available
- **Offline Sync** - Seamless offline/online transitions

## Extension UI Integration

### Extension Buttons
- **Toolbar Integration** - Extensions add buttons to main toolbar
- **Context Menus** - Extension actions in right-click menus
- **Sidebar Widgets** - Some extensions add sidebar components
- **Modal Windows** - Extensions can open in overlay windows

### Visual Consistency
- **Icon System** - Feather icons for consistent visual language
- **Color Coordination** - Extensions follow main theme colors
- **Typography** - Consistent fonts and sizing across extensions
- **Interaction Patterns** - Standard interaction behaviors

## Accessibility Features

### Keyboard Navigation
- **Tab Order** - Logical tab progression through interface
- **Focus Indicators** - Clear visual focus indicators
- **Skip Links** - Jump to main content areas
- **Shortcut Keys** - Comprehensive keyboard shortcuts

### Screen Reader Support
- **Semantic HTML** - Proper heading and landmark structure
- **ARIA Labels** - Descriptive labels for interactive elements
- **Alt Text** - Alternative text for images and icons
- **Status Announcements** - Important changes announced to screen readers

### Visual Accessibility
- **High Contrast** - Sufficient color contrast ratios
- **Large Text** - Support for increased text size
- **Reduced Motion** - Option to disable animations
- **Color Independence** - Information not dependent on color alone

## Performance Optimization

### Loading States
- **Progressive Loading** - Content loads incrementally
- **Skeleton Screens** - Loading placeholders for better UX
- **Lazy Loading** - Images and attachments load on demand
- **Caching** - Intelligent caching for faster navigation

### Responsiveness
- **Debounced Input** - Search and editing optimized for performance
- **Virtual Scrolling** - Handle large lists efficiently
- **Background Sync** - Save changes without blocking interface
- **Error Recovery** - Graceful handling of network issues

## Customization Tips

### Personal Workflow
1. **Organize Favorites** - Curate your most-used pages
2. **Configure Extensions** - Set up extensions for your workflow
3. **Customize Shortcuts** - Learn and use keyboard shortcuts
4. **Theme Selection** - Choose themes that work for your environment

### Team Collaboration
1. **Naming Conventions** - Establish consistent page/note naming
2. **Property Standards** - Define standard properties for your team
3. **Template Creation** - Build reusable templates for common patterns
4. **Extension Configuration** - Configure extensions for team workflows

The notd interface is designed to get out of your way and let you focus on your thoughts and ideas while providing powerful tools when you need them.