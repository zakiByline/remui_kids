# Course Sidebar UI Customization Guide

## Overview
The course sidebar (Course Menu) has been completely redesigned with a modern, professional look that matches the overall theme aesthetic.

## Files Modified/Created

### 1. **iomad/theme/remui_kids/style/course-sidebar.css** (NEW)
   - Complete custom styling for the course navigation drawer/sidebar
   - Modern gradient backgrounds
   - Smooth animations and transitions
   - Interactive hover effects
   - Custom scrollbar styling

### 2. **iomad/theme/remui_kids/templates/course.mustache** (MODIFIED)
   - Added link to the new course-sidebar.css file
   - Applies to main course view pages

### 3. **iomad/theme/remui_kids/templates/incourse.mustache** (NEW)
   - New template for module/activity pages
   - Includes the course-sidebar.css file
   - Includes JavaScript for sidebar toggle functionality
   - Ensures sidebar styling works on video lessons, quizzes, assignments, etc.

## Key Features Implemented

### 🎨 Visual Improvements

1. **Gradient Header**
   - Beautiful blue gradient (from #3b82f6 to #2563eb)
   - White text with subtle shadow
   - Smooth rotating close button animation

2. **Modern List Items**
   - Clean white background with subtle hover effects
   - Blue accent bar appears on hover (left border)
   - Smooth background gradient transition
   - Increased padding for better touch targets

3. **Active Item Highlighting**
   - Light blue gradient background for current lesson
   - Bold text and darker blue accent
   - Clear visual indicator of where you are

4. **Icons & Status Indicators**
   - Completion status icons with gradients:
     - ✅ **Completed**: Green gradient (#10b981 → #059669)
     - 🟧 **In Progress**: Orange gradient (#f59e0b → #d97706)
     - ⚪ **Not Started**: Gray (#e5e7eb)

5. **Custom Scrollbar**
   - Slim 8px width
   - Gradient thumb with smooth hover effects
   - Rounded corners

6. **Floating Toggle Button**
   - Fixed position at bottom-left
   - Blue gradient with shadow
   - Hover animation (lifts up and scales)
   - Smooth rotation on interaction

### 🎭 Animations

- **Drawer Open/Close**: Smooth slide-in from left (300ms cubic-bezier)
- **Hover Effects**: Gentle scale and color transitions
- **Backdrop**: Blur effect with fade-in/out
- **Icons**: Scale animation on hover

### 📱 Responsive Design

- **Desktop**: 320px width
- **Tablet (< 768px)**: 280px width
- **Mobile (< 480px)**: Full width (max 280px)
- Adjusted padding and font sizes for smaller screens

## Customization Options

### Change Sidebar Colors

#### Primary Color (Header & Accents)
```css
/* In course-sidebar.css, find and replace: */
#3b82f6 → Your color
#2563eb → Your darker shade
```

#### Background Colors
```css
/* Sidebar background gradient */
background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);

/* Hover effect */
background: linear-gradient(90deg, #eff6ff 0%, #dbeafe 100%);

/* Active item */
background: linear-gradient(90deg, #dbeafe 0%, #bfdbfe 100%);
```

### Change Toggle Button Position
```css
.custom-sidebar-toggle {
    bottom: 20px !important;  /* Distance from bottom */
    left: 20px !important;    /* Distance from left */
}

/* To move to bottom-right: */
/* Change left: 20px to right: 20px */
```

### Adjust Sidebar Width
```css
.drawer.drawer-left,
.theme_remui-drawers-courseindex {
    width: 320px !important;  /* Default: 320px */
}
```

### Modify Item Padding
```css
.courseindex .courseindex-item {
    padding: 1rem 1.25rem !important;  /* Top/Bottom Left/Right */
}
```

### Change Font Sizes
```css
/* Title in header */
.drawer .drawer-header .drawer-title {
    font-size: 1.25rem !important;  /* Default: 1.25rem */
}

/* List item text */
.courseindex .courseindex-item .courseindex-link {
    font-size: 0.95rem !important;  /* Default: 0.95rem */
}
```

## How to Test

1. **Clear Moodle Cache**:
   - Go to: Site Administration → Development → Purge all caches
   - Or run: `php admin/cli/purge_caches.php`

2. **Test on Course Page**:
   - Navigate to any course page (`course/view.php?id=X`)
   - The sidebar should automatically load with the new styling

3. **Test on Activity/Module Pages**:
   - Open any video lesson, quiz, assignment, or other activity
   - The sidebar should now have the same styling
   - This was previously not working - now fixed with `incourse.mustache`

4. **Test Interactions**:
   - Click the blue circular toggle button (bottom-left)
   - Hover over lesson items
   - Click different lessons to see active state
   - Try on mobile devices

## Browser Compatibility

✅ Chrome/Edge (latest)
✅ Firefox (latest)
✅ Safari (latest)
✅ Mobile browsers (iOS Safari, Chrome Mobile)

## Troubleshooting

### Styles Not Applying?

1. **Clear browser cache**: Ctrl+F5 (Windows) or Cmd+Shift+R (Mac)
2. **Purge Moodle caches**: Site Admin → Development → Purge all caches
3. **Check file path**: Ensure `course-sidebar.css` is in `/theme/remui_kids/style/`
4. **Inspect elements**: Use browser DevTools to see if CSS is loading

### Toggle Button Not Appearing?

- The button is created by JavaScript in `course.mustache`
- Check browser console for JavaScript errors
- Ensure the page has the `.drawers` element

### Sidebar Width Issues?

- Check if parent theme (RemUI) has conflicting styles
- Use `!important` flags if needed (already in place)
- Inspect with DevTools to see which styles are overriding

## Future Enhancements

Possible additions you might want:
- Progress percentage indicator in header
- Search/filter functionality
- Collapse all/expand all buttons
- Keyboard navigation support
- Dark mode variant
- Course completion badge in header

## Support

For any issues or customization requests, check:
1. Browser console for errors
2. Moodle debug mode (Site Admin → Development)
3. CSS file is properly loaded in Network tab

---

**Created**: November 5, 2025
**Version**: 1.0
**Theme**: RemUI Kids

