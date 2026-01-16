# Teacher Emulator Access - Individual Selection

## Overview

This feature allows school managers to grant emulator access to individual teachers, since teachers are typically not organized in cohorts.

## Problem Statement

**Original Issue:**
- The system only allowed cohort-based access control
- Teachers are NOT typically in cohorts (unlike students)
- School managers couldn't selectively grant emulator access to specific teachers

**Solution:**
- Added individual teacher selection for emulator access
- School managers can now toggle access for each teacher independently
- Access is managed per emulator, per teacher, per school

---

## Features

### For School Managers:

1. **Individual Teacher Selection**
   - View all teachers in your school
   - Toggle emulator access for each teacher individually
   - See teacher's full name and email
   - Real-time status indicators (Enabled/Disabled)

2. **Granular Control**
   - Control access per emulator, per teacher
   - Changes save automatically
   - **Default: All access is DISABLED** (must explicitly grant access)

3. **Visual Interface**
   - List view of all teachers
   - Toggle switches for each teacher
   - Status badges (Enabled/Disabled)
   - Loading indicators

---

## Database Schema

### New Table: `mdl_theme_remui_kids_teacher_emulator`

```sql
CREATE TABLE `mdl_theme_remui_kids_teacher_emulator` (
  `id` bigint(10) NOT NULL AUTO_INCREMENT,
  `teacherid` bigint(10) NOT NULL,           -- Teacher user ID
  `companyid` bigint(10) NOT NULL,           -- School ID
  `emulator` varchar(100) NOT NULL,          -- Emulator slug
  `allowed` tinyint(1) NOT NULL DEFAULT 1,   -- 1=allowed, 0=denied
  `createdby` bigint(10) NOT NULL,
  `modifiedby` bigint(10) DEFAULT NULL,
  `timecreated` bigint(10) NOT NULL,
  `timemodified` bigint(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_emulator_unique` (`teacherid`,`companyid`,`emulator`)
);
```

**Key Points:**
- Unique constraint: One record per teacher-company-emulator combination
- `allowed`: 1 = granted, 0 = denied
- **If no record exists = default DISABLED** (must explicitly grant access)

---

## Installation Steps

### Step 1: Run Moodle Upgrade

1. Navigate to: `http://localhost/kodeit/iomad/admin/index.php`
2. Moodle will detect version `2025120503`
3. Click **"Upgrade Moodle database now"**
4. Upgrade will create the `theme_remui_kids_teacher_emulator` table

### Step 2: Verify Installation

Check phpMyAdmin for the new table:
- `mdl_theme_remui_kids_teacher_emulator`

### Step 3: Test as School Manager

1. Login as school manager
2. Go to: **School Manager → Emulator Access**
3. Select an emulator from the left sidebar
4. Scroll down to **"INDIVIDUAL TEACHERS"** section
5. You'll see all teachers in your school
6. Toggle access for individual teachers

---

## Security Model

### Default: DENY ALL (Secure by Default)

**All access is DISABLED by default:**
- Schools: Must be explicitly granted emulators
- Teachers: Must be explicitly enabled (school-wide OR individually)
- Students: Must be explicitly enabled (school-wide OR per cohort)

This is a **whitelist approach** - more secure, explicit permissions required.

---

## How It Works

### Access Hierarchy

```
1. Global Admin (School Grants)
   ↓ Must EXPLICITLY grant emulator to school
2. School-Wide Access (Teachers toggle)
   ↓ Must EXPLICITLY enable for teachers
3. Individual Teacher Selection ← NEW!
   ↓ Can grant/deny specific teachers
4. Teacher Can Access Emulator ✓
```

### Example Scenario 1: School-Wide Access

**Setup:**
- Global Admin: Grants "Code Editor" to School A
- School Manager: Enables "Code Editor" for teachers school-wide
- **No individual teacher settings**

**Result:**
- All teachers in School A can access Code Editor
- All granted at school-wide level

### Example Scenario 2: Individual Teacher Selection

**Setup:**
- Global Admin: Grants "Code Editor" to School A
- School Manager: Keeps teachers DISABLED school-wide
- School Manager: Explicitly enables for Teacher John and Teacher Jane

**Result:**
- Only Teacher John and Teacher Jane can access Code Editor
- All other teachers: DENIED (default)

### Example Scenario 3: Mixed Approach

**Setup:**
- Global Admin: Grants "Code Editor" to School A
- School Manager: Enables "Code Editor" for teachers school-wide
- School Manager: Individually **denies** access to Teacher Bob

**Result:**
- All teachers can access Code Editor
- **Except** Teacher Bob (explicitly denied)

---

## UI Components

### School Manager View:

```
┌─────────────────────────────────────────────────┐
│ Code Editor                                     │
│ Judge0-based IDE with multi-language support   │
├─────────────────────────────────────────────────┤
│ SCHOOL-WIDE ACCESS                              │
│ ✓ Teachers: Enabled                             │
│ ✓ Students: Enabled                             │
├─────────────────────────────────────────────────┤
│ INDIVIDUAL TEACHERS                             │
│ Select specific teachers who can access this    │
│                                                  │
│ ┌─────────────────────────────────────────────┐ │
│ │ John Doe (john@school.com)                  │ │
│ │ [Enabled] ☑                                 │ │
│ ├─────────────────────────────────────────────┤ │
│ │ Jane Smith (jane@school.com)                │ │
│ │ [Disabled] ☐                                │ │
│ ├─────────────────────────────────────────────┤ │
│ │ Bob Teacher (bob@school.com)                │ │
│ │ [Enabled] ☑                                 │ │
│ └─────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

---

## API Functions

### Get Teachers for School:
```php
$teachers = theme_remui_kids_get_school_teachers($companyid);
```

### Get Teacher Access Status:
```php
$access = theme_remui_kids_get_teacher_emulator_access('code_editor', $companyid);
```

### Update Teacher Access:
```php
theme_remui_kids_update_teacher_emulator_access(
    $teacherid,
    $companyid,
    'code_editor',
    true,  // allowed
    $userid
);
```

---

## AJAX Endpoints

### Get Teachers List:
```
POST /theme/remui_kids/ajax/teacher_emulator_access.php
action=get_teachers
emulator=code_editor
sesskey=xxx
```

**Response:**
```json
{
  "success": true,
  "teachers": [
    {
      "id": 123,
      "firstname": "John",
      "lastname": "Doe",
      "fullname": "John Doe",
      "email": "john@school.com",
      "allowed": true
    }
  ]
}
```

### Update Teacher Access:
```
POST /theme/remui_kids/ajax/teacher_emulator_access.php
action=update_teacher_access
teacherid=123
emulator=code_editor
allowed=1
sesskey=xxx
```

**Response:**
```json
{
  "success": true,
  "message": "Teacher access updated"
}
```

---

## Files Modified/Created

### New Files:
- `db/teacher_emulator_access_table.sql` - SQL reference
- `ajax/teacher_emulator_access.php` - AJAX handler for teacher access
- `TEACHER_EMULATOR_ACCESS_SETUP.md` - This documentation

### Modified Files:
- `db/upgrade.php` - Added version 2025120503
- `db/install.xml` - Added table definition
- `version.php` - Updated to 2025120503
- `lib/emulator_manager.php` - Added teacher management functions
- `school_manager/emulator_access.php` - Added teacher selection UI

---

## Benefits

✅ **Secure by Default** - All access DENIED until explicitly granted  
✅ **Granular Control** - Select individual teachers  
✅ **No Cohort Required** - Teachers don't need to be in cohorts  
✅ **School-Level** - Managed by school managers  
✅ **Auto-Save** - Changes save instantly  
✅ **Whitelist Model** - Explicit permissions required  
✅ **Flexible Options** - School-wide OR individual teacher grants  
✅ **Override Capability** - Can deny specific teachers even with school-wide access  

---

## Troubleshooting

### Teachers not showing:
1. Check if teachers are assigned to the school (company_users table)
2. Verify teachers have 'teacher' or 'editingteacher' role
3. Ensure teachers are not deleted or suspended

### Access not working:
1. Check if emulator is granted to the school (school grants)
2. Verify school-wide teacher access is enabled
3. Check if specific teacher is denied in individual selection

### AJAX errors:
1. Check sesskey is valid
2. Verify school manager permissions
3. Check browser console for JavaScript errors
4. Check PHP error logs

---

## Support

For issues or questions, contact your system administrator.

