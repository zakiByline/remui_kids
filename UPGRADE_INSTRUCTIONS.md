# Moodle Upgrade Instructions - Emulator School Grants

## Quick Start

### 1. Navigate to Admin Upgrade Page
Open your browser and go to:
```
http://localhost/kodeit/iomad/admin/index.php
```

### 2. Click "Upgrade Moodle database now"
Moodle will automatically detect version **2025120502** and run the upgrade.

### 3. Watch for Success Messages
You should see two upgrade steps complete:
- ✅ **2025120501**: Creates emulator access table
- ✅ **2025120502**: Creates emulator school grants table

### 4. Click "Continue"
Once the upgrade finishes, your database is ready!

---

## What Was Created

### Two Database Tables:

#### 1. `mdl_theme_remui_kids_emulator_access`
Stores teacher/student access permissions at school and cohort levels.

#### 2. `mdl_theme_remui_kids_emulator_school_grants`
Stores which emulators are granted to which schools (new hierarchical control).

---

## How It Works

### Before (Old Behavior):
- All schools could see all emulators
- School managers controlled teacher/student access directly

### After (New Behavior):
1. **Global Admin** grants specific emulators to specific schools
2. **School Managers** only see granted emulators
3. **School Managers** control teacher/student access for their granted emulators

---

## Testing Instructions

### Test 1: Global Admin Access
1. Login as admin
2. Go to: **Site Administration → IOMAD Dashboard → Emulator Access Control**
3. Click **"School Grants"** tab
4. You'll see:
   - School dropdown selector at the top
   - Emulator cards in a grid layout (not a table!)
5. Select a school from the dropdown
6. **Two workflows available:**
   - **Quick Grant:** Toggle switches on/off to grant/deny emulators
   - **Configure Access:** Click "Access Control" button on any card to:
     - Jump to detailed access control page
     - Pre-selects that emulator
     - Configure teacher/student/cohort permissions
     - Return via "Back to School Grants" button
7. Changes save automatically with visual feedback

### Test 2: School Manager Access
1. Login as school manager
2. Go to: **School Manager Dashboard → Emulator Access**
3. Verify you only see granted emulators
4. Manage teacher/student access as normal

### Test 3: Student Access
1. Login as student
2. Check sidebar emulators
3. Only see emulators granted to your school AND enabled by your school manager

---

## Default Behavior: DENY ALL (Secure)

**By default, all access is DISABLED:**
- Schools must be explicitly granted emulators by global admin
- Teachers must be explicitly enabled by school manager
- Students must be explicitly enabled by school manager

```php
// File: lib/emulator_manager.php
const THEME_REMUI_KIDS_EMULATOR_DEFAULT_ALLOW = false;        // DENY ALL
const THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL = false;    // DENY ALL
```

**To allow all by default (less secure), change both to `true`:**
```php
const THEME_REMUI_KIDS_EMULATOR_DEFAULT_ALLOW = true;
const THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL = true;
```

---

## Troubleshooting

### Issue: Upgrade page shows "No database changes required"
**Solution:** The version was already installed. Check if tables exist in phpMyAdmin.

### Issue: School managers see no emulators
**Solutions:**
1. Grant emulators to the school via admin "School Grants" tab
2. OR set `THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL = true`

### Issue: "Cannot find table" error
**Solution:** Run the SQL manually:
```sql
-- See: db/emulator_school_grants_table.sql
```

---

## Files Modified

### Database Files:
- ✏️ `db/upgrade.php` - Added two upgrade blocks
- ✏️ `db/install.xml` - Added table definition
- ✏️ `version.php` - Updated to 2025120502

### Functionality Files:
- ✏️ `lib/emulator_manager.php` - Added grant functions
- ✏️ `admin/emulator_access.php` - Added "School Grants" tab
- ✏️ `ajax/emulator_access.php` - Added grant handler
- ✏️ `school_manager/emulator_access.php` - Filter by grants

---

## Support

For detailed documentation, see: `EMULATOR_SCHOOL_GRANTS_SETUP.md`

For issues, check:
1. Browser console (F12) for JavaScript errors
2. PHP error logs in `moodledata/` folder
3. Database tables in phpMyAdmin

