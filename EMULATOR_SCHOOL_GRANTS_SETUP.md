# Emulator School Grants - Setup Guide

## Overview

This feature adds hierarchical access control for emulators, allowing global administrators to control which emulators are available to each school. School managers can only see and manage emulators that have been granted to their school by the global admin.

## Features

### For Global Administrators:
1. **School Grant Control** - A new "School Grants" tab in the admin emulator access page
2. **Grant/Deny Emulators** - Control which emulators each school can access
3. **Bulk Management** - View all schools and emulators in a matrix table
4. **Default Behavior** - Optionally grant all emulators by default (backward compatible)

### For School Managers:
1. **Filtered Catalog** - Only see emulators granted to their school
2. **Teacher/Student Access** - Manage teacher and student access within their school
3. **Cohort Overrides** - Set cohort-specific permissions for granted emulators

## Database Setup

### Automatic Installation via Moodle Upgrade (Recommended)

The database tables will be created automatically using Moodle's upgrade system:

1. **Navigate to Site Administration** in your browser:
   - Go to: `http://localhost/kodeit/iomad/admin/index.php`
   
2. **Moodle will detect the new version** and prompt you to upgrade:
   - Current version: `2025120406`
   - New version: `2025120502`
   
3. **Click "Upgrade Moodle database now"**
   - This will automatically create:
     - `mdl_theme_remui_kids_emulator_access` (if not exists)
     - `mdl_theme_remui_kids_emulator_school_grants` (new table)
   
4. **Wait for the upgrade to complete**
   - You'll see confirmation messages for each upgrade step
   - Version `2025120501`: Creates emulator_access table
   - Version `2025120502`: Creates emulator_school_grants table

### Manual Installation (Alternative)

If you prefer to run SQL directly (not recommended for production):

```sql
-- Note: Only use this if the automatic upgrade fails
-- The upgrade.php method is preferred for Moodle best practices

CREATE TABLE IF NOT EXISTS `mdl_theme_remui_kids_emulator_school_grants` (
  `id` bigint(10) NOT NULL AUTO_INCREMENT,
  `emulator` varchar(100) NOT NULL,
  `companyid` bigint(10) NOT NULL,
  `granted` tinyint(1) NOT NULL DEFAULT 1,
  `createdby` bigint(10) NOT NULL,
  `modifiedby` bigint(10) DEFAULT NULL,
  `timecreated` bigint(10) NOT NULL,
  `timemodified` bigint(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emulator_companyid` (`emulator`,`companyid`),
  KEY `emulator_idx` (`emulator`),
  KEY `companyid_idx` (`companyid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='School-level emulator grants';
```

### Configure Default Behavior

In `iomad/theme/remui_kids/lib/emulator_manager.php`, the default behavior is set to:

```php
const THEME_REMUI_KIDS_EMULATOR_DEFAULT_ALLOW = false;        // Default DISABLED
const THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL = false;    // Default DISABLED
```

**Default Security Model: DENY ALL**
- **`false`** (default): All access is DISABLED by default - must explicitly grant
- Schools must be explicitly granted emulators
- Teachers/students must be explicitly granted access
- More secure, explicit permission model

**Alternative: Allow All** (less secure)
- Change both constants to `true` for backward compatibility
- All emulators available to all schools unless explicitly denied

## Installation Steps

### Step 1: Run Moodle Upgrade

1. Open your browser and navigate to:
   ```
   http://localhost/kodeit/iomad/admin/index.php
   ```

2. Moodle will automatically detect the new version (2025120502) and display an upgrade notification

3. Click the **"Upgrade Moodle database now"** button

4. Wait for the upgrade process to complete. You should see:
   - ✅ Version 2025120501: Creates `theme_remui_kids_emulator_access` table
   - ✅ Version 2025120502: Creates `theme_remui_kids_emulator_school_grants` table

5. Click "Continue" when the upgrade finishes

### Step 2: Verify Installation

1. Open **phpMyAdmin** and check that these tables exist:
   - `mdl_theme_remui_kids_emulator_access`
   - `mdl_theme_remui_kids_emulator_school_grants`

2. Both tables should have the proper structure with indexes

### Step 3: Test as Global Admin

1. Navigate to: **Site Administration → IOMAD Dashboard → Emulator Access Control**
2. You should see two tabs: **"School Grants"** and **"Access Control"**
3. Click on **"School Grants"** tab
4. You'll see:
   - A **school dropdown selector** at the top
   - **Emulator cards** displayed in a grid layout
   - Each card shows:
     - Emulator icon with gradient background
     - Emulator name and description
     - Toggle switch to grant/deny access
     - Current status (Granted/Denied/Default)
5. Select a school from the dropdown
6. For each emulator card, you can:
   - **Toggle the switch** to grant/deny the emulator (saves automatically)
   - **Click "Access Control" button** to configure detailed permissions
7. When you click "Access Control":
   - Navigates to Access Control tab
   - Pre-selects that emulator automatically
   - Shows a "Back to School Grants" button
   - Configure school-wide access, cohort overrides, and teacher selection
8. Changes save automatically

### Step 4: Test as School Manager

1. Login as a school manager user
2. Navigate to: **School Manager Dashboard → Emulator Access**
3. You should only see emulators granted to your school
4. If you see all emulators, that's because `THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL = true`
5. Test managing teacher/student access for visible emulators

## How to Use

### For Global Administrators:

1. Navigate to **Site Administration → IOMAD Dashboard → Emulator Access Control**
2. Click on the **"School Grants"** tab
3. You'll see:
   - A **dropdown to select a school** (one school at a time for easier management)
   - **Visual emulator cards** displayed in a responsive grid
4. **Select a school** from the dropdown
5. For each emulator card, you'll see:
   - **Emulator icon** with colorful gradient background
   - **Name and description** of the emulator
   - **Current status** (Granted/Denied/Default)
   - **Toggle switch** to grant or deny access
   - **"Access Control" button** to configure detailed permissions
6. **Two Options:**
   - **Option A:** Toggle the switch to grant (ON) or deny (OFF) access
     - 🟢 **Switch ON** (Green) = Emulator is granted to this school
     - 🔴 **Switch OFF** (Gray) = Emulator is denied to this school
   - **Option B:** Click **"Access Control"** button to:
     - Jump to detailed access control settings
     - Configure school-wide teacher/student access
     - Set cohort-specific permissions
     - Manage individual teacher access
     - Return via "Back to School Grants" button
7. Changes save **automatically** when you toggle switches
8. "(Default)" label appears when using system default behavior

### For School Managers:

1. Navigate to **School Manager Dashboard → Emulator Access**
2. You'll only see emulators that have been granted to your school
3. If no emulators are available, a notice will appear asking you to contact the administrator
4. Manage teacher and student access for granted emulators
5. Set cohort-specific permissions as needed

## Access Control Flow

The access control works in the following hierarchy:

```
1. School Grants (Global Admin)
   ↓ (If granted to school)
2. School-Wide Access (School Manager)
   ↓ (Default for all users in school)
3. Cohort Overrides (School Manager)
   ↓ (Specific permissions for cohorts)
4. Individual User Check
   ↓ (Final access decision)
```

### Example Scenario:

**Global Admin:**
- Grants "Code Editor" to School A
- Denies "Scratch" to School A
- Grants "SQL Lab" to School A

**School A Manager sees:**
- Code Editor ✓
- SQL Lab ✓
- (Scratch is not visible)

**School A Manager can then:**
- Enable/disable Code Editor for teachers
- Enable/disable Code Editor for students
- Set cohort-specific permissions for Code Editor
- Same for SQL Lab

## Files Modified

### New Files:
- `db/emulator_school_grants_table.sql` - SQL reference (for manual installation only)
- `EMULATOR_SCHOOL_GRANTS_SETUP.md` - This setup guide

### Modified Files (Database):
- `db/upgrade.php` - Added upgrade steps for versions 2025120501 and 2025120502
- `db/install.xml` - Added table definitions for both emulator tables
- `version.php` - Updated version to 2025120502

### Modified Files (Functionality):
- `lib/emulator_manager.php` - Added grant management functions
- `admin/emulator_access.php` - Added School Grants tab with matrix UI
- `ajax/emulator_access.php` - Added grant action handler and permissions
- `school_manager/emulator_access.php` - Filter by granted emulators only

## API Functions

### Check if emulator is granted to school:
```php
theme_remui_kids_is_emulator_granted_to_school('code_editor', $companyid);
```

### Get all granted emulators for a school:
```php
$emulators = theme_remui_kids_get_granted_emulators_for_school($companyid);
```

### Update grant status:
```php
theme_remui_kids_update_emulator_school_grant('code_editor', $companyid, true, $userid);
```

### Build grant matrix (for admin UI):
```php
$matrix = theme_remui_kids_build_school_grant_matrix();
```

## Troubleshooting

### School managers can't see any emulators:

1. Check if the database table was created successfully
2. Verify that `THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL` is set to `true` (for backward compatibility)
3. OR grant emulators to the school explicitly via the admin interface

### Changes not saving:

1. Check browser console for JavaScript errors
2. Verify sesskey is valid
3. Check PHP error logs for server-side errors
4. Ensure the AJAX endpoint has proper permissions

### Global admin can't access grants tab:

1. Verify user has `moodle/site:config` capability
2. Clear browser cache and try again
3. Check if the view parameter is being passed correctly in the URL

## Security Notes

- Only global administrators can manage school-level grants
- School managers can only manage access for their assigned school
- School managers cannot see or access the grants tab
- All changes require a valid sesskey (CSRF protection)
- Database constraints prevent duplicate grant records

## Support

For issues or questions, please contact your system administrator or development team.

