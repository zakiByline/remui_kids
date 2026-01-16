# Parent Competencies Page - Real Data Documentation

## ✅ This Page Uses 100% REAL Moodle Data (No Dummy Data)

The `parent_competencies.php` page fetches **actual data** from Moodle's database tables. Here's exactly what real data it uses:

---

## 📊 Real Data Sources

### 1. **Children List** (Real Parent-Child Relationships)
- **Table**: `user_info_data` (custom profile field: `parent_children`)
- **Function**: `remui_kids_get_parent_children($USER->id)`
- **What it does**: Fetches children linked to the parent's account
- **Fallback**: Admins see all students (for testing only)

### 2. **Child's Enrolled Courses** (Real Course Enrollments)
- **Function**: `enrol_get_all_users_courses($childid, true)`
- **What it does**: Gets ALL courses the selected child is enrolled in
- **Source**: Moodle's enrollment system (real enrollments)

### 3. **Competency Frameworks** (Real Learning Standards)
- **Tables**:
  - `{competency_framework}` - Framework definitions
  - `{competency}` - Individual competencies
  - `{competency_coursecomp}` - Competencies linked to courses
- **What it does**: Fetches competency frameworks (Math, Science, Language Arts, etc.)
- **Data**: Subject areas, standards, learning objectives

### 4. **Student Progress Data** (Real Learning Progress)
- **Tables**:
  - `{competency_usercompcourse}` - Progress in course competencies
  - `{competency_usercomp}` - Overall competency achievements
  - `{competency_usercompplan}` - Learning plan progress
- **What it does**: Gets actual progress percentages, proficiency status
- **Calculations**:
  - Progress percentage (0-100%)
  - Status: Competent / In Progress / Not Yet Competent
  - Proficiency flags (achieved or not)

### 5. **Teacher Feedback & Ratings** (Real Teacher Assessments)
- **Table**: `{competency_evidence}`
- **What it does**: Gets teacher comments, ratings, and timestamps
- **Data**:
  - Rating scale values (e.g., "Mastered", "Proficient", "Developing")
  - Teacher comments/notes
  - Assessment dates
  - Teacher names (from `{user}` table)

### 6. **Activity Completion** (Real Activity Data)
- **Tables**:
  - `{competency_modulecomp}` - Activities linked to competencies
  - `{course_modules_completion}` - Completion status
- **What it does**: Shows which activities are completed/in-progress
- **Data**: Quiz scores, assignment grades, completion states

### 7. **Learning Plans** (Real Academic Plans)
- **Tables**:
  - `{competency_plan}` - Learning plans
  - `{competency_plancomp}` - Plan competencies
- **What it does**: Shows structured learning pathways
- **Data**: Plan names, assigned competencies, progress

---

## 🎯 How Data is Categorized

### **Strong Areas (Green)** 🏆
- **Criteria**: Progress ≥ 80% AND Status = "Competent"
- **Real Data**: Child's actual mastered competencies
- **Shows**: Skills where child excels

### **Areas for Improvement (Blue)** 📈
- **Criteria**: Status = "In Progress"
- **Real Data**: Competencies currently being worked on
- **Shows**: Skills in development

### **Areas Needing Focus (Orange)** 🎯
- **Criteria**: Progress < 40% OR Status = "Not Competent"
- **Real Data**: Skills requiring extra attention
- **Shows**: Where child needs support

---

## 🔍 Debug Mode

Enable debug mode to see what data is being fetched:

```
/theme/remui_kids/parent/parent_competencies.php?debug=1
```

This shows:
- Selected child information
- Which database tables are being queried
- Course enrollment count
- Competency data sources

---

## 📱 How to Use the Page

### **For Parents:**

1. **Access**: Navigate to "Skills & Competencies" in sidebar
2. **Select Child**: Use dropdown if you have multiple children
3. **View Data**: See real competency progress organized by:
   - Strong Areas (what they're good at)
   - Improvement Areas (what they're working on)
   - Weak Areas (where they need help)

### **For Administrators:**

1. **Setup Requirements**:
   - Create competency frameworks (Site Admin → Competencies)
   - Link competencies to courses
   - Link competencies to activities (assignments, quizzes)
   - Teachers grade/assess using competency scales

2. **Linking Children to Parents**:
   - Parents can link children via the page interface
   - Admin can manually edit user profile field: `parent_children`
   - Format: Comma-separated child user IDs (e.g., "123,456,789")

---

## ⚠️ When No Data Appears

If the page shows "No Competency Data Available", it means:

1. ✅ **Page is working correctly**
2. ❌ **Data doesn't exist yet because**:
   - Child not enrolled in any courses
   - Courses don't have competency frameworks assigned
   - Teachers haven't assessed the child on competencies yet
   - Competencies not linked to course activities

### **Solution:**
1. Ensure child is enrolled in courses
2. Set up competency frameworks (admin task)
3. Link competencies to courses and activities
4. Teachers assess students on competencies
5. Data will then appear automatically!

---

## 🚀 Real-Time Data Flow

```
1. Parent logs in
   ↓
2. Selects child from dropdown
   ↓
3. PHP queries Moodle database:
   - Get child's courses (enrol_get_all_users_courses)
   - Get course competencies ({competency_coursecomp})
   - Get progress data ({competency_usercompcourse})
   - Get teacher feedback ({competency_evidence})
   - Get activity completion ({course_modules_completion})
   ↓
4. Data is analyzed and categorized:
   - Calculate progress percentages
   - Determine competency status
   - Sort into Strong/Improvement/Weak areas
   ↓
5. Display beautiful visualizations:
   - Progress bars (real percentages)
   - Status badges (real proficiency levels)
   - Teacher comments (real feedback)
   - Activity lists (real activities)
```

---

## 📋 Database Queries Used

```sql
-- Get child's enrolled courses
SELECT * FROM mdl_course WHERE id IN (SELECT courseid FROM mdl_enrol...)

-- Get competencies for courses
SELECT c.*, f.* FROM mdl_competency_coursecomp cc
JOIN mdl_competency c ON c.id = cc.competencyid
JOIN mdl_competency_framework f ON f.id = c.competencyframeworkid
WHERE cc.courseid IN (...)

-- Get student progress
SELECT * FROM mdl_competency_usercompcourse
WHERE userid = ? AND courseid IN (...)

-- Get teacher evidence/feedback
SELECT ce.*, u.firstname, u.lastname
FROM mdl_competency_evidence ce
LEFT JOIN mdl_user u ON u.id = ce.usermodified
WHERE ce.usercompetencyid IN (...)

-- Get activity completion
SELECT * FROM mdl_course_modules_completion
WHERE userid = ? AND coursemoduleid IN (...)
```

---

## ✨ Summary

**This page is NOT using dummy data.** Every piece of information displayed comes directly from:
- ✅ Real course enrollments
- ✅ Real competency frameworks
- ✅ Real student progress
- ✅ Real teacher assessments
- ✅ Real activity completions

If you don't see data, it means the data doesn't exist in the database yet - which is expected for new setups or children who haven't started competency-based learning.

---

## 🆘 Support

If you need help setting up competencies:
1. Check Moodle documentation: https://docs.moodle.org/en/Competencies
2. Ensure competency frameworks are created
3. Link competencies to courses and activities
4. Train teachers on competency-based grading

Once set up, this page will automatically display all real competency data!




