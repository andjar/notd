# CSS structure

Plain CSS, no build step. `main.css` imports every file below; pages load the
active theme first (via `theme_loader.php`), then `main.css`.

```
assets/css/
├── main.css          # imports everything below
├── theme_loader.php  # emits <link> tags for the active theme (ACTIVE_THEME)
├── base/             # variables.css (custom properties), reset.css
├── layout/           # grid.css, sidebar.css
├── components/       # one file per UI part: notes, tasks, modals, calendar, ...
├── utilities/        # helper classes
└── themes/           # one file per theme; each only sets custom properties
```

## Adding a component

1. Create `components/<name>.css`, using the custom properties from
   `base/variables.css` for colours and spacing so every theme applies.
2. Add `@import url('components/<name>.css');` to `main.css`.

## Adding a theme

Copy `themes/default.css` to `themes/<name>.css`, change the custom property
values, and set `ACTIVE_THEME` to `<name>` in `config.local.php`.
