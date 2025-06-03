# Notd Application Roadmap

This document outlines the planned features and improvements for the `notd` application. Our development approach considers the constraints of the phpdesktop environment, ensuring all backend interactions utilize GET or POST (with method override for PUT/DELETE operations) requests.

## Proposed Features

This section lists features planned for upcoming development. Features are grouped by category.

### Core Note-Taking Enhancements
- **Note Templates:** Allow users to create and use templates for new notes or pages.
- **Note Pinning/Favoriting:** Enable users to pin important notes within a page or add them to a global "Favorites" list.
- **Advanced Search Operators:** Enhance search with operators like `tag:`, `created:YYYY-MM-DD`, `in:page_name`.
- **Auto-suggsetions:** Get helpful suggestions for links and property values

### Organization & Navigation
- **Tagging System:** Implement a dedicated tagging system (e.g., `Tags`, `NoteTags`, `PageTags` tables) for notes and pages, with UI for managing and filtering by tags.
- **Customizable Page Aliases/Pretty URLs:** Allow users to define user-friendly aliases for page URLs, utilizing the existing `alias` field in the `Pages` table.
- **Saved Searches/Filters:** Enable users to save complex search queries or filter combinations for quick reuse.
- **Calendar View Enhancements:** Improve the calendar widget to visually indicate days with notes (e.g., linked by creation date or a `date::` property) and allow easier navigation to those notes/pages.

### Export & Import
- **Full Page Export (Markdown with Frontmatter):** Allow users to export an entire page and its notes as a single Markdown file, with page properties as YAML frontmatter.
- **Selective Note Export (Markdown):** Enable users to select and export one or more notes as a Markdown file.
- **Download Database Backup (SQLite):** Provide an option to download a backup of the entire application database (SQLite file).

### UI/UX Improvements
- **Theme Customization:** Offer Light/Dark mode options, and potentially a way for users to input custom CSS snippets.
- **Improved Mobile/Responsive View:** Enhance the application's layout and usability on smaller screens.
- **Command Palette:** Implement a command palette (e.g., Ctrl+K) for quick access to common actions and navigation.
- **Focus Mode / Distraction-Free Writing:** Add a mode that hides non-essential UI elements to allow users to focus on note content.

## Future Considerations

This section lists features that are being considered for later stages of development or require more research.

- **Simple Note Version History / Undo-Redo:** Basic versioning for notes or enhanced undo/redo functionality beyond typical browser behavior.
- **Import from Database Backup (SQLite):** Allow restoring the application state from an SQLite backup file (requires careful consideration of phpdesktop limitations for file uploads).
- **Keyboard Shortcut Customization:** Allow users to view and potentially customize keyboard shortcuts.
- **Read-only Sharing of Pages:** Generate shareable, read-only links for individual pages.
- **Enhanced Attachment Management:** Previews for more file types, gallery view for image attachments within a note.
- **Recurring Tasks/Notes:** Support for notes or tasks that repeat on a schedule.

## Completed Features

*(This section will be updated as features are implemented.)*

---

We welcome feedback and contributions on this roadmap!
