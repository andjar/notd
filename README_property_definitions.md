# Property Definitions System

A rule-based system for automatically managing property internal status across your note-taking application.

## Overview

Instead of manually managing the internal status of individual property instances, the Property Definitions system lets you define rules that automatically apply to all properties with matching names. This provides consistent, automatic management of which properties should be internal (hidden) vs. public.

## Key Components

### 1. PropertyDefinitions Table
Central table that stores rules for property names:
- `name`: Property name to match (e.g., "debug", "internal", "system")
- `internal`: Whether properties with this name should be internal (0 or 1)
- `description`: Human-readable description of the property's purpose
- `auto_apply`: Whether to automatically apply this rule to existing properties

### 2. Automatic Application
- **New Properties**: Automatically checked against definitions when created
- **Existing Properties**: Can be updated in bulk when definitions change
- **API Integration**: Works seamlessly with existing property creation endpoints

### 3. Management Interfaces
- **Property Definitions Manager** (`property_definitions_manager.php`): Manage rules
- **Property Manager** (`property_manager.php`): View individual property instances

## Setup Instructions

### 1. Initialize the System
```bash
php setup_property_definitions.php
```

This will:
- Create the `PropertyDefinitions` table
- Insert common default definitions
- Apply definitions to existing properties

### 2. Access the Interfaces
- **Definitions Manager**: `/property_definitions_manager.php`
- **Individual Properties**: `/property_manager.php`

## How It Works

### Automatic Property Classification
When a property is created, the system:

1. **Checks Property Definitions**: Looks for a matching rule by property name
2. **Applies Rule**: Sets internal status based on the definition
3. **Falls Back to Default**: If no definition exists, defaults to public (internal=0)
4. **Respects Explicit Settings**: Manual internal flag settings override definitions

### Definition Priority
1. **Explicit Internal Flag**: Manual settings in API calls take highest priority
2. **Property Definitions**: Automatic rules based on property name
3. **Default**: Public/non-internal if no rule exists

## Common Property Definitions

The system comes with these pre-configured definitions:

| Property Name | Internal | Description |
|---------------|----------|-------------|
| `internal` | Yes | Properties that control note/page visibility |
| `debug` | Yes | Debug and development properties |
| `system` | Yes | System-generated properties |
| `_private` | Yes | Private properties (underscore prefix) |
| `metadata` | Yes | Metadata properties for internal use |

## Usage Examples

### Adding a New Definition
```php
// Via API
POST /api/property_definitions.php
{
    "name": "admin_note",
    "internal": 1,
    "description": "Administrative notes for staff only",
    "auto_apply": 1
}
```

### Applying Definitions to Existing Properties
```php
// Apply all definitions
GET /api/property_definitions.php?apply_all=1

// Apply specific definition
POST /api/property_definitions.php
{
    "action": "apply_definition",
    "name": "debug"
}
```

## Benefits

### 1. Consistency
- All properties with the same name have consistent internal status
- No need to manually set internal flag for each property instance

### 2. Automation
- New properties automatically classified based on rules
- Existing properties can be updated in bulk

### 3. Flexibility
- Add/remove/modify definitions at any time
- Choose whether to apply changes to existing properties
- Override definitions with explicit settings when needed

### 4. Visibility
- Clear view of which properties are defined as internal
- Statistics on property usage and classification
- Easy identification of undefined properties

## API Integration

### Properties API
The existing `/api/properties.php` endpoint now:
- Automatically checks property definitions when creating properties
- Sets internal status based on matching rules
- Respects explicit internal flags when provided

### Notes API
The `/api/notes.php` endpoint:
- Applies property definitions when parsing properties from note content
- Automatically classifies properties extracted from `{property::value}` syntax

## Management Features

### Definitions Manager
- **Add Definitions**: Create new rules for property names
- **Auto-Apply**: Choose whether to update existing properties
- **Quick Add**: One-click addition for undefined properties
- **Bulk Operations**: Apply all definitions at once

### Property Instance Manager
- **View All Properties**: See individual property instances
- **Override Definitions**: Manually adjust specific instances
- **Usage Statistics**: See how many notes/pages use each property

## Technical Details

### Database Schema
```sql
CREATE TABLE PropertyDefinitions (
    id INTEGER PRIMARY KEY,
    name TEXT UNIQUE NOT NULL,
    internal INTEGER NOT NULL DEFAULT 0,
    description TEXT,
    auto_apply INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### Helper Functions
- `determinePropertyInternalStatus()`: Check definitions for a property name
- `applyPropertyDefinitionsToExisting()`: Bulk apply definitions
- `getPropertyInternalStatusFromDefinition()`: Cached definition lookup

### Performance
- **Caching**: Definition lookups are cached to avoid repeated database queries
- **Batch Operations**: Bulk updates for applying definitions to existing properties
- **Indexed Queries**: Database indexes for fast property name lookups

## Migration from Manual Management

If you were previously using the individual property manager:

1. **Run Setup**: Execute `setup_property_definitions.php`
2. **Review Existing**: Check which properties are already marked as internal
3. **Create Definitions**: Add definitions for property patterns you want to maintain
4. **Apply Rules**: Use "Apply All Definitions" to update existing properties
5. **Future Properties**: Will automatically follow the new rules

## Best Practices

### 1. Naming Conventions
- Use consistent property names across your application
- Consider prefixes for related properties (e.g., `debug_`, `admin_`, `system_`)

### 2. Definition Management
- Add descriptions to document why properties should be internal
- Use auto-apply judiciously - disable for definitions you want to test first
- Regularly review undefined properties and add definitions as needed

### 3. Testing
- Test property definitions on a small set before applying to all properties
- Use the individual property manager to verify results
- Monitor API responses to ensure internal properties are properly hidden

## Troubleshooting

### Properties Not Being Set as Internal
1. Check if a property definition exists for the property name
2. Verify the definition has `internal = 1`
3. Ensure `auto_apply = 1` if you want it to affect new properties
4. Check if an explicit internal flag is overriding the definition

### Existing Properties Not Updated
1. Ensure the definition has `auto_apply = 1`
2. Use "Apply All Definitions" or apply the specific definition
3. Check for database transaction errors in logs

### Performance Issues
1. Property definition lookups are cached - restart application if needed
2. For large bulk operations, consider running during low-traffic periods
3. Monitor database performance during bulk property updates 