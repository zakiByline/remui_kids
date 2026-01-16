# Admin Sidebar Usage Guide

## 📋 Overview
The centralized admin sidebar (`admin_sidebar.php`) provides consistent navigation across all admin pages with automatic active state detection.

---

## 🔧 Usage in PHP Files

### Method 1: Direct Include (Recommended)
```php
<?php
require_once('../../../config.php');
require_login();

// ... your page setup code ...

echo $OUTPUT->header();

// Include admin sidebar - automatically detects active page
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Your page content
echo "<div class='admin-main-content'>";
// ... your content here ...
echo "</div>";

echo $OUTPUT->footer();
?>
```

### Method 2: For Cohorts Folder
```php
<?php
// From cohorts/ folder, use relative path
require_once(__DIR__ . '/../admin/includes/admin_sidebar.php');
?>
```

### Method 3: For Subfolders (e.g., superreports/)
```php
<?php
// From admin/superreports/ folder
require_once(__DIR__ . '/../includes/admin_sidebar.php');
?>
```

---

## 🎨 Usage in Mustache Templates

### Step 1: Capture Sidebar HTML in PHP Controller
```php
<?php
// In your PHP controller that renders the mustache template

global $CFG, $OUTPUT;

// Capture the sidebar HTML
ob_start();
require_once($CFG->dirroot . '/theme/remui_kids/admin/includes/admin_sidebar.php');
$admin_sidebar_html = ob_get_clean();

// Pass to template context
$context = [
    'admin_sidebar_html' => $admin_sidebar_html,
    // ... your other context variables ...
];

// Render template
echo $OUTPUT->render_from_template('theme_remui_kids/admin_dashboard', $context);
?>
```

### Step 2: Use in Mustache Template
```mustache
{{!
    Admin Dashboard Template
}}

<!-- Include admin sidebar from PHP -->
{{{admin_sidebar_html}}}

<!-- Main content area -->
<div class="admin-main-content">
    <!-- Your content here -->
</div>
```

**Note:** Use triple braces `{{{admin_sidebar_html}}}` to render raw HTML, not double braces.

---

## ✨ Active State Detection

The sidebar **automatically** detects the current page and highlights the appropriate menu item!

### How It Works
1. Gets current URL: `$PAGE->url->out_omit_querystring()`
2. Gets script name: `basename($_SERVER['SCRIPT_NAME'])`
3. Checks against pattern arrays for each menu item
4. Adds 'active' class if match found

### Pattern Examples

| Menu Item | Patterns Matched |
|-----------|------------------|
| **Teachers** | `teachers_list.php`, `add_teacher.php`, `edit_teacher.php`, `view_teacher.php` |
| **Courses & Programs** | `courses.php`, `course_categories.php`, `manage_course_content.php`, `view_all_courses.php` |
| **Schools** | `schools_management.php`, `companies_list.php`, `company_create.php`, `company_edit.php`, `assign_to_school.php`, etc. |
| **User Management** | `users_management_dashboard.php`, `browse_users.php`, `create_user.php`, `edit_users.php`, `detail_*` |
| **Cohort Navigation** | `cohorts/`, `add_cohort.php`, `edit_cohort.php`, `manage_members.php`, `upload_cohorts.php` |
| **AI Assistant** | `ai_assistant.php` |
| **Train AI** | `train_ai.php` |

### Adding New Pages
To add a new page to an existing menu item's active detection:

1. Open `admin/includes/admin_sidebar.php`
2. Find the relevant menu item
3. Add your page pattern to the `is_active_page()` array

**Example:**
```php
// Before
echo "<li class='sidebar-item " . is_active_page(['courses.php']) . "'>";

// After - add new page
echo "<li class='sidebar-item " . is_active_page(['courses.php', 'my_new_course_page.php']) . "'>";
```

---

## 📂 File Structure

```
theme/remui_kids/
├── admin/
│   ├── includes/
│   │   ├── admin_sidebar.php          ← Main sidebar file
│   │   └── SIDEBAR_USAGE.md           ← This file
│   ├── ai_assistant.php               ← Uses sidebar
│   ├── train_ai.php                   ← Uses sidebar
│   └── ... (35 files using sidebar)
├── cohorts/
│   ├── index.php                      ← Uses sidebar
│   └── ... (6 files using sidebar)
└── templates/
    ├── shared_admin_sidebar.mustache  ← Uses {{{admin_sidebar_html}}}
    └── admin_dashboard.mustache       ← Uses {{{admin_sidebar_html}}}
```

---

## 🎯 Benefits

✅ **Single Source of Truth** - One file controls all navigation  
✅ **Automatic Active Detection** - No manual configuration  
✅ **Consistent UI** - Same sidebar everywhere  
✅ **Easy Maintenance** - Update once, applies globally  
✅ **AI Assistant Integration** - Visible on all admin pages  
✅ **Smart Grouping** - Related pages share active state  

---

## 🔄 Making Sidebar Changes

### To Add a New Menu Item
1. Edit `admin/includes/admin_sidebar.php`
2. Add new `<li>` with `is_active_page()` pattern matching
3. Save - changes apply to all 43 files automatically!

### To Update AI Assistant Links
1. Edit `admin/includes/admin_sidebar.php`
2. Modify the AI ASSISTANT section (lines 134-154)
3. Changes reflect everywhere immediately

### To Change Active Detection Logic
1. Edit the `is_active_page()` function (lines 19-28)
2. Modify pattern matching arrays for each menu item
3. Test on a few pages to verify highlighting works

---

## 📝 Current Coverage

**Total Files Using Centralized Sidebar: 43**
- PHP Files: 41 (35 admin + 6 cohorts)
- Mustache Templates: 2 (shared_admin_sidebar, admin_dashboard)

**All files automatically get:**
- Updated navigation items
- New menu sections (like AI Assistant)
- Correct active state highlighting
- Consistent styling

---

## 🚀 Quick Reference

### Include Sidebar (PHP)
```php
require_once(__DIR__ . '/includes/admin_sidebar.php');
```

### Include Sidebar (Mustache)
```php
// In controller
ob_start();
require_once($CFG->dirroot . '/theme/remui_kids/admin/includes/admin_sidebar.php');
$context['admin_sidebar_html'] = ob_get_clean();
```
```mustache
{{{admin_sidebar_html}}}
```

### Check Active State
No action needed! The `is_active_page()` function handles it automatically.

---

*Last Updated: October 30, 2025*

