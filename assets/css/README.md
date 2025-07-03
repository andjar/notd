# Modular CSS Structure

This directory contains a modular CSS architecture for the outliner application, making it easier to maintain and organize styles.

## Structure

```
assets/css/
├── base/                    # Foundation styles
│   ├── variables.css        # CSS custom properties and variables
│   └── reset.css           # CSS reset and base typography
├── layout/                  # Layout and grid systems
│   ├── grid.css            # Main grid layout and responsive breakpoints
│   └── sidebar.css         # Sidebar layout and styling
├── components/              # Reusable UI components
│   ├── notes.css           # Notes/outliner system
│   ├── tasks.css           # Task/todo system
│   ├── splash.css          # Splash screen
│   ├── buttons.css         # Button components (TODO)
│   ├── modals.css          # Modal dialogs (TODO)
│   ├── forms.css           # Form elements (TODO)
│   ├── search.css          # Search functionality (TODO)
│   ├── properties.css      # Page properties (TODO)
│   ├── pills.css           # Pill/tag components (TODO)
│   ├── transclusions.css   # Transclusion system (TODO)
│   └── encryption.css      # Encryption modals (TODO)
├── utilities/               # Helper classes and utilities
│   ├── animations.css      # CSS animations (TODO)
│   ├── helpers.css         # Utility classes (TODO)
│   └── responsive.css      # Responsive utilities (TODO)
├── themes/                  # Theme-specific overrides
│   ├── dark.css            # Dark theme (TODO)
│   └── light.css           # Light theme (TODO)
├── style_modular.css       # Main file that imports all components
├── style_clean.css         # Original monolithic file (legacy)
└── style.css               # Original file (legacy)
```

## Usage

### For Development
Use `style_modular.css` as the main CSS file. It imports all the modular components:

```html
<link rel="stylesheet" href="assets/css/style_modular.css">
```

### For Production
For production, you may want to concatenate all the modular files into a single file for better performance.

## Benefits

1. **Maintainability**: Each component is in its own file, making it easier to find and modify specific styles
2. **Reusability**: Components can be easily reused across different parts of the application
3. **Organization**: Clear separation of concerns with logical grouping
4. **Scalability**: Easy to add new components without affecting existing code
5. **Team Collaboration**: Multiple developers can work on different components simultaneously

## Adding New Components

1. Create a new file in the appropriate directory (e.g., `components/buttons.css`)
2. Add the import to `style_modular.css`:
   ```css
   @import url('components/buttons.css');
   ```
3. Uncomment the import line in `style_modular.css`

## Migration from Monolithic CSS

The original `style_clean.css` file has been broken down into modular components. To migrate:

1. Replace references to `style_clean.css` with `style_modular.css`
2. Gradually move remaining styles from `style_clean.css` to appropriate modular files
3. Remove `style_clean.css` once migration is complete

## Best Practices

1. **Keep components focused**: Each component file should handle one specific UI element or system
2. **Use CSS custom properties**: Leverage the variables defined in `base/variables.css`
3. **Follow naming conventions**: Use consistent class naming (BEM methodology recommended)
4. **Document complex styles**: Add comments for non-obvious CSS rules
5. **Test responsiveness**: Ensure components work across different screen sizes

## Component Guidelines

### Base (`base/`)
- **variables.css**: Define all CSS custom properties here
- **reset.css**: CSS reset, base typography, and fundamental styles

### Layout (`layout/`)
- **grid.css**: Main layout grid, responsive breakpoints
- **sidebar.css**: Sidebar positioning, transitions, responsive behavior

### Components (`components/`)
- Each file should be focused on a single component or system
- Include all states (hover, focus, active, disabled)
- Include responsive behavior if needed
- Use CSS custom properties for theming

### Utilities (`utilities/`)
- Helper classes for common patterns
- Animations and transitions
- Responsive utilities

### Themes (`themes/`)
- Theme-specific overrides
- Dark/light mode variations
- Custom theme implementations 