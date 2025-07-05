# Pomodoro Timer Extension

## Alpine.js Version
- Uses Alpine.js v3.14.9 (latest as of 2025) for reactive UI and state management.

## Configurable Note Template
- The note entry for each Pomodoro session is configurable via the `noteTemplate` field in `config.json`.
- Placeholders available:
  - `{noteTitle}`: Title of the session (e.g., "Pomodoro Work Session - 14:00")
  - `{duration}`: Duration in minutes
  - `{pomodoroType}`: Type of session (`work`, `short_break`, `long_break`)
  - `{customProperties}`: Additional properties entered by the user

Example `config.json`:
```json
{
  "workDurationMinutes": 25,
  "shortBreakDurationMinutes": 5,
  "longBreakDurationMinutes": 15,
  "pomodorosBeforeLongBreak": 4,
  "noteTemplate": "## {noteTitle}\n\n{duration}\n{pomodoro_type}\n{customProperties}"
}
```

## Property Formatting in Notes
- In notd, properties are expected as `{name::value}` pairs (with double colons and curly brackets).
- The extension will now output `duration` and `pomodoro_type` as structured properties:
  - `{duration::25}`
  - `{pomodoro_type::work}`
- Any custom properties entered in the textarea will also be formatted as `{name::value}` pairs.

## Example Output
```
## Pomodoro Work Session - 14:00

{duration::25}
{pomodoro_type::work}
{project::notd}
{tag::focus}
```

- You can customize the template in `config.json` to change the note format as needed. 

## Configurable Page and Cycle
- The page where session notes are added is configurable via the `pageTemplate` field in `config.json`.
- Supported placeholders in `pageTemplate`:
  - `<today>`: Current date in YYYY-MM-DD
  - `<year>`, `<month>`, `<day>`: Components of the current date
- Example: `<today>/pomodoro` will log to a subpage for each day.
- The Pomodoro cycle is configurable via the `cycle` array in `config.json` (e.g., `["work", "short_break", "work", "long_break"]`). The timer will auto-repeat this sequence.

## Additional Note Placeholders
- `{date}`: The current date (YYYY-MM-DD)

### Updated Example `config.json`:
```json
{
  "workDurationMinutes": 25,
  "shortBreakDurationMinutes": 5,
  "longBreakDurationMinutes": 15,
  "pomodorosBeforeLongBreak": 4,
  "noteTemplate": "## {noteTitle}\n\n{date}\n{duration}\n{pomodoroType}\n{customProperties}",
  "pageTemplate": "<today>/pomodoro",
  "cycle": ["work", "short_break", "work", "long_break"]
}
```

## Entering Custom Properties
- To add custom properties to your session note, enter them in the textarea at the bottom of the timer.
- **Format:** Each property should be on its own line, in the format `key:value` (no curly brackets needed).
- **Example (single property):**
  ```
  project:notd
  ```
- **Example (multiple properties):**
  ```
  project:notd
  tag:focus
  context:deep work
  ```
- These will be rendered in the note as `{project::notd}`, `{tag::focus}`, `{context::deep work}`. 