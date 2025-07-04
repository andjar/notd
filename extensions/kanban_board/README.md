# Kanban Board Extension – Configuration Guide

This Kanban board extension for notd is configurable via a `config.json` file. This file allows you to define multiple boards, each with custom filters, and set the icon for the extension.

## Location

The configuration file is located at:

```
extensions/kanban_board/config.json
```

## Structure

The config file is a JSON object with the following structure:

```json
{
  "featherIcon": "trello",
  "boards": [
    {
      "id": "all_tasks",
      "label": "All Tasks",
      "filters": []
    },
    {
      "id": "work_tasks",
      "label": "Work Tasks",
      "filters": [
        {"name": "type", "value": "work"}
      ]
    }
    // ... more boards ...
  ]
}
```

### Top-Level Fields

- **featherIcon**: *(string)*
  - The [Feather icon](https://feathericons.com/) name to use for the extension (e.g., `trello`, `list`, `check-square`).
- **boards**: *(array)*
  - An array of board definitions. Each board appears as a selectable option in the Kanban UI.

### Board Object Fields

Each board object in the `boards` array has the following fields:

- **id**: *(string, required)*
  - Unique identifier for the board. Used internally.
- **label**: *(string, required)*
  - Human-readable name shown in the board selector dropdown.
- **filters**: *(array, optional)*
  - An array of filter objects. Each filter restricts which tasks appear on this board.
  - If empty or omitted, all tasks are shown (subject to Kanban status columns).

#### Filter Object Fields

Each filter object has:

- **name**: *(string, required)*
  - The property name to filter by (e.g., `type`, `priority`).
- **value**: *(string, required)*
  - The value that the property must match for a task to be included on this board.

## Example

```json
{
  "featherIcon": "trello",
  "boards": [
    {
      "id": "all_tasks",
      "label": "All Tasks",
      "filters": []
    },
    {
      "id": "work_tasks",
      "label": "Work Tasks",
      "filters": [
        {"name": "type", "value": "work"}
      ]
    },
    {
      "id": "personal_tasks",
      "label": "Personal Tasks",
      "filters": [
        {"name": "type", "value": "personal"}
      ]
    },
    {
      "id": "urgent_tasks",
      "label": "Urgent Tasks",
      "filters": [
        {"name": "priority", "value": "high"}
      ]
    }
  ]
}
```

## How Filters Work

- Each board can have zero or more filters.
- A task will appear on a board **only if it matches all filters** for that board.
- Filters check both the task's own properties and its parent page's properties.
- Example: If a board has `{ "name": "type", "value": "work" }`, only tasks with `type=work` (either on the note or its parent page) will be shown.

## Adding or Editing Boards

1. Open `extensions/kanban_board/config.json` in a text editor.
2. Add a new object to the `boards` array, or edit an existing one.
3. Save the file and reload the Kanban board in your browser.

## Changing the Icon

- Set the `featherIcon` field to any valid [Feather icon name](https://feathericons.com/).

## Notes

- The Kanban board columns (e.g., TODO, DOING, DONE) are configured elsewhere (see `TASK_STATES` in your PHP config if you wish to customize columns).
- Filters are case-sensitive and must match the property values exactly.
- If no boards are defined, the Kanban board will show an error.

## Troubleshooting

- If you see "No boards configured" or "Configuration for board ... not found", check your `config.json` for syntax errors or missing fields.
- After editing `config.json`, reload the Kanban board page to see changes.

---

For further customization, see the main documentation or source code in `kanban.php`. 