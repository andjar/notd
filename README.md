# notd - A Note-Taking Application

**notd** is a flexible note-taking application designed for organizing thoughts, tasks, and information efficiently. It combines rich text editing with powerful organizational features, allowing users to manage notes within pages, customize them with properties, and much more.

## Main Features

*   **Versatile Note-Taking:** Create and manage notes with rich content capabilities. Notes are organized within pages and can be nested to create hierarchical structures.
*   **Page Management:** Organize your notes into distinct pages. Create new pages easily, rename them, and navigate through your collection. Special handling for 'Journal' pages (e.g., daily logs named `YYYY-MM-DD` or "journal").
*   **Properties System:** Enhance notes and pages with custom metadata using a flexible key-value properties system. This allows for advanced filtering, organization, and automation (e.g., `status: todo`, `priority: high`). Some properties are managed internally by the system.
*   **File Attachments:** Attach relevant files directly to your notes, keeping all related information in one place.
*   **Global Search:** Quickly find information across all your notes with a comprehensive global search feature.
*   **Note Templates:** Speed up note creation for recurring formats using predefined or custom note templates (e.g., meeting notes, bookmarks).
*   **Calendar Integration:** A calendar widget provides a visual way to navigate notes, potentially linking to notes by creation date or specific date properties.
*   **Backlinks:** Discover connections between your notes and pages through a backlinks panel, showing which other notes or pages reference the current one.
*   **Markdown Support:** Notes can be written using Markdown for easy formatting.
*   **Client-Side Rendering & API Backend:** Features a responsive JavaScript-driven frontend that interacts with a robust PHP backend API for data management.

## Plan Forward / Roadmap

The `notd` application is continuously evolving. Key areas of future development include:

### Core Note-Taking Enhancements
*   ~~**Note Templates:** Expanding template functionality.~~
*   ~~**Note Pinning/Favoriting:** For quick access to important notes.~~
*   **Advanced Search Operators:** More powerful search capabilities (e.g., `tag:`, `created:`, `in:`).
*   **Auto-suggestions:** For ~~links~~ and property values.

### Organization & Navigation
*   ~~**Tagging System:** A dedicated system for tagging notes and pages.~~
*   **Customizable Page Aliases/Pretty URLs:** User-friendly URLs for pages.
*   **Saved Searches/Filters:** Save and reuse complex search queries.
*   **Calendar View Enhancements:** Better integration of notes with the calendar.

### Export & Import
*   **Full Page Export (Markdown with Frontmatter):** Export entire pages.
*   **Selective Note Export (Markdown):** Export individual notes.
*   **Database Backup/Import (SQLite):** For data safety and portability.

### UI/UX Improvements
*   ~~**Theme Customization:** More theme options (e.g., Light/Dark modes).~~
*   **Improved Mobile/Responsive View:** Better usability on small screens.
*   **Command Palette:** Quick access to commands.
*   **Focus Mode:** Distraction-free writing environment.

For a more detailed list of planned features and future considerations, please refer to the [ROADMAP.md](ROADMAP.md) file.

## Technical Implementation

This section provides an overview of the technical architecture and components of the `notd` application.

### Architecture Overview

`notd` employs a client-server architecture:

*   **Frontend (Client-Side):** A dynamic user interface built with HTML, CSS, and vanilla JavaScript. It handles user interactions, renders content, and communicates with the backend via API calls. The application is designed with considerations for a potential phpdesktop environment, meaning backend interactions primarily use GET/POST requests.
*   **Backend (Server-Side):** A PHP-based API serves as the backbone, handling business logic, data processing, and database interactions.
*   **Database:** SQLite is used as the database engine, providing a lightweight, file-based storage solution.

### Backend

The backend is composed of PHP scripts located primarily in the `api/` directory. Key components include:

*   **`api/db_connect.php`:** Manages the database connection and initial setup.
*   **`api/notes.php`:** Handles CRUD (Create, Read, Update, Delete) operations for notes.
*   **`api/pages.php`:** Manages CRUD operations for pages.
*   **`api/properties.php`:** Deals with managing properties for notes and pages.
*   **`api/attachments.php`:** Handles file uploads and attachment management.
*   **`api/search.php`:** Powers the global search functionality.
*   **`api/pattern_processor.php` & `api/property_parser.php`:** Involved in parsing special patterns within note content to automatically extract or apply properties.

### Frontend

The frontend logic resides in the `assets/js/` directory:

*   **`assets/js/app.js`:** The main application script that initializes the app, manages overall state, and coordinates different UI modules.
*   **`assets/js/api_client.js`:** Contains functions for making asynchronous requests to the PHP backend API.
*   **`assets/js/ui.js`:** Responsible for rendering UI components, DOM manipulations, and managing user interface elements.
*   **`assets/js/app/state.js`:** Manages the client-side state of the application, such as the current page, loaded notes, and user preferences.
*   **`assets/js/app/page-loader.js`:** Handles the logic for fetching and displaying page content and notes.
*   **`assets/js/app/note-actions.js`:** Contains functions related to note manipulation, such as saving, deleting, and handling keyboard interactions within notes.
*   Libraries:
    *   **Feather Icons:** For iconography.
    *   **Marked.js:** For rendering Markdown in notes.
    *   **Sortable.js:** Used for drag-and-drop reordering of notes.

### Database

*   **Schema:** The database structure is defined in `db/schema.sql`. Key tables include:
    *   `Pages`: Stores information about pages.
    *   `Notes`: Contains the content and structure of notes, linked to pages and potentially parent notes.
    *   `Properties`: A flexible table for storing key-value metadata for both notes and pages.
    *   `Attachments`: Manages file attachments linked to notes.
    *   `PropertyDefinitions`: Defines known property names and their behavior (e.g., if they are internal).
*   **Setup:** The database is initialized by `db/setup_db.php` (called from `api/db_connect.php` on first run). This includes creating the schema and populating initial data, such as welcome notes from `assets/template/page/welcome_notes.json`.

## Getting Started / Installation

To run the `notd` application, you'll typically need a web server environment with PHP and SQLite support.

1.  **Web Server:** Configure a web server (like Apache or Nginx) to serve the application files. Ensure PHP is correctly installed and configured.
2.  **PHP:** PHP version 7.4 or higher is recommended. The `pdo_sqlite` extension must be enabled for database connectivity.
3.  **Permissions:** Ensure the web server has write permissions to the `db/` directory (or wherever `DB_PATH` in `config.php` points) to create and write to the SQLite database file (e.g., `notd.sqlite`).
4.  **Configuration:**
    *   Copy `config.php.example` to `config.php` if it exists, or ensure `config.php` is present.
    *   Modify `config.php` to set `DB_PATH` if you want the database to be stored in a custom location. The default is usually within the `db/` directory.
5.  **Access:** Open the application in your web browser by navigating to its URL on your server.

The application is also designed with [phpdesktop](https://github.com/cztomczak/phpdesktop) in mind, which allows it to be packaged as a standalone desktop application. If using a phpdesktop build, refer to its specific instructions for launching.

## Contributing

Contributions to `notd` are welcome! If you have ideas for new features, bug fixes, or improvements, please consider the following:

1.  **Roadmap:** Check the [ROADMAP.md](ROADMAP.md) for planned features and areas where help might be needed.
2.  **Issues:** Feel free to open an issue on the project's issue tracker to discuss proposed changes or report bugs.
3.  **Pull Requests:** If you'd like to submit code, please fork the repository, create a new branch for your changes, and then open a pull request. Ensure your code aligns with the existing style and structure.

We appreciate your help in making `notd` better!
