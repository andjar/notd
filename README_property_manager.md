# Property Manager

A web interface for managing the internal status of properties in your note-taking system.

## Features

- **View All Properties**: See all properties in your system organized by name and value
- **Count Statistics**: View how many notes and pages use each property
- **Internal Status Management**: Toggle internal status for properties with checkboxes
- **Bulk Operations**: Select/deselect all properties at once
- **Real-time Updates**: Changes are saved to the database and trigger appropriate handlers

## Usage

1. **Access the Interface**: Navigate to `property_manager.php` in your web browser
2. **View Properties**: The table shows all properties grouped by name, with their values and usage counts
3. **Toggle Internal Status**: Use the checkboxes in the "Internal" column to mark properties as internal
4. **Bulk Operations**: Use "Select All Internal" or "Deselect All" buttons for quick changes
5. **Save Changes**: Click "Save Changes" to apply your modifications

## Table Columns

- **Property Name**: The name of the property (e.g., "status", "tag", "priority")
- **Value**: The specific value of the property (e.g., "TODO", "important", "high")
- **Notes**: Number of notes that have this property
- **Pages**: Number of pages that have this property  
- **Total**: Total usage count
- **Internal**: Checkbox to mark the property as internal (hidden from normal views)

## What "Internal" Means

When a property is marked as internal:
- It may be hidden from normal API responses (unless `include_internal=true` is specified)
- Special properties like "internal" can trigger automatic behavior (e.g., hiding notes)
- It helps separate system/metadata properties from user-visible properties

## Safety Features

- Confirmation dialog when marking many properties as internal
- Transaction-based updates (all changes succeed or none do)
- Error handling and user feedback
- Automatic trigger execution for special properties

## Technical Details

The interface integrates with:
- Property trigger system for special handling of certain properties
- Database transactions for data consistency
- Existing API structure for seamless integration

## Database Schema

Works with the existing `Properties` table:
- `id`: Unique identifier
- `name`: Property name
- `value`: Property value
- `internal`: Boolean flag (0 or 1)
- `note_id`/`page_id`: Foreign keys to associated entities 