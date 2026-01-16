# Teacher Sidebar Refactoring Guide

## 🎯 Goal

Replace hardcoded sidebars in all teacher pages with the reusable `teacher_sidebar.mustache` component.

## ✅ What Was Created

**Reusable Component:**
- `/templates/teacher_sidebar.mustache` - Single sidebar component for all teacher pages

**Benefits:**
- ✅ Update sidebar once, changes reflect everywhere
- ✅ Consistent navigation across all pages
- ✅ Active page highlighting
- ✅ Easier maintenance
- ✅ Less code duplication

## 📋 Pages to Refactor

### Already Using Reusable Sidebar:
- ✅ `teacher/schedule.php` - New schedule page (already uses component)

### Need Refactoring (have hardcoded sidebar):
- ⏳ `templates/teacher_dashboard.mustache`
- ⏳ `teacher/assignments.php`
- ⏳ `teacher/quizzes.php`
- ⏳ `teacher/students.php`
- ⏳ `teacher/competencies.php`
- ⏳ `teacher/rubrics.php`
- ⏳ `teacher/gradebook.php`
- ⏳ `teacher/enroll_students.php`
- ⏳ `teacher/teacher_courses.php`
- ⏳ `teacher/view_course.php`

## 🔧 How to Refactor Each Page

### Step 1: In the Template (.mustache file)

**REMOVE this entire block:**
```mustache
<!-- Teacher Sidebar Navigation -->
<div class="teacher-sidebar">
    <div class="sidebar-content">
        ... all the sidebar menu items ...
    </div>
</div>
```

**REPLACE with:**
```mustache
{{> theme_remui_kids/teacher_sidebar}}
```

### Step 2: In the PHP file

**ADD currentpage data to template context:**

```php
$templatecontext['currentpage'] = ['assignments' => true];
```

Replace `'assignments'` with the appropriate page identifier:

| Page | currentpage value |
|------|-------------------|
| Dashboard | `['dashboard' => true]` |
| My Courses | `['courses' => true]` |
| Teacher Resources | `['resources' => true]` |
| My Schedule | `['schedule' => true]` |
| All Students | `['students' => true]` |
| Enroll Students | `['enroll' => true]` |
| Progress Reports | `['progress' => true]` |
| Assignments | `['assignments' => true]` |
| Quizzes | `['quizzes' => true]` |
| Competencies | `['competencies' => true]` |
| Rubrics | `['rubrics' => true]` |
| Gradebook | `['gradebook' => true]` |
| Questions | `['questions' => true]` |
| Activity Logs | `['logs' => true]` |
| Course Reports | `['reports' => true]` |
| Progress Tracking | `['tracking' => true]` |

### Step 3: Ensure Wrapper Structure

Make sure your template has the proper wrapper:

```mustache
<div class="teacher-dashboard-wrapper">
    
    {{> theme_remui_kids/teacher_sidebar}}
    
    <div class="teacher-main-content">
        <!-- Your page content here -->
    </div>
</div>
```

## 📝 Example: Refactoring assignments.php

### Before (Hardcoded):
```mustache
<div class="teacher-dashboard-wrapper">
    <button class="sidebar-toggle">...</button>
    
    <div class="teacher-sidebar">
        <div class="sidebar-content">
            <div class="sidebar-section">
                <h3>DASHBOARD</h3>
                <ul>
                    <li><a href="/my/">Dashboard</a></li>
                    ... 50 more lines of menu items ...
                </ul>
            </div>
        </div>
    </div>
    
    <div class="teacher-main-content">
        <!-- assignments content -->
    </div>
</div>
```

### After (Reusable):
```mustache
<div class="teacher-dashboard-wrapper">
    
    {{> theme_remui_kids/teacher_sidebar}}
    
    <div class="teacher-main-content">
        <!-- assignments content -->
    </div>
</div>
```

### In PHP file:
```php
$templatecontext = [
    'assignments_data' => $assignments,
    // ... other data ...
    'config' => ['wwwroot' => $CFG->wwwroot],
    'currentpage' => ['assignments' => true]  // ← Add this
];
```

## 🎨 Sidebar Styling

The sidebar uses these CSS classes (already in teacher_dashboard.scss):
- `.teacher-sidebar`
- `.sidebar-content`
- `.sidebar-section`
- `.sidebar-category`
- `.sidebar-menu`
- `.sidebar-item`
- `.sidebar-item.active` (highlighted)
- `.sidebar-link`
- `.sidebar-icon`
- `.sidebar-text`

No CSS changes needed when refactoring!

## ⚡ Quick Refactor Script

To refactor a template file:

1. **Find and delete** the hardcoded sidebar:
   - Search for `<div class="teacher-sidebar">`
   - Delete until closing `</div>` of sidebar
   - Also delete `<button class="sidebar-toggle">`

2. **Add the include** after opening wrapper:
   ```mustache
   <div class="teacher-dashboard-wrapper">
       {{> theme_remui_kids/teacher_sidebar}}
   ```

3. **Update PHP** to add currentpage:
   ```php
   $templatecontext['currentpage'] = ['pagename' => true];
   ```

## 🧪 Testing After Refactoring

1. **Clear Moodle cache**:
   - Admin → Development → Purge all caches

2. **Visit the refactored page**

3. **Check:**
   - ✅ Sidebar appears correctly
   - ✅ Current page is highlighted
   - ✅ All menu links work
   - ✅ Mobile toggle works
   - ✅ Responsive design works

## 📌 Benefits Summary

**Before:** 
- Sidebar code duplicated in 15+ files
- Update requires changing all files
- Inconsistencies creep in
- Hard to maintain

**After:**
- Sidebar in 1 file only
- Update once, changes everywhere
- Always consistent
- Easy to maintain

## 🚀 Next Steps

1. Start with `teacher_dashboard.mustache` (most important)
2. Then refactor other high-traffic pages (assignments, quizzes, students)
3. Gradually refactor remaining pages
4. Test each page after refactoring
5. Delete hardcoded sidebar JavaScript (now in component)

---

**Created:** November 2025  
**Component:** `templates/teacher_sidebar.mustache`

