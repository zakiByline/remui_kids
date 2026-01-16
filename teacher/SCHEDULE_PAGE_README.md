# Teacher Schedule Page Documentation

## 📅 Overview

A dedicated full-page schedule interface for teachers to view all their events, assignments, quizzes, and sessions in one place.

## 🔗 Access

**URL:** `your-site.com/theme/remui_kids/teacher/schedule.php`

**Navigation:**
- From Teacher Dashboard sidebar: Click **"My Schedule"**
- From Dashboard schedule section: Click **"View Full Calendar →"**
- From Upcoming Sessions: Click **"View full schedule →"**

## ✨ Features

### 1. Three View Modes

#### Week View (Default)
- Shows Monday-Sunday calendar grid
- Events displayed in time slots
- Navigate between weeks with arrows
- Current day highlighted in blue
- Each event shows:
  - Time
  - Event name with icon
  - Course name
  - Colored left border

#### Month View
- Full month calendar grid
- Days with events show colored dots
- Click day to see details
- Navigate between months

#### List View
- Chronological list of all events
- Grouped by date
- Shows next 30 days
- Full event details including descriptions
- Easy to scan and print

### 2. Statistics Dashboard
- **Total Events** - All scheduled items
- **Today** - Events happening today
- **This Week** - Events this week

### 3. Event Types

The page shows all event types:

| Icon | Type | Color | Source |
|------|------|-------|--------|
| 📅 | Calendar Events | Blue | Moodle Calendar |
| 📝 | Assignment Due | Red | Course Assignments |
| ❓ | Quiz Close | Green | Course Quizzes |
| 🔓 | Quiz Open | Blue | Course Quizzes |
| 👤 | Personal Event | Purple | User Calendar |
| 👥 | Group Event | Pink | Group Calendar |

### 4. Navigation

- **← → Arrows**: Navigate weeks/months
- **Today Button**: Jump to current week/month
- **View Selector**: Switch between Week/Month/List
- **Date Range Display**: Shows current viewing period

## 🎨 Design Features

- ✅ Clean, modern interface
- ✅ Color-coded events by type
- ✅ Responsive design (desktop/tablet/mobile)
- ✅ Hover effects and transitions
- ✅ Today highlighting
- ✅ Empty states with helpful messages

## 📊 Data Sources

The page automatically fetches:

1. **Calendar Events** (via Moodle's `calendar_get_events()` API)
   - Course events
   - User personal events
   - Site-wide events
   - Group events

2. **Assignment Deadlines**
   - All assignments with due dates
   - From enrolled courses
   - Visible assignments only

3. **Quiz Schedules**
   - Quiz close times
   - Quiz open times
   - From enrolled courses
   - Active quizzes only

## 🔧 Technical Details

### Files Created:
1. `/teacher/schedule.php` - Main PHP controller
2. `/templates/teacher_schedule_page.mustache` - Template
3. `/style/teacher_schedule_page.css` - Page styles

### Database Queries:
- Uses Moodle's standard calendar API
- Queries `{assign}` table for assignments
- Queries `{quiz}` table for quizzes
- Filters by user's enrolled courses
- Respects visibility settings

### Performance:
- Events cached by Moodle
- Efficient queries with proper indexes
- Limits to 30 days in list view
- Paginated by week/month

## 🚀 Usage Examples

### Navigate to Next Week:
```
/teacher/schedule.php?view=week&offset=1
```

### View Current Month:
```
/teacher/schedule.php?view=month
```

### See List of All Upcoming:
```
/teacher/schedule.php?view=list
```

## 📱 Responsive Breakpoints

- **Desktop** (>1200px): Full 7-column grid
- **Tablet** (768px-1200px): Adjusted spacing
- **Mobile** (<768px): Stacked single column

## 🎯 Integration Points

### Teacher Dashboard Integration:
- Sidebar link: "My Schedule"
- Schedule section: "View Full Calendar →" link
- Upcoming Sessions: "View full schedule →" link

### Moodle Calendar Integration:
- Full compatibility with Moodle calendar
- Click events to see in Moodle calendar
- Uses same event data as Moodle

## 🔍 Troubleshooting

### No Events Showing?

1. **Check enrolled courses**:
   - User must be enrolled in courses
   - Events must be in those courses

2. **Create test events**:
   - Go to Calendar → New Event
   - Or add assignment with due date
   - Or add quiz with close time

3. **Check visibility**:
   - Events must have `visible = 1`
   - Assignments/quizzes must be visible

4. **Clear cache**:
   - Admin → Development → Purge all caches

### Events in Wrong Date Range?

- Week view shows Monday-Sunday of selected week
- Month view shows entire month
- List view shows next 30 days from today

Use the arrow buttons to navigate to the correct time period.

## 🎓 For Teachers

### How to Add Events to Your Schedule:

**Method 1: Manual Calendar Event**
1. Click **Calendar** (top right)
2. Click **New Event**
3. Choose **Course Event**
4. Set date/time
5. Save

**Method 2: Assignment Due Date**
1. Go to your course
2. Add **Assignment** activity
3. Set **Due date**
4. Save (automatically appears in schedule)

**Method 3: Quiz Times**
1. Go to your course
2. Add **Quiz** activity
3. Set **Open** and **Close** times
4. Save (automatically appears in schedule)

## 📖 Related Pages

- **Teacher Dashboard**: `/my/` - Overview with schedule preview
- **Moodle Calendar**: `/calendar/view.php` - Standard calendar
- **Course Management**: `/theme/remui_kids/teacher/teacher_courses.php`

---

**Created:** November 2025  
**Version:** 1.0  
**Package:** theme_remui_kids

