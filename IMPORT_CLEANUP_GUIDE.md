# Import Registrations - Dry Run & Cleanup Guide

## New Features Added

### 1. Dry Run Mode

Test imports without saving to database:

```bash
php artisan import:registrations "Clark_TN_2025.xlsx" --sheet="Overall Delegates" --event-id=2 --dry-run
```

This will:

-   Show preview of first 5 records
-   Count how many records would be imported
-   Show what would happen (no actual database changes)

### 2. Cleanup Null Users Command

A new command to safely remove users with null names.

## How to Revert Null Users

### Step 1: Preview what will be deleted (DRY RUN)

```bash
php artisan users:cleanup-null --dry-run
```

### Step 2: Preview with filters (recommended)

If you know when the bad import happened:

```bash
# Only show users created after a specific time
php artisan users:cleanup-null --dry-run --created-after="2025-11-07 10:00:00"

# Only show users attached to event ID 2
php artisan users:cleanup-null --dry-run --event-id=2

# Combine both filters
php artisan users:cleanup-null --dry-run --event-id=2 --created-after="2025-11-07 10:00:00"
```

### Step 3: Actually delete (CAREFUL!)

Once you're confident, remove the `--dry-run` flag:

```bash
# Delete all null users
php artisan users:cleanup-null

# Or with filters
php artisan users:cleanup-null --event-id=2 --created-after="2025-11-07 10:00:00"
```

The command will ask for confirmation before deleting.

## Recommended Workflow for Future Imports

### Step 1: Always test with dry-run first

```bash
php artisan import:registrations "your-file.xlsx" --sheet="Your Sheet" --event-id=2 --dry-run
```

### Step 2: Review the preview output

Check that:

-   Names are populated correctly
-   Emails look valid
-   Other fields have data
-   Count matches expectations

### Step 3: Run actual import

```bash
php artisan import:registrations "your-file.xlsx" --sheet="Your Sheet" --event-id=2
```

## Quick Delete Recent Null Users from Event 2

If you just imported bad data to event 2, run:

```bash
# Preview first
php artisan users:cleanup-null --dry-run --event-id=2 --created-after="2025-11-07 00:00:00"

# Then delete
php artisan users:cleanup-null --event-id=2 --created-after="2025-11-07 00:00:00"
```

## Manual SQL Query (Advanced)

If you prefer SQL, you can check null users:

```sql
SELECT id, email, created_at, role
FROM users
WHERE role = 'user'
  AND first_name IS NULL
  AND last_name IS NULL
ORDER BY created_at DESC;
```

To delete them manually:

```sql
DELETE FROM users
WHERE role = 'user'
  AND first_name IS NULL
  AND last_name IS NULL
  AND created_at > '2025-11-07 00:00:00';
```

## Troubleshooting

### Why were users created with null values?

Possible reasons:

1. Sheet headers don't match the expected format
2. Data is in different columns than expected
3. Headers have extra spaces or special characters

### How to verify before import?

Always use `--dry-run` first and check the preview output!

### The cleanup command isn't finding users

Make sure:

-   Users have `role = 'user'`
-   Both first_name AND last_name are NULL
-   Use correct date format: `Y-m-d H:i:s` (e.g., "2025-11-07 14:30:00")
