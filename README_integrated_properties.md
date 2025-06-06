# Integrated Property Management System

A seamlessly integrated rule-based system for automatically managing property internal status across your note-taking application.

## Overview

The Property Management system is now fully integrated into the existing database setup and codebase, providing automatic classification of properties without requiring separate setup scripts or schema files.

## Integration Points

### Database Schema (`db/schema.sql`)
The `PropertyDefinitions` table is now part of the main database schema:
- Automatically created during standard database setup
- Includes default property definitions for common internal properties
- Uses the same naming and structure conventions as other tables

### Database Setup (`db/setup_db.php`)
The existing setup script now handles:
- Creating the PropertyDefinitions table
- Inserting default property definitions
- Applying definitions to existing properties
- Providing setup completion feedback

### API Integration
All existing API endpoints seamlessly use the new system:
- **`api/properties.php`**: Automatically checks property definitions when creating properties
- **`api/notes.php`**: Applies property definitions when parsing note content
- **`api/property_definitions.php`**: Manages property definitions and bulk operations

## Quick Start

### 1. Database Setup
Run the standard database setup (same as before):
```bash
php db/setup_db.php
```

This will now automatically:
- ✅ Create all standard tables including PropertyDefinitions
- ✅ Insert default property definitions
- ✅ Apply definitions to any existing properties
- ✅ Provide feedback on what was updated

### 2. Access Management Interfaces
- **Property Definitions**: `property_definitions_manager.php`
- **Individual Properties**: `property_manager.php`

### 3. Automatic Behavior
From this point forward:
- New properties automatically follow defined rules
- Existing properties can be updated via the management interface
- No additional setup required

## Default Property Definitions

The system comes pre-configured with these property rules:

| Property Name | Internal | Description | Auto-Apply |
|---------------|----------|-------------|------------|
| `internal` | Yes | Properties that control note/page visibility | Yes |
| `debug` | Yes | Debug and development properties | Yes |
| `system` | Yes | System-generated properties | Yes |
| `_private` | Yes | Private properties (underscore prefix) | Yes |
| `metadata` | Yes | Metadata properties for internal use | Yes |

## File Structure

### Core Files
- `db/schema.sql` - Complete database schema including PropertyDefinitions
- `db/setup_db.php` - Enhanced setup script with property definitions support
- `api/property_auto_internal.php` - Helper functions for automatic classification
- `api/property_definitions.php` - API endpoints for managing definitions

### Management Interfaces
- `property_definitions_manager.php` - Rule-based property management
- `property_manager.php` - Individual property instance management

### Integration Files
- `api/properties.php` - Enhanced with automatic definition checking
- `api/notes.php` - Enhanced with automatic definition checking
- `api/property_triggers.php` - Property trigger system (unchanged)

## How Property Classification Works

### Priority Order:
1. **Explicit Internal Flag**: Manual settings in API calls (highest priority)
2. **Property Definitions**: Automatic rules based on property name
3. **Default**: Public/non-internal if no rule exists (lowest priority)

### Automatic Application:
- **New Properties**: Checked against definitions when created
- **Existing Properties**: Can be updated in bulk when definitions change
- **API Responses**: Internal properties hidden unless explicitly requested

## Benefits of Integration

### 1. Simplified Setup
- Single setup command handles everything
- No separate schema files to manage
- Consistent with existing project structure

### 2. Seamless Operation
- Works with existing API endpoints
- No changes needed to client code
- Backward compatible with existing properties

### 3. Maintainable Codebase
- Follows existing naming conventions
- Uses established patterns and structures
- Consolidated documentation and setup

### 4. Graceful Degradation
- System works even if PropertyDefinitions table doesn't exist
- Fallback to default behavior if definitions aren't available
- No breaking changes to existing functionality

## Migration from Previous Setup

If you previously installed the separate Property Definitions system:

1. **Run Standard Setup**: `php db/setup_db.php` (handles everything automatically)
2. **Remove Old Files**: The separate schema and setup files are no longer needed
3. **Continue Using**: All management interfaces work the same way

## Advanced Usage

### Adding Custom Definitions
```php
// Via API
POST /api/property_definitions.php
{
    "name": "admin_only",
    "internal": 1,
    "description": "Properties visible only to administrators",
    "auto_apply": 1
}
```

### Bulk Operations
```php
// Apply all definitions to existing properties
GET /api/property_definitions.php?apply_all=1
```

### Checking Property Status
```php
// Get properties including internal status
GET /api/properties.php?entity_type=note&entity_id=123&include_internal=1
```

## API Compatibility

### Existing Behavior Preserved
- All existing API calls work unchanged
- Internal properties still hidden by default
- `include_internal=true` parameter still works

### New Automatic Features
- Properties automatically classified based on definitions
- Bulk updates possible via management interface
- Consistent internal status across properties with same name

## Performance Considerations

### Optimizations
- **Cached Lookups**: Property definition checks are cached
- **Batch Operations**: Bulk updates use transactions
- **Indexed Queries**: Database indexes for fast lookups

### Best Practices
- Use consistent property naming conventions
- Add definitions for frequently used property patterns
- Review and apply definitions during low-traffic periods

## Troubleshooting

### Setup Issues
- **Problem**: PropertyDefinitions table not created
- **Solution**: Run `php db/setup_db.php` to apply latest schema

### Property Classification Issues
- **Problem**: Properties not being marked as internal
- **Solution**: Check if property definition exists and has correct settings

### Performance Issues
- **Problem**: Slow property operations
- **Solution**: Property definition cache is per-request; restart application if needed

## Future Enhancements

The integrated system provides a foundation for:
- Pattern-based property matching (e.g., wildcard rules)
- Role-based property visibility
- Property validation rules
- Custom property behaviors

This integrated approach ensures the Property Management system feels like a natural part of your existing codebase rather than an add-on component. 