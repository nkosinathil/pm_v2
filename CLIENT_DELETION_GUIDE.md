# Client Deletion Functionality - Usage Guide

## Overview
The client deletion functionality has been implemented to solve foreign key constraint violations when deleting clients. The system now properly handles all related records to prevent database errors.

## Accessing the Delete Client Feature

1. **From Dashboard**: 
   - Navigate to the main dashboard (`dashboard.php`)
   - Find the case associated with the client you want to delete
   - Click the three-dot menu (⋮) in the "Actions" column
   - Select "Delete Client" from the dropdown menu

## Deletion Process

### Step 1: Preview
- The system will show a preview of all records that will be affected
- Statistics are displayed for each table:
  - **Consent Forms** - Manual deletion required
  - **Cases** - Manual deletion required  
  - **Credit Topups** - Auto-cascaded deletion
  - **Credit Usage** - Auto-cascaded deletion
  - **Invoices** - Auto-cascaded deletion

### Step 2: Confirmation
- Review the preview carefully
- Click "Confirm Deletion" if you want to proceed
- The system will ask for final confirmation via JavaScript dialog

### Step 3: Execution
- Records are deleted in the correct order to avoid foreign key violations:
  1. `consent_forms` (manual deletion)
  2. `cases` (manual deletion)
  3. `clients` (cascades to credit_topups, credit_usage, invoices)

## Safety Features

- **Transaction Safety**: All deletions occur within a database transaction
- **Rollback Protection**: If any step fails, all changes are rolled back
- **Audit Logging**: All deletions are logged with user and timestamp
- **Preview Mode**: Shows exactly what will be deleted before execution
- **Multiple Confirmations**: Requires user confirmation at multiple steps

## Database Schema Handled

The system correctly handles these foreign key relationships:

```sql
-- Manual deletion required (no CASCADE)
consent_forms.client_code -> clients.client_code
cases.client_code -> clients.client_code

-- Automatic cascade deletion
credit_topups.client_id -> clients.id (ON DELETE CASCADE)
credit_usage.client_id -> clients.id (ON DELETE CASCADE)  
invoices.client_id -> clients.id (ON DELETE CASCADE)
```

## Error Handling

- **Invalid Client ID**: System validates client exists before proceeding
- **Foreign Key Violations**: Prevented by proper deletion order
- **Database Errors**: Caught and displayed to user with rollback
- **Missing Records**: Gracefully handled with appropriate messaging

## Success Feedback

After successful deletion, the system displays:
- Confirmation message with deletion statistics
- Number of records deleted from each table
- Automatic redirect to dashboard after 5 seconds

## Technical Notes

- Uses PDO prepared statements for security
- Implements proper transaction management
- Follows existing codebase patterns and styling
- Mobile-responsive design
- Maintains audit trail for compliance

## Files Modified

- `delete-client.php` - New file with complete deletion functionality
- `dashboard.php` - Added client ID to query and delete menu option